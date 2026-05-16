<?php
/**
 * AJAX endpoint to get environments and databases
 *
 * Modes:
 * - No params: Returns all environments grouped by MySQL version
 * - ?env=<envId>: Returns databases for a specific environment
 * - ?sourceEnv=<envId>&targetEnv=<envId>: Returns databases for comparison (present in either)
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/dbtools/common/common.php';

header('Content-Type: application/json');

// Get authenticated user from common.php
$userId = getCurrentUserId();

if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    // Access config variables - they're in scope from the include chain
    // (config.php is included via common.php -> common-dbfunctions.php -> config.php)
    $DB_ENVIRONMENTS = $GLOBALS['DB_ENVIRONMENTS'] ?? [];
    $MYSQL_VERSIONS = $GLOBALS['MYSQL_VERSIONS'] ?? [];

    // Mode 1: Get all environments (for initial dropdown population)
    if (!isset($_GET['env']) && !isset($_GET['sourceEnv'])) {
        $result = [
            'versions' => [],
            'environments' => []
        ];

        // Build versions list
        foreach ($MYSQL_VERSIONS as $verId => $verInfo) {
            $result['versions'][] = [
                'id' => $verId,
                'label' => $verInfo['label']
            ];
        }

        // Build environments list grouped by version
        foreach ($DB_ENVIRONMENTS as $envId => $env) {
            $result['environments'][] = [
                'id' => $env['id'],
                'label' => $env['label'],
                'host' => $env['host'],
                'port' => $env['port'],
                'version' => $env['version'],
                'type' => $env['type']
            ];
        }

        sendSuccess($result);
        exit;
    }

    // Mode 2: Get databases for a single environment
    if (isset($_GET['env'])) {
        $envId = $_GET['env'];
        $pdo = getEnvConnection($envId);
        $databases = getDatabases($pdo);
        sort($databases);

        $result = [];
        foreach ($databases as $dbname) {
            $result[] = [
                'name' => $dbname
            ];
        }

        sendSuccess(['databases' => $result]);
        exit;
    }

    // Mode 3: Get databases for comparison between two environments
    if (isset($_GET['sourceEnv']) && isset($_GET['targetEnv'])) {
        $sourceEnvId = $_GET['sourceEnv'];
        $targetEnvId = $_GET['targetEnv'];

        $sourcePdo = getEnvConnection($sourceEnvId);
        $targetPdo = getEnvConnection($targetEnvId);

        $sourceDatabases = getDatabases($sourcePdo);
        $targetDatabases = getDatabases($targetPdo);

        // Combine and get unique database names
        $allDatabases = array_unique(array_merge($sourceDatabases, $targetDatabases));
        sort($allDatabases);

        $sourceEnv = getEnvironment($sourceEnvId);
        $targetEnv = getEnvironment($targetEnvId);

        // Build result with status for each database
        $databases = [];
        foreach ($allDatabases as $dbname) {
            $inSource = in_array($dbname, $sourceDatabases);
            $inTarget = in_array($dbname, $targetDatabases);

            $status = 'both';
            $statusLabel = "Exists in both {$sourceEnv['label']} and {$targetEnv['label']}";
            $statusClass = 'success';

            if ($inSource && !$inTarget) {
                $status = 'source-only';
                $statusLabel = "{$sourceEnv['label']} only";
                $statusClass = 'warning';
            } elseif (!$inSource && $inTarget) {
                $status = 'target-only';
                $statusLabel = "{$targetEnv['label']} only";
                $statusClass = 'info';
            }

            $databases[] = [
                'name' => $dbname,
                'status' => $status,
                'statusLabel' => $statusLabel,
                'statusClass' => $statusClass,
                'inSource' => $inSource,
                'inTarget' => $inTarget
            ];
        }

        sendSuccess([
            'databases' => $databases,
            'sourceEnv' => $sourceEnv['label'],
            'targetEnv' => $targetEnv['label']
        ]);
        exit;
    }

} catch (Exception $e) {
    sendError($e->getMessage());
}
?>
