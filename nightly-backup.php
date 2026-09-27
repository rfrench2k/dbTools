<?php
// Nightly backup: what the "Backup - Nightly" task (D:\AdvancedVentures\BackupJob) backs up, when it
// last ran, and what is NOT backed up. Read-only: reads backup.json, state\*.json and logs\*.log.
$pageTitle = 'Nightly Backup';
include $_SERVER['DOCUMENT_ROOT'] . '/dbtools/common/Header.php';

$jobDir = 'D:/AdvancedVentures/BackupJob';
$cfg = json_decode((string)@file_get_contents("$jobDir/backup.json"), true) ?: [];
$apps = $cfg['apps'] ?? [];
$servers = $cfg['mysql_servers'] ?? [];

// Databases that are deliberately not in backup.json, with the reason shown on the page.
$ownBackup = ['movies' => 'Has its own backup (task "Movies - Database Backup").'];

function nb_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function nb_when($iso) {
    if (!$iso) return '<span class="text-muted">never</span>';
    $t = strtotime($iso);
    return nb_h(date('M j, Y g:i a', $t)) . ' <span class="text-muted small">(' . nb_h(nb_ago($t)) . ')</span>';
}
function nb_ago($t) {
    $d = time() - $t;
    if ($d < 3600) return max(1, (int)($d / 60)) . ' min ago';
    if ($d < 172800) return (int)($d / 3600) . ' h ago';
    return (int)($d / 86400) . ' days ago';
}
function nb_serverLabel($servers, $key) {
    $port = $servers[$key]['port'] ?? '?';
    return ($key === 'mysql95' ? 'MySQL 9.5' : ($key === 'mysql84' ? 'MySQL 8.4' : $key)) . " (port $port)";
}

// Last nightly run: the most recent "=== backup start" at 01:3x, and what it logged.
$logs = glob("$jobDir/logs/backup-*.log") ?: [];
rsort($logs);
$nightly = null;
foreach ($logs as $log) {
    $lines = file($log, FILE_IGNORE_NEW_LINES) ?: [];
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        if (preg_match('/^(\S+ 01:3\d:\d\d) \[INFO\] === backup start$/', $lines[$i], $m)) {
            $run = ['start' => $m[1], 'end' => null, 'result' => null, 'errors' => [], 'uploads' => 0];
            for ($j = $i + 1; $j < count($lines); $j++) {
                if (preg_match('/^(\S+ \S+) \[INFO\] === backup end: (.*)$/', $lines[$j], $e)) {
                    $run['end'] = $e[1];
                    $run['result'] = $e[2];
                    break;
                }
                if (preg_match('/\[(ERROR|WARN\w*)\]/', $lines[$j])) $run['errors'][] = $lines[$j];
                if (strpos($lines[$j], ': uploaded ') !== false) $run['uploads']++;
            }
            $nightly = $run;
            break 2;
        }
    }
}

// Databases on each server, to show what is NOT backed up.
$backedUp = [];
foreach ($apps as $a) {
    foreach ($a['databases'] ?? [] as $d) $backedUp[$d['server'] . '/' . strtolower($d['db'])] = $a['name'];
}
$notBackedUp = [];
foreach ($servers as $key => $s) {
    try {
        $c = @new mysqli('127.0.0.1', getenv($s['user_env']), getenv($s['pass_env']), '', (int)$s['port']);
        $res = $c->query("SELECT s.schema_name, ROUND(IFNULL(SUM(t.data_length + t.index_length), 0) / 1048576) mb
                          FROM information_schema.schemata s
                          LEFT JOIN information_schema.tables t ON t.table_schema = s.schema_name
                          WHERE s.schema_name NOT IN ('mysql', 'sys', 'performance_schema', 'information_schema')
                          GROUP BY s.schema_name ORDER BY s.schema_name");
        while ($r = $res->fetch_row()) {
            if (!isset($backedUp[$key . '/' . strtolower($r[0])])) {
                $notBackedUp[] = ['server' => $key, 'db' => $r[0], 'mb' => $r[1]];
            }
        }
        $c->close();
    } catch (Throwable $e) {
        $notBackedUp[] = ['server' => $key, 'db' => null, 'mb' => null, 'error' => $e->getMessage()];
    }
}

$latestLog = $logs[0] ?? null;
$tail = $latestLog ? array_slice(file($latestLog, FILE_IGNORE_NEW_LINES) ?: [], -40) : [];
?>
    <style>
        .nb-app td { vertical-align: top; }
        .nb-sub { font-size: .85rem; }
        .nb-log { background: #1e1e1e; color: #d4d4d4; padding: 16px; border-radius: 5px; font-family: 'Courier New', monospace;
                  font-size: 13px; white-space: pre-wrap; word-wrap: break-word; max-height: 420px; overflow-y: auto; }
        .nb-log .err { color: #ff8080; }
    </style>

    <div class="container py-4">
        <h1 class="h3 mb-1"><i class="bi bi-cloud-check"></i> Nightly Backup</h1>
        <p class="text-muted mb-4">Every night at 1:30 AM the <strong>Backup - Nightly</strong> task copies each app's databases and
            user files (photos, uploads) to Google Drive. App code is not in the backup: code lives in git.</p>

        <!-- Last nightly run -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h5 class="card-title">Last nightly run</h5>
                <?php if (!$nightly): ?>
                    <p class="mb-0 text-danger">No nightly run found in the logs.</p>
                <?php else:
                    $ok = $nightly['result'] === 'OK' && !$nightly['errors']; ?>
                    <p class="mb-1">
                        <span class="badge <?= $ok ? 'bg-success' : 'bg-danger' ?> fs-6"><?= $ok ? 'OK' : nb_h($nightly['result'] ?: 'did not finish') ?></span>
                        &nbsp;<?= nb_when(str_replace(' ', 'T', $nightly['start'])) ?>
                        <?php if ($nightly['end']): ?><span class="text-muted">, took <?= max(1, (int)round((strtotime($nightly['end']) - strtotime($nightly['start'])) / 60)) ?> min</span><?php endif; ?>
                    </p>
                    <p class="mb-0 text-muted"><?= (int)$nightly['uploads'] ?> database copies uploaded (databases with no changes since the night before are skipped).</p>
                    <?php foreach ($nightly['errors'] as $e): ?><div class="text-danger small"><?= nb_h($e) ?></div><?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- How it works -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h5 class="card-title">How it works</h5>
                <ul class="mb-0">
                    <li><strong>Databases:</strong> copied only when they changed since the last copy (checked in milliseconds, nothing copied otherwise).</li>
                    <li><strong>Folders:</strong> only new or changed files are sent. Deleted or overwritten files are kept for 30 days.</li>
                    <li><strong>Kept:</strong> the newest 30 copies of each database, plus one copy per month for the last 12 months.</li>
                    <li><strong>Sundays:</strong> checks the newest copies really are on Google Drive and emails a summary.</li>
                    <li><strong>1st of each month:</strong> restores every database's newest copy into a scratch database to prove it works, then drops it.</li>
                    <li><strong>Any error:</strong> emails <?= nb_h($cfg['email']['to'] ?? 'the owner') ?>.</li>
                </ul>
            </div>
        </div>

        <!-- What is backed up -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h5 class="card-title">What is backed up (<?= count($apps) ?> apps)</h5>
                <div class="table-responsive">
                    <table class="table table-sm nb-app mb-0">
                        <thead class="table-light">
                            <tr><th>App</th><th>Database</th><th>Last copy</th><th>Files (folders)</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($apps as $a):
                            $state = json_decode((string)@file_get_contents("$jobDir/state/{$a['name']}.json"), true) ?: []; ?>
                            <tr>
                                <td>
                                    <strong><?= nb_h($a['name']) ?></strong>
                                    <?php if (empty($a['enabled'])): ?><span class="badge bg-warning text-dark">turned off</span><?php endif; ?>
                                    <?php if (!empty($a['what'])): ?><div class="text-muted nb-sub"><?= nb_h($a['what']) ?></div><?php endif; ?>
                                </td>
                                <td>
                                    <?php foreach ($a['databases'] ?? [] as $d): ?>
                                        <div><?= nb_h($d['db']) ?> <span class="text-muted nb-sub">on <?= nb_h(nb_serverLabel($servers, $d['server'])) ?></span></div>
                                    <?php endforeach; ?>
                                    <?php if (empty($a['databases'])): ?><span class="text-muted">none</span><?php endif; ?>
                                </td>
                                <td>
                                    <?php foreach ($a['databases'] ?? [] as $d):
                                        $s = $state["db:{$d['server']}/{$d['db']}"] ?? null; ?>
                                        <div><?= nb_when($s['last_upload'] ?? null) ?>
                                            <?php if ($s && isset($s['last_mb'])): ?><span class="text-muted nb-sub">, <?= nb_h($s['last_mb']) ?> MB</span><?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </td>
                                <td>
                                    <?php foreach ($a['folders'] ?? [] as $f):
                                        $s = $state["folder:{$a['name']}/{$f['name']}"] ?? null; ?>
                                        <div><?= nb_h($f['what'] ?? $f['name']) ?>
                                            <div class="text-muted nb-sub"><?= nb_h($f['path']) ?>, last sync <?= nb_when($s['last_sync'] ?? null) ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (empty($a['folders'])): ?><span class="text-muted">none</span><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- What is NOT backed up -->
        <div class="card shadow-sm mb-4 border-warning">
            <div class="card-body">
                <h5 class="card-title">Not in this backup</h5>
                <?php if (!$notBackedUp): ?>
                    <p class="mb-0 text-success">Every database on this machine is in the backup.</p>
                <?php else: ?>
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>Database</th><th>Server</th><th>Size</th><th>Why</th></tr></thead>
                        <tbody>
                        <?php foreach ($notBackedUp as $n): ?>
                            <tr>
                                <?php if ($n['db'] === null): ?>
                                    <td colspan="4" class="text-danger"><?= nb_h(nb_serverLabel($servers, $n['server'])) ?>: could not read the database list (<?= nb_h($n['error']) ?>)</td>
                                <?php else: ?>
                                    <td><?= nb_h($n['db']) ?></td>
                                    <td><?= nb_h(nb_serverLabel($servers, $n['server'])) ?></td>
                                    <td><?= nb_h($n['mb']) ?> MB</td>
                                    <td><?php
                                        $db = strtolower($n['db']);
                                        if (isset($ownBackup[$db])) {
                                            echo nb_h($ownBackup[$db]);
                                        } elseif ($n['server'] !== 'mysql95' && isset($backedUp['mysql95/' . $db])) {
                                            echo 'Old copy left on ' . nb_h(nb_serverLabel($servers, $n['server'])) . '. The live one is on MySQL 9.5 and is backed up ('
                                                . nb_h($backedUp['mysql95/' . $db]) . ').';
                                        } else {
                                            echo '<span class="text-danger">Not backed up. Add it to backup.json if it holds real data.</span>';
                                        }
                                    ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Where + restore -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h5 class="card-title">Where the copies are, and how to restore</h5>
                <p class="mb-2">Google Drive, folder <code>dbBackup</code> (rclone remote <code><?= nb_h($cfg['rclone']['remote'] ?? 'dbBackup:dbBackup') ?></code>):</p>
<pre class="bg-light p-2 rounded small mb-3">dbBackup/&lt;App&gt;/database/&lt;db&gt;/&lt;db&gt;-YYYY-MM-DD_HHMMSS.sql.gz   one file per copy
dbBackup/&lt;App&gt;/files/&lt;folder&gt;/...                        mirror of the folder
dbBackup/&lt;App&gt;/files-replaced/&lt;folder&gt;/&lt;date&gt;/...        deleted/overwritten files, kept 30 days</pre>
                <p class="mb-1"><strong>Restore a database:</strong> download the <code>.sql.gz</code> from that folder, unzip it, create an empty
                    database, then load it: <code>mysql -h 127.0.0.1 -P 3307 -u sysdba &lt;database&gt; &lt; file.sql</code></p>
                <p class="mb-0"><strong>Restore files:</strong> copy them back from <code>dbBackup/&lt;App&gt;/files/&lt;folder&gt;</code>.
                    Full instructions: <code>D:\AdvancedVentures\BackupJob\README.md</code>.</p>
            </div>
        </div>

        <!-- Latest log -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h5 class="card-title">Latest log <?php if ($latestLog): ?><span class="text-muted small">(<?= nb_h(basename($latestLog)) ?>, last 40 lines)</span><?php endif; ?></h5>
                <div class="nb-log"><?php foreach ($tail as $l): ?><div class="<?= preg_match('/\[(ERROR|WARN\w*)\]/', $l) ? 'err' : '' ?>"><?= nb_h($l) ?></div><?php endforeach; ?></div>
            </div>
        </div>
    </div>
</div><!-- container-fluid opened by common/Header.php -->
</body>
</html>
