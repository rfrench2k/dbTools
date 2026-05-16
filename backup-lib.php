<?php
/**
 * Shared Backup Library
 *
 * Common functions for database backup operations.
 * All backup tools (web interface, scheduled tasks, etc.) use this library
 * so changes are consistent across all tools.
 */

require_once __DIR__ . '/config.php';

/**
 * Create a complete database backup
 *
 * @param string $host Database host
 * @param string $user Database username
 * @param string $password Database password
 * @param string $database Database name
 * @param string $backupDir Directory to save backup
 * @param string $filenamePrefix Prefix for backup filename (e.g., "DatabaseName-Prod")
 * @return array Result with success status, filepath, filesize, etc.
 */
function createDatabaseBackup($host, $user, $password, $database, $backupDir, $filenamePrefix = null) {
    return createDatabaseBackupWithPath($host, $user, $password, $database, $backupDir, $filenamePrefix, 3306, null);
}

/**
 * Create a complete database backup with custom port and mysqldump path
 *
 * @param string $host Database host
 * @param string $user Database username
 * @param string $password Database password
 * @param string $database Database name
 * @param string $backupDir Directory to save backup
 * @param string $filenamePrefix Prefix for backup filename (e.g., "DatabaseName-Prod")
 * @param int $port Database port (default 3306)
 * @param string|null $mysqldumpPath Path to mysqldump executable (null to auto-detect)
 * @return array Result with success status, filepath, filesize, etc.
 */
function createDatabaseBackupWithPath($host, $user, $password, $database, $backupDir, $filenamePrefix = null, $port = 3306, $mysqldumpPath = null) {
    try {
        // Find mysqldump
        $mysqldump = $mysqldumpPath;
        if (!$mysqldump || !file_exists($mysqldump)) {
            $mysqldump = findMysqldumpExecutable();
        }
        if (!$mysqldump) {
            throw new Exception("mysqldump not found. Please install MySQL client tools.");
        }

        // Ensure backup directory exists
        if (!file_exists($backupDir)) {
            if (!@mkdir($backupDir, 0755, true)) {
                throw new Exception("Cannot create backup directory: $backupDir");
            }
        }

        // Create filename
        if (!$filenamePrefix) {
            $filenamePrefix = $database;
        }
        $timestamp = date('Y-m-d-Hi');
        $filename = "{$filenamePrefix}-{$timestamp}.sql";
        $filepath = rtrim($backupDir, '\\/') . DIRECTORY_SEPARATOR . $filename;

        // Build mysqldump command with ALL proper flags (matching proven working batch file)
        // Flags explained:
        // --routines: Include stored procedures and functions
        // --triggers: Include triggers
        // --single-transaction: Consistent snapshot for InnoDB without locking tables
        // --lock-tables=false: Don't lock tables (safe with single-transaction)
        // --add-drop-database: Add DROP DATABASE before CREATE DATABASE
        // --databases: Include CREATE DATABASE statement
        // --port: Specify the port (important for multi-version MySQL)
        // --set-gtid-purged=OFF: Exclude GTID info (avoids conflicts when importing to different server)
        // Note: Using 2>nul to discard warnings (prevents them from corrupting SQL file)
        $command = sprintf(
            '"%s" -h%s -P%d -u%s -p%s --routines --triggers --single-transaction --lock-tables=false --add-drop-database --set-gtid-purged=OFF --databases %s > %s 2>nul',
            $mysqldump,
            $host,
            $port,
            $user,
            $password,
            escapeshellarg($database),
            escapeshellarg($filepath)
        );

        // Execute backup
        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            $errorMsg = implode("\n", $output);
            throw new Exception("Backup command failed (exit code $returnCode): $errorMsg");
        }

        // Verify file was created
        if (!file_exists($filepath)) {
            throw new Exception("Backup file was not created");
        }

        $fileSize = filesize($filepath);
        if ($fileSize === 0) {
            throw new Exception("Backup file is empty");
        }

        // Success!
        return [
            'success' => true,
            'database' => $database,
            'filename' => $filename,
            'filepath' => $filepath,
            'fileSize' => formatBackupFileSize($fileSize),
            'fileSizeBytes' => $fileSize,
            'timestamp' => date('Y-m-d H:i:s'),
            'command' => str_replace($password, '****', $command) // Hide password
        ];

    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'database' => $database
        ];
    }
}

/**
 * Find mysqldump executable
 *
 * @return string|null Path to mysqldump or null if not found
 */
function findMysqldumpExecutable() {
    // Use configured path from config.php
    if (defined('MYSQLDUMP_PATH') && file_exists(MYSQLDUMP_PATH)) {
        return MYSQLDUMP_PATH;
    }

    // Fallback: try to find it (check both MySQL versions)
    $possiblePaths = [
        'C:\\Program Files\\MySQL\\MySQL Server 9.5\\bin\\mysqldump.exe',
        'C:\\Program Files\\MySQL\\MySQL Server 8.4\\bin\\mysqldump.exe',
        'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe',
        'C:\\Program Files\\MySQL\\MySQL Server 5.7\\bin\\mysqldump.exe',
        'mysqldump',
    ];

    foreach ($possiblePaths as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }

    // Last resort: try to execute mysqldump from PATH
    $output = [];
    $returnCode = 0;
    @exec('mysqldump --version 2>&1', $output, $returnCode);
    if ($returnCode === 0 && !empty($output)) {
        return 'mysqldump';
    }

    return null;
}

/**
 * Format file size for display
 *
 * @param int $bytes File size in bytes
 * @return string Formatted file size
 */
function formatBackupFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}

/**
 * Apply retention policy to backups
 *
 * GFS (Grandfather-Father-Son) backup retention:
 * - Keep 30 daily backups (last 30 days)
 * - Keep 12 monthly backups (one per month for last 12 months)
 * - Keep yearly backups (one per year indefinitely)
 *
 * @param string $backupDir Directory containing backups
 * @param string $databaseName Database name to filter backups
 * @return array List of files deleted
 */
function applyRetentionPolicy($backupDir, $databaseName) {
    if (!file_exists($backupDir)) {
        return ['deleted' => [], 'kept' => []];
    }

    $files = scandir($backupDir);
    $backups = [];

    // Parse all backup files for this database
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        if (pathinfo($file, PATHINFO_EXTENSION) !== 'sql') continue;

        // Match pattern: DatabaseName-Prod-YYYY-MM-DD-HHMM.sql
        if (preg_match('/^' . preg_quote($databaseName, '/') . '-Prod-(\d{4})-(\d{2})-(\d{2})-(\d{4})\.sql$/', $file, $matches)) {
            $year = $matches[1];
            $month = $matches[2];
            $day = $matches[3];
            $time = $matches[4];

            $filepath = $backupDir . DIRECTORY_SEPARATOR . $file;
            $timestamp = strtotime("$year-$month-$day");

            $backups[] = [
                'file' => $file,
                'filepath' => $filepath,
                'timestamp' => $timestamp,
                'year' => $year,
                'month' => $month,
                'day' => $day,
                'yearmonth' => "$year-$month",
                'date' => "$year-$month-$day"
            ];
        }
    }

    // Sort by timestamp descending (newest first)
    usort($backups, function($a, $b) {
        return $b['timestamp'] - $a['timestamp'];
    });

    $now = time();
    $keep = [];
    $delete = [];

    // Track which periods we've kept
    $keptDays = [];
    $keptMonths = [];
    $keptYears = [];

    foreach ($backups as $backup) {
        $age = ($now - $backup['timestamp']) / 86400; // days
        $keepReason = null;

        // Daily backups: Keep last 30 days
        if ($age <= 30) {
            if (!isset($keptDays[$backup['date']])) {
                $keepReason = 'daily';
                $keptDays[$backup['date']] = true;
            }
        }

        // Monthly backups: Keep one per month for last 12 months
        elseif ($age <= 365) {
            if (!isset($keptMonths[$backup['yearmonth']])) {
                $keepReason = 'monthly';
                $keptMonths[$backup['yearmonth']] = true;
            }
        }

        // Yearly backups: Keep one per year indefinitely
        else {
            if (!isset($keptYears[$backup['year']])) {
                $keepReason = 'yearly';
                $keptYears[$backup['year']] = true;
            }
        }

        if ($keepReason) {
            $keep[] = array_merge($backup, ['reason' => $keepReason]);
        } else {
            $delete[] = $backup;
            // Actually delete the file
            @unlink($backup['filepath']);
        }
    }

    return [
        'kept' => $keep,
        'deleted' => $delete,
        'summary' => [
            'total' => count($backups),
            'kept' => count($keep),
            'deleted' => count($delete),
            'daily' => count($keptDays),
            'monthly' => count($keptMonths),
            'yearly' => count($keptYears)
        ]
    ];
}
?>
