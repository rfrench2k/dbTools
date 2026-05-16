<?php
/**
 * AJAX endpoint to compare databases between two environments
 *
 * Parameters:
 * - database: Database name to compare
 * - sourceEnv: Source environment ID (e.g., '84-local')
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
    $sourceEnvId = $_POST['sourceEnv'] ?? '';
    $targetEnvId = $_POST['targetEnv'] ?? '';

    if (empty($dbname)) {
        throw new Exception("Database name is required");
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
        throw new Exception("Cannot compare environments with different MySQL versions ({$sourceEnv['version']} vs {$targetEnv['version']})");
    }

    // Connect to both environments
    $sourcePdo = getEnvConnection($sourceEnvId);
    $targetPdo = getEnvConnection($targetEnvId);

    // Check if database exists in both environments
    $sourceDatabases = getDatabases($sourcePdo);
    $targetDatabases = getDatabases($targetPdo);

    $existsInSource = in_array($dbname, $sourceDatabases);
    $existsInTarget = in_array($dbname, $targetDatabases);

    if (!$existsInSource && !$existsInTarget) {
        throw new Exception("Database '$dbname' does not exist in either environment");
    }

    $result = [
        'database' => $dbname,
        'sourceEnv' => $sourceEnv['label'],
        'targetEnv' => $targetEnv['label'],
        'sourceEnvId' => $sourceEnvId,
        'targetEnvId' => $targetEnvId,
        'existsInSource' => $existsInSource,
        'existsInTarget' => $existsInTarget,
        'existsInBoth' => $existsInSource && $existsInTarget,
        'summary' => [
            'totalObjects' => 0,
            'missingInTarget' => 0,
            'missingInSource' => 0,
            'different' => 0,
            'identical' => 0
        ],
        'tables' => [],
        'views' => [],
        'procedures' => [],
        'functions' => [],
        'triggers' => [],
        'events' => []
    ];

    // If database doesn't exist in both, return early
    if (!$result['existsInBoth']) {
        sendSuccess($result);
        return;
    }

    // Compare Tables
    $sourceTables = $existsInSource ? getTables($sourcePdo, $dbname) : [];
    $targetTables = $existsInTarget ? getTables($targetPdo, $dbname) : [];
    $result['tables'] = compareTables($sourcePdo, $targetPdo, $dbname, $sourceTables, $targetTables, $sourceEnv['label'], $targetEnv['label']);

    // Compare Views
    $sourceViews = $existsInSource ? getViews($sourcePdo, $dbname) : [];
    $targetViews = $existsInTarget ? getViews($targetPdo, $dbname) : [];
    $result['views'] = compareObjects($sourcePdo, $targetPdo, $dbname, $sourceViews, $targetViews, 'view', $sourceEnv['label'], $targetEnv['label']);

    // Compare Stored Procedures
    $sourceProcs = $existsInSource ? getProcedures($sourcePdo, $dbname) : [];
    $targetProcs = $existsInTarget ? getProcedures($targetPdo, $dbname) : [];
    $result['procedures'] = compareObjects($sourcePdo, $targetPdo, $dbname, $sourceProcs, $targetProcs, 'procedure', $sourceEnv['label'], $targetEnv['label']);

    // Compare Functions
    $sourceFuncs = $existsInSource ? getFunctions($sourcePdo, $dbname) : [];
    $targetFuncs = $existsInTarget ? getFunctions($targetPdo, $dbname) : [];
    $result['functions'] = compareObjects($sourcePdo, $targetPdo, $dbname, $sourceFuncs, $targetFuncs, 'function', $sourceEnv['label'], $targetEnv['label']);

    // Compare Triggers
    $sourceTriggers = $existsInSource ? getTriggers($sourcePdo, $dbname) : [];
    $targetTriggers = $existsInTarget ? getTriggers($targetPdo, $dbname) : [];
    $result['triggers'] = compareObjects($sourcePdo, $targetPdo, $dbname, $sourceTriggers, $targetTriggers, 'trigger', $sourceEnv['label'], $targetEnv['label']);

    // Compare Events
    $sourceEvents = $existsInSource ? getEvents($sourcePdo, $dbname) : [];
    $targetEvents = $existsInTarget ? getEvents($targetPdo, $dbname) : [];
    $result['events'] = compareObjects($sourcePdo, $targetPdo, $dbname, $sourceEvents, $targetEvents, 'event', $sourceEnv['label'], $targetEnv['label']);

    // Calculate summary
    foreach (['tables', 'views', 'procedures', 'functions', 'triggers', 'events'] as $type) {
        $result['summary']['totalObjects'] += count($result[$type]);
        foreach ($result[$type] as $item) {
            if ($item['status'] === 'missing-in-target') {
                $result['summary']['missingInTarget']++;
            } elseif ($item['status'] === 'missing-in-source') {
                $result['summary']['missingInSource']++;
            } elseif ($item['status'] === 'different') {
                $result['summary']['different']++;
            } elseif ($item['status'] === 'identical') {
                $result['summary']['identical']++;
            }
        }
    }

    sendSuccess($result);

} catch (Exception $e) {
    sendError($e->getMessage());
}

/**
 * Compare tables between source and target environments
 */
function compareTables($sourcePdo, $targetPdo, $dbname, $sourceTables, $targetTables, $sourceLabel, $targetLabel) {
    $allTables = array_unique(array_merge($sourceTables, $targetTables));
    sort($allTables);

    $comparison = [];
    foreach ($allTables as $table) {
        $inSource = in_array($table, $sourceTables);
        $inTarget = in_array($table, $targetTables);

        $item = [
            'name' => $table,
            'type' => 'table',
            'inSource' => $inSource,
            'inTarget' => $inTarget,
            'status' => 'identical',
            'differences' => [],
            'sourceRowCount' => 0,
            'targetRowCount' => 0
        ];

        if (!$inTarget) {
            $item['status'] = 'missing-in-target';
            $item['sourceRowCount'] = getTableRowCount($sourcePdo, $dbname, $table);
        } elseif (!$inSource) {
            $item['status'] = 'missing-in-source';
            $item['targetRowCount'] = getTableRowCount($targetPdo, $dbname, $table);
        } else {
            // Both exist, compare structure
            $sourceStructure = getTableStructure($sourcePdo, $dbname, $table);
            $targetStructure = getTableStructure($targetPdo, $dbname, $table);

            $sourceIndexes = getTableIndexes($sourcePdo, $dbname, $table);
            $targetIndexes = getTableIndexes($targetPdo, $dbname, $table);

            $item['sourceStructure'] = $sourceStructure;
            $item['targetStructure'] = $targetStructure;
            $item['sourceIndexes'] = $sourceIndexes;
            $item['targetIndexes'] = $targetIndexes;
            $item['sourceRowCount'] = getTableRowCount($sourcePdo, $dbname, $table);
            $item['targetRowCount'] = getTableRowCount($targetPdo, $dbname, $table);

            // Compare columns
            $sourceColumns = array_column($sourceStructure, 'Field');
            $targetColumns = array_column($targetStructure, 'Field');

            $missingInTarget = array_diff($sourceColumns, $targetColumns);
            $missingInSource = array_diff($targetColumns, $sourceColumns);

            if (!empty($missingInTarget)) {
                $item['differences'][] = count($missingInTarget) . " column(s) missing in $targetLabel: " . implode(', ', $missingInTarget);
            }
            if (!empty($missingInSource)) {
                $item['differences'][] = count($missingInSource) . " column(s) missing in $sourceLabel: " . implode(', ', $missingInSource);
            }

            // Compare column definitions for common columns
            $commonColumns = array_intersect($sourceColumns, $targetColumns);
            foreach ($commonColumns as $col) {
                $sourceCol = array_values(array_filter($sourceStructure, fn($c) => $c['Field'] === $col))[0];
                $targetCol = array_values(array_filter($targetStructure, fn($c) => $c['Field'] === $col))[0];

                if ($sourceCol['Type'] !== $targetCol['Type']) {
                    $item['differences'][] = "Column '$col' type differs: $sourceLabel={$sourceCol['Type']}, $targetLabel={$targetCol['Type']}";
                }
                if ($sourceCol['Null'] !== $targetCol['Null']) {
                    $item['differences'][] = "Column '$col' NULL differs: $sourceLabel={$sourceCol['Null']}, $targetLabel={$targetCol['Null']}";
                }
                if ($sourceCol['Default'] !== $targetCol['Default']) {
                    $item['differences'][] = "Column '$col' default differs: $sourceLabel={$sourceCol['Default']}, $targetLabel={$targetCol['Default']}";
                }
                if ($sourceCol['Extra'] !== $targetCol['Extra']) {
                    $item['differences'][] = "Column '$col' extra differs: $sourceLabel={$sourceCol['Extra']}, $targetLabel={$targetCol['Extra']}";
                }
            }

            // Compare indexes
            $sourceIndexNames = array_unique(array_column($sourceIndexes, 'Key_name'));
            $targetIndexNames = array_unique(array_column($targetIndexes, 'Key_name'));

            $missingIndexesInTarget = array_diff($sourceIndexNames, $targetIndexNames);
            $missingIndexesInSource = array_diff($targetIndexNames, $sourceIndexNames);

            if (!empty($missingIndexesInTarget)) {
                $item['differences'][] = count($missingIndexesInTarget) . " index(es) missing in $targetLabel: " . implode(', ', $missingIndexesInTarget);
            }
            if (!empty($missingIndexesInSource)) {
                $item['differences'][] = count($missingIndexesInSource) . " index(es) missing in $sourceLabel: " . implode(', ', $missingIndexesInSource);
            }

            if (!empty($item['differences'])) {
                $item['status'] = 'different';
            }
        }

        $comparison[] = $item;
    }

    return $comparison;
}

/**
 * Compare other database objects (views, procedures, functions, triggers, events)
 */
function compareObjects($sourcePdo, $targetPdo, $dbname, $sourceObjects, $targetObjects, $type, $sourceLabel, $targetLabel) {
    $allObjects = array_unique(array_merge($sourceObjects, $targetObjects));
    sort($allObjects);

    $comparison = [];
    foreach ($allObjects as $objName) {
        $inSource = in_array($objName, $sourceObjects);
        $inTarget = in_array($objName, $targetObjects);

        $item = [
            'name' => $objName,
            'type' => $type,
            'inSource' => $inSource,
            'inTarget' => $inTarget,
            'status' => 'identical',
            'differences' => []
        ];

        if (!$inTarget) {
            $item['status'] = 'missing-in-target';
        } elseif (!$inSource) {
            $item['status'] = 'missing-in-source';
        } else {
            // Both exist, compare definitions
            try {
                $sourceDef = getObjectDefinition($sourcePdo, $dbname, $objName, $type);
                $targetDef = getObjectDefinition($targetPdo, $dbname, $objName, $type);

                $item['sourceDefinition'] = $sourceDef;
                $item['targetDefinition'] = $targetDef;

                // Normalize definitions for comparison (remove whitespace variations)
                $sourceDefNorm = preg_replace('/\s+/', ' ', trim($sourceDef));
                $targetDefNorm = preg_replace('/\s+/', ' ', trim($targetDef));

                if ($sourceDefNorm !== $targetDefNorm) {
                    $item['status'] = 'different';
                    $item['differences'][] = ucfirst($type) . " definition differs between $sourceLabel and $targetLabel";
                }
            } catch (Exception $e) {
                $item['status'] = 'error';
                $item['differences'][] = 'Error comparing: ' . $e->getMessage();
            }
        }

        $comparison[] = $item;
    }

    return $comparison;
}

/**
 * Get object definition based on type
 */
function getObjectDefinition($pdo, $dbname, $objName, $type) {
    switch ($type) {
        case 'view':
            return getViewDefinition($pdo, $dbname, $objName);
        case 'procedure':
            return getProcedureDefinition($pdo, $dbname, $objName);
        case 'function':
            return getFunctionDefinition($pdo, $dbname, $objName);
        case 'trigger':
            return getTriggerDefinition($pdo, $dbname, $objName);
        case 'event':
            return getEventDefinition($pdo, $dbname, $objName);
        default:
            throw new Exception("Unknown object type: $type");
    }
}
?>
