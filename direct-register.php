<?php
/**
 * Direct database registration for DBTOOLS
 */

require_once __DIR__ . '/config.php';

try {
    $pdo = new PDO('mysql:host=' . DB_TEST_HOST . ';dbname=auth_db', DB_TEST_USER, DB_TEST_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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

    $stmt->execute([DB_TEST_HOST, DB_TEST_USER, DB_TEST_PASS]);
    echo "SUCCESS: DBTOOLS registered\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
?>
