<?php
/**
 * AJAX endpoint to clear log files
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
    $errorLogFile = __DIR__ . '/error.log';
    $sqlLogFile = __DIR__ . '/sql-execution.log';

    // Clear error log
    if (file_exists($errorLogFile)) {
        file_put_contents($errorLogFile, '');
    }

    // Clear SQL log
    if (file_exists($sqlLogFile)) {
        file_put_contents($sqlLogFile, '');
    }

    sendSuccess(['message' => 'Logs cleared successfully']);

} catch (Exception $e) {
    sendError($e->getMessage());
}
?>
