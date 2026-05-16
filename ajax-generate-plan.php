<?php
/**
 * AJAX endpoint to generate update plan with risk assessment
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
    $comparisonDataJson = $_POST['comparisonData'] ?? '';

    if (empty($dbname) || empty($comparisonDataJson)) {
        throw new Exception("Database name and comparison data are required");
    }

    $comparisonData = json_decode($comparisonDataJson, true);

    if (!$comparisonData) {
        throw new Exception("Invalid comparison data");
    }

    // Connect to TEST environment to get CREATE statements
    $testPdo = getTestConnection();

    // Initialize plan
    $plan = [
        'database' => $dbname,
        'risks' => [
            'high' => [],
            'medium' => [],
            'low' => []
        ],
        'impact' => [
            'tablesAffected' => [],
            'estimatedTime' => 'Less than 1 minute'
        ],
        'sqlStatements' => []
    ];

    // Collect tables that need to be added (for dependency ordering)
    $tablesToAdd = [];
    if (isset($comparisonData['tables'])) {
        foreach ($comparisonData['tables'] as $obj) {
            if ($obj['status'] === 'missing-in-prod') {
                $tablesToAdd[] = $obj['name'];
            }
        }
    }

    // Order tables by dependencies if multiple tables need to be added
    $orderedTables = [];
    if (count($tablesToAdd) > 1) {
        $orderedTables = orderTablesByDependencies($testPdo, $dbname, $tablesToAdd);
    } else {
        $orderedTables = $tablesToAdd;
    }

    // Add SET FOREIGN_KEY_CHECKS statements at the beginning if we're adding tables
    if (!empty($tablesToAdd)) {
        $plan['sqlStatements'][] = "SET FOREIGN_KEY_CHECKS=0";
    }

    // Process tables in dependency order
    if (isset($comparisonData['tables'])) {
        // First process tables that are missing in prod (in dependency order)
        foreach ($orderedTables as $tableName) {
            foreach ($comparisonData['tables'] as $obj) {
                if ($obj['name'] === $tableName && $obj['status'] === 'missing-in-prod') {
                    processObject($testPdo, $dbname, $obj, $plan);
                    break;
                }
            }
        }

        // Then process tables with differences
        foreach ($comparisonData['tables'] as $obj) {
            if ($obj['status'] === 'different') {
                processObject($testPdo, $dbname, $obj, $plan);
            }
        }
    }

    // Re-enable foreign key checks after table creation
    if (!empty($tablesToAdd)) {
        $plan['sqlStatements'][] = "SET FOREIGN_KEY_CHECKS=1";
    }

    // Process other object types
    $types = ['views', 'procedures', 'functions', 'triggers', 'events'];

    foreach ($types as $type) {
        if (!isset($comparisonData[$type])) continue;

        foreach ($comparisonData[$type] as $obj) {
            processObject($testPdo, $dbname, $obj, $plan);
        }
    }

    // Estimate execution time based on number of statements
    $stmtCount = count($plan['sqlStatements']);
    if ($stmtCount > 50) {
        $plan['impact']['estimatedTime'] = 'Several minutes';
    } elseif ($stmtCount > 20) {
        $plan['impact']['estimatedTime'] = '1-2 minutes';
    } elseif ($stmtCount > 5) {
        $plan['impact']['estimatedTime'] = '30-60 seconds';
    }

    sendSuccess($plan);

} catch (Exception $e) {
    sendError($e->getMessage());
}

/**
 * Process an object and add to update plan
 */
function processObject($testPdo, $dbname, $obj, &$plan) {
    $status = $obj['status'];
    $type = $obj['type'];
    $name = $obj['name'];

    // Skip identical objects
    if ($status === 'identical') {
        return;
    }

    // Skip objects missing in TEST (we only update PROD from TEST)
    if ($status === 'missing-in-test') {
        return;
    }

    // Handle objects missing in PROD
    if ($status === 'missing-in-prod') {
        handleMissingInProd($testPdo, $dbname, $obj, $plan);
        return;
    }

    // Handle objects with differences
    if ($status === 'different') {
        handleDifferentObject($testPdo, $dbname, $obj, $plan);
        return;
    }
}

/**
 * Handle objects that are missing in PROD
 */
function handleMissingInProd($testPdo, $dbname, $obj, &$plan) {
    $type = $obj['type'];
    $name = $obj['name'];

    try {
        if ($type === 'table') {
            // Get CREATE TABLE statement from TEST
            $createStmt = getCreateTable($testPdo, $dbname, $name);
            // Modify to use database name
            $createStmt = str_replace("CREATE TABLE `$name`", "CREATE TABLE `$dbname`.`$name`", $createStmt);

            $plan['sqlStatements'][] = $createStmt;
            $plan['risks']['low'][] = "Creating new table: $name";
            $plan['impact']['tablesAffected'][] = $name;

        } elseif ($type === 'view') {
            $createStmt = getViewDefinition($testPdo, $dbname, $name);
            // Convert to CREATE OR REPLACE
            $createStmt = preg_replace('/^CREATE/', 'CREATE OR REPLACE', $createStmt);
            $plan['sqlStatements'][] = $createStmt;
            $plan['risks']['low'][] = "Creating new view: $name";

        } elseif ($type === 'procedure') {
            $createStmt = getProcedureDefinition($testPdo, $dbname, $name);
            // Drop and recreate
            $plan['sqlStatements'][] = "DROP PROCEDURE IF EXISTS `$dbname`.`$name`";
            $plan['sqlStatements'][] = $createStmt;
            $plan['risks']['low'][] = "Creating new stored procedure: $name";

        } elseif ($type === 'function') {
            $createStmt = getFunctionDefinition($testPdo, $dbname, $name);
            // Drop and recreate
            $plan['sqlStatements'][] = "DROP FUNCTION IF EXISTS `$dbname`.`$name`";
            $plan['sqlStatements'][] = $createStmt;
            $plan['risks']['low'][] = "Creating new function: $name";

        } elseif ($type === 'trigger') {
            $createStmt = getTriggerDefinition($testPdo, $dbname, $name);
            // Drop and recreate
            $plan['sqlStatements'][] = "DROP TRIGGER IF EXISTS `$dbname`.`$name`";

            // Fix trigger statement to include database name
            if (preg_match('/ON\s+`?(\w+)`?\s+FOR\s+EACH\s+ROW/i', $createStmt, $matches)) {
                $createStmt = preg_replace(
                    '/(CREATE\s+TRIGGER\s+)`?(\w+)`?(\s+(?:BEFORE|AFTER)\s+(?:INSERT|UPDATE|DELETE)\s+ON\s+)`?(\w+)`?/i',
                    "$1`$dbname`.`$2`$3`$dbname`.`$4`",
                    $createStmt
                );
            }

            $plan['sqlStatements'][] = $createStmt;
            $plan['risks']['medium'][] = "Creating new trigger: $name (may affect data operations)";

        } elseif ($type === 'event') {
            $createStmt = getEventDefinition($testPdo, $dbname, $name);
            // Drop and recreate
            $plan['sqlStatements'][] = "DROP EVENT IF EXISTS `$dbname`.`$name`";
            $plan['sqlStatements'][] = $createStmt;
            $plan['risks']['low'][] = "Creating new event: $name";
        }
    } catch (Exception $e) {
        $plan['risks']['high'][] = "Error processing $type $name: " . $e->getMessage();
    }
}

/**
 * Handle objects with differences
 */
function handleDifferentObject($testPdo, $dbname, $obj, &$plan) {
    $type = $obj['type'];
    $name = $obj['name'];

    try {
        if ($type === 'table') {
            handleTableDifferences($testPdo, $dbname, $obj, $plan);

        } else {
            // For views, procedures, functions, triggers, events - just recreate
            if ($type === 'view') {
                $createStmt = getViewDefinition($testPdo, $dbname, $name);
                $createStmt = preg_replace('/^CREATE/', 'CREATE OR REPLACE', $createStmt);
                $plan['sqlStatements'][] = $createStmt;
                $plan['risks']['low'][] = "Updating view: $name";

            } elseif ($type === 'procedure') {
                $createStmt = getProcedureDefinition($testPdo, $dbname, $name);
                $plan['sqlStatements'][] = "DROP PROCEDURE IF EXISTS `$dbname`.`$name`";
                $plan['sqlStatements'][] = $createStmt;
                $plan['risks']['medium'][] = "Updating stored procedure: $name";

            } elseif ($type === 'function') {
                $createStmt = getFunctionDefinition($testPdo, $dbname, $name);
                $plan['sqlStatements'][] = "DROP FUNCTION IF EXISTS `$dbname`.`$name`";
                $plan['sqlStatements'][] = $createStmt;
                $plan['risks']['medium'][] = "Updating function: $name";

            } elseif ($type === 'trigger') {
                $createStmt = getTriggerDefinition($testPdo, $dbname, $name);
                $plan['sqlStatements'][] = "DROP TRIGGER IF EXISTS `$dbname`.`$name`";

                // Fix trigger statement to include database name
                if (preg_match('/ON\s+`?(\w+)`?\s+FOR\s+EACH\s+ROW/i', $createStmt, $matches)) {
                    $createStmt = preg_replace(
                        '/(CREATE\s+TRIGGER\s+)`?(\w+)`?(\s+(?:BEFORE|AFTER)\s+(?:INSERT|UPDATE|DELETE)\s+ON\s+)`?(\w+)`?/i',
                        "$1`$dbname`.`$2`$3`$dbname`.`$4`",
                        $createStmt
                    );
                }

                $plan['sqlStatements'][] = $createStmt;
                $plan['risks']['high'][] = "Updating trigger: $name (may affect data operations)";

            } elseif ($type === 'event') {
                $createStmt = getEventDefinition($testPdo, $dbname, $name);
                $plan['sqlStatements'][] = "DROP EVENT IF EXISTS `$dbname`.`$name`";
                $plan['sqlStatements'][] = $createStmt;
                $plan['risks']['medium'][] = "Updating event: $name";
            }

            $plan['impact']['tablesAffected'][] = $name;
        }
    } catch (Exception $e) {
        $plan['risks']['high'][] = "Error processing $type $name: " . $e->getMessage();
    }
}

/**
 * Handle table differences with ALTER TABLE statements
 */
function handleTableDifferences($testPdo, $dbname, $obj, &$plan) {
    $tableName = $obj['name'];
    $testStructure = $obj['testStructure'] ?? [];
    $prodStructure = $obj['prodStructure'] ?? [];

    $plan['impact']['tablesAffected'][] = $tableName;

    // Build column maps
    $testCols = [];
    foreach ($testStructure as $col) {
        $testCols[$col['Field']] = $col;
    }

    $prodCols = [];
    foreach ($prodStructure as $col) {
        $prodCols[$col['Field']] = $col;
    }

    // Find columns to add (in TEST but not in PROD)
    foreach ($testCols as $colName => $colDef) {
        if (!isset($prodCols[$colName])) {
            $alterStmt = buildAddColumnStatement($dbname, $tableName, $colName, $colDef);
            $plan['sqlStatements'][] = $alterStmt;

            if ($colDef['Null'] === 'NO' && empty($colDef['Default'])) {
                $plan['risks']['high'][] = "Adding NOT NULL column without default to table $tableName: $colName";
            } else {
                $plan['risks']['low'][] = "Adding column to table $tableName: $colName";
            }
        }
    }

    // Find columns that exist in both but have different definitions
    foreach ($testCols as $colName => $testCol) {
        if (isset($prodCols[$colName])) {
            $prodCol = $prodCols[$colName];

            // Check if column definition is different
            if ($testCol['Type'] !== $prodCol['Type'] ||
                $testCol['Null'] !== $prodCol['Null'] ||
                $testCol['Default'] !== $prodCol['Default'] ||
                $testCol['Extra'] !== $prodCol['Extra']) {

                $alterStmt = buildModifyColumnStatement($dbname, $tableName, $colName, $testCol);
                $plan['sqlStatements'][] = $alterStmt;

                // Type changes are high risk
                if ($testCol['Type'] !== $prodCol['Type']) {
                    $plan['risks']['high'][] = "Changing column type in table $tableName: $colName (may cause data loss)";
                } else {
                    $plan['risks']['medium'][] = "Modifying column in table $tableName: $colName";
                }
            }
        }
    }

    // Note: We don't drop columns from PROD that are missing in TEST
    // This is intentional to prevent accidental data loss
    foreach ($prodCols as $colName => $colDef) {
        if (!isset($testCols[$colName])) {
            $plan['risks']['medium'][] = "Column exists in PROD but not in TEST (will NOT be dropped): $tableName.$colName";
        }
    }

    // Handle index differences
    handleIndexDifferences($dbname, $obj, $plan);
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
 * Handle index differences
 */
function handleIndexDifferences($dbname, $obj, &$plan) {
    $tableName = $obj['name'];
    $testIndexes = $obj['testIndexes'] ?? [];
    $prodIndexes = $obj['prodIndexes'] ?? [];

    // Group indexes by name
    $testIndexMap = [];
    foreach ($testIndexes as $idx) {
        $indexName = $idx['Key_name'];
        if (!isset($testIndexMap[$indexName])) {
            $testIndexMap[$indexName] = [];
        }
        $testIndexMap[$indexName][] = $idx;
    }

    $prodIndexMap = [];
    foreach ($prodIndexes as $idx) {
        $indexName = $idx['Key_name'];
        if (!isset($prodIndexMap[$indexName])) {
            $prodIndexMap[$indexName] = [];
        }
        $prodIndexMap[$indexName][] = $idx;
    }

    // Find indexes to add
    foreach ($testIndexMap as $indexName => $indexCols) {
        if (!isset($prodIndexMap[$indexName]) && $indexName !== 'PRIMARY') {
            $columns = array_map(fn($idx) => "`{$idx['Column_name']}`", $indexCols);
            $isUnique = $indexCols[0]['Non_unique'] == 0;

            $sql = "ALTER TABLE `$dbname`.`$tableName` ADD ";
            if ($isUnique) {
                $sql .= "UNIQUE ";
            }
            $sql .= "INDEX `$indexName` (" . implode(', ', $columns) . ")";

            $plan['sqlStatements'][] = $sql;
            $plan['risks']['low'][] = "Adding index to table $tableName: $indexName";
        }
    }

    // Note: We don't drop indexes that exist in PROD but not in TEST
    foreach ($prodIndexMap as $indexName => $indexCols) {
        if (!isset($testIndexMap[$indexName]) && $indexName !== 'PRIMARY') {
            $plan['risks']['medium'][] = "Index exists in PROD but not in TEST (will NOT be dropped): $tableName.$indexName";
        }
    }
}
?>
