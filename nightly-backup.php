<?php
// Nightly backup: what the "Backup - Nightly" task (D:\AdvancedVentures\BackupJob) backs up, when it last ran,
// and what is NOT backed up - and the controls to change it (ajax-nightly-backup.php edits backup.json and
// queues runs for the "Backup - On Demand" task). Reads backup.json, state\*.json and logs\*.log.
$pageTitle = 'Nightly Backup';
include $_SERVER['DOCUMENT_ROOT'] . '/dbtools/common/Header.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/auth/includes/csrf_functions.php';
$csrf = generateCSRFToken();

$jobDir = 'D:/AdvancedVentures/BackupJob';
$cfg = json_decode((string)@file_get_contents("$jobDir/backup.json"), true) ?: [];
$apps = $cfg['apps'] ?? [];
$inventory = json_decode((string)@file_get_contents("$jobDir/state/inventory.json"), true) ?: [];
$runStatus = json_decode((string)@file_get_contents("$jobDir/state/run-status.json"), true);
$queued = json_decode((string)@file_get_contents("$jobDir/state/run-request.json"), true);
if ($runStatus && !empty($runStatus['running']) && strtotime($runStatus['started']) < time() - 6 * 3600) $runStatus['running'] = false;

// Server key -> [kind, label]
$serverInfo = [];
foreach ($cfg['mysql_servers'] ?? [] as $k => $s) $serverInfo[$k] = ['mysql', ($k === 'mysql95' ? 'MySQL 9.5' : ($k === 'mysql84' ? 'MySQL 8.4' : $k)) . " (port {$s['port']})"];
foreach ($cfg['postgres_servers'] ?? [] as $k => $s) $serverInfo[$k] = ['postgres', "Postgres (port {$s['port']})"];
foreach ($cfg['qdrant_servers'] ?? [] as $k => $s) $serverInfo[$k] = ['qdrant', 'Qdrant'];

// Databases that are deliberately not in backup.json, with the reason shown on the page.
$ownBackup = ['movies' => 'Has its own backup (task "Movies - Database Backup").',
              'unraveled_req' => 'Left out on purpose (owner decision, 2026-09-27).'];

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
function nb_serverLabel($key) { global $serverInfo; return $serverInfo[$key][1] ?? $key; }

// Last nightly run: the most recent "=== backup start" at 01:3x, and what it logged.
$logs = glob("$jobDir/logs/backup-*.log") ?: [];
rsort($logs);
$nightly = null;
foreach ($logs as $log) {
    $lines = file($log, FILE_IGNORE_NEW_LINES) ?: [];
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        if (preg_match('/^(\S+ 01:3\d:\d\d) \[INFO\] === backup start/', $lines[$i], $m)) {
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

// What is backed up (kind/server/name -> app), and what exists but is not.
$backedUp = [];
foreach ($apps as $a) {
    foreach ($a['databases'] ?? [] as $d) $backedUp[($serverInfo[$d['server']][0] ?? 'mysql') . "/{$d['server']}/" . strtolower($d['db'])] = $a['name'];
    foreach ($a['collections'] ?? [] as $c) $backedUp["qdrant/{$c['server']}/" . strtolower($c['collection'])] = $a['name'];
}
$notBackedUp = [];
$uncovered = [];   // for the Add picker: kind => server => [names]
foreach (['mysql', 'postgres', 'qdrant'] as $kind) {
    foreach ($inventory[$kind] ?? [] as $server => $list) {
        if (isset($list['error'])) { $notBackedUp[] = ['kind' => $kind, 'server' => $server, 'name' => null, 'error' => $list['error']]; continue; }
        foreach ($list as $x) {
            if (isset($backedUp["$kind/$server/" . strtolower($x['name'])])) continue;
            $notBackedUp[] = ['kind' => $kind, 'server' => $server, 'name' => $x['name'], 'mb' => $x['mb'] ?? null, 'points' => $x['points'] ?? null];
            if (!isset($ownBackup[strtolower($x['name'])]) && !($server === 'mysql84')) $uncovered[$kind][$server][] = $x['name'];
        }
    }
}

$latestLog = $logs[0] ?? null;
$tail = $latestLog ? array_slice(file($latestLog, FILE_IGNORE_NEW_LINES) ?: [], -40) : [];
?>
    <style>
        .nb-app td { vertical-align: top; }
        .nb-sub { font-size: .85rem; }
        .nb-item { display: flex; align-items: flex-start; gap: .35rem; margin-bottom: .2rem; }
        .nb-x { border: 0; background: none; color: #adb5bd; padding: 0 .2rem; line-height: 1.2; }
        .nb-x:hover { color: #dc3545; }
        .nb-log { background: #1e1e1e; color: #d4d4d4; padding: 16px; border-radius: 5px; font-family: 'Courier New', monospace;
                  font-size: 13px; white-space: pre-wrap; word-wrap: break-word; max-height: 420px; overflow-y: auto; }
        .nb-log .err { color: #ff8080; }
        tr.nb-off { opacity: .55; }
    </style>

    <div class="container py-4">
        <h1 class="h3 mb-1"><i class="bi bi-cloud-check"></i> Nightly Backup</h1>
        <p class="text-muted mb-4">Every night at 1:30 AM the <strong>Backup - Nightly</strong> task copies each app's databases (MySQL and Postgres),
            Qdrant collections and user files (photos, uploads) to Google Drive. App code is not in the backup: code lives in git.</p>
        <div id="nbAlert"></div>

        <!-- Last nightly run + run now -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2 align-items-start">
                    <div class="me-auto">
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
                            <p class="mb-0 text-muted"><?= (int)$nightly['uploads'] ?> copies uploaded (anything with no changes since the night before is skipped).</p>
                            <?php foreach ($nightly['errors'] as $e): ?><div class="text-danger small"><?= nb_h($e) ?></div><?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <button class="btn btn-primary nb-run" data-app=""><i class="bi bi-play-fill"></i> Run all now</button>
                </div>
                <div id="nbRunState" class="mt-3 small">
                    <?php if ($queued): ?>
                        <span class="badge bg-info text-dark">Queued</span> <?= !empty($queued['inventory_only']) ? 'Refreshing the list of databases' : 'Run of ' . nb_h($queued['app'] ?? 'all apps') ?> - starts within a minute.
                    <?php elseif ($runStatus && !empty($runStatus['running'])): ?>
                        <span class="badge bg-primary">Running</span> <?= nb_h($runStatus['app'] ?? 'all apps') ?>, started <?= nb_when($runStatus['started']) ?>.
                    <?php elseif ($runStatus && !empty($runStatus['finished'])): ?>
                        Last run: <?= nb_h($runStatus['app'] ?? 'all apps') ?> finished <?= nb_when($runStatus['finished']) ?> -
                        <span class="<?= ($runStatus['result'] ?? '') === 'OK' ? 'text-success' : 'text-danger' ?>"><?= nb_h($runStatus['result'] ?? '') ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- How it works -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h5 class="card-title">How it works</h5>
                <ul class="mb-0">
                    <li><strong>MySQL databases:</strong> copied only when they changed since the last copy (checked in milliseconds, nothing copied otherwise).</li>
                    <li><strong>Postgres databases:</strong> dumped every night; uploaded only when the content differs from the last copy.</li>
                    <li><strong>Qdrant collections:</strong> a snapshot is uploaded when the number of points changed, and every Sunday.</li>
                    <li><strong>Folders:</strong> only new or changed files are sent. Deleted or overwritten files are kept for 30 days.</li>
                    <li><strong>Kept:</strong> the newest 30 copies of each database / collection, plus one copy per month for the last 12 months.</li>
                    <li><strong>Sundays:</strong> checks the newest copies really are on Google Drive and emails a summary.</li>
                    <li><strong>1st of each month:</strong> restores every database's newest copy into a scratch database to prove it works, then drops it.</li>
                    <li><strong>Any error:</strong> emails <?= nb_h($cfg['email']['to'] ?? 'the owner') ?>.</li>
                    <li><strong>Run now</strong> (here) starts within a minute (task <em>Backup - On Demand</em>).</li>
                </ul>
            </div>
        </div>

        <!-- What is backed up -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <div class="d-flex align-items-center mb-2">
                    <h5 class="card-title mb-0 me-auto">What is backed up (<?= count($apps) ?> apps)</h5>
                    <button class="btn btn-sm btn-success" id="nbAddApp"><i class="bi bi-plus-lg"></i> Add app</button>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm nb-app mb-0">
                        <thead class="table-light">
                            <tr><th>On</th><th>App</th><th>Databases / collections</th><th>Last copy</th><th>Files (folders)</th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($apps as $a):
                            $state = json_decode((string)@file_get_contents("$jobDir/state/{$a['name']}.json"), true) ?: [];
                            $items = [];
                            foreach ($a['databases'] ?? [] as $d) $items[] = ['kind' => $serverInfo[$d['server']][0] ?? 'mysql', 'server' => $d['server'], 'name' => $d['db'], 'key' => "db:{$d['server']}/{$d['db']}"];
                            foreach ($a['collections'] ?? [] as $c) $items[] = ['kind' => 'qdrant', 'server' => $c['server'], 'name' => $c['collection'], 'key' => "qdrant:{$c['server']}/{$c['collection']}"]; ?>
                            <tr class="<?= empty($a['enabled']) ? 'nb-off' : '' ?>">
                                <td><div class="form-check form-switch m-0"><input class="form-check-input nb-toggle" type="checkbox" data-app="<?= nb_h($a['name']) ?>" <?= !empty($a['enabled']) ? 'checked' : '' ?> title="Include in the nightly backup"></div></td>
                                <td>
                                    <strong><?= nb_h($a['name']) ?></strong>
                                    <?php if (empty($a['enabled'])): ?><span class="badge bg-warning text-dark">turned off</span><?php endif; ?>
                                    <?php if (!empty($a['what'])): ?><div class="text-muted nb-sub"><?= nb_h($a['what']) ?></div><?php endif; ?>
                                </td>
                                <td>
                                    <?php foreach ($items as $it): ?>
                                        <div class="nb-item"><span><?= nb_h($it['name']) ?> <span class="text-muted nb-sub">on <?= nb_h(nb_serverLabel($it['server'])) ?></span></span>
                                            <button class="nb-x nb-remove" title="Remove from the backup (copies already on Drive are kept)" data-app="<?= nb_h($a['name']) ?>" data-kind="<?= nb_h($it['kind']) ?>" data-server="<?= nb_h($it['server']) ?>" data-name="<?= nb_h($it['name']) ?>"><i class="bi bi-x-circle"></i></button></div>
                                    <?php endforeach; ?>
                                    <?php if (!$items): ?><span class="text-muted">none</span><?php endif; ?>
                                </td>
                                <td>
                                    <?php foreach ($items as $it):
                                        $s = $state[$it['key']] ?? null; ?>
                                        <div class="nb-item"><span><?= nb_when($s['last_upload'] ?? null) ?>
                                            <?php if ($s && isset($s['last_mb'])): ?><span class="text-muted nb-sub">, <?= nb_h($s['last_mb']) ?> MB</span><?php endif; ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </td>
                                <td>
                                    <?php foreach ($a['folders'] ?? [] as $f):
                                        $s = $state["folder:{$a['name']}/{$f['name']}"] ?? null; ?>
                                        <div class="nb-item"><div><?= nb_h($f['what'] ?? $f['name']) ?>
                                            <div class="text-muted nb-sub"><?= nb_h($f['path']) ?>, last sync <?= nb_when($s['last_sync'] ?? null) ?>
                                                <?php if (!empty($f['exclude'])): ?><br>skips <?= nb_h(implode(', ', $f['exclude'])) ?><?php endif; ?></div></div>
                                            <button class="nb-x nb-remove" title="Remove from the backup (copies already on Drive are kept)" data-app="<?= nb_h($a['name']) ?>" data-kind="folder" data-name="<?= nb_h($f['name']) ?>"><i class="bi bi-x-circle"></i></button>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (empty($a['folders'])): ?><span class="text-muted">none</span><?php endif; ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <button class="btn btn-sm btn-outline-success nb-add" data-app="<?= nb_h($a['name']) ?>" title="Add a database, collection or folder"><i class="bi bi-plus-lg"></i></button>
                                    <button class="btn btn-sm btn-outline-primary nb-run" data-app="<?= nb_h($a['name']) ?>" title="Back up this app now"><i class="bi bi-play-fill"></i> Run</button>
                                    <?php if (!$items && empty($a['folders'])): ?>
                                        <button class="btn btn-sm btn-outline-danger nb-remove-app" data-app="<?= nb_h($a['name']) ?>" title="Remove this empty app"><i class="bi bi-trash"></i></button>
                                    <?php endif; ?>
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
                <div class="d-flex align-items-center mb-2">
                    <h5 class="card-title mb-0 me-auto">Not in this backup</h5>
                    <span class="text-muted small me-2">List from <?= nb_when($inventory['updated'] ?? null) ?></span>
                    <button class="btn btn-sm btn-outline-secondary" id="nbRefresh"><i class="bi bi-arrow-clockwise"></i> Refresh list</button>
                </div>
                <?php if (!$inventory): ?>
                    <p class="mb-0 text-muted">No list yet: press Refresh list.</p>
                <?php elseif (!$notBackedUp): ?>
                    <p class="mb-0 text-success">Every database and Qdrant collection on this machine is in the backup.</p>
                <?php else: ?>
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>Database / collection</th><th>Server</th><th>Size</th><th>Why</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($notBackedUp as $n): ?>
                            <tr>
                                <?php if ($n['name'] === null): ?>
                                    <td colspan="5" class="text-danger"><?= nb_h(nb_serverLabel($n['server'])) ?>: could not read the list (<?= nb_h($n['error']) ?>)</td>
                                <?php else:
                                    $lname = strtolower($n['name']);
                                    $canAdd = true; ?>
                                    <td><?= nb_h($n['name']) ?></td>
                                    <td><?= nb_h(nb_serverLabel($n['server'])) ?></td>
                                    <td><?= $n['points'] !== null ? number_format($n['points']) . ' points' : nb_h($n['mb']) . ' MB' ?></td>
                                    <td><?php
                                        if (isset($ownBackup[$lname])) {
                                            echo nb_h($ownBackup[$lname]); $canAdd = false;
                                        } elseif ($n['server'] === 'mysql84') {
                                            echo 'Old copy left on MySQL 8.4' . (isset($backedUp["mysql/mysql95/$lname"]) ? '. The live one is on MySQL 9.5 and is backed up (' . nb_h($backedUp["mysql/mysql95/$lname"]) . ').' : '.');
                                            $canAdd = false;
                                        } else {
                                            echo '<span class="text-danger">Not backed up.</span>';
                                        }
                                    ?></td>
                                    <td class="text-end"><?php if ($canAdd): ?>
                                        <button class="btn btn-sm btn-outline-success nb-add" data-kind="<?= nb_h($n['kind']) ?>" data-server="<?= nb_h($n['server']) ?>" data-name="<?= nb_h($n['name']) ?>"><i class="bi bi-plus-lg"></i> Add to backup</button>
                                    <?php endif; ?></td>
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
<pre class="bg-light p-2 rounded small mb-3">dbBackup/&lt;App&gt;/database/&lt;db&gt;/&lt;db&gt;-YYYY-MM-DD_HHMMSS.sql.gz              one file per copy (MySQL and Postgres)
dbBackup/&lt;App&gt;/qdrant/&lt;collection&gt;/&lt;collection&gt;-YYYY-MM-DD_HHMMSS.snapshot.gz  Qdrant snapshot (gzipped)
dbBackup/&lt;App&gt;/files/&lt;folder&gt;/...                                   mirror of the folder
dbBackup/&lt;App&gt;/files-replaced/&lt;folder&gt;/&lt;date&gt;/...                   deleted/overwritten files, kept 30 days</pre>
                <p class="mb-1"><strong>Restore a MySQL database:</strong> download the <code>.sql.gz</code>, unzip it, create an empty
                    database, then load it: <code>mysql -h 127.0.0.1 -P 3307 -u sysdba &lt;database&gt; &lt; file.sql</code></p>
                <p class="mb-1"><strong>Restore a Postgres database:</strong> unzip it, <code>createdb &lt;database&gt;</code>, then
                    <code>psql -h 127.0.0.1 -U postgres -d &lt;database&gt; -f file.sql</code></p>
                <p class="mb-1"><strong>Restore a Qdrant collection:</strong> unzip the <code>.snapshot.gz</code>, then upload the <code>.snapshot</code> file:
                    <code>POST http://127.0.0.1:6333/collections/&lt;collection&gt;/snapshots/upload</code> (with the api-key header).</p>
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

    <!-- Add a database / collection / folder -->
    <div class="modal fade" id="nbAddModal" tabindex="-1">
        <div class="modal-dialog"><div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Add to the backup</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">App</label>
                    <select class="form-select" id="nbAddApp2"></select>
                    <input class="form-control mt-2 d-none" id="nbNewAppName" placeholder="New app name">
                </div>
                <div class="mb-3">
                    <label class="form-label">What</label>
                    <select class="form-select" id="nbAddKind">
                        <option value="mysql">MySQL database</option>
                        <option value="postgres">Postgres database</option>
                        <option value="qdrant">Qdrant collection</option>
                        <option value="folder">Folder of files</option>
                    </select>
                </div>
                <div id="nbAddDb" class="mb-3">
                    <label class="form-label">Name</label>
                    <select class="form-select" id="nbAddName"></select>
                    <div class="form-text">Only what is on this server and not backed up yet. Missing something new? Refresh list first.</div>
                </div>
                <div id="nbAddFolder" class="d-none">
                    <div class="mb-2"><label class="form-label">Folder on this server</label><input class="form-control" id="nbFolderPath" placeholder="D:\AdvancedVentures\htdocs\app\images"></div>
                    <div class="mb-2"><label class="form-label">Short name (folder name on Drive)</label><input class="form-control" id="nbFolderName" placeholder="images"></div>
                    <div class="mb-2"><label class="form-label">What it holds</label><input class="form-control" id="nbFolderWhat" placeholder="photos users upload"></div>
                </div>
                <div id="nbAddErr" class="text-danger"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button>
                <button class="btn btn-primary" id="nbAddSave" type="button">Add</button>
            </div>
        </div></div>
    </div>

    <script>
    const NB = {
        csrf: <?= json_encode($csrf) ?>,
        apps: <?= json_encode(array_column($apps, 'name')) ?>,
        uncovered: <?= json_encode($uncovered ?: new stdClass()) ?>,
        servers: <?= json_encode(array_map(fn($x) => $x[1], $serverInfo)) ?>,
        waiting: <?= json_encode((bool)($queued || ($runStatus['running'] ?? false))) ?>
    };
    const nbModal = new bootstrap.Modal('#nbAddModal');
    const nbEsc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    async function nbPost(action, body) {
        const r = await fetch('ajax-nightly-backup.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(Object.assign({action, csrf: NB.csrf}, body || {}))});
        let j; try { j = await r.json(); } catch (e) { throw new Error('Server error (' + r.status + ')'); }
        if (!j.ok) throw new Error(j.error || 'Failed');
        return j;
    }
    function nbFlash(msg, type) {
        $('#nbAlert').html(`<div class="alert alert-${type || 'danger'} alert-dismissible">${nbEsc(msg)}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`);
        window.scrollTo(0, 0);
    }

    function nbFillNames() {
        const kind = $('#nbAddKind').val(), pick = $('#nbAddName').data('pick');
        $('#nbAddDb').toggleClass('d-none', kind === 'folder');
        $('#nbAddFolder').toggleClass('d-none', kind !== 'folder');
        const opts = [];
        for (const [server, names] of Object.entries(NB.uncovered[kind] || {}))
            for (const n of names) opts.push(`<option value="${nbEsc(server + '|' + n)}" ${pick === server + '|' + n ? 'selected' : ''}>${nbEsc(n)} - ${nbEsc(NB.servers[server] || server)}</option>`);
        $('#nbAddName').html(opts.join('') || '<option value="">(nothing left to add)</option>');
    }
    function nbOpenAdd(app, kind, server, name) {
        $('#nbAddApp2').html(NB.apps.map(a => `<option ${a === app ? 'selected' : ''}>${nbEsc(a)}</option>`).join('') + '<option value="__new">New app...</option>');
        if (!app && name) $('#nbAddApp2').val('__new');
        $('#nbNewAppName').val(!app && name ? name : '').toggleClass('d-none', $('#nbAddApp2').val() !== '__new');
        $('#nbAddKind').val(kind || 'mysql');
        $('#nbAddName').data('pick', server && name ? server + '|' + name : '');
        $('#nbFolderPath, #nbFolderName, #nbFolderWhat').val('');
        $('#nbAddErr').text('');
        nbFillNames();
        nbModal.show();
    }

    $(document)
        .on('click', '.nb-add', e => { const b = $(e.currentTarget); nbOpenAdd(b.data('app') || '', b.data('kind'), b.data('server'), b.data('name')); })
        .on('change', '#nbAddKind', nbFillNames)
        .on('change', '#nbAddApp2', () => $('#nbNewAppName').toggleClass('d-none', $('#nbAddApp2').val() !== '__new'))
        .on('click', '#nbAddApp', () => { nbOpenAdd('', 'mysql'); $('#nbAddApp2').val('__new'); $('#nbNewAppName').removeClass('d-none').focus(); })
        .on('click', '#nbAddSave', async () => {
            $('#nbAddErr').text('');
            try {
                let app = $('#nbAddApp2').val();
                if (app === '__new') { app = $('#nbNewAppName').val().trim(); await nbPost('add_app', {name: app}); }
                const kind = $('#nbAddKind').val();
                if (kind === 'folder') await nbPost('add_item', {app, kind, path: $('#nbFolderPath').val(), name: $('#nbFolderName').val(), what: $('#nbFolderWhat').val()});
                else {
                    const [server, name] = ($('#nbAddName').val() || '|').split('|');
                    if (!name) throw new Error('Pick one');
                    await nbPost('add_item', {app, kind, server, name});
                }
                location.reload();
            } catch (x) { $('#nbAddErr').text(x.message); }
        })
        .on('click', '.nb-remove', e => {
            const b = $(e.currentTarget);
            if (!b.data('armed')) { b.data('armed', 1).html('<span class="small text-danger">remove?</span>'); return; }
            nbPost('remove_item', {app: b.data('app'), kind: b.data('kind'), server: b.data('server') || '', name: String(b.data('name'))}).then(() => location.reload()).catch(x => nbFlash(x.message));
        })
        .on('click', '.nb-remove-app', e => nbPost('remove_app', {app: $(e.currentTarget).data('app')}).then(() => location.reload()).catch(x => nbFlash(x.message)))
        .on('change', '.nb-toggle', e => nbPost('toggle_app', {app: $(e.target).data('app'), enabled: e.target.checked}).then(() => location.reload()).catch(x => nbFlash(x.message)))
        .on('click', '.nb-run', e => {
            const app = $(e.currentTarget).data('app') || null;
            nbPost('run', {app}).then(() => location.reload()).catch(x => nbFlash(x.message));
        })
        .on('click', '#nbRefresh', () => nbPost('refresh_inventory').then(() => location.reload()).catch(x => nbFlash(x.message)));

    // While a run is queued or running, check every 10 s and reload when it is done.
    if (NB.waiting) setInterval(async () => {
        try {
            const r = await (await fetch('ajax-nightly-backup.php?action=status')).json();
            if (!r.queued && !(r.run && r.run.running)) location.reload();
        } catch (e) {}
    }, 10000);
    </script>
</div><!-- container-fluid opened by common/Header.php -->
</body>
</html>
