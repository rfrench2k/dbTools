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

    $action = $_REQUEST['action'] ?? 'init';



    switch ($action) {

        case 'init':

            ib_restore_init();

            break;

        case 'backups':

            ib_restore_list_backups();

            break;

        case 'check':

            ib_restore_check();

            break;

        case 'restore':

            ib_restore_run();

            break;

        default:

            throw new InvalidArgumentException('Invalid action.');

    }

} catch (Throwable $e) {

    ib_send_error($e->getMessage());

}



function ib_restore_init(): void

{

    $databases = array_map(function ($db) {

        return [

            'id' => $db['id'] ?? '',

            'name' => $db['name'] ?? ($db['id'] ?? ''),

            'description' => $db['description'] ?? '',

            'paths' => [

                'prod' => $db['prodPath'] ?? '',

                'test' => $db['testPath'] ?? ''

            ]

        ];

    }, ib_get_databases());



    ib_send_success([

        'databases' => $databases,

        'backups' => ib_restore_collect_backups(),

        'backupDir' => ib_get_backup_directory()

    ]);

}



function ib_restore_list_backups(): void

{

    ib_send_success([

        'backups' => ib_restore_collect_backups()

    ]);

}



function ib_restore_check(): void

{

    $databaseId = trim((string) ($_POST['database'] ?? ''));

    $environment = strtolower(trim((string) ($_POST['environment'] ?? 'prod')));

    $backupName = trim((string) ($_POST['backup'] ?? ''));

    $targetPath = trim((string) ($_POST['targetPath'] ?? ''));



    if ($databaseId === '') {

        throw new InvalidArgumentException('Database id is required.');

    }



    if (!in_array($environment, ['prod', 'test'], true)) {

        throw new InvalidArgumentException('Environment must be prod or test.');

    }



    if ($backupName === '') {

        throw new InvalidArgumentException('Backup selection is required.');

    }



    if ($targetPath === '') {

        throw new InvalidArgumentException('Target path is required.');

    }



    $database = ib_get_database($databaseId);

    if (!$database) {

        throw new InvalidArgumentException('Unknown database id: ' . $databaseId);

    }



    ib_restore_validate_backup_for_database($backupName, $database);



    $backupPath = ib_restore_resolve_backup($backupName);



    $status = ib_run_script('ibRestoreToolRestore.ps1', [

        '-DatabaseId', ib_escape_arg($databaseId),

        '-Environment', ib_escape_arg($environment),

        '-BackupFile', ib_escape_arg($backupPath),

        '-TargetPath', ib_escape_arg($targetPath),

        '-CheckOnly'

    ]);



    ib_send_success(['status' => $status]);

}



function ib_restore_run(): void

{

    $databaseId = trim((string) ($_POST['database'] ?? ''));

    $environment = strtolower(trim((string) ($_POST['environment'] ?? 'prod')));

    $backupName = trim((string) ($_POST['backup'] ?? ''));

    $targetPath = trim((string) ($_POST['targetPath'] ?? ''));



    if ($databaseId === '') {

        throw new InvalidArgumentException('Database id is required.');

    }



    if (!in_array($environment, ['prod', 'test'], true)) {

        throw new InvalidArgumentException('Environment must be prod or test.');

    }



    if ($backupName === '') {

        throw new InvalidArgumentException('Backup selection is required.');

    }



    if ($targetPath === '') {

        throw new InvalidArgumentException('Target path is required.');

    }



    $database = ib_get_database($databaseId);

    if (!$database) {

        throw new InvalidArgumentException('Unknown database id: ' . $databaseId);

    }



    ib_restore_validate_backup_for_database($backupName, $database);



    $backupPath = ib_restore_resolve_backup($backupName);



    $result = ib_run_script('ibRestoreToolRestore.ps1', [

        '-DatabaseId', ib_escape_arg($databaseId),

        '-Environment', ib_escape_arg($environment),

        '-BackupFile', ib_escape_arg($backupPath),

        '-TargetPath', ib_escape_arg($targetPath)

    ]);



    ib_send_success(['result' => $result]);

}



function ib_restore_collect_backups(): array

{

    $dir = ib_get_backup_directory();

    if (!$dir || !is_dir($dir)) {

        return [];

    }



    $entries = scandir($dir);

    $lookup = ib_restore_get_database_lookup();

    $backups = [];



    foreach ($entries as $entry) {

        if ($entry === '.' || $entry === '..') {

            continue;

        }



        $path = rtrim($dir, "\/") . DIRECTORY_SEPARATOR . $entry;

        if (!is_file($path)) {

            continue;

        }



        if (strtolower(pathinfo($entry, PATHINFO_EXTENSION)) !== 'fbk') {

            continue;

        }



        $meta = ib_restore_parse_backup_metadata($entry, $lookup);

        $size = filesize($path);

        $createdTs = filemtime($path);



        $backups[] = [

            'filename' => $entry,

            'databaseId' => $meta['databaseId'],

            'database' => $meta['databaseName'],

            'environment' => $meta['environment'],

            'recognized' => $meta['databaseId'] !== null,

            'sizeBytes' => $size,

            'size' => ib_restore_format_size($size),

            'createdTs' => $createdTs,

            'created' => $createdTs ? date('Y-m-d H:i:s', $createdTs) : null

        ];

    }



    usort($backups, function ($a, $b) {

        $tsCompare = ($b['createdTs'] <=> $a['createdTs']);

        if ($tsCompare !== 0) {

            return $tsCompare;

        }

        return ($b['sizeBytes'] <=> $a['sizeBytes']);

    });



    $backups = array_slice($backups, 0, 100);

    return array_map(function ($entry) {

        unset($entry['createdTs'], $entry['sizeBytes']);

        return $entry;

    }, $backups);

}





function ib_restore_resolve_backup(string $filename): string

{

    $filename = trim($filename);

    if ($filename === '') {

        throw new InvalidArgumentException('Backup filename is required.');

    }



    $dir = ib_get_backup_directory();

    if (!$dir) {

        throw new RuntimeException('Backup directory is not configured.');

    }



    if (!is_dir($dir)) {

        throw new RuntimeException('Backup directory not found: ' . $dir);

    }



    $dirReal = realpath($dir);

    $path = rtrim($dir, "\\/") . DIRECTORY_SEPARATOR . $filename;

    $pathReal = realpath($path);



    if ($dirReal === false) {

        throw new RuntimeException('Unable to resolve backup directory path.');

    }



    if ($pathReal === false || strpos($pathReal, $dirReal) !== 0) {

        throw new InvalidArgumentException('Backup file not found: ' . $filename);

    }



    return $pathReal;

}



function ib_restore_get_database_lookup(): array

{

    static $lookup = null;

    if ($lookup !== null) {

        return $lookup;

    }



    $lookup = [];

    foreach (ib_get_databases() as $db) {

        $id = strtolower((string) ($db['id'] ?? ''));

        $name = strtolower((string) ($db['name'] ?? ''));

        if ($id !== '') {

            $lookup[$id] = $db;

        }

        if ($name !== '') {

            $lookup[$name] = $db;

        }

    }



    return $lookup;

}



function ib_restore_parse_backup_metadata(string $filename, ?array $lookup = null): array

{

    if ($lookup === null) {

        $lookup = ib_restore_get_database_lookup();

    }



    $base = pathinfo($filename, PATHINFO_FILENAME);

    $tokens = explode('-', $base);

    $prefix = strtolower($tokens[0] ?? '');



    $databaseId = null;

    $databaseName = $tokens[0] ?? $filename;

    if ($prefix !== '' && isset($lookup[$prefix])) {

        $db = $lookup[$prefix];

        $databaseId = $db['id'] ?? null;

        $databaseName = $db['name'] ?? ($db['id'] ?? $databaseName);

    }



    $environment = 'UNKNOWN';

    $envToken = $tokens[1] ?? '';

    if ($envToken !== '') {

        if (strcasecmp($envToken, 'Prod') === 0) {

            $environment = 'PROD';

        } elseif (strcasecmp($envToken, 'Test') === 0) {

            $environment = 'TEST';

        }

    } elseif (preg_match('/-(Prod|Test)-/i', $filename, $matches)) {

        $environment = strtoupper($matches[1]);

    }



    return [

        'databaseId' => $databaseId,

        'databaseName' => $databaseName,

        'environment' => $environment,

        'prefix' => $prefix,

    ];

}



function ib_restore_validate_backup_for_database(string $filename, array $database): void

{

    $metadata = ib_restore_parse_backup_metadata($filename);

    $databaseId = strtolower((string) ($database['id'] ?? ''));

    $databaseName = strtolower((string) ($database['name'] ?? $databaseId));

    $prefix = $metadata['prefix'] ?? '';



    if ($metadata['databaseId']) {

        if ($databaseId !== '' && strcasecmp($metadata['databaseId'], $databaseId) === 0) {

            return;

        }

    }



    $candidates = array_filter([$databaseId, $databaseName]);

    if ($prefix !== '') {

        foreach ($candidates as $candidate) {

            if ($candidate !== '' && $prefix === $candidate) {

                return;

            }

        }

    }



    $display = $database['name'] ?? ($database['id'] ?? $databaseId);

    throw new InvalidArgumentException(sprintf('Backup "%s" does not match database "%s".', $filename, $display));

}



function ib_restore_format_size($bytes): string

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

