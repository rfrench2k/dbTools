<?php
// Nightly Backup management for nightly-backup.php: add / change / delete the backup's settings, servers, apps and
// items (backup_* tables in the tools database), and ask for a run (backup_queue; the "Backup - On Demand" task runs
// it within a minute). This page never runs rclone or holds a database password. SUPERADMIN only.
include_once $_SERVER['DOCUMENT_ROOT'] . '/auth/includes/auth_functions.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/auth/includes/csrf_functions.php';
$user = auth_checkProgramAccess('DBTOOLS', 'SUPERADMIN');
require __DIR__ . '/nightly-backup-db.php';

header('Content-Type: application/json');
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $_GET['action'] ?? ($in['action'] ?? '');

function nb_out($d) { echo json_encode($d, JSON_UNESCAPED_SLASHES); exit; }
function nb_bad($msg) { http_response_code(400); nb_out(['ok' => false, 'error' => $msg]); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $action !== 'status') nb_bad('POST required');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCSRFToken($in['csrf'] ?? '')) nb_bad('Session expired - reload the page');
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

function nb_appId($name) {
    $id = nb_q('SELECT id FROM backup_apps WHERE name = ?', [$name])->fetchColumn();
    if (!$id) throw new RuntimeException("No app named $name");
    return (int)$id;
}
function nb_int($v, $min, $max, $what) {
    $v = trim((string)$v);
    if (!preg_match('/^\d+$/', $v) || (int)$v < $min || (int)$v > $max) throw new RuntimeException("$what: a number from $min to $max");
    return (int)$v;
}
function nb_envName($x) {
    if (!preg_match('/^[A-Z][A-Z0-9_]{1,59}$/', (string)$x)) throw new RuntimeException('Environment variable names: upper-case letters, numbers, _');
    return $x;
}
function nb_excludes($itemId, $text) {
    $list = array_values(array_unique(array_filter(array_map('trim', explode(',', (string)$text)), 'strlen')));
    foreach ($list as $x) if (!preg_match('/^[A-Za-z0-9*?._ -]{1,100}$/', $x)) throw new RuntimeException("Skip pattern not allowed: $x");
    nb_q('DELETE FROM backup_item_excludes WHERE item_id = ?', [$itemId]);
    foreach ($list as $x) nb_q('INSERT INTO backup_item_excludes (item_id, pattern) VALUES (?, ?)', [$itemId, $x]);
}

try {
    switch ($action) {
        case 'status':
            $running = nb_q("SELECT * FROM backup_runs WHERE result = 'running' AND started_at > NOW() - INTERVAL 6 HOUR ORDER BY id DESC LIMIT 1")->fetch();
            nb_out(['ok' => true, 'running' => (bool)$running, 'queued' => (int)nb_q('SELECT COUNT(*) FROM backup_queue')->fetchColumn()]);

        // ---- settings ----
        case 'save_setting':
            $name = $in['name'] ?? '';
            $v = trim((string)($in['value'] ?? ''));
            switch ($name) {
                case 'schedule_time': if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) throw new RuntimeException('Time must be HH:MM (24-hour)'); break;
                case 'weekly_day': $v = nb_int($v, 0, 6, 'Day'); break;
                case 'restore_test_day': $v = nb_int($v, 1, 28, 'Day of month'); break;
                case 'keep_versions': $v = nb_int($v, 1, 365, 'Copies'); break;
                case 'keep_monthly': $v = nb_int($v, 0, 120, 'Months'); break;
                case 'keep_deleted_files_days': $v = nb_int($v, 1, 365, 'Days'); break;
                case 'email_to': if (!filter_var($v, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Not an email address'); break;
                case 'email_summary': if (!in_array($v, ['errors', 'weekly', 'daily'], true)) throw new RuntimeException('Pick one'); break;
                case 'rclone_remote': if (!preg_match('/^[A-Za-z0-9_-]+:[A-Za-z0-9 _\/.-]*$/', $v)) throw new RuntimeException('Format: remote:folder (e.g. dbBackup:dbBackup)'); break;
                default: throw new RuntimeException('Unknown setting');
            }
            nb_q('INSERT INTO backup_settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$name, (string)$v]);
            nb_out(['ok' => true]);

        // ---- servers ----
        case 'save_server':
            $kind = $in['kind'] ?? '';
            $id = trim($in['id'] ?? '');
            $f = $in['fields'] ?? [];
            if (!in_array($kind, ['mysql', 'postgres', 'qdrant'], true)) throw new RuntimeException('Unknown server type');
            if (!preg_match('/^[a-z][a-z0-9_]{1,19}$/', $id)) throw new RuntimeException('Server id: lower-case letters, numbers, _ (e.g. mysql95)');
            if ($kind === 'qdrant') {
                if (!preg_match('#^https?://[A-Za-z0-9.-]+(:\d+)?/?$#', $f['url'] ?? '')) throw new RuntimeException('URL like http://127.0.0.1:6333');
                $row = [null, rtrim($f['url'], '/'), null, null, null, nb_envName($f['key_env'] ?? '')];
            } else {
                $port = nb_int($f['port'] ?? '', 1, 65535, 'Port');
                $bin = rtrim(trim($f['bin'] ?? ''), '\\/');
                $exe = $kind === 'mysql' ? 'mysqldump.exe' : 'pg_dump.exe';
                if (!is_file("$bin\\$exe")) throw new RuntimeException("No $exe in $bin");
                $row = [$port, null, $bin, nb_envName($f['user_env'] ?? ''), nb_envName($f['pass_env'] ?? ''), null];
            }
            $note = trim($f['note'] ?? '') ?: null;
            if (!empty($in['existing'])) {
                if (!nb_q('SELECT 1 FROM backup_servers WHERE id = ? AND kind = ?', [$id, $kind])->fetchColumn()) throw new RuntimeException("No server $id");
                nb_q('UPDATE backup_servers SET port = ?, url = ?, bin = ?, user_env = ?, pass_env = ?, key_env = ?, note = ? WHERE id = ?', array_merge($row, [$note, $id]));
            } else {
                if (nb_q('SELECT 1 FROM backup_servers WHERE id = ?', [$id])->fetchColumn()) throw new RuntimeException("There is already a server $id");
                nb_q('INSERT INTO backup_servers (id, kind, port, url, bin, user_env, pass_env, key_env, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', array_merge([$id, $kind], $row, [$note]));
            }
            nb_out(['ok' => true]);

        case 'delete_server':
            $id = $in['id'] ?? '';
            if ($a = nb_q('SELECT a.name FROM backup_items i JOIN backup_apps a ON a.id = i.app_id WHERE i.server_id = ? LIMIT 1', [$id])->fetchColumn())
                throw new RuntimeException("$a still backs up something on $id - remove that first");
            nb_q('DELETE FROM backup_servers WHERE id = ?', [$id]);
            nb_out(['ok' => true]);

        // ---- apps ----
        case 'add_app':
            $name = trim($in['name'] ?? '');
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._-]{0,39}$/', $name)) throw new RuntimeException('App name: letters, numbers, spaces, . _ - (max 40)');
            if (nb_q('SELECT 1 FROM backup_apps WHERE name = ?', [$name])->fetchColumn()) throw new RuntimeException("There is already an app named $name");
            nb_q('INSERT INTO backup_apps (name, what, enabled) VALUES (?, ?, 1)', [$name, trim($in['what'] ?? '') ?: null]);
            nb_out(['ok' => true]);

        case 'update_app':
            // Description and own retention. The name is not changed here: it is the app's folder on Drive.
            $id = nb_appId($in['app'] ?? '');
            $kv = trim((string)($in['keep_versions'] ?? ''));
            $km = trim((string)($in['keep_monthly'] ?? ''));
            if ($kv === '' && $km === '') { $kv = null; $km = null; }
            else { $kv = nb_int($kv, 1, 365, 'Copies kept'); $km = nb_int($km, 0, 120, 'Monthly copies kept'); }
            nb_q('UPDATE backup_apps SET what = ?, keep_versions = ?, keep_monthly = ? WHERE id = ?', [trim($in['what'] ?? '') ?: null, $kv, $km, $id]);
            nb_out(['ok' => true]);

        case 'toggle_app':
            nb_q('UPDATE backup_apps SET enabled = ? WHERE id = ?', [(int)!empty($in['enabled']), nb_appId($in['app'] ?? '')]);
            nb_out(['ok' => true]);

        case 'delete_app':
            $id = nb_appId($in['app'] ?? '');
            if (nb_q('SELECT COUNT(*) FROM backup_items WHERE app_id = ?', [$id])->fetchColumn()) throw new RuntimeException('Remove its databases and folders first');
            nb_q('DELETE FROM backup_apps WHERE id = ?', [$id]);
            nb_out(['ok' => true]);

        // ---- items ----
        case 'add_item':
            $appId = nb_appId($in['app'] ?? '');
            $kind = $in['kind'] ?? '';
            if ($kind === 'folder') {
                $path = rtrim(trim($in['path'] ?? ''), '\\/');
                $name = trim($in['name'] ?? '');
                if (!preg_match('/^[A-Za-z]:\\\\/', $path . '\\') || !is_dir($path)) throw new RuntimeException("Folder not found: $path");
                if (!preg_match('/^[A-Za-z0-9._-]{1,40}$/', $name)) throw new RuntimeException('Short name: letters, numbers, . _ - (it becomes the folder name on Drive)');
                if (nb_q("SELECT 1 FROM backup_items WHERE app_id = ? AND kind = 'folder' AND name = ?", [$appId, $name])->fetchColumn()) throw new RuntimeException("This app already has a folder named $name");
                nb_q("INSERT INTO backup_items (app_id, kind, name, path, what) VALUES (?, 'folder', ?, ?, ?)", [$appId, $name, $path, trim($in['what'] ?? '') ?: null]);
                nb_excludes((int)nb_db()->lastInsertId(), $in['exclude'] ?? '');
            } else {
                if (!in_array($kind, ['database', 'collection'], true)) throw new RuntimeException('Unknown kind');
                $server = $in['server'] ?? '';
                $name = $in['name'] ?? '';
                if (!nb_q('SELECT 1 FROM backup_inventory WHERE server_id = ? AND name = ?', [$server, $name])->fetchColumn()) throw new RuntimeException("$name is not on $server (press Refresh list if it is new)");
                if ($by = nb_q('SELECT a.name FROM backup_items i JOIN backup_apps a ON a.id = i.app_id WHERE i.server_id = ? AND i.name = ?', [$server, $name])->fetchColumn())
                    throw new RuntimeException("$name is already backed up by $by");
                nb_q('INSERT INTO backup_items (app_id, kind, server_id, name) VALUES (?, ?, ?, ?)', [$appId, $kind, $server, $name]);
            }
            nb_out(['ok' => true]);

        case 'update_folder':
            $id = (int)($in['id'] ?? 0);
            $path = rtrim(trim($in['path'] ?? ''), '\\/');
            if (!nb_q("SELECT 1 FROM backup_items WHERE id = ? AND kind = 'folder'", [$id])->fetchColumn()) throw new RuntimeException('Folder not found');
            if (!preg_match('/^[A-Za-z]:\\\\/', $path . '\\') || !is_dir($path)) throw new RuntimeException("Folder not found: $path");
            nb_q('UPDATE backup_items SET path = ?, what = ? WHERE id = ?', [$path, trim($in['what'] ?? '') ?: null, $id]);
            nb_excludes($id, $in['exclude'] ?? '');
            nb_out(['ok' => true]);

        case 'delete_item':
            nb_q('DELETE FROM backup_items WHERE id = ?', [(int)($in['id'] ?? 0)]);   // copies already on Drive are kept
            nb_out(['ok' => true]);

        // ---- exclusions (left out on purpose) ----
        case 'save_exclusion':
            $reason = trim($in['reason'] ?? '');
            if ($reason === '') throw new RuntimeException('Say why it is left out');
            nb_q('INSERT INTO backup_exclusions (server_id, name, reason) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE reason = VALUES(reason)', [$in['server'] ?? '', $in['name'] ?? '', $reason]);
            nb_out(['ok' => true]);

        case 'delete_exclusion':
            nb_q('DELETE FROM backup_exclusions WHERE server_id = ? AND name = ?', [$in['server'] ?? '', $in['name'] ?? '']);
            nb_out(['ok' => true]);

        // ---- runs ----
        case 'run':
            if (nb_q('SELECT COUNT(*) FROM backup_queue')->fetchColumn()) throw new RuntimeException('A run is already waiting to start');
            $appId = !empty($in['app']) ? nb_appId($in['app']) : null;
            nb_q('INSERT INTO backup_queue (app_id, test_restore, requested_by) VALUES (?, ?, ?)', [$appId, (int)!empty($in['test_restore']), $user['email'] ?? null]);
            nb_out(['ok' => true]);

        case 'refresh_inventory':
            if (nb_q('SELECT COUNT(*) FROM backup_queue')->fetchColumn()) throw new RuntimeException('A run is already waiting to start');
            nb_q('INSERT INTO backup_queue (inventory_only, requested_by) VALUES (1, ?)', [$user['email'] ?? null]);
            nb_out(['ok' => true]);

        default:
            nb_bad('Unknown action');
    }
} catch (RuntimeException $e) {
    nb_bad($e->getMessage());
} catch (PDOException $e) {
    nb_bad('Database error: ' . $e->getMessage());
}
