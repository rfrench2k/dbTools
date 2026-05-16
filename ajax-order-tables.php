<?php
/**
 * AJAX endpoint to order tables by foreign key dependencies
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
    $tablesJson = $_POST['tables'] ?? '';

    if (empty($dbname) || empty($tablesJson)) {
        throw new Exception("Database name and tables list are required");
    }

    $tables = json_decode($tablesJson, true);

    if (!is_array($tables) || empty($tables)) {
        throw new Exception("Invalid tables list");
    }

    // Connect to TEST to analyze dependencies
    $testPdo = getTestConnection();

    // Order tables by dependencies
    $orderedTables = orderTablesByDependencies($testPdo, $dbname, $tables);

    sendSuccess([
        'orderedTables' => $orderedTables
    ]);

} catch (Exception $e) {
    sendError($e->getMessage());
}
?>
