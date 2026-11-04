<?php
/**
 * Direct database registration for DBTOOLS
 */

require_once __DIR__ . '/common-dbfunctions.php';

try {
    $server = dbtoolsServer();
    $pdo = getEnvConnection(DBTOOLS_ENV, 'auth_db');

    // Check if exists
    $stmt = $pdo->prepare("SELECT program_id FROM programs WHERE program_id = 'DBTOOLS'");
    $stmt->execute();

    if ($stmt->fetch()) {
        echo "DBTOOLS already registered\n";
        exit(0);
    }

    // Insert
    $stmt = $pdo->prepare("
        INSERT INTO programs (
            program_id, program_name, program_url, default_start_page,
            cookie_prefix, cookie_duration_days, db_host, db_name,
            db_user, db_password, enabled, created_at, updated_at
        ) VALUES (
            'DBTOOLS', 'Database Management Tools', '/dbtools/', '/dbtools/index.php',
            'DBTOOLS', 30, ?, 'auth_db',
            ?, ?, 1, NOW(), NOW()
        )
    ");

    $stmt->execute([$server['host'] . ':' . $server['port'], $server['user'], $server['pass']]);
    echo "SUCCESS: DBTOOLS registered\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
?>
