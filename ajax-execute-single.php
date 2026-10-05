<?php
/**
 * AJAX endpoint to execute a single database object update
 *
 * Parameters:
 * - database: Database name
 * - objectType: Type of object (table, view, procedure, function, trigger, event)
 * - objectName: Name of the object
 * - objectStatus: Status (missing-in-target, different)
 * - sourceEnv: Source environment ID (e.g., '95-local')
 * - targetEnv: Target environment ID (e.g., '84-prod')
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
    $objectType = $_POST['objectType'] ?? '';
    $objectName = $_POST['objectName'] ?? '';
    $objectStatus = $_POST['objectStatus'] ?? '';
    $sourceEnvId = $_POST['sourceEnv'] ?? '';
    $targetEnvId = $_POST['targetEnv'] ?? '';

    // Log the request
    logError("EXECUTE SINGLE REQUEST", [
        'database' => $dbname,
        'objectType' => $objectType,
        'objectName' => $objectName,
        'objectStatus' => $objectStatus,
        'sourceEnv' => $sourceEnvId,
        'targetEnv' => $targetEnvId
    ]);

    if (empty($dbname) || empty($objectType) || empty($objectName) || empty($objectStatus)) {
        throw new Exception("Database name, object type, object name, and object status are required");
    }

    if (empty($sourceEnvId) || empty($targetEnvId)) {
        throw new Exception("Source and target environments are required");
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

    // Verify both environments use the same MySQL version
    if ($sourceEnv['version'] !== $targetEnv['version']) {
        throw new Exception("Cannot sync between environments with different MySQL versions ({$sourceEnv['version']} vs {$targetEnv['version']})");
    }

    // Only process missing-in-target or different objects
    if ($objectStatus !== 'missing-in-target' && $objectStatus !== 'different') {
        throw new Exception("Cannot update object with status: {$objectStatus}");
    }

    // Connect to source environment to get CREATE statements
    $sourcePdo = getEnvConnection($sourceEnvId);

    // Connect to target environment to execute updates
    $targetPdo = getEnvConnection($targetEnvId);

    // Build target object with necessary data
    $targetObject = [
        'name' => $objectName,
        'type' => $objectType,
        'status' => $objectStatus
    ];

    // For tables with differences, we need to fetch structure from both databases
    if ($objectType === 'table' && $objectStatus === 'different') {
        // Get table structure from source
        $stmt = $sourcePdo->query("DESCRIBE `$dbname`.`$objectName`");
        $targetObject['sourceStructure'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get table indexes from source
        $stmt = $sourcePdo->query("SHOW INDEX FROM `$dbname`.`$objectName`");
        $targetObject['sourceIndexes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get table structure from target
        $stmt = $targetPdo->query("DESCRIBE `$dbname`.`$objectName`");
        $targetObject['targetStructure'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get table indexes from target
        $stmt = $targetPdo->query("SHOW INDEX FROM `$dbname`.`$objectName`");
        $targetObject['targetIndexes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Generate SQL statements for this object
    $sqlStatements = [];
    generateSqlForObject($sourcePdo, $dbname, $targetObject, $sqlStatements);

    logError("GENERATED SQL STATEMENTS", [
        'database' => $dbname,
        'object' => "$objectType.$objectName",
        'count' => count($sqlStatements),
        'statements' => $sqlStatements
    ]);

    if (empty($sqlStatements)) {
        throw new Exception("No SQL statements generated for object: $objectName");
    }

    // Check for dependencies if this is a table
    $dependencyWarning = null;
    if ($objectType === 'table' && $targetObject['status'] === 'missing-in-target') {
        $dependencies = checkTableDependencies($sourcePdo, $dbname, $objectName);

        if (!empty($dependencies['missingTables'])) {
            $dependencyWarning = "WARNING: This table has foreign key constraints referencing: " .
                               implode(', ', array_unique($dependencies['missingTables'])) .
                               ". These tables don't exist in {$targetEnv['label']} yet. Foreign key checks were temporarily disabled.";
        }
    }

    // Execute each SQL statement
    $targetPdo->beginTransaction();

    try {
        // Disable foreign key checks for table creation
        if ($objectType === 'table') {
            $targetPdo->exec("SET FOREIGN_KEY_CHECKS=0");
        }

        $executedStatements = [];
        foreach ($sqlStatements as $index => $sql) {
            try {
                logError("EXECUTING SQL STATEMENT #" . ($index + 1), [
                    'database' => $dbname,
                    'object' => "$objectType.$objectName",
                    'sql' => $sql
                ]);

                $targetPdo->exec($sql);

                logSql($dbname, $objectType, $objectName, $sql, true);

                $executedStatements[] = [
                    'index' => $index,
                    'sql' => substr($sql, 0, 200),
                    'success' => true
                ];
            } catch (PDOException $e) {
                // Log the error with the SQL statement
                logSql($dbname, $objectType, $objectName, $sql, false, $e->getMessage());
                logError("SQL EXECUTION FAILED", [
                    'database' => $dbname,
                    'object' => "$objectType.$objectName",
                    'error' => $e->getMessage(),
                    'sql' => $sql
                ]);

                $executedStatements[] = [
                    'index' => $index,
                    'sql' => substr($sql, 0, 200),
                    'success' => false,
                    'error' => $e->getMessage()
                ];
                throw new Exception("SQL Error on statement " . ($index + 1) . ": " . $e->getMessage() . "\nSQL: " . substr($sql, 0, 500));
            }
        }

        // If this is a table being added to target, copy the data
        $rowCount = 0;
        if ($objectType === 'table' && $targetObject['status'] === 'missing-in-target') {
            $rowCount = copyTableData($sourcePdo, $targetPdo, $dbname, $objectName);
        }

        // Re-enable foreign key checks
        if ($objectType === 'table') {
            $targetPdo->exec("SET FOREIGN_KEY_CHECKS=1");
        }

        $targetPdo->commit();

        $message = "Successfully updated $objectName in {$targetEnv['label']}";
        if ($objectType === 'table' && $targetObject['status'] === 'missing-in-target' && $rowCount > 0) {
            $message .= " with $rowCount rows of data";
        }

        $response = [
            'message' => $message,
            'statements' => count($sqlStatements)
        ];

        if ($dependencyWarning) {
            $response['warning'] = $dependencyWarning;
        }

        sendSuccess($response);

    } catch (Exception $e) {
        // Re-enable foreign key checks on error
        if ($objectType === 'table') {
            try {
                $targetPdo->exec("SET FOREIGN_KEY_CHECKS=1");
            } catch (Exception $ignored) {}
        }

        // Only rollback if there's an active transaction
        if ($targetPdo->inTransaction()) {
            $targetPdo->rollBack();
        }
        throw new Exception("Failed to execute SQL: " . $e->getMessage());
    }

} catch (Exception $e) {
    logError("EXECUTE SINGLE EXCEPTION", [
        'database' => $dbname ?? 'unknown',
        'objectType' => $objectType ?? 'unknown',
        'objectName' => $objectName ?? 'unknown',
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    sendError($e->getMessage());
}

/**
 * Generate SQL statements for a single object
 */
function generateSqlForObject($sourcePdo, $dbname, $obj, &$sqlStatements) {
    $status = $obj['status'];
    $type = $obj['type'];
    $name = $obj['name'];

    // Handle objects missing in target
    if ($status === 'missing-in-target') {
        generateSqlForMissingObject($sourcePdo, $dbname, $type, $name, $sqlStatements);
        return;
    }

    // Handle objects with differences
    if ($status === 'different') {
        generateSqlForDifferentObject($sourcePdo, $dbname, $obj, $sqlStatements);
        return;
    }
}

/**
 * Generate SQL for objects missing in target
 */
function generateSqlForMissingObject($sourcePdo, $dbname, $type, $name, &$sqlStatements) {
    if ($type === 'table') {
        // Get CREATE TABLE statement from source
        $createStmt = getCreateTable($sourcePdo, $dbname, $name);
        // Modify to use database name
        $createStmt = str_replace("CREATE TABLE `$name`", "CREATE TABLE `$dbname`.`$name`", $createStmt);
        $sqlStatements[] = $createStmt;

    } elseif ($type === 'view') {
        $createStmt = getViewDefinition($sourcePdo, $dbname, $name);
        // Convert to CREATE OR REPLACE
        $createStmt = preg_replace('/^CREATE/', 'CREATE OR REPLACE', $createStmt);
        $sqlStatements[] = $createStmt;

    } elseif ($type === 'procedure') {
        $createStmt = getProcedureDefinition($sourcePdo, $dbname, $name);
        // Drop and recreate
        $sqlStatements[] = "DROP PROCEDURE IF EXISTS `$dbname`.`$name`";
        $sqlStatements[] = $createStmt;

    } elseif ($type === 'function') {
        $createStmt = getFunctionDefinition($sourcePdo, $dbname, $name);
        // Drop and recreate
        $sqlStatements[] = "DROP FUNCTION IF EXISTS `$dbname`.`$name`";
        $sqlStatements[] = $createStmt;

    } elseif ($type === 'trigger') {
        $createStmt = getTriggerDefinition($sourcePdo, $dbname, $name);
        // Drop and recreate
        $sqlStatements[] = "DROP TRIGGER IF EXISTS `$dbname`.`$name`";

        // Fix trigger statement to include database name
        // Find the table name in the trigger definition
        if (preg_match('/ON\s+`?(\w+)`?\s+FOR\s+EACH\s+ROW/i', $createStmt, $matches)) {
            $tableName = $matches[1];
            // Replace table reference with fully qualified name
            $createStmt = preg_replace(
                '/(CREATE\s+TRIGGER\s+)`?(\w+)`?(\s+(?:BEFORE|AFTER)\s+(?:INSERT|UPDATE|DELETE)\s+ON\s+)`?(\w+)`?/i',
                "$1`$dbname`.`$2`$3`$dbname`.`$4`",
                $createStmt
            );
        }

        $sqlStatements[] = $createStmt;

    } elseif ($type === 'event') {
        $createStmt = getEventDefinition($sourcePdo, $dbname, $name);
        // Drop and recreate
        $sqlStatements[] = "DROP EVENT IF EXISTS `$dbname`.`$name`";
        $sqlStatements[] = $createStmt;
    }
}

/**
 * Generate SQL for objects with differences
 */
function generateSqlForDifferentObject($sourcePdo, $dbname, $obj, &$sqlStatements) {
    $type = $obj['type'];
    $name = $obj['name'];

    if ($type === 'table') {
        generateSqlForTableDifferences($sourcePdo, $dbname, $obj, $sqlStatements);

    } else {
        // For views, procedures, functions, triggers, events - just recreate
        if ($type === 'view') {
            $createStmt = getViewDefinition($sourcePdo, $dbname, $name);
            $createStmt = preg_replace('/^CREATE/', 'CREATE OR REPLACE', $createStmt);
            $sqlStatements[] = $createStmt;

        } elseif ($type === 'procedure') {
            $createStmt = getProcedureDefinition($sourcePdo, $dbname, $name);
            $sqlStatements[] = "DROP PROCEDURE IF EXISTS `$dbname`.`$name`";
            $sqlStatements[] = $createStmt;

        } elseif ($type === 'function') {
            $createStmt = getFunctionDefinition($sourcePdo, $dbname, $name);
            $sqlStatements[] = "DROP FUNCTION IF EXISTS `$dbname`.`$name`";
            $sqlStatements[] = $createStmt;

        } elseif ($type === 'trigger') {
            $createStmt = getTriggerDefinition($sourcePdo, $dbname, $name);
            $sqlStatements[] = "DROP TRIGGER IF EXISTS `$dbname`.`$name`";

            // Fix trigger statement to include database name
            if (preg_match('/ON\s+`?(\w+)`?\s+FOR\s+EACH\s+ROW/i', $createStmt, $matches)) {
                $createStmt = preg_replace(
                    '/(CREATE\s+TRIGGER\s+)`?(\w+)`?(\s+(?:BEFORE|AFTER)\s+(?:INSERT|UPDATE|DELETE)\s+ON\s+)`?(\w+)`?/i',
                    "$1`$dbname`.`$2`$3`$dbname`.`$4`",
                    $createStmt
                );
            }

            $sqlStatements[] = $createStmt;

        } elseif ($type === 'event') {
            $createStmt = getEventDefinition($sourcePdo, $dbname, $name);
            $sqlStatements[] = "DROP EVENT IF EXISTS `$dbname`.`$name`";
            $sqlStatements[] = $createStmt;
        }
    }
}

/**
 * Generate SQL for table differences
 */
function generateSqlForTableDifferences($sourcePdo, $dbname, $obj, &$sqlStatements) {
    $tableName = $obj['name'];
    $sourceStructure = $obj['sourceStructure'] ?? [];
    $targetStructure = $obj['targetStructure'] ?? [];

    // Build column maps
    $sourceCols = [];
    foreach ($sourceStructure as $col) {
        $sourceCols[$col['Field']] = $col;
    }

    $targetCols = [];
    foreach ($targetStructure as $col) {
        $targetCols[$col['Field']] = $col;
    }

    // Find columns to add (in source but not in target)
    foreach ($sourceCols as $colName => $colDef) {
        if (!isset($targetCols[$colName])) {
            $alterStmt = buildAddColumnStatement($dbname, $tableName, $colName, $colDef);
            $sqlStatements[] = $alterStmt;
        }
    }

    // Find columns that exist in both but have different definitions
    foreach ($sourceCols as $colName => $sourceCol) {
        if (isset($targetCols[$colName])) {
            $targetCol = $targetCols[$colName];

            // Check if column definition is different
            if ($sourceCol['Type'] !== $targetCol['Type'] ||
                $sourceCol['Null'] !== $targetCol['Null'] ||
                $sourceCol['Default'] !== $targetCol['Default'] ||
                $sourceCol['Extra'] !== $targetCol['Extra']) {

                $alterStmt = buildModifyColumnStatement($dbname, $tableName, $colName, $sourceCol);
                $sqlStatements[] = $alterStmt;
            }
        }
    }

    // Handle index differences
    generateSqlForIndexDifferences($dbname, $obj, $sqlStatements);
}

/**
 * Build ADD COLUMN statement
 */
function buildAddColumnStatement($dbname, $tableName, $colName, $colDef) {
    $sql = "ALTER TABLE `$dbname`.`$tableName` ADD COLUMN `$colName` {$colDef['Type']}";

    if ($colDef['Null'] === 'NO') {
        $sql .= " NOT NULL";
    } else {
        $sql .= " NULL";
    }

    if ($colDef['Default'] !== null) {
        $sql .= " DEFAULT " . (is_numeric($colDef['Default']) ? $colDef['Default'] : "'{$colDef['Default']}'");
    }

    if (!empty($colDef['Extra'])) {
        $sql .= " {$colDef['Extra']}";
    }

    return $sql;
}

/**
 * Build MODIFY COLUMN statement
 */
function buildModifyColumnStatement($dbname, $tableName, $colName, $colDef) {
    $sql = "ALTER TABLE `$dbname`.`$tableName` MODIFY COLUMN `$colName` {$colDef['Type']}";

    if ($colDef['Null'] === 'NO') {
        $sql .= " NOT NULL";
    } else {
        $sql .= " NULL";
    }

    if ($colDef['Default'] !== null) {
        $sql .= " DEFAULT " . (is_numeric($colDef['Default']) ? $colDef['Default'] : "'{$colDef['Default']}'");
    }

    if (!empty($colDef['Extra'])) {
        $sql .= " {$colDef['Extra']}";
    }

    return $sql;
}

/**
 * Generate SQL for index differences
 */
function generateSqlForIndexDifferences($dbname, $obj, &$sqlStatements) {
    $tableName = $obj['name'];
    $sourceIndexes = $obj['sourceIndexes'] ?? [];
    $targetIndexes = $obj['targetIndexes'] ?? [];

    // Group indexes by name
    $sourceIndexMap = [];
    foreach ($sourceIndexes as $idx) {
        $indexName = $idx['Key_name'];
        if (!isset($sourceIndexMap[$indexName])) {
            $sourceIndexMap[$indexName] = [];
        }
        $sourceIndexMap[$indexName][] = $idx;
    }

    $targetIndexMap = [];
    foreach ($targetIndexes as $idx) {
        $indexName = $idx['Key_name'];
        if (!isset($targetIndexMap[$indexName])) {
            $targetIndexMap[$indexName] = [];
        }
        $targetIndexMap[$indexName][] = $idx;
    }

    // Find indexes to add
    foreach ($sourceIndexMap as $indexName => $indexCols) {
        if (!isset($targetIndexMap[$indexName]) && $indexName !== 'PRIMARY') {
            $columns = array_map(fn($idx) => "`{$idx['Column_name']}`", $indexCols);
            $isUnique = $indexCols[0]['Non_unique'] == 0;

            $sql = "ALTER TABLE `$dbname`.`$tableName` ADD ";
            if ($isUnique) {
                $sql .= "UNIQUE ";
            }
            $sql .= "INDEX `$indexName` (" . implode(', ', $columns) . ")";

            $sqlStatements[] = $sql;
        }
    }
}
?>
