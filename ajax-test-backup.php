<?php
/**
 * AJAX endpoint for backup system testing
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/dbtools/common/common.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors, we'll catch them
ini_set('log_errors', 1);

header('Content-Type: application/json');

$userId = getCurrentUserId();
if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {

    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'check_system':
            checkSystem();
            break;

        case 'get_tables':
            getTablesFromDatabase();
            break;

        case 'test_backup':
            testBackup();
            break;

        case 'list_backups':
            listBackups();
            break;

        case 'delete_backup':
            deleteBackup();
            break;

        default:
            sendError("Invalid action: " . $action);
    }
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    exit;
} catch (Error $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'PHP Error: ' . $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    exit;
}

/**
 * Check system requirements and configuration
 */
function checkSystem() {
    $backupDirExists = file_exists(BACKUP_DIR);
    $backupDirWritable = false;

    if ($backupDirExists) {
        $backupDirWritable = is_writable(BACKUP_DIR);
    } else {
        // Try to create it
        $backupDirWritable = @mkdir(BACKUP_DIR, 0755, true);
        $backupDirExists = file_exists(BACKUP_DIR);
    }

    $result = [
        'backupDir' => BACKUP_DIR,
        'backupDirExists' => $backupDirExists,
        'backupDirWritable' => $backupDirWritable,
        'mysqldumpAvailable' => false,
        'mysqldumpPath' => null,
        'testConnection' => [
            'host' => DB_TEST_HOST,
            'success' => false,
            'error' => null
        ],
        'prodConnection' => [
            'host' => DB_PROD_HOST,
            'success' => false,
            'error' => null
        ]
    ];

    // Check mysqldump availability
    // Try common Windows paths
    $possiblePaths = [
        'mysqldump', // In PATH
        'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe',
        'C:\\Program Files\\MySQL\\MySQL Server 5.7\\bin\\mysqldump.exe',
        'C:\\MySQL\\bin\\mysqldump.exe',
        'C:\\xampp\\mysql\\bin\\mysqldump.exe',
        'C:\\wamp\\bin\\mysql\\mysql8.0.27\\bin\\mysqldump.exe',
    ];

    foreach ($possiblePaths as $path) {
        try {
            $output = [];
            $returnCode = 0;
            @exec('"' . $path . '" --version 2>&1', $output, $returnCode);
            if ($returnCode === 0 && !empty($output)) {
                $result['mysqldumpAvailable'] = true;
                $result['mysqldumpPath'] = $path;
                break;
            }
        } catch (Exception $e) {
            // Continue to next path
        }
    }

    // If not found, try using 'where' command on Windows
    if (!$result['mysqldumpAvailable']) {
        try {
            $whereOutput = [];
            $whereCode = 0;
            @exec('where mysqldump 2>&1', $whereOutput, $whereCode);
            if ($whereCode === 0 && !empty($whereOutput)) {
                $result['mysqldumpAvailable'] = true;
                $result['mysqldumpPath'] = trim($whereOutput[0]);
            }
        } catch (Exception $e) {
            // mysqldump not found
        }
    }

    // Test TEST connection
    try {
        $testPdo = getTestConnection();
        $result['testConnection']['success'] = true;
    } catch (Exception $e) {
        $result['testConnection']['error'] = $e->getMessage();
    }

    // Test PROD connection
    try {
        $prodPdo = getProdConnection();
        $result['prodConnection']['success'] = true;
    } catch (Exception $e) {
        $result['prodConnection']['error'] = $e->getMessage();
    }

    sendSuccess($result);
}

/**
 * Get tables from a database
 */
function getTablesFromDatabase() {
    try {
        $dbname = $_POST['database'] ?? '';
        if (empty($dbname)) {
            throw new Exception("Database name required");
        }

        $prodPdo = getProdConnection($dbname);
        $stmt = $prodPdo->query("SHOW TABLES");
        $tables = [];
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }

        sendSuccess(['tables' => $tables]);

    } catch (Exception $e) {
        sendError($e->getMessage());
    }
}

/**
 * Run a test backup
 */
function testBackup() {
    try {
        $database = $_POST['database'] ?? '';
        $backupType = $_POST['backupType'] ?? 'full';
        $table = $_POST['table'] ?? '';

        if (empty($database)) {
            throw new Exception("Database name required");
        }

        // Find mysqldump
        $mysqldump = findMysqldump();
        if (!$mysqldump) {
            throw new Exception("mysqldump not found. Please install MySQL client tools or add to PATH.");
        }

        $timestamp = date('Y-m-d-Hi');

        if ($backupType === 'single' && !empty($table)) {
            // For single table test backups, include table name
            $backupFile = BACKUP_DIR . "{$database}-Prod-{$timestamp}-{$table}.sql";
            $tableArg = escapeshellarg($table);
        } else {
            $backupFile = BACKUP_DIR . "{$database}-Prod-{$timestamp}.sql";
            $tableArg = '';
        }

        // Build mysqldump command with proper flags
        if ($backupType === 'single' && !empty($table)) {
            // Single table - no --databases flag
            $command = sprintf(
                '"%s" -h%s -u%s -p%s --routines --triggers --single-transaction --lock-tables=false %s %s > %s 2>&1',
                $mysqldump,
                DB_PROD_HOST,
                DB_PROD_USER,
                DB_PROD_PASS,
                escapeshellarg($database),
                $tableArg,
                escapeshellarg($backupFile)
            );
        } else {
            // Full database - use --databases flag
            $command = sprintf(
                '"%s" -h%s -u%s -p%s --routines --triggers --single-transaction --lock-tables=false --add-drop-database --databases %s > %s 2>&1',
                $mysqldump,
                DB_PROD_HOST,
                DB_PROD_USER,
                DB_PROD_PASS,
                escapeshellarg($database),
                escapeshellarg($backupFile)
            );
        }

        // Execute backup
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new Exception("Backup failed with return code $returnCode. Output: " . implode("\n", $output));
        }

        // Check if file was created
        if (!file_exists($backupFile)) {
            throw new Exception("Backup file was not created");
        }

        $fileSize = filesize($backupFile);
        if ($fileSize === 0) {
            throw new Exception("Backup file is empty");
        }

        // Read first 50 lines for preview
        $preview = '';
        $handle = fopen($backupFile, 'r');
        $lineCount = 0;
        while (!feof($handle) && $lineCount < 50) {
            $preview .= fgets($handle);
            $lineCount++;
        }
        fclose($handle);

        sendSuccess([
            'backupFile' => $backupFile,
            'fileSize' => formatFileSize($fileSize),
            'timestamp' => date('Y-m-d H:i:s'),
            'command' => str_replace(DB_PROD_PASS, '****', $command), // Hide password
            'preview' => $preview
        ]);

    } catch (Exception $e) {
        sendError($e->getMessage());
    }
}

/**
 * List all backup files
 */
function listBackups() {
    try {
        if (!file_exists(BACKUP_DIR)) {
            sendSuccess(['backups' => [], 'totalSize' => '0 bytes']);
            return;
        }

        $backups = [];
        $totalSize = 0;
        $files = scandir(BACKUP_DIR);

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;

            $filepath = BACKUP_DIR . $file;
            if (!is_file($filepath)) continue;

            $size = filesize($filepath);
            $totalSize += $size;

            $backups[] = [
                'filename' => $file,
                'filepath' => $filepath,
                'size' => formatFileSize($size),
                'sizeBytes' => $size,
                'created' => date('Y-m-d H:i:s', filemtime($filepath))
            ];
        }

        // Sort by created date, newest first
        usort($backups, function($a, $b) {
            return $b['sizeBytes'] <=> $a['sizeBytes'];
        });

        sendSuccess([
            'backups' => $backups,
            'totalSize' => formatFileSize($totalSize)
        ]);

    } catch (Exception $e) {
        sendError($e->getMessage());
    }
}

/**
 * Delete a backup file
 */
function deleteBackup() {
    try {
        $filename = $_POST['filename'] ?? '';
        if (empty($filename)) {
            throw new Exception("Filename required");
        }

        // Security: ensure filename doesn't contain path traversal
        if (strpos($filename, '..') !== false || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
            throw new Exception("Invalid filename");
        }

        $filepath = BACKUP_DIR . $filename;

        if (!file_exists($filepath)) {
            throw new Exception("Backup file not found");
        }

        if (!unlink($filepath)) {
            throw new Exception("Failed to delete file");
        }

        sendSuccess(['message' => 'Backup deleted successfully']);

    } catch (Exception $e) {
        sendError($e->getMessage());
    }
}

/**
 * Find mysqldump executable
 */
function findMysqldump() {
    // Use the configured path from config.php
    if (defined('MYSQLDUMP_PATH') && file_exists(MYSQLDUMP_PATH)) {
        return MYSQLDUMP_PATH;
    }

    // Fallback: try to find it
    $possiblePaths = [
        'C:\\Program Files\\MySQL\\MySQL Server 8.4\\bin\\mysqldump.exe',
        'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe',
        'C:\\Program Files\\MySQL\\MySQL Server 5.7\\bin\\mysqldump.exe',
        'mysqldump',
    ];

    foreach ($possiblePaths as $path) {
        $output = [];
        $returnCode = 0;
        @exec('"' . $path . '" --version 2>&1', $output, $returnCode);
        if ($returnCode === 0 && !empty($output)) {
            return $path;
        }
    }

    return null;
}

/**
 * Format file size
 */
function formatFileSize($bytes) {
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
?>
