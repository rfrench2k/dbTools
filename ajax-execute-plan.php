<?php
/**
 * AJAX endpoint to execute update plan on PROD database
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/dbtools/common/common.php';
require_once __DIR__ . '/backup-lib.php';   // createDatabaseBackup()
dbtoolsRequireAction();   // SUPERADMIN, from DB Tools' own pages only

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
    $prodPdo = getEnvConnection(DBTOOLS_ENV, $dbname);
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
    $backup = createDatabaseBackup(dbtoolsServer(), $dbname, BACKUP_DIR, "{$dbname}-Prod");
    if (!$backup['success']) {
        return [
            'success' => false,
            'message' => 'Backup failed: ' . $backup['error']
        ];
    }

    return [
        'success' => true,
        'message' => "Full database backup created: {$backup['filepath']} ({$backup['fileSize']})",
        'file' => $backup['filepath']
    ];
}

/**
 * Backup specific tables
 */
function backupTables($dbname, $tables) {
    if (empty($tables)) {
        return [
            'success' => true,
            'message' => 'No tables to backup'
        ];
    }

    $backup = createDatabaseBackup(dbtoolsServer(), $dbname, BACKUP_DIR, "{$dbname}-Prod-Tables", $tables);
    if (!$backup['success']) {
        return [
            'success' => false,
            'message' => 'Table backup failed: ' . $backup['error']
        ];
    }

    $tableCount = count($tables);
    return [
        'success' => true,
        'message' => "Backed up $tableCount table(s): {$backup['filepath']} ({$backup['fileSize']})",
        'file' => $backup['filepath']
    ];
}
?>
