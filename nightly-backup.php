<?php
// Nightly Backup (D:\AdvancedVentures\BackupJob\backup.php): its settings, servers, apps and what each backs up -
// every one a row in the tools database (backup_* tables) that can be added, changed or removed here through
// ajax-nightly-backup.php - plus the run history, what is NOT backed up, and Run now.
$pageTitle = 'Nightly Backup';
include $_SERVER['DOCUMENT_ROOT'] . '/dbtools/common/Header.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/auth/includes/csrf_functions.php';
require __DIR__ . '/nightly-backup-db.php';
$csrf = generateCSRFToken();

function nb_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function nb_when($d) {
    if (!$d) return '<span class="text-muted">never</span>';
    $t = strtotime($d);
    $ago = time() - $t;
    $rel = $ago < 3600 ? max(1, (int)($ago / 60)) . ' min ago' : ($ago < 172800 ? (int)($ago / 3600) . ' h ago' : (int)($ago / 86400) . ' days ago');
    return nb_h(date('M j, Y g:i a', $t)) . ' <span class="text-muted small">(' . $rel . ')</span>';
}
function nb_ord($n) { return $n . (in_array($n % 100, [11, 12, 13]) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th')); }
/** An error text without the long Google API URL rclone puts in it. */
function nb_errText($t) { return mb_strimwidth(preg_replace('#"?(https?://|d/drive/v3/)\S+#', '...', (string)$t), 0, 300, '...'); }

try {
    $set = nb_q('SELECT name, value FROM backup_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    $servers = nb_q('SELECT * FROM backup_servers ORDER BY kind, id')->fetchAll();
    $apps = nb_q('SELECT * FROM backup_apps ORDER BY id')->fetchAll();
    $items = nb_q('SELECT i.*, s.kind AS server_kind, st.last_upload, st.last_mb, st.last_sync
                   FROM backup_items i LEFT JOIN backup_servers s ON s.id = i.server_id LEFT JOIN backup_item_state st ON st.item_id = i.id
                   ORDER BY i.kind, i.name')->fetchAll();
    $excludes = [];
    foreach (nb_q('SELECT item_id, pattern FROM backup_item_excludes ORDER BY pattern')->fetchAll() as $x) $excludes[$x['item_id']][] = $x['pattern'];
    $lastNightly = nb_q("SELECT * FROM backup_runs WHERE kind = 'nightly' ORDER BY id DESC LIMIT 1")->fetch();
    $nightlyErrors = $lastNightly ? nb_q("SELECT * FROM backup_run_items WHERE run_id = ? AND outcome = 'error'", [$lastNightly['id']])->fetchAll() : [];
    $running = nb_q("SELECT * FROM backup_runs WHERE result = 'running' AND started_at > NOW() - INTERVAL 6 HOUR ORDER BY id DESC LIMIT 1")->fetch();
    $queued = nb_q('SELECT q.*, a.name AS app_name FROM backup_queue q LEFT JOIN backup_apps a ON a.id = q.app_id ORDER BY q.id LIMIT 1')->fetch();
    $runs = nb_q('SELECT * FROM backup_runs ORDER BY id DESC LIMIT 15')->fetchAll();
    $inventory = nb_q('SELECT * FROM backup_inventory ORDER BY server_id, name')->fetchAll();
    $invErrors = nb_q('SELECT * FROM backup_inventory_errors')->fetchAll();
    $invUpdated = nb_q('SELECT MAX(updated_at) FROM backup_inventory')->fetchColumn();
    $exclusions = nb_q('SELECT * FROM backup_exclusions ORDER BY server_id, name')->fetchAll();
} catch (PDOException $e) {
    echo '<div class="container py-4"><div class="alert alert-danger">Could not read the tools database: ' . nb_h($e->getMessage()) . '</div></div></div></body></html>';
    exit;
}

$days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$summaryText = ['errors' => 'Only when something fails', 'weekly' => 'Errors, plus a summary on the weekly check day', 'daily' => 'Every night (OK or ERROR)'];
$kindLabel = ['mysql' => 'MySQL', 'postgres' => 'Postgres', 'qdrant' => 'Qdrant'];
$serverById = array_column($servers, null, 'id');
$serverLabel = fn($id) => isset($serverById[$id]) ? $kindLabel[$serverById[$id]['kind']] . (isset($serverById[$id]['port']) ? ' :' . $serverById[$id]['port'] : '') . " ($id)" : $id;
$shown = function ($name, $v) use ($days, $summaryText) {
    switch ($name) {
        case 'schedule_time': return date('g:i A', strtotime($v));
        case 'weekly_day': return $days[(int)$v] ?? $v;
        case 'restore_test_day': return nb_ord((int)$v) . ' of each month';
        case 'email_summary': return $summaryText[$v] ?? $v;
        default: return $v;
    }
};
$itemsByApp = [];
foreach ($items as $i) $itemsByApp[$i['app_id']][] = $i;
$serverUse = [];
foreach ($items as $i) if ($i['server_id']) $serverUse[$i['server_id']] = ($serverUse[$i['server_id']] ?? 0) + 1;
$covered = [];
foreach ($items as $i) if ($i['server_id']) $covered[$i['server_id'] . '/' . strtolower($i['name'])] = true;
$excluded = [];
foreach ($exclusions as $x) $excluded[$x['server_id'] . '/' . strtolower($x['name'])] = $x;
$notBackedUp = array_values(array_filter($inventory, fn($x) => !isset($covered[$x['server_id'] . '/' . strtolower($x['name'])]) && !isset($excluded[$x['server_id'] . '/' . strtolower($x['name'])])));
$nextRun = strtotime(date('Y-m-d') . ' ' . ($set['schedule_time'] ?? '01:30'));
if ($nextRun <= time()) $nextRun = strtotime('+1 day', $nextRun);
// For the Add picker: what exists and is not backed up (and not left out on purpose), per kind.
$pick = ['database' => [], 'collection' => []];
foreach ($notBackedUp as $x) {
    $k = ($serverById[$x['server_id']]['kind'] ?? '') === 'qdrant' ? 'collection' : 'database';
    $pick[$k][$x['server_id'] . '|' . $x['name']] = $x['name'] . ' - ' . $serverLabel($x['server_id']);
}
?>
    <style>
        .nb-tbl td { vertical-align: top; }
        .nb-sub { font-size: .85rem; }
        .nb-item { display: flex; align-items: flex-start; gap: .35rem; margin-bottom: .2rem; }
        .nb-x { border: 0; background: none; color: #adb5bd; padding: 0 .2rem; line-height: 1.2; }
        .nb-x:hover { color: #0d6efd; }
        .nb-x.nb-del:hover { color: #dc3545; }
        .nb-err { color: #dc3545; font-size: .85rem; overflow-wrap: anywhere; }
        tr.nb-off { opacity: .55; }
    </style>

    <div class="container py-4">
        <h1 class="h3 mb-3"><i class="bi bi-cloud-check"></i> Nightly Backup</h1>
        <div id="nbAlert"></div>

        <!-- Status -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <div class="d-flex flex-wrap gap-4 align-items-start">
                    <div>
                        <div class="text-muted small">Last nightly run</div>
                        <?php if (!$lastNightly): ?>
                            <span class="text-muted">none yet</span>
                        <?php else: $ok = $lastNightly['result'] === 'OK'; ?>
                            <span class="badge <?= $ok ? 'bg-success' : ($lastNightly['result'] === 'running' ? 'bg-primary' : 'bg-danger') ?>"><?= nb_h($lastNightly['result'] ?: 'did not finish') ?></span>
                            <?= nb_when($lastNightly['started_at']) ?>
                            <?php if ($lastNightly['ended_at']): ?><span class="text-muted small">took <?= max(1, (int)round((strtotime($lastNightly['ended_at']) - strtotime($lastNightly['started_at'])) / 60)) ?> min</span><?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="text-muted small">Next nightly run</div>
                        <?= nb_h(date('D M j, g:i A', $nextRun)) ?>
                    </div>
                    <div class="ms-auto"><button class="btn btn-primary nb-run" data-app=""><i class="bi bi-play-fill"></i> Run all now</button></div>
                </div>
                <?php foreach ($nightlyErrors as $e): ?><div class="nb-err mt-2"><?= nb_h($e['app_name'] . ' / ' . $e['item'] . ': ' . nb_errText($e['detail'])) ?></div><?php endforeach; ?>
                <?php if ($queued): ?>
                    <div class="mt-2 small"><span class="badge bg-info text-dark">Queued</span> <?= $queued['inventory_only'] ? 'Refreshing the list of databases' : 'Run of ' . nb_h($queued['app_name'] ?? 'all apps') ?> - starts within a minute.</div>
                <?php elseif ($running): ?>
                    <div class="mt-2 small"><span class="badge bg-primary">Running</span> <?= nb_h($running['app_name'] ?? 'all apps') ?>, started <?= nb_when($running['started_at']) ?>.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Settings -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h5 class="card-title">Settings</h5>
                <table class="table table-sm nb-tbl mb-0"><tbody>
                <?php foreach (nb_settingDefs() as $name => [$label, $type, $help]): $v = $set[$name] ?? ''; ?>
                    <tr>
                        <td style="width:28%"><strong><?= nb_h($label) ?></strong></td>
                        <td><?= nb_h($v === '' ? '(not set)' : $shown($name, $v)) ?><?php if ($help): ?><div class="text-muted nb-sub"><?= nb_h($help) ?></div><?php endif; ?></td>
                        <td class="text-end"><button class="btn btn-sm btn-outline-secondary nb-edit-setting" data-name="<?= nb_h($name) ?>" data-label="<?= nb_h($label) ?>" data-type="<?= nb_h($type) ?>" data-value="<?= nb_h($v) ?>" title="Change"><i class="bi bi-pencil"></i></button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table>
            </div>
        </div>

        <!-- Servers -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <div class="d-flex align-items-center mb-2">
                    <h5 class="card-title mb-0 me-auto">Servers</h5>
                    <button class="btn btn-sm btn-success nb-server"><i class="bi bi-plus-lg"></i> Add server</button>
                </div>
                <table class="table table-sm nb-tbl mb-0">
                    <thead class="table-light"><tr><th>Id</th><th>Type</th><th>Where</th><th>Programs folder</th><th>Login (environment variables)</th><th>Used by</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($servers as $s): ?>
                        <tr>
                            <td><strong><?= nb_h($s['id']) ?></strong><?php if ($s['note']): ?><div class="text-muted nb-sub"><?= nb_h($s['note']) ?></div><?php endif; ?></td>
                            <td><?= nb_h($kindLabel[$s['kind']]) ?></td>
                            <td><?= nb_h($s['url'] ?? ('127.0.0.1:' . $s['port'])) ?></td>
                            <td class="nb-sub"><?= nb_h($s['bin']) ?></td>
                            <td class="nb-sub"><?= nb_h($s['kind'] === 'qdrant' ? $s['key_env'] : $s['user_env'] . ' / ' . $s['pass_env']) ?></td>
                            <td><?= (int)($serverUse[$s['id']] ?? 0) ?></td>
                            <td class="text-end text-nowrap">
                                <button class="btn btn-sm btn-outline-secondary nb-server" data-row="<?= nb_h(json_encode($s)) ?>" title="Change"><i class="bi bi-pencil"></i></button>
                                <?php if (empty($serverUse[$s['id']])): ?><button class="btn btn-sm btn-outline-danger nb-del" data-action="delete_server" data-args="<?= nb_h(json_encode(['id' => $s['id']])) ?>" title="Delete"><i class="bi bi-trash"></i></button><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Apps -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <div class="d-flex align-items-center mb-2">
                    <h5 class="card-title mb-0 me-auto">Apps (<?= count($apps) ?>)</h5>
                    <button class="btn btn-sm btn-success" id="nbAddApp"><i class="bi bi-plus-lg"></i> Add app</button>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm nb-tbl mb-0">
                        <thead class="table-light"><tr><th>On</th><th>App</th><th>Databases / collections</th><th>Folders</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($apps as $a):
                            $mine = $itemsByApp[$a['id']] ?? [];
                            $dbs = array_filter($mine, fn($i) => $i['kind'] !== 'folder');
                            $folders = array_filter($mine, fn($i) => $i['kind'] === 'folder'); ?>
                            <tr class="<?= $a['enabled'] ? '' : 'nb-off' ?>">
                                <td><div class="form-check form-switch m-0"><input class="form-check-input nb-toggle" type="checkbox" data-app="<?= nb_h($a['name']) ?>" <?= $a['enabled'] ? 'checked' : '' ?> title="Include in the nightly backup"></div></td>
                                <td>
                                    <strong><?= nb_h($a['name']) ?></strong>
                                    <?php if ($a['what']): ?><div class="text-muted nb-sub"><?= nb_h($a['what']) ?></div><?php endif; ?>
                                    <?php if ($a['keep_versions'] !== null): ?><div class="text-muted nb-sub">keeps <?= (int)$a['keep_versions'] ?> copies + <?= (int)$a['keep_monthly'] ?> monthly</div><?php endif; ?>
                                </td>
                                <td>
                                    <?php foreach ($dbs as $i): ?>
                                        <div class="nb-item"><div><?= nb_h($i['name']) ?> <span class="text-muted nb-sub">on <?= nb_h($serverLabel($i['server_id'])) ?></span>
                                            <div class="text-muted nb-sub">last copy <?= nb_when($i['last_upload']) ?><?= $i['last_mb'] !== null ? ', ' . nb_h($i['last_mb']) . ' MB' : '' ?></div></div>
                                            <button class="nb-x nb-del" data-action="delete_item" data-args="<?= nb_h(json_encode(['id' => (int)$i['id']])) ?>" title="Remove from the backup (copies already on Drive are kept)"><i class="bi bi-x-circle"></i></button>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (!$dbs): ?><span class="text-muted">none</span><?php endif; ?>
                                </td>
                                <td>
                                    <?php foreach ($folders as $i): $ex = $excludes[$i['id']] ?? []; ?>
                                        <div class="nb-item"><div><?= nb_h($i['name']) ?><?= $i['what'] ? ' <span class="text-muted nb-sub">- ' . nb_h($i['what']) . '</span>' : '' ?>
                                            <div class="text-muted nb-sub"><?= nb_h($i['path']) ?>, last sync <?= nb_when($i['last_sync']) ?><?php if ($ex): ?><br>skips <?= nb_h(implode(', ', $ex)) ?><?php endif; ?></div></div>
                                            <button class="nb-x nb-edit-folder" title="Change" data-row="<?= nb_h(json_encode(['id' => (int)$i['id'], 'name' => $i['name'], 'app' => $a['name'], 'path' => $i['path'], 'what' => $i['what'], 'exclude' => implode(', ', $ex)])) ?>"><i class="bi bi-pencil"></i></button>
                                            <button class="nb-x nb-del" data-action="delete_item" data-args="<?= nb_h(json_encode(['id' => (int)$i['id']])) ?>" title="Remove from the backup (copies already on Drive are kept)"><i class="bi bi-x-circle"></i></button>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (!$folders): ?><span class="text-muted">none</span><?php endif; ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <button class="btn btn-sm btn-outline-secondary nb-edit-app" data-row="<?= nb_h(json_encode(['app' => $a['name'], 'what' => $a['what'], 'kv' => $a['keep_versions'], 'km' => $a['keep_monthly']])) ?>" title="Change"><i class="bi bi-pencil"></i></button>
                                    <button class="btn btn-sm btn-outline-success nb-add" data-app="<?= nb_h($a['name']) ?>" title="Add a database, collection or folder"><i class="bi bi-plus-lg"></i></button>
                                    <button class="btn btn-sm btn-outline-primary nb-run" data-app="<?= nb_h($a['name']) ?>" title="Back up this app now"><i class="bi bi-play-fill"></i></button>
                                    <?php if (!$mine): ?><button class="btn btn-sm btn-outline-danger nb-del" data-action="delete_app" data-args="<?= nb_h(json_encode(['app' => $a['name']])) ?>" title="Delete this empty app"><i class="bi bi-trash"></i></button><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Not backed up + left out on purpose -->
        <div class="card shadow-sm mb-4 border-warning">
            <div class="card-body">
                <div class="d-flex align-items-center mb-2">
                    <h5 class="card-title mb-0 me-auto">Not in this backup</h5>
                    <span class="text-muted small me-2">List from <?= nb_when($invUpdated) ?></span>
                    <button class="btn btn-sm btn-outline-secondary" id="nbRefresh"><i class="bi bi-arrow-clockwise"></i> Refresh list</button>
                </div>
                <?php foreach ($invErrors as $e): ?><div class="nb-err"><?= nb_h($serverLabel($e['server_id'])) ?>: could not read the list (<?= nb_h($e['error']) ?>)</div><?php endforeach; ?>
                <?php if (!$notBackedUp): ?>
                    <p class="text-success">Everything on this machine is backed up or left out on purpose.</p>
                <?php else: ?>
                    <table class="table table-sm nb-tbl">
                        <thead class="table-light"><tr><th>Database / collection</th><th>Server</th><th>Size</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($notBackedUp as $n): $k = ($serverById[$n['server_id']]['kind'] ?? '') === 'qdrant' ? 'collection' : 'database'; ?>
                            <tr>
                                <td><?= nb_h($n['name']) ?></td>
                                <td><?= nb_h($serverLabel($n['server_id'])) ?></td>
                                <td><?= $n['points'] !== null ? number_format($n['points']) . ' points' : nb_h($n['mb']) . ' MB' ?></td>
                                <td class="text-end text-nowrap">
                                    <button class="btn btn-sm btn-outline-success nb-add" data-kind="<?= $k ?>" data-pick="<?= nb_h($n['server_id'] . '|' . $n['name']) ?>"><i class="bi bi-plus-lg"></i> Add to backup</button>
                                    <button class="btn btn-sm btn-outline-secondary nb-exclude" data-server="<?= nb_h($n['server_id']) ?>" data-name="<?= nb_h($n['name']) ?>" data-reason="">Leave out</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                <?php if ($exclusions): ?>
                    <div class="fw-semibold mt-3 mb-1">Left out on purpose</div>
                    <table class="table table-sm nb-tbl mb-0"><tbody>
                    <?php foreach ($exclusions as $x): ?>
                        <tr>
                            <td style="width:22%"><?= nb_h($x['name']) ?></td>
                            <td style="width:22%" class="nb-sub"><?= nb_h($serverLabel($x['server_id'])) ?></td>
                            <td class="nb-sub"><?= nb_h($x['reason']) ?></td>
                            <td class="text-end text-nowrap">
                                <button class="btn btn-sm btn-outline-secondary nb-exclude" data-server="<?= nb_h($x['server_id']) ?>" data-name="<?= nb_h($x['name']) ?>" data-reason="<?= nb_h($x['reason']) ?>" title="Change"><i class="bi bi-pencil"></i></button>
                                <button class="btn btn-sm btn-outline-danger nb-del" data-action="delete_exclusion" data-args="<?= nb_h(json_encode(['server' => $x['server_id'], 'name' => $x['name']])) ?>" title="Delete (it shows as not backed up again)"><i class="bi bi-trash"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody></table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Run history -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h5 class="card-title">Recent runs</h5>
                <?php if (!$runs): ?><p class="text-muted mb-0">No runs recorded yet.</p><?php else: ?>
                <table class="table table-sm nb-tbl mb-0">
                    <thead class="table-light"><tr><th>Started</th><th>Kind</th><th>Apps</th><th>Result</th><th>Took</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($runs as $r): ?>
                        <tr>
                            <td><?= nb_when($r['started_at']) ?></td>
                            <td><?= nb_h(['nightly' => 'Nightly', 'manual' => 'By hand', 'dbtools' => 'Run now'][$r['kind']]) ?><?= $r['restore_test'] ? ' <span class="badge bg-light text-dark">restore test</span>' : '' ?><?= $r['verify'] ? ' <span class="badge bg-light text-dark">check</span>' : '' ?></td>
                            <td><?= nb_h($r['app_name'] ?? 'all') ?></td>
                            <td class="<?= $r['result'] === 'OK' ? 'text-success' : ($r['result'] === 'running' ? 'text-primary' : 'text-danger') ?>"><?= nb_h($r['result']) ?></td>
                            <td><?= $r['ended_at'] ? max(1, (int)round((strtotime($r['ended_at']) - strtotime($r['started_at'])) / 60)) . ' min' : '' ?></td>
                            <td class="text-end"><button class="btn btn-sm btn-outline-secondary nb-run-items" data-run="<?= (int)$r['id'] ?>" title="What happened to each item"><i class="bi bi-list-ul"></i></button></td>
                        </tr>
                        <tr class="d-none" id="nbRun<?= (int)$r['id'] ?>"><td colspan="6"><div class="nb-sub">
                            <?php foreach (nb_q("SELECT * FROM backup_run_items WHERE run_id = ? ORDER BY FIELD(outcome, 'error', 'copied', 'synced', 'restore_ok', 'unchanged', 'dry_run'), app_name, item", [$r['id']])->fetchAll() as $ri): ?>
                                <div class="<?= $ri['outcome'] === 'error' ? 'nb-err' : ($ri['outcome'] === 'unchanged' ? 'text-muted' : '') ?>"><?= nb_h($ri['app_name'] . ' / ' . $ri['item'] . ': ' . nb_errText($ri['detail'])) ?></div>
                            <?php endforeach; ?>
                        </div></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Restore help -->
        <details class="card shadow-sm mb-4">
            <summary class="card-body py-2"><strong>How to restore</strong></summary>
            <div class="card-body pt-0">
                <p class="mb-2">Copies are on Google Drive under <code><?= nb_h($set['rclone_remote'] ?? '') ?></code>:
                    <code>&lt;App&gt;/database/&lt;db&gt;/*.sql.gz</code>, <code>&lt;App&gt;/qdrant/&lt;collection&gt;/*.snapshot.gz</code>,
                    <code>&lt;App&gt;/files/&lt;folder&gt;/</code>, <code>&lt;App&gt;/files-replaced/&lt;folder&gt;/&lt;date&gt;/</code>.</p>
                <p class="mb-1"><strong>MySQL:</strong> unzip, create an empty database, <code>mysql -h 127.0.0.1 -P 3307 -u sysdba &lt;db&gt; &lt; file.sql</code></p>
                <p class="mb-1"><strong>Postgres:</strong> unzip, <code>createdb &lt;db&gt;</code>, <code>psql -h 127.0.0.1 -U postgres -d &lt;db&gt; -f file.sql</code></p>
                <p class="mb-1"><strong>Qdrant:</strong> unzip, then POST the <code>.snapshot</code> to <code>http://127.0.0.1:6333/collections/&lt;collection&gt;/snapshots/upload</code> (api-key header).</p>
                <p class="mb-0"><strong>Files:</strong> copy back from <code>&lt;App&gt;/files/&lt;folder&gt;</code>. More: <code>D:\AdvancedVentures\BackupJob\README.md</code>.</p>
            </div>
        </details>
    </div>

    <!-- One modal for every edit -->
    <div class="modal fade" id="nbModal" tabindex="-1">
        <div class="modal-dialog"><div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="nbModalTitle"></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body"><div id="nbModalBody"></div><div id="nbModalErr" class="text-danger mt-2"></div></div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button>
                <button class="btn btn-primary" id="nbModalSave" type="button">Save</button>
            </div>
        </div></div>
    </div>

    <script>
    const NB = {
        csrf: <?= json_encode($csrf) ?>,
        apps: <?= json_encode(array_column($apps, 'name')) ?>,
        pick: <?= json_encode(['database' => $pick['database'] ?: new stdClass(), 'collection' => $pick['collection'] ?: new stdClass()]) ?>,
        days: <?= json_encode($days) ?>,
        summary: <?= json_encode($summaryText) ?>,
        waiting: <?= json_encode((bool)($queued || $running)) ?>
    };
    const nbModal = new bootstrap.Modal('#nbModal');
    const nbEsc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const v = id => $('#' + id).val();
    let nbSave = null;

    async function nbPost(action, body) {
        const r = await fetch('ajax-nightly-backup.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(Object.assign({action, csrf: NB.csrf}, body || {}))});
        let j; try { j = await r.json(); } catch (e) { throw new Error('Server error (' + r.status + ')'); }
        if (!j.ok) throw new Error(j.error || 'Failed');
        return j;
    }
    function nbFlash(msg) {
        $('#nbAlert').html(`<div class="alert alert-danger alert-dismissible">${nbEsc(msg)}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`);
        window.scrollTo(0, 0);
    }
    const nbField = (id, label, value, attrs) => `<div class="mb-2"><label class="form-label">${label}</label><input class="form-control" id="${id}" value="${nbEsc(value)}" ${attrs || ''}></div>`;
    const nbSelect = (id, label, opts, value) => `<div class="mb-2"><label class="form-label">${label}</label><select class="form-select" id="${id}">` +
        Object.entries(opts).map(([k, t]) => `<option value="${nbEsc(k)}" ${String(k) === String(value) ? 'selected' : ''}>${nbEsc(t)}</option>`).join('') + '</select></div>';
    function nbOpen(title, html, save) {
        $('#nbModalTitle').text(title); $('#nbModalBody').off().html(html); $('#nbModalErr').text('');
        nbSave = save; nbModal.show();
    }
    $('#nbModalSave').on('click', async () => {
        $('#nbModalErr').text('');
        try { await nbSave(); location.reload(); } catch (x) { $('#nbModalErr').text(x.message); }
    });

    // Settings
    $(document).on('click', '.nb-edit-setting', e => {
        const b = $(e.currentTarget), type = String(b.data('type')), val = b.attr('data-value');
        let html;
        if (type === 'time') html = nbField('nbV', b.data('label'), val, 'type="time"');
        else if (type === 'weekday') html = nbSelect('nbV', b.data('label'), Object.assign({}, NB.days), val);
        else if (type === 'summary') html = nbSelect('nbV', b.data('label'), NB.summary, val);
        else if (type.startsWith('number')) { const [, mn, mx] = type.split(':'); html = nbField('nbV', b.data('label'), val, `type="number" min="${mn}" max="${mx}"`); }
        else html = nbField('nbV', b.data('label'), val, type === 'email' ? 'type="email"' : '');
        nbOpen(b.data('label'), html, () => nbPost('save_setting', {name: b.data('name'), value: v('nbV')}));
    });

    // Servers
    $(document).on('click', '.nb-server', e => {
        const row = $(e.currentTarget).data('row') || null;
        let kind = row ? row.kind : 'mysql';
        const body = () => (row ? '' : nbSelect('nbKind', 'Type', {mysql: 'MySQL', postgres: 'Postgres', qdrant: 'Qdrant'}, kind) + nbField('nbId', 'Id (e.g. mysql95)', ''))
            + (kind === 'qdrant'
                ? nbField('nbUrl', 'URL', row ? row.url : 'http://127.0.0.1:6333') + nbField('nbKeyEnv', 'API key environment variable', row ? row.key_env : '')
                : nbField('nbPort', 'Port', row ? row.port : '', 'type="number"') + nbField('nbBin', 'Programs folder (has ' + (kind === 'mysql' ? 'mysqldump.exe' : 'pg_dump.exe') + ')', row ? row.bin : '')
                  + nbField('nbUserEnv', 'User name environment variable', row ? row.user_env : '') + nbField('nbPassEnv', 'Password environment variable', row ? row.pass_env : ''))
            + nbField('nbNote', 'Note', row ? row.note : '');
        nbOpen(row ? 'Server ' + row.id : 'Add server', body(), () => nbPost('save_server', {existing: !!row, kind, id: row ? row.id : v('nbId'),
            fields: {url: v('nbUrl'), key_env: v('nbKeyEnv'), port: v('nbPort'), bin: v('nbBin'), user_env: v('nbUserEnv'), pass_env: v('nbPassEnv'), note: v('nbNote')}}));
        $('#nbModalBody').on('change', '#nbKind', () => { const id = v('nbId'); kind = v('nbKind'); $('#nbModalBody').html(body()); $('#nbKind').val(kind); $('#nbId').val(id); });
    });

    // Apps
    $('#nbAddApp').on('click', () => nbOpen('Add app', nbField('nbName', 'Name (it becomes the folder on Google Drive)', '') + nbField('nbWhat', 'Description', ''),
        () => nbPost('add_app', {name: v('nbName'), what: v('nbWhat')})));
    $(document).on('click', '.nb-edit-app', e => {
        const r = $(e.currentTarget).data('row');
        nbOpen('App ' + r.app, nbField('nbWhat', 'Description', r.what) +
            '<div class="form-text mb-2">Own retention (leave both empty to use the Settings):</div>' +
            nbField('nbKv', 'Copies kept', r.kv, 'type="number" min="1"') + nbField('nbKm', 'Monthly copies kept', r.km, 'type="number" min="0"') +
            '<div class="form-text">The app name is its folder on Google Drive, so it is not renamed here.</div>',
            () => nbPost('update_app', {app: r.app, what: v('nbWhat'), keep_versions: v('nbKv'), keep_monthly: v('nbKm')}));
    });
    $(document).on('change', '.nb-toggle', e => nbPost('toggle_app', {app: $(e.target).data('app'), enabled: e.target.checked}).then(() => location.reload()).catch(x => nbFlash(x.message)));

    // Folders
    $(document).on('click', '.nb-edit-folder', e => {
        const r = $(e.currentTarget).data('row');
        nbOpen('Folder ' + r.name + ' (' + r.app + ')',
            nbField('nbPath', 'Folder on this server', r.path) + nbField('nbWhat', 'What it holds', r.what) +
            nbField('nbExcl', 'Skip files matching (comma separated, e.g. *.lock, *.log)', r.exclude),
            () => nbPost('update_folder', {id: r.id, path: v('nbPath'), what: v('nbWhat'), exclude: v('nbExcl')}));
    });

    // Add a database / collection / folder
    $(document).on('click', '.nb-add', e => {
        const b = $(e.currentTarget), pick = b.data('pick') || '';
        let kind = b.data('kind') || 'database';
        const appOpts = Object.fromEntries(NB.apps.map(a => [a, a])); appOpts.__new = 'New app...';
        const startApp = b.data('app') || (pick ? '__new' : NB.apps[0]);
        const body = () => nbSelect('nbApp', 'App', appOpts, startApp) +
            `<div id="nbNewAppWrap" class="${startApp === '__new' ? '' : 'd-none'}">${nbField('nbNewApp', 'New app name', pick ? pick.split('|')[1] : '')}</div>` +
            nbSelect('nbKind', 'What', {database: 'Database (MySQL or Postgres)', collection: 'Qdrant collection', folder: 'Folder of files'}, kind) +
            (kind === 'folder'
                ? nbField('nbPath', 'Folder on this server', '') + nbField('nbFname', 'Short name (folder name on Drive)', '') + nbField('nbWhat', 'What it holds', '') + nbField('nbExcl', 'Skip files matching (optional, comma separated)', '')
                : (Object.keys(NB.pick[kind]).length ? nbSelect('nbPick', 'Name', NB.pick[kind], pick) : '<p class="text-muted">Nothing of this kind is left to add. New? Press Refresh list first.</p>'));
        nbOpen('Add to the backup', body(), async () => {
            let app = v('nbApp');
            if (app === '__new') { app = v('nbNewApp').trim(); await nbPost('add_app', {name: app}); }
            if (kind === 'folder') return nbPost('add_item', {app, kind, path: v('nbPath'), name: v('nbFname'), what: v('nbWhat'), exclude: v('nbExcl')});
            const [server, name] = (v('nbPick') || '|').split('|');
            if (!name) throw new Error('Nothing to add');
            return nbPost('add_item', {app, kind, server, name});
        });
        $('#nbModalBody').on('change', '#nbKind', () => { const app = v('nbApp'); kind = v('nbKind'); $('#nbModalBody').html(body()); $('#nbKind').val(kind); $('#nbApp').val(app).trigger('change'); })
            .on('change', '#nbApp', () => $('#nbNewAppWrap').toggleClass('d-none', v('nbApp') !== '__new'));
    });

    // Leave out on purpose (add or change the reason)
    $(document).on('click', '.nb-exclude', e => {
        const b = $(e.currentTarget);
        nbOpen('Leave out ' + b.data('name'), nbField('nbReason', 'Why it is left out', b.attr('data-reason')),
            () => nbPost('save_exclusion', {server: b.data('server'), name: String(b.data('name')), reason: v('nbReason')}));
    });

    // Delete buttons: first click arms, second click deletes.
    $(document).on('click', '.nb-del', e => {
        const b = $(e.currentTarget);
        if (!b.data('armed')) { b.data('armed', 1).addClass('text-danger').attr('title', 'Click again to delete'); b.find('i').attr('class', 'bi bi-question-circle'); return; }
        nbPost(b.data('action'), b.data('args')).then(() => location.reload()).catch(x => nbFlash(x.message));
    });

    // Runs
    $(document).on('click', '.nb-run', e => nbPost('run', {app: $(e.currentTarget).data('app') || null}).then(() => location.reload()).catch(x => nbFlash(x.message)));
    $(document).on('click', '.nb-run-items', e => $('#nbRun' + $(e.currentTarget).data('run')).toggleClass('d-none'));
    $('#nbRefresh').on('click', () => nbPost('refresh_inventory').then(() => location.reload()).catch(x => nbFlash(x.message)));
    if (NB.waiting) setInterval(async () => {
        try {
            const r = await (await fetch('ajax-nightly-backup.php?action=status')).json();
            if (!r.queued && !r.running) location.reload();
        } catch (e) {}
    }, 10000);
    </script>
</div><!-- container-fluid opened by common/Header.php -->
</body>
</html>
