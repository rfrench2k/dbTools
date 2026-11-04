<?php
/**
 * AJAX endpoint for backup tool
 *
 * Supports multi-environment backup operations
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/dbtools/common/common.php';
dbtoolsRequireAction();   // SUPERADMIN, from DB Tools' own pages only

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
    require_once 'backup-lib.php'; // Shared backup functions

    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'check_system':
            checkSystemStatus();
            break;

        case 'backup':
            createBackup();
            break;

        case 'list_backups':
            listBackupFiles();
            break;

        default:
            sendError("Invalid action");
    }
} catch (Exception $e) {
    sendError($e->getMessage());
}

/**
 * Check system status for a specific environment
 */
function checkSystemStatus() {
    $envId = $_POST['env'] ?? '';

    $backupDirExists = file_exists(BACKUP_DIR);
    $backupDirWritable = false;

    if (!$backupDirExists) {
        @mkdir(BACKUP_DIR, 0755, true);
        $backupDirExists = file_exists(BACKUP_DIR);
    }

    if ($backupDirExists) {
        $backupDirWritable = is_writable(BACKUP_DIR);
    }

    $mysqldumpPath = MYSQLDUMP_PATH;
    $mysqldumpAvailable = file_exists($mysqldumpPath);

    // Test environment connection
    $envConnection = [
        'success' => false,
        'label' => ''
    ];

    if (!$envId) {
        $envId = DBTOOLS_ENV;
    }
    $env = getEnvironment($envId);
    if ($env) {
        $envConnection['label'] = $env['label'];
        try {
            $pdo = getEnvConnection($envId);
            $envConnection['success'] = true;
        } catch (Exception $e) {
            $envConnection['error'] = $e->getMessage();
        }
    } else {
        $envConnection['error'] = "Unknown environment: $envId";
    }

    sendSuccess([
        'backupDir' => BACKUP_DIR,
        'backupDirExists' => $backupDirExists,
        'backupDirWritable' => $backupDirWritable,
        'mysqldumpAvailable' => $mysqldumpAvailable,
        'mysqldumpPath' => $mysqldumpPath,
        'envConnection' => $envConnection
    ]);
}

/**
 * Create database backup using shared library
 */
function createBackup() {
    $database = $_POST['database'] ?? '';
    $envId = $_POST['env'] ?? '';

    if (empty($database)) {
        throw new Exception("Database name required");
    }

    if (empty($envId)) {
        throw new Exception("Environment ID required");
    }

    // Get environment details
    $env = getEnvironment($envId);
    if (!$env) {
        throw new Exception("Unknown environment: $envId");
    }

    // Create a short label for the filename (remove spaces and special chars)
    $envLabel = preg_replace('/[^a-zA-Z0-9]/', '', $env['label']);

    // Use shared backup library function with environment-specific settings
    $result = createDatabaseBackup($env, $database, BACKUP_DIR, "{$database}-{$envLabel}");

    if ($result['success']) {
        $result['environment'] = $env['label'];
        sendSuccess($result);
    } else {
        throw new Exception($result['error']);
    }
}

/**
 * List backup files
 */
function listBackupFiles() {
    if (!file_exists(BACKUP_DIR)) {
        sendSuccess(['backups' => []]);
        return;
    }

    $backups = [];
    $files = scandir(BACKUP_DIR);

    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;

        $filepath = BACKUP_DIR . $file;
        if (!is_file($filepath)) continue;
        if (pathinfo($file, PATHINFO_EXTENSION) !== 'sql') continue;

        $backups[] = [
            'filename' => $file,
            'filepath' => $filepath,
            'size' => formatBackupFileSize(filesize($filepath)),
            'sizeBytes' => filesize($filepath),
            'created' => date('Y-m-d H:i:s', filemtime($filepath))
        ];
    }

    // Sort by creation time, newest first
    usort($backups, function($a, $b) {
        return $b['sizeBytes'] <=> $a['sizeBytes'];
    });

    sendSuccess(['backups' => $backups]);
}

// Note: formatBackupFileSize() is in backup-lib.php
?>
