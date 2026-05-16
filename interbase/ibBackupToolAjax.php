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
    $action = $_REQUEST['action'] ?? '';

    switch ($action) {
        case 'list':
            ib_list_databases();
            break;

        case 'check':
            ib_check_status();
            break;

        case 'backup':
            ib_run_backup();
            break;

        case 'recent':
            ib_list_recent_backups();
            break;

        default:
            throw new InvalidArgumentException('Invalid action.');
    }
} catch (Throwable $e) {
    ib_send_error($e->getMessage());
}

function ib_list_databases(): void
{
    $databases = array_map(function ($db) {
        return [
            'id' => $db['id'] ?? '',
            'name' => $db['name'] ?? ($db['id'] ?? ''),
            'description' => $db['description'] ?? ''
        ];
    }, ib_get_databases());

    ib_send_success([
        'databases' => $databases,
        'backupDir' => ib_get_backup_directory()
    ]);
}

function ib_check_status(): void
{
    $databaseId = trim((string) ($_POST['database'] ?? ''));
    $environment = strtolower(trim((string) ($_POST['environment'] ?? 'prod')));

    if ($databaseId === '') {
        throw new InvalidArgumentException('Database id is required.');
    }

    if (!in_array($environment, ['prod', 'test'], true)) {
        throw new InvalidArgumentException('Environment must be prod or test.');
    }

    if (!ib_get_database($databaseId)) {
        throw new InvalidArgumentException('Unknown database id: ' . $databaseId);
    }

    $status = ib_run_script('ibBackupToolBackup.ps1', [
        '-DatabaseId', ib_escape_arg($databaseId),
        '-Environment', ib_escape_arg($environment),
        '-CheckOnly'
    ]);

    ib_send_success(['status' => $status]);
}

function ib_run_backup(): void
{
    $databaseId = trim((string) ($_POST['database'] ?? ''));
    $environment = strtolower(trim((string) ($_POST['environment'] ?? 'prod')));

    if ($databaseId === '') {
        throw new InvalidArgumentException('Database id is required.');
    }

    if (!in_array($environment, ['prod', 'test'], true)) {
        throw new InvalidArgumentException('Environment must be prod or test.');
    }

    if (!ib_get_database($databaseId)) {
        throw new InvalidArgumentException('Unknown database id: ' . $databaseId);
    }

    $result = ib_run_script('ibBackupToolBackup.ps1', [
        '-DatabaseId', ib_escape_arg($databaseId),
        '-Environment', ib_escape_arg($environment)
    ]);

    $result['displaySize'] = ib_format_size($result['backupSize'] ?? 0);

    ib_send_success(['result' => $result]);
}

function ib_list_recent_backups(): void
{
    $dir = ib_get_backup_directory();
    if (!$dir || !is_dir($dir)) {
        ib_send_success(['backups' => []]);
        return;
    }

    $entries = scandir($dir);
    $backups = [];

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = rtrim($dir, "\\/") . DIRECTORY_SEPARATOR . $entry;
        if (!is_file($path)) {
            continue;
        }

        if (pathinfo($entry, PATHINFO_EXTENSION) !== 'fbk') {
            continue;
        }

        $env = 'Unknown';
        if (preg_match('/-(Prod|Test)-/i', $entry, $matches)) {
            $env = strtoupper($matches[1]);
        }

        $size = filesize($path);
        $createdTs = filemtime($path);

        $backups[] = [
            'filename' => $entry,
            'filepath' => $path,
            'environment' => $env,
            'sizeBytes' => $size,
            'size' => ib_format_size($size),
            'created' => date('Y-m-d H:i:s', $createdTs),
            'createdTs' => $createdTs
        ];
    }

    usort($backups, function ($a, $b) {
        return ($b['createdTs'] <=> $a['createdTs']) ?: ($b['sizeBytes'] <=> $a['sizeBytes']);
    });

    $backups = array_map(function ($entry) {
        unset($entry['createdTs']);
        return $entry;
    }, array_slice($backups, 0, 20));

    ib_send_success(['backups' => $backups]);
}

function ib_format_size($bytes): string
{
    $bytes = (int) $bytes;
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' bytes';
}
