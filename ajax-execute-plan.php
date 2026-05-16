<?php
/**
 * AJAX endpoint to execute update plan on PROD database
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/dbtools/common/common.php';

header('Content-Type: application/json');

$userId = getCurrentUserId();
if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $dbname = $_POST['database'] ?? '';
    $updatePlanJson = $_POST['updatePlan'] ?? '';
    $backupOption = $_POST['backupOption'] ?? 'full';

    if (empty($dbname) || empty($updatePlanJson)) {
        throw new Exception("Database name and update plan are required");
    }

    $updatePlan = json_decode($updatePlanJson, true);

    if (!$updatePlan) {
        throw new Exception("Invalid update plan");
    }

    // Connect to PROD
    $prodPdo = getProdConnection($dbname);
    $prodPdo->setAttribute(PDO::ATTR_AUTOCOMMIT, 0);

    $result = [
        'database' => $dbname,
        'backupResult' => null,
        'sqlResults' => [],
        'success' => true
    ];

    // Step 1: Perform backup if requested
    if ($backupOption === 'full') {
        $result['backupResult'] = backupDatabase($dbname, 'full');
    } elseif ($backupOption === 'tables') {
        $affectedTables = $updatePlan['impact']['tablesAffected'] ?? [];
        $result['backupResult'] = backupTables($dbname, $affectedTables);
    } else {
        $result['backupResult'] = [
            'success' => true,
            'message' => 'Backup skipped by user request'
        ];
    }

    // If backup failed, stop here
    if (!$result['backupResult']['success']) {
        $result['success'] = false;
        sendSuccess($result);
        return;
    }

    // Step 2: Execute SQL statements
    $sqlStatements = $updatePlan['sqlStatements'] ?? [];

    try {
        // Begin transaction
        $prodPdo->beginTransaction();

        foreach ($sqlStatements as $sql) {
            try {
                $prodPdo->exec($sql);
                $result['sqlResults'][] = [
                    'success' => true,
                    'sql' => substr($sql, 0, 100) . '...',
                    'message' => 'Successfully executed'
                ];
            } catch (PDOException $e) {
                $result['sqlResults'][] = [
                    'success' => false,
                    'sql' => substr($sql, 0, 100) . '...',
                    'message' => 'Error: ' . $e->getMessage()
                ];
                $result['success'] = false;

                // Rollback on error
                $prodPdo->rollBack();
                throw new Exception("SQL execution failed, transaction rolled back: " . $e->getMessage());
            }
        }

        // Commit transaction
        $prodPdo->commit();

    } catch (Exception $e) {
        // Already rolled back in the catch block above
        throw $e;
    }

    sendSuccess($result);

} catch (Exception $e) {
    sendError($e->getMessage());
}

/**
 * Backup entire database
 */
function backupDatabase($dbname, $type) {
    try {
        $timestamp = date('Y-m-d-Hi');
        $backupFile = BACKUP_DIR . "{$dbname}-Prod-{$timestamp}.sql";

        // Use mysqldump to create backup with all proper flags
        $mysqldump = defined('MYSQLDUMP_PATH') ? MYSQLDUMP_PATH : 'mysqldump';
        $command = sprintf(
            '"%s" -h%s -u%s -p%s --routines --triggers --single-transaction --lock-tables=false --add-drop-database --databases %s > %s 2>&1',
            $mysqldump,
            DB_PROD_HOST,
            DB_PROD_USER,
            DB_PROD_PASS,
            escapeshellarg($dbname),
            escapeshellarg($backupFile)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            return [
                'success' => false,
                'message' => 'Backup failed: ' . implode("\n", $output)
            ];
        }

        // Check if backup file was created and has content
        if (!file_exists($backupFile) || filesize($backupFile) === 0) {
            return [
                'success' => false,
                'message' => 'Backup file was not created or is empty'
            ];
        }

        $fileSize = formatFileSize(filesize($backupFile));

        return [
            'success' => true,
            'message' => "Full database backup created: $backupFile ($fileSize)",
            'file' => $backupFile
        ];

    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Backup error: ' . $e->getMessage()
        ];
    }
}

/**
 * Backup specific tables
 */
function backupTables($dbname, $tables) {
    try {
        if (empty($tables)) {
            return [
                'success' => true,
                'message' => 'No tables to backup'
            ];
        }

        $timestamp = date('Y-m-d-Hi');
        $backupFile = BACKUP_DIR . "{$dbname}-Prod-{$timestamp}.sql";

        // Use mysqldump to backup specific tables with proper flags
        $tableList = implode(' ', array_map('escapeshellarg', $tables));
        $mysqldump = defined('MYSQLDUMP_PATH') ? MYSQLDUMP_PATH : 'mysqldump';

        $command = sprintf(
            '"%s" -h%s -u%s -p%s --routines --triggers --single-transaction --lock-tables=false %s %s > %s 2>&1',
            $mysqldump,
            DB_PROD_HOST,
            DB_PROD_USER,
            DB_PROD_PASS,
            escapeshellarg($dbname),
            $tableList,
            escapeshellarg($backupFile)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            return [
                'success' => false,
                'message' => 'Table backup failed: ' . implode("\n", $output)
            ];
        }

        // Check if backup file was created and has content
        if (!file_exists($backupFile) || filesize($backupFile) === 0) {
            return [
                'success' => false,
                'message' => 'Backup file was not created or is empty'
            ];
        }

        $fileSize = formatFileSize(filesize($backupFile));
        $tableCount = count($tables);

        return [
            'success' => true,
            'message' => "Backed up $tableCount table(s): $backupFile ($fileSize)",
            'file' => $backupFile
        ];

    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Backup error: ' . $e->getMessage()
        ];
    }
}

/**
 * Format file size
 */
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}
?>
