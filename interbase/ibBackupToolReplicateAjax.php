<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/dbtools/common/common.php';
require_once __DIR__ . '/ibCommon.php';

header('Content-Type: application/json');

$userId = getCurrentUserId();
if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    switch ($action) {
        case 'list':
            $databases = array_map(function ($db) {
                return [
                    'id' => $db['id'] ?? '',
                    'name' => $db['name'] ?? ($db['id'] ?? ''),
                    'description' => $db['description'] ?? '',
                ];
            }, ib_get_databases());
            ib_send_success(['databases' => $databases]);
            break;

        case 'check':
            $databaseId = trim((string)($_POST['database'] ?? ''));
            if ($databaseId === '') {
                throw new InvalidArgumentException('Database id is required.');
            }
            if (!ib_get_database($databaseId)) {
                throw new InvalidArgumentException('Unknown database id: ' . $databaseId);
            }
            $status = ib_run_refresh($databaseId, true);
            ib_send_success($status);
            break;

        case 'replicate':
            $databaseId = trim((string)($_POST['database'] ?? ''));
            if ($databaseId === '') {
                throw new InvalidArgumentException('Database id is required.');
            }
            if (!ib_get_database($databaseId)) {
                throw new InvalidArgumentException('Unknown database id: ' . $databaseId);
            }
            $result = ib_run_refresh($databaseId, false);
            ib_send_success($result);
            break;

        default:
            throw new InvalidArgumentException('Invalid action.');
    }
} catch (Throwable $e) {
    ib_send_error($e->getMessage());
}
?>
