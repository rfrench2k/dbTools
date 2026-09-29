<?php
// Nightly Backup management for nightly-backup.php: change backup.json (apps, databases, folders,
// Qdrant collections, on/off) and ask for a run. The run itself is done by the "Backup - On Demand"
// task (SYSTEM), which picks up state\run-request.json within a minute - this page never runs
// rclone or holds any database password. SUPERADMIN only.
include_once $_SERVER['DOCUMENT_ROOT'] . '/auth/includes/auth_functions.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/auth/includes/csrf_functions.php';
$user = auth_checkProgramAccess('DBTOOLS', 'SUPERADMIN');

header('Content-Type: application/json');
const NB_JOB = 'D:/AdvancedVentures/BackupJob';
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $_GET['action'] ?? ($in['action'] ?? '');

function nb_out($d) { echo json_encode($d, JSON_UNESCAPED_SLASHES); exit; }
function nb_bad($msg) { http_response_code(400); nb_out(['ok' => false, 'error' => $msg]); }
function nb_read($f, $def = null) { $j = json_decode((string)@file_get_contents($f), true); return is_array($j) ? $j : $def; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $action !== 'status') nb_bad('POST required');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCSRFToken($in['csrf'] ?? '')) nb_bad('Session expired - reload the page');
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

/** Change backup.json under a lock; $fn gets the config and returns it (or throws a message). */
function nb_update(callable $fn) {
    $fp = fopen(NB_JOB . '/state/backup-json.lock', 'c');
    flock($fp, LOCK_EX);
    try {
        $raw = file_get_contents(NB_JOB . '/backup.json');
        $cfg = json_decode($raw, true);
        if (!is_array($cfg)) throw new RuntimeException('backup.json is not valid JSON - fix it by hand first');
        $cfg = $fn($cfg);
        $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        file_put_contents(NB_JOB . '/backup.json.tmp', $json);
        rename(NB_JOB . '/backup.json.tmp', NB_JOB . '/backup.json');
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function &nb_app(array &$cfg, $name) {
    foreach ($cfg['apps'] as &$a) if (strcasecmp($a['name'], $name) === 0) return $a;
    throw new RuntimeException("No app named $name");
}

/** Which app already backs up this database / collection, or null. */
function nb_coveredBy(array $cfg, $kind, $server, $name) {
    foreach ($cfg['apps'] as $a) {
        $list = $kind === 'qdrant' ? ($a['collections'] ?? []) : ($a['databases'] ?? []);
        foreach ($list as $x) if ($x['server'] === $server && strcasecmp($kind === 'qdrant' ? $x['collection'] : $x['db'], $name) === 0) return $a['name'];
    }
    return null;
}

/** Is this name on that server according to the last inventory the backup job wrote? */
function nb_inInventory($kind, $server, $name) {
    $inv = nb_read(NB_JOB . '/state/inventory.json', []);
    foreach ($inv[$kind][$server] ?? [] as $x) if (is_array($x) && isset($x['name']) && $x['name'] === $name) return true;
    return false;
}

try {
    switch ($action) {
        case 'status':
            $run = nb_read(NB_JOB . '/state/run-status.json');
            if ($run && !empty($run['running']) && strtotime($run['started']) < time() - 6 * 3600) $run['running'] = false;   // stale
            nb_out(['ok' => true, 'run' => $run, 'queued' => nb_read(NB_JOB . '/state/run-request.json')]);

        case 'toggle_app':
            nb_update(function ($cfg) use ($in) { $a = &nb_app($cfg, $in['app'] ?? ''); $a['enabled'] = !empty($in['enabled']); return $cfg; });
            nb_out(['ok' => true]);

        case 'add_app':
            $name = trim($in['name'] ?? '');
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._-]{0,39}$/', $name)) nb_bad('App name: letters, numbers, spaces, . _ - (max 40)');
            nb_update(function ($cfg) use ($name, $in) {
                foreach ($cfg['apps'] as $a) if (strcasecmp($a['name'], $name) === 0) throw new RuntimeException("There is already an app named {$a['name']}");
                $cfg['apps'][] = ['name' => $name, 'what' => trim($in['what'] ?? ''), 'enabled' => true, 'databases' => [], 'folders' => []];
                return $cfg;
            });
            nb_out(['ok' => true]);

        case 'remove_app':
            nb_update(function ($cfg) use ($in) {
                nb_app($cfg, $in['app'] ?? '');   // exists?
                $cfg['apps'] = array_values(array_filter($cfg['apps'], fn($a) => strcasecmp($a['name'], $in['app']) !== 0));
                return $cfg;
            });
            nb_out(['ok' => true]);

        case 'add_item':
            $kind = $in['kind'] ?? '';
            nb_update(function ($cfg) use ($in, $kind) {
                $a = &nb_app($cfg, $in['app'] ?? '');
                if ($kind === 'folder') {
                    $path = rtrim(trim($in['path'] ?? ''), '\\/');
                    $fname = trim($in['name'] ?? '');
                    if (!preg_match('/^[A-Za-z]:\\\\/', $path . '\\') || !is_dir($path)) throw new RuntimeException("Folder not found: $path");
                    if (!preg_match('/^[A-Za-z0-9._-]{1,40}$/', $fname)) throw new RuntimeException('Folder name: letters, numbers, . _ - (it becomes the folder name on Drive)');
                    foreach ($a['folders'] ?? [] as $f) if (strcasecmp($f['name'], $fname) === 0) throw new RuntimeException("{$a['name']} already has a folder named $fname");
                    $row = ['name' => $fname, 'path' => $path];
                    if (trim($in['what'] ?? '') !== '') $row['what'] = trim($in['what']);
                    $a['folders'][] = $row;
                    return $cfg;
                }
                $server = $in['server'] ?? '';
                $name = $in['name'] ?? '';
                $map = ['mysql' => 'mysql_servers', 'postgres' => 'postgres_servers', 'qdrant' => 'qdrant_servers'][$kind] ?? null;
                if (!$map || !isset($cfg[$map][$server])) throw new RuntimeException('Unknown server');
                if (!nb_inInventory($kind, $server, $name)) throw new RuntimeException("$name is not on $server (press Refresh list if it is new)");
                if ($by = nb_coveredBy($cfg, $kind, $server, $name)) throw new RuntimeException("$name is already backed up by $by");
                if ($kind === 'qdrant') $a['collections'][] = ['server' => $server, 'collection' => $name];
                else $a['databases'][] = ['server' => $server, 'db' => $name];
                return $cfg;
            });
            nb_out(['ok' => true]);

        case 'remove_item':
            nb_update(function ($cfg) use ($in) {
                $a = &nb_app($cfg, $in['app'] ?? '');
                $kind = $in['kind'] ?? '';
                $list = $kind === 'folder' ? 'folders' : ($kind === 'qdrant' ? 'collections' : 'databases');
                $before = count($a[$list] ?? []);
                $a[$list] = array_values(array_filter($a[$list] ?? [], function ($x) use ($kind, $in) {
                    if ($kind === 'folder') return $x['name'] !== ($in['name'] ?? '');
                    return !($x['server'] === ($in['server'] ?? '') && ($kind === 'qdrant' ? $x['collection'] : $x['db']) === ($in['name'] ?? ''));
                }));
                if (count($a[$list]) === $before) throw new RuntimeException('Not found');
                if ($kind === 'qdrant' && !$a[$list]) unset($a[$list]);
                return $cfg;
            });
            nb_out(['ok' => true]);

        case 'run':
            if (is_file(NB_JOB . '/state/run-request.json')) nb_bad('A run is already waiting to start');
            $app = $in['app'] ?? null;
            if ($app !== null) { $cfg = nb_read(NB_JOB . '/backup.json', []); nb_app($cfg, $app); }
            file_put_contents(NB_JOB . '/state/run-request.json', json_encode(['app' => $app, 'test_restore' => !empty($in['test_restore']),
                'at' => date('c'), 'by' => $user['email'] ?? '']));
            nb_out(['ok' => true]);

        case 'refresh_inventory':
            if (is_file(NB_JOB . '/state/run-request.json')) nb_bad('A run is already waiting to start');
            file_put_contents(NB_JOB . '/state/run-request.json', json_encode(['inventory_only' => true, 'at' => date('c')]));
            nb_out(['ok' => true]);

        default:
            nb_bad('Unknown action');
    }
} catch (RuntimeException $e) {
    nb_bad($e->getMessage());
}
