<?php
/**
 * Scheduled Database Backup Script
 *
 * This script is designed to be run by Windows Task Scheduler.
 * It backs up all configured databases and applies retention policy.
 *
 * TO CONFIGURE WHICH DATABASES TO BACKUP:
 * Edit config.php (lines 18-23) and add/remove database names
 *
 * Usage: C:\PHP\php.exe "D:\AdvancedVentures\htdocs\dbtools\scheduled-backup.php"
 */

// Set DOCUMENT_ROOT for CLI
if (!isset($_SERVER['DOCUMENT_ROOT'])) {
    $_SERVER['DOCUMENT_ROOT'] = 'D:/AdvancedVentures/htdocs';
}

// ONLY include baseFunctions.php - NO common.php!
require_once __DIR__ . '/common/baseFunctions.php';

// Set timezone
date_default_timezone_set('America/New_York');

// Load dependencies
require_once __DIR__ . '/backup-lib.php';

// Start output
echo "==========================================================\n";
echo "DATABASE BACKUP SCRIPT\n";
echo "Started: " . date('Y-m-d H:i:s') . "\n";
echo "==========================================================\n\n";

// Load configuration
$configFile = __DIR__ . '/backup-schedule-config.php';
if (!file_exists($configFile)) {
    echo "ERROR: Configuration file not found: $configFile\n";
    exit(1);
}

$config = require $configFile;

// Backup summary
$summary = [
    'total' => 0,
    'success' => 0,
    'failed' => 0,
    'skipped' => 0,
    'results' => []
];

// Process each database
foreach ($config['databases'] as $dbName => $dbConfig) {
    $summary['total']++;

    echo "Processing database: $dbName\n";
    echo str_repeat('-', 60) . "\n";

    // Check if enabled
    if (!$dbConfig['enabled']) {
        echo "  Status: SKIPPED (disabled in config)\n\n";
        $summary['skipped']++;
        continue;
    }

    // Create backup
    echo "  Host: {$dbConfig['host']}\n";
    echo "  Backup Directory: {$dbConfig['backupDir']}\n";

    $result = createDatabaseBackup(
        $dbConfig['host'],
        $dbConfig['user'],
        $dbConfig['password'],
        $dbName,
        $dbConfig['backupDir'],
        $dbConfig['filenamePrefix']
    );

    if ($result['success']) {
        echo "  ✓ Backup created successfully\n";
        echo "  File: {$result['filename']}\n";
        echo "  Size: {$result['fileSize']}\n";
        $summary['success']++;

        // Apply retention policy
        echo "  Applying retention policy...\n";
        $retention = applyRetentionPolicy($dbConfig['backupDir'], $dbName);

        echo "  Retention summary:\n";
        echo "    - Total backups: {$retention['summary']['total']}\n";
        echo "    - Kept: {$retention['summary']['kept']} ({$retention['summary']['daily']} daily, {$retention['summary']['monthly']} monthly, {$retention['summary']['yearly']} yearly)\n";
        echo "    - Deleted: {$retention['summary']['deleted']}\n";

        $summary['results'][] = [
            'database' => $dbName,
            'status' => 'success',
            'file' => $result['filename'],
            'size' => $result['fileSize'],
            'retention' => $retention['summary']
        ];
    } else {
        echo "  ✗ Backup FAILED\n";
        echo "  Error: {$result['error']}\n";
        $summary['failed']++;

        $summary['results'][] = [
            'database' => $dbName,
            'status' => 'failed',
            'error' => $result['error']
        ];
    }

    echo "\n";
}

// Print summary
echo "==========================================================\n";
echo "BACKUP SUMMARY\n";
echo "==========================================================\n";
echo "Total databases: {$summary['total']}\n";
echo "Successful: {$summary['success']}\n";
echo "Failed: {$summary['failed']}\n";
echo "Skipped: {$summary['skipped']}\n";
echo "\n";

// Detailed results
if ($summary['failed'] > 0) {
    echo "FAILED BACKUPS:\n";
    foreach ($summary['results'] as $result) {
        if ($result['status'] === 'failed') {
            echo "  - {$result['database']}: {$result['error']}\n";
        }
    }
    echo "\n";
}

echo "Completed: " . date('Y-m-d H:i:s') . "\n";
echo "==========================================================\n";

// Exit with appropriate code
exit($summary['failed'] > 0 ? 1 : 0);
?>
