<?php
/**
 * AJAX endpoint for backup system testing
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/dbtools/common/common.php';
require_once __DIR__ . '/backup-lib.php';   // createDatabaseBackup()
dbtoolsRequireAction();   // SUPERADMIN, from DB Tools' own pages only

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

    $server = dbtoolsServer();
    $result = [
        'backupDir' => BACKUP_DIR,
        'backupDirExists' => $backupDirExists,
        'backupDirWritable' => $backupDirWritable,
        'mysqldumpAvailable' => file_exists(MYSQLDUMP_PATH),
        'mysqldumpPath' => MYSQLDUMP_PATH,
        'connection' => [
            'host' => $server['host'] . ':' . $server['port'],
            'success' => false,
            'error' => null
        ]
    ];

    try {
        getEnvConnection(DBTOOLS_ENV);
        $result['connection']['success'] = true;
    } catch (Exception $e) {
        $result['connection']['error'] = $e->getMessage();
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

        $prodPdo = getEnvConnection(DBTOOLS_ENV, $dbname);
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

        // Single table: only that table, its name in the file name
        $single = ($backupType === 'single' && !empty($table));
        $backup = createDatabaseBackup(
            dbtoolsServer(),
            $database,
            BACKUP_DIR,
            $single ? "{$database}-Prod-{$table}" : "{$database}-Prod",
            $single ? [$table] : []
        );
        if (!$backup['success']) {
            throw new Exception($backup['error']);
        }
        $backupFile = $backup['filepath'];

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
            'fileSize' => $backup['fileSize'],
            'timestamp' => $backup['timestamp'],
            'command' => $backup['command'], // holds no password
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
                'size' => formatBackupFileSize($size),
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
            'totalSize' => formatBackupFileSize($totalSize)
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


?>
