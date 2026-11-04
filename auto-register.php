<?php
/**
 * Auto-register DBTOOLS in auth system
 * This runs automatically - no user interaction needed
 */

if (!isset($_SERVER['DOCUMENT_ROOT'])) {
    $_SERVER['DOCUMENT_ROOT'] = 'D:/AdvancedVentures/htdocs';
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/auth/admin/common/admin_functions.php';
require_once __DIR__ . '/config.php';

$server = dbtoolsServer();

$data = [
    'program_id' => 'DBTOOLS',
    'program_name' => 'Database Management Tools',
    'program_url' => '/dbtools/',
    'default_start_page' => '/dbtools/index.php',
    'cookie_prefix' => 'DBTOOLS',
    'cookie_duration_days' => 30,
    'db_host' => $server['host'] . ':' . $server['port'],
    'db_name' => 'auth_db',
    'db_user' => $server['user'],
    'db_password' => $server['pass'],
    'enabled' => 1
];

$result = admin_createProgram($data);

if ($result['success']) {
    echo "SUCCESS: DBTOOLS registered automatically\n";
} else {
    if (strpos($result['message'], 'already exists') !== false) {
        echo "DBTOOLS already registered - skipping\n";
    } else {
        echo "ERROR: " . $result['message'] . "\n";
        exit(1);
    }
}
?>
