<?php
/**
 * DBTOOLS Program Registration Script
 * Run this once to register DBTOOLS in the auth system
 *
 * Usage: php register-program.php
 */

// Set DOCUMENT_ROOT for CLI
if (!isset($_SERVER['DOCUMENT_ROOT'])) {
    $_SERVER['DOCUMENT_ROOT'] = 'D:/AdvancedVentures/htdocs';
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/auth/includes/auth_functions.php';
require_once __DIR__ . '/config.php';

echo "==========================================================\n";
echo "DBTOOLS Program Registration\n";
echo "==========================================================\n\n";

try {
    // Get auth database connection
    $auth_db = auth_getAuthDatabase();

    // Check if DBTOOLS already exists
    $stmt = $auth_db->prepare("SELECT program_id FROM programs WHERE program_id = 'DBTOOLS'");
    $stmt->execute();
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        echo "DBTOOLS program already registered.\n";
        echo "Skipping registration.\n\n";
    } else {
        echo "Registering DBTOOLS program...\n";

        // Insert DBTOOLS program
        $stmt = $auth_db->prepare("
            INSERT INTO programs (
                program_id,
                program_name,
                program_url,
                default_start_page,
                cookie_prefix,
                cookie_duration_days,
                db_host,
                db_name,
                db_user,
                db_password,
                enabled,
                created_at,
                updated_at
            ) VALUES (
                'DBTOOLS',
                'Database Management Tools',
                '/dbtools/',
                '/dbtools/index.php',
                'DBTOOLS',
                30,
                ?,
                'auth_db',
                ?,
                ?,
                1,
                NOW(),
                NOW()
            )
        ");

        $stmt->execute([DB_TEST_HOST, DB_TEST_USER, DB_TEST_PASS]);
        echo "SUCCESS: DBTOOLS program registered!\n\n";
    }

    // Check if current user has SUPERADMIN permission for DBTOOLS
    // This assumes you're running as a logged-in user, but in CLI we can't check session
    echo "IMPORTANT: Make sure to grant SUPERADMIN permission for DBTOOLS\n";
    echo "Visit: /auth/admin/permissions.php (on your auth server)\n";
    echo "Or run the grant-permission.php script\n\n";

    echo "Registration complete!\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
?>
