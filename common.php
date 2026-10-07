<?php
/**
 * Database Management Tools - Common Functions
 *
 * Shared functions for database connections and operations
 */

require_once 'config.php';

/**
 * Get PDO connection to TEST environment
 */
function getTestConnection($dbname = null) {
    try {
        $dsn = "mysql:host=" . DB_TEST_HOST;
        if ($dbname) {
            $dsn .= ";dbname=" . $dbname;
        }
        $pdo = new PDO($dsn, DB_TEST_USER, DB_TEST_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch(PDOException $e) {
        throw new Exception("TEST connection failed: " . $e->getMessage());
    }
}

/**
 * Get PDO connection to PROD environment
 */
function getProdConnection($dbname = null) {
    try {
        $dsn = "mysql:host=" . DB_PROD_HOST;
        if ($dbname) {
            $dsn .= ";dbname=" . $dbname;
        }
        $pdo = new PDO($dsn, DB_PROD_USER, DB_PROD_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch(PDOException $e) {
        throw new Exception("PROD connection failed: " . $e->getMessage());
    }
}

/**
 * Get list of all databases from an environment
 */
function getDatabases($pdo) {
    $stmt = $pdo->query("SHOW DATABASES");
    $databases = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $dbname = $row['Database'];
        // Exclude system databases
        if (!in_array($dbname, ['information_schema', 'mysql', 'performance_schema', 'sys'])) {
            $databases[] = $dbname;
        }
    }
    return $databases;
}

/**
 * Get all tables in a database
 */
function getTables($pdo, $dbname) {
    $stmt = $pdo->query("SHOW TABLES FROM `$dbname`");
    $tables = [];
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }
    return $tables;
}

/**
 * Get table structure details
 */
function getTableStructure($pdo, $dbname, $tablename) {
    $stmt = $pdo->query("SHOW FULL COLUMNS FROM `$dbname`.`$tablename`");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get table indexes
 */
function getTableIndexes($pdo, $dbname, $tablename) {
    $stmt = $pdo->query("SHOW INDEXES FROM `$dbname`.`$tablename`");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get CREATE TABLE statement
 */
function getCreateTable($pdo, $dbname, $tablename) {
    $stmt = $pdo->query("SHOW CREATE TABLE `$dbname`.`$tablename`");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row['Create Table'];
}

/**
 * Get table row count
 */
function getTableRowCount($pdo, $dbname, $tablename) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM `$dbname`.`$tablename`");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)$row['cnt'];
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Get all views in a database
 */
function getViews($pdo, $dbname) {
    $stmt = $pdo->query("SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = '$dbname'");
    $views = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $views[] = $row['TABLE_NAME'];
    }
    return $views;
}

/**
 * Get view definition
 */
function getViewDefinition($pdo, $dbname, $viewname) {
    try {
        $stmt = $pdo->query("SHOW CREATE VIEW `$dbname`.`$viewname`");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return stripDefiner($row['Create View']);
    } catch (PDOException $e) {
        // If view is invalid/broken (error 1356 or similar), throw a more descriptive error
        if (strpos($e->getMessage(), '1356') !== false) {
            throw new Exception("View '$viewname' is invalid - references missing tables/columns or has permission issues. View needs to be fixed or recreated.");
        }
        throw $e;
    }
}

/**
 * Strip DEFINER clause from SQL statements
 * This allows objects to be created in different environments without permission issues
 */
function stripDefiner($sql) {
    $original = $sql;

    // Remove DEFINER=`user`@`host` clause
    $sql = preg_replace('/DEFINER\s*=\s*`[^`]+`@`[^`]+`\s*/i', '', $sql);

    // Change SQL SECURITY DEFINER to SQL SECURITY INVOKER
    // This makes the object run with the privileges of the invoker, not the definer
    $sql = preg_replace('/SQL\s+SECURITY\s+DEFINER/i', 'SQL SECURITY INVOKER', $sql);

    // Log if DEFINER was found and stripped
    if ($original !== $sql) {
        logError("DEFINER STRIPPED", [
            'before_length' => strlen($original),
            'after_length' => strlen($sql),
            'before' => substr($original, 0, 300),
            'after' => substr($sql, 0, 300)
        ]);
    }

    return $sql;
}

/**
 * Get all stored procedures in a database
 */
function getProcedures($pdo, $dbname) {
    $stmt = $pdo->query("SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = '$dbname' AND ROUTINE_TYPE = 'PROCEDURE'");
    $procedures = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $procedures[] = $row['ROUTINE_NAME'];
    }
    return $procedures;
}

/**
 * Get procedure definition
 */
function getProcedureDefinition($pdo, $dbname, $procname) {
    $stmt = $pdo->query("SHOW CREATE PROCEDURE `$dbname`.`$procname`");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return stripDefiner($row['Create Procedure']);
}

/**
 * Get all functions in a database
 */
function getFunctions($pdo, $dbname) {
    $stmt = $pdo->query("SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = '$dbname' AND ROUTINE_TYPE = 'FUNCTION'");
    $functions = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $functions[] = $row['ROUTINE_NAME'];
    }
    return $functions;
}

/**
 * Get function definition
 */
function getFunctionDefinition($pdo, $dbname, $funcname) {
    $stmt = $pdo->query("SHOW CREATE FUNCTION `$dbname`.`$funcname`");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return stripDefiner($row['Create Function']);
}

/**
 * Get all triggers in a database
 */
function getTriggers($pdo, $dbname) {
    $stmt = $pdo->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = '$dbname'");
    $triggers = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $triggers[] = $row['TRIGGER_NAME'];
    }
    return $triggers;
}

/**
 * Get trigger definition
 */
function getTriggerDefinition($pdo, $dbname, $triggername) {
    $stmt = $pdo->query("SHOW CREATE TRIGGER `$dbname`.`$triggername`");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return stripDefiner($row['SQL Original Statement']);
}

/**
 * Get all events in a database
 */
function getEvents($pdo, $dbname) {
    $stmt = $pdo->query("SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA = '$dbname'");
    $events = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $events[] = $row['EVENT_NAME'];
    }
    return $events;
}

/**
 * Get event definition
 */
function getEventDefinition($pdo, $dbname, $eventname) {
    $stmt = $pdo->query("SHOW CREATE EVENT `$dbname`.`$eventname`");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return stripDefiner($row['Create Event']);
}

/**
 * Format number with commas
 */
function formatNumber($num) {
    return number_format($num);
}

/**
 * Send JSON response
 */
function sendJson($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Send error response
 */
function sendError($message) {
    sendJson(['success' => false, 'error' => $message]);
}

/**
 * Send success response
 */
function sendSuccess($data = []) {
    sendJson(array_merge(['success' => true], $data));
}

/**
 * Log error to file
 */
function logError($message, $context = []) {
    $logFile = 'D:/AdvancedVentures/logs/dbtools/error.log';
    $timestamp = date('Y-m-d H:i:s');

    $logEntry = "[$timestamp] $message\n";

    if (!empty($context)) {
        $logEntry .= "Context: " . print_r($context, true) . "\n";
    }

    $logEntry .= str_repeat('-', 80) . "\n";

    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

/**
 * Log SQL execution
 */
function logSql($database, $objectType, $objectName, $sql, $success, $error = null) {
    $logFile = 'D:/AdvancedVentures/logs/dbtools/sql-execution.log';
    $timestamp = date('Y-m-d H:i:s');

    $logEntry = "[$timestamp] " . ($success ? "SUCCESS" : "FAILED") . "\n";
    $logEntry .= "Database: $database\n";
    $logEntry .= "Object Type: $objectType\n";
    $logEntry .= "Object Name: $objectName\n";

    if (!$success && $error) {
        $logEntry .= "ERROR: $error\n";
    }

    $logEntry .= "SQL:\n" . $sql . "\n";
    $logEntry .= str_repeat('=', 80) . "\n\n";

    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

/**
 * Copy all data from TEST table to PROD table
 */
function copyTableData($testPdo, $prodPdo, $dbname, $tablename) {
    // Get row count first
    $rowCount = getTableRowCount($testPdo, $dbname, $tablename);

    if ($rowCount === 0) {
        return 0;
    }

    // Get all data from TEST
    $stmt = $testPdo->query("SELECT * FROM `$dbname`.`$tablename`");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        return 0;
    }

    // Get column names
    $columns = array_keys($rows[0]);
    $columnList = '`' . implode('`, `', $columns) . '`';

    // Prepare insert statement
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $insertSql = "INSERT INTO `$dbname`.`$tablename` ($columnList) VALUES ($placeholders)";
    $insertStmt = $prodPdo->prepare($insertSql);

    // Insert each row
    foreach ($rows as $row) {
        $insertStmt->execute(array_values($row));
    }

    return $rowCount;
}

/**
 * Get foreign key constraints for a table
 */
function getTableForeignKeys($pdo, $dbname, $tablename) {
    $sql = "SELECT
                CONSTRAINT_NAME,
                COLUMN_NAME,
                REFERENCED_TABLE_NAME,
                REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = :dbname
                AND TABLE_NAME = :tablename
                AND REFERENCED_TABLE_NAME IS NOT NULL";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['dbname' => $dbname, 'tablename' => $tablename]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Check if a table's foreign key dependencies exist in target environment
 */
function checkTableDependencies($pdo, $dbname, $tablename) {
    $foreignKeys = getTableForeignKeys($pdo, $dbname, $tablename);

    $dependencies = [
        'hasDependencies' => !empty($foreignKeys),
        'missingTables' => [],
        'existingTables' => []
    ];

    foreach ($foreignKeys as $fk) {
        $referencedTable = $fk['REFERENCED_TABLE_NAME'];

        // Check if referenced table exists in target environment
        try {
            $stmt = $pdo->query("SHOW TABLES FROM `$dbname` LIKE '$referencedTable'");
            $exists = $stmt->rowCount() > 0;

            if ($exists) {
                $dependencies['existingTables'][] = $referencedTable;
            } else {
                $dependencies['missingTables'][] = $referencedTable;
            }
        } catch (Exception $e) {
            $dependencies['missingTables'][] = $referencedTable;
        }
    }

    return $dependencies;
}

/**
 * Order tables by foreign key dependencies
 * Tables with no dependencies come first, then tables that depend on them
 */
function orderTablesByDependencies($pdo, $dbname, $tableNames) {
    $dependencies = [];
    $ordered = [];
    $processed = [];

    // Build dependency map
    foreach ($tableNames as $table) {
        $fks = getTableForeignKeys($pdo, $dbname, $table);
        $dependencies[$table] = [];

        foreach ($fks as $fk) {
            $referencedTable = $fk['REFERENCED_TABLE_NAME'];
            // Only consider dependencies within our table list
            if (in_array($referencedTable, $tableNames)) {
                $dependencies[$table][] = $referencedTable;
            }
        }
    }

    // Process tables in dependency order using topological sort
    $maxIterations = count($tableNames) * 2; // Prevent infinite loops
    $iteration = 0;

    while (count($ordered) < count($tableNames) && $iteration < $maxIterations) {
        $iteration++;
        $addedInThisPass = false;

        foreach ($tableNames as $table) {
            if (in_array($table, $processed)) {
                continue;
            }

            // Check if all dependencies are already processed
            $allDepsProcessed = true;
            foreach ($dependencies[$table] as $dep) {
                if (!in_array($dep, $processed)) {
                    $allDepsProcessed = false;
                    break;
                }
            }

            if ($allDepsProcessed) {
                $ordered[] = $table;
                $processed[] = $table;
                $addedInThisPass = true;
            }
        }

        // If we couldn't add any tables, there might be circular dependencies
        // Add remaining tables as-is
        if (!$addedInThisPass && count($ordered) < count($tableNames)) {
            foreach ($tableNames as $table) {
                if (!in_array($table, $processed)) {
                    $ordered[] = $table;
                    $processed[] = $table;
                }
            }
            break;
        }
    }

    return $ordered;
}
?>
