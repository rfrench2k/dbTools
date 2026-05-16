<?php
/**
 * AJAX endpoint for database replication
 *
 * Supports multi-environment replication
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/dbtools/common/common.php';

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');

$userId = getCurrentUserId();
if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    require_once 'backup-lib.php';

    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'check':
            checkDatabaseStatus();
            break;

        case 'replicate':
            replicateDatabase();
            break;

        default:
            sendError("Invalid action");
    }
} catch (Exception $e) {
    sendError($e->getMessage());
}

/**
 * Check database status before replication
 */
function checkDatabaseStatus() {
    $database = $_POST['database'] ?? '';
    $sourceEnvId = $_POST['sourceEnv'] ?? '';
    $targetEnvId = $_POST['targetEnv'] ?? '';

    if (empty($database)) {
        throw new Exception("Database name required");
    }

    if (empty($sourceEnvId) || empty($targetEnvId)) {
        throw new Exception("Source and target environments required");
    }

    // Get environment details
    $sourceEnv = getEnvironment($sourceEnvId);
    $targetEnv = getEnvironment($targetEnvId);

    if (!$sourceEnv) {
        throw new Exception("Invalid source environment: $sourceEnvId");
    }
    if (!$targetEnv) {
        throw new Exception("Invalid target environment: $targetEnvId");
    }

    // Verify same MySQL version
    if ($sourceEnv['version'] !== $targetEnv['version']) {
        throw new Exception("Cannot replicate between different MySQL versions ({$sourceEnv['version']} vs {$targetEnv['version']})");
    }

    // Check source
    $sourcePdo = getEnvConnection($sourceEnvId);
    $sourceDatabases = getDatabases($sourcePdo);
    $existsInSource = in_array($database, $sourceDatabases);

    // Check target
    $targetPdo = getEnvConnection($targetEnvId);
    $targetDatabases = getDatabases($targetPdo);
    $existsInTarget = in_array($database, $targetDatabases);

    $sourceRowCount = 0;
    $targetRowCount = 0;

    // Get row counts
    if ($existsInSource) {
        try {
            $stmt = $sourcePdo->query("
                SELECT SUM(TABLE_ROWS) as total
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = '$database'
            ");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $sourceRowCount = (int)$row['total'];
        } catch (Exception $e) {
            // Ignore errors
        }
    }

    if ($existsInTarget) {
        try {
            $stmt = $targetPdo->query("
                SELECT SUM(TABLE_ROWS) as total
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = '$database'
            ");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $targetRowCount = (int)$row['total'];
        } catch (Exception $e) {
            // Ignore errors
        }
    }

    $canReplicate = $existsInSource && $existsInTarget;
    $message = '';

    if (!$existsInSource) {
        $message = "Database does not exist in {$sourceEnv['label']}";
    } elseif (!$existsInTarget) {
        $message = "Database does not exist in {$targetEnv['label']}";
    } elseif ($canReplicate) {
        $message = "Ready to replicate";
    }

    sendSuccess([
        'database' => $database,
        'existsInSource' => $existsInSource,
        'existsInTarget' => $existsInTarget,
        'sourceRowCount' => $sourceRowCount,
        'targetRowCount' => $targetRowCount,
        'canReplicate' => $canReplicate,
        'message' => $message,
        'sourceEnvLabel' => $sourceEnv['label'],
        'targetEnvLabel' => $targetEnv['label']
    ]);
}

/**
 * Replicate database from source to target environment
 */
function replicateDatabase() {
    $database = $_POST['database'] ?? '';
    $sourceEnvId = $_POST['sourceEnv'] ?? '';
    $targetEnvId = $_POST['targetEnv'] ?? '';

    if (empty($database)) {
        throw new Exception("Database name required");
    }

    if (empty($sourceEnvId) || empty($targetEnvId)) {
        throw new Exception("Source and target environments required");
    }

    // Get environment details
    $sourceEnv = getEnvironment($sourceEnvId);
    $targetEnv = getEnvironment($targetEnvId);

    if (!$sourceEnv) {
        throw new Exception("Invalid source environment: $sourceEnvId");
    }
    if (!$targetEnv) {
        throw new Exception("Invalid target environment: $targetEnvId");
    }

    // Verify same MySQL version
    if ($sourceEnv['version'] !== $targetEnv['version']) {
        throw new Exception("Cannot replicate between different MySQL versions");
    }

    $startTime = microtime(true);
    $steps = [];

    // Get version-specific tools
    $mysqldumpPath = getMysqldumpPath($sourceEnvId);
    $mysqlPath = getMysqlPath($targetEnvId);

    if (!$mysqldumpPath || !file_exists($mysqldumpPath)) {
        $mysqldumpPath = findMysqldumpExecutable();
    }
    if (!$mysqlPath || !file_exists($mysqlPath)) {
        $mysqlPath = defined('MYSQL_PATH') ? MYSQL_PATH : 'mysql';
    }

    if (!$mysqldumpPath) {
        throw new Exception("mysqldump not found");
    }

    // Create short labels for filenames
    $sourceLabel = preg_replace('/[^a-zA-Z0-9]/', '', $sourceEnv['label']);
    $targetLabel = preg_replace('/[^a-zA-Z0-9]/', '', $targetEnv['label']);

    // Step 1: Backup target database first (safety)
    $steps[] = ['message' => "Creating backup of {$targetEnv['label']} database...", 'success' => false];

    $targetBackupResult = createDatabaseBackupWithPath(
        $targetEnv['host'],
        $targetEnv['user'],
        $targetEnv['pass'],
        $database,
        BACKUP_DIR,
        "{$database}-{$targetLabel}-BeforeReplication",
        $targetEnv['port'],
        $mysqldumpPath
    );

    if ($targetBackupResult['success']) {
        $steps[count($steps) - 1]['success'] = true;
        $steps[count($steps) - 1]['message'] .= ' ✓ ' . $targetBackupResult['filename'];
        $targetBackupFile = $targetBackupResult['filepath'];
    } else {
        throw new Exception("Failed to backup {$targetEnv['label']} database: " . $targetBackupResult['error']);
    }

    // Step 2: Create dump from source
    $steps[] = ['message' => "Creating dump from {$sourceEnv['label']} database...", 'success' => false];

    $sourceBackupResult = createDatabaseBackupWithPath(
        $sourceEnv['host'],
        $sourceEnv['user'],
        $sourceEnv['pass'],
        $database,
        BACKUP_DIR,
        "{$database}-{$sourceLabel}-ForReplication",
        $sourceEnv['port'],
        $mysqldumpPath
    );

    if ($sourceBackupResult['success']) {
        $steps[count($steps) - 1]['success'] = true;
        $steps[count($steps) - 1]['message'] .= ' ✓ ' . $sourceBackupResult['fileSize'];
        $sourceDumpFile = $sourceBackupResult['filepath'];
    } else {
        throw new Exception("Failed to dump {$sourceEnv['label']} database: " . $sourceBackupResult['error']);
    }

    // Step 3: Drop target database
    $steps[] = ['message' => "Dropping {$targetEnv['label']} database...", 'success' => false];

    try {
        $targetPdo = getEnvConnection($targetEnvId);
        $targetPdo->exec("DROP DATABASE IF EXISTS `$database`");
        $steps[count($steps) - 1]['success'] = true;
    } catch (Exception $e) {
        throw new Exception("Failed to drop {$targetEnv['label']} database: " . $e->getMessage());
    }

    // Step 4: Import source dump into target
    $steps[] = ['message' => "Importing {$sourceEnv['label']} data into {$targetEnv['label']}...", 'success' => false];

    $command = sprintf(
        '"%s" -h%s -P%d -u%s -p%s < %s 2>nul',
        $mysqlPath,
        $targetEnv['host'],
        $targetEnv['port'],
        $targetEnv['user'],
        $targetEnv['pass'],
        escapeshellarg($sourceDumpFile)
    );

    $output = [];
    $returnCode = 0;
    exec($command, $output, $returnCode);

    if ($returnCode !== 0) {
        $errorMsg = implode("\n", $output);
        throw new Exception("Import failed: $errorMsg");
    }

    $steps[count($steps) - 1]['success'] = true;

    // Step 5: Verify import
    $steps[] = ['message' => 'Verifying import...', 'success' => false];

    try {
        $targetPdo = getEnvConnection($targetEnvId, $database);
        $stmt = $targetPdo->query("SHOW TABLES");
        $tableCount = $stmt->rowCount();

        $steps[count($steps) - 1]['success'] = true;
        $steps[count($steps) - 1]['message'] .= " ✓ $tableCount tables";
    } catch (Exception $e) {
        $steps[count($steps) - 1]['message'] .= " ⚠ Could not verify";
    }

    // Clean up source dump file
    @unlink($sourceDumpFile);

    $endTime = microtime(true);
    $duration = round($endTime - $startTime, 2) . ' seconds';

    sendSuccess([
        'database' => $database,
        'sourceEnv' => $sourceEnv['label'],
        'targetEnv' => $targetEnv['label'],
        'targetBackup' => basename($targetBackupFile),
        'sourceBackup' => basename($sourceDumpFile) . ' (deleted after import)',
        'duration' => $duration,
        'steps' => $steps
    ]);
}
?>
