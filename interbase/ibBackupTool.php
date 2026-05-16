<?php
$pageTitle = 'InterBase Backup Tool';
include $_SERVER['DOCUMENT_ROOT'] . '/dbtools/common/Header.php';
?>
    <div class="container py-4">
        <div class="row mb-4">
            <div class="col">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="../index.php">Database Tools</a></li>
                        <li class="breadcrumb-item active">InterBase Backup</li>
                    </ol>
                </nav>
                <h1 class="h3">InterBase Backup Tool</h1>
                <p class="text-muted">Use gbak to create transportable backups of PROD or TEST InterBase databases.</p>
            </div>
        </div>

        <div id="alertContainer"></div>

        <div class="row g-4 mb-4">
            <div class="col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="bi bi-hdd-fill"></i> Create Backup</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Environment</label>
                            <div class="btn-group" role="group" aria-label="Environment selection">
                                <input type="radio" class="btn-check" name="environment" id="envProd" value="prod" autocomplete="off" checked>
                                <label class="btn btn-outline-success" for="envProd">PROD</label>
                                <input type="radio" class="btn-check" name="environment" id="envTest" value="test" autocomplete="off">
                                <label class="btn btn-outline-success" for="envTest">TEST</label>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="databaseSelect" class="form-label">InterBase Database</label>
                            <select id="databaseSelect" class="form-select" disabled>
                                <option value="">Loading databases...</option>
                            </select>
                        </div>

                        <p class="small text-muted">
                            Backups are stored in <code id="backupDirDisplay">(loading)</code><br>
                            Filename format: <code>Database-Env-YYYYMMDD_HHMMSS.fbk</code>
                        </p>

                        <div class="d-flex flex-column gap-2">
                            <button class="btn btn-outline-secondary" id="checkBtn" disabled>
                                <i class="bi bi-search"></i> Check Prerequisites
                            </button>
                            <button class="btn btn-success btn-lg" id="backupBtn" disabled>
                                <i class="bi bi-hdd"></i> Run Backup
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-info-circle"></i> System Status</h5>
                    </div>
                    <div class="card-body">
                        <div id="statusPanel" class="text-muted small">
                            <div class="text-center">
                                <div class="spinner-border spinner-border-sm" role="status"></div>
                                <span class="ms-2">Loading...</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4" id="resultRow" style="display:none;">
            <div class="col-12">
                <div class="card shadow-sm border-success">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="bi bi-check-circle"></i> Backup Complete</h5>
                    </div>
                    <div class="card-body" id="resultBody"></div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="bi bi-clock-history"></i> Recent Backups
                    <button class="btn btn-sm btn-outline-secondary float-end" id="refreshBackups">
                        <i class="bi bi-arrow-clockwise"></i> Refresh
                    </button>
                </h5>
            </div>
            <div class="card-body" id="recentBackups">
                <div class="text-center text-muted">
                    <div class="spinner-border spinner-border-sm" role="status"></div>
                    <span class="ms-2">Loading...</span>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const $database = $('#databaseSelect');
        const $checkBtn = $('#checkBtn');
        const $backupBtn = $('#backupBtn');
        const $statusPanel = $('#statusPanel');
        const $resultRow = $('#resultRow');
        const $resultBody = $('#resultBody');
        const $alertContainer = $('#alertContainer');
        const $backupDirDisplay = $('#backupDirDisplay');

        let latestStatus = null;

        function showAlert(message, type = 'info') {
            const html = `<div class="alert alert-${type} alert-dismissible fade show" role="alert">${message}<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>`;
            $alertContainer.html(html);
        }

        function clearAlert() {
            $alertContainer.empty();
        }

        function loadDatabases() {
            $.getJSON('ibBackupToolAjax.php', { action: 'list' })
                .done(function(response) {
                    if (!response.success) {
                        throw new Error(response.error || 'Failed to load databases');
                    }

                    const options = ['<option value="">-- Select a database --</option>'];
                    (response.databases || []).forEach(db => {
                        options.push(`<option value="${db.id}">${db.name}</option>`);
                    });
                    $database.html(options.join(''));
                    $database.prop('disabled', false);
                    $checkBtn.prop('disabled', false);

                    if (response.backupDir) {
                        $backupDirDisplay.text(response.backupDir);
                    }

                    if (response.databases && response.databases.length) {
                        $statusPanel.html('<div class="text-muted">Select a database and click Check Prerequisites to view system status.</div>');
                    } else {
                        $statusPanel.html('<div class="alert alert-warning mb-0">No InterBase databases are configured.</div>');
                        $checkBtn.prop('disabled', true);
                        $backupBtn.prop('disabled', true);
                    }
                })
                .fail(function(xhr, status, error) {
                    $database.html('<option value="">Failed to load databases</option>');
                    $statusPanel.html('<div class="alert alert-danger mb-0">Unable to load database list: ' + error + '</div>');
                    showAlert('Unable to load database list: ' + error, 'danger');
                });
        }

        function renderStatus(status) {
            latestStatus = status;
            const badge = (ok) => ok ? '<span class="badge bg-success">OK</span>' : '<span class="badge bg-danger">Issue</span>';
            const hostBadge = status.hostReachable === true ? '<span class="badge bg-success">Reachable</span>' : (status.hostReachable === false ? '<span class="badge bg-danger">Unreachable</span>' : '<span class="badge bg-secondary">Unknown</span>');

            const items = [];
            items.push(`<li><strong>Database:</strong> ${status.databaseName || status.databaseId}</li>`);
            items.push(`<li><strong>Environment:</strong> ${status.environment}</li>`);
            items.push(`<li><strong>Host:</strong> ${status.host || 'n/a'} ${hostBadge}</li>`);
            items.push(`<li><strong>Database path:</strong> <code>${status.databasePath || ''}</code></li>`);
            items.push(`<li><strong>gbak:</strong> <code>${status.gbakPath || 'Not found'}</code> ${badge(status.gbakExists)}</li>`);
            items.push(`<li><strong>Backup directory:</strong> <code>${status.backupDir || '(not set)'}</code> ${badge(status.backupDirExists && status.backupDirWritable)}</li>`);

            const readyBadge = status.canBackup ? '<span class="badge bg-success">Ready</span>' : '<span class="badge bg-warning text-dark">Not Ready</span>';
            const html = `
                <div class="mb-2"><strong>Status:</strong> ${readyBadge}</div>
                <ul class="small mb-2">${items.map(item => `<li>${item}</li>`).join('')}</ul>
                <div class="text-muted">${status.message || ''}</div>
            `;

            $statusPanel.html(html);
            $backupBtn.prop('disabled', !status.canBackup);

            if (status.backupDir) {
                $backupDirDisplay.text(status.backupDir);
            }
        }

        function runStatusCheck() {
            const databaseId = $database.val();
            if (!databaseId) {
                showAlert('Select a database first.', 'warning');
                return;
            }

            clearAlert();
            $statusPanel.html('<div class="text-center"><div class="spinner-border spinner-border-sm"></div><span class="ms-2">Checking...</span></div>');
            $backupBtn.prop('disabled', true);

            $.post('ibBackupToolAjax.php', {
                action: 'check',
                database: databaseId,
                environment: $('input[name="environment"]:checked').val()
            }, null, 'json')
                .done(function(response) {
                    if (!response.success) {
                        throw new Error(response.error || 'Check failed');
                    }
                    renderStatus(response.status);
                })
                .fail(function(xhr, status, error) {
                    $statusPanel.html(`<div class="alert alert-danger">${error}</div>`);
                });
        }

        function runBackup() {
            const databaseId = $database.val();
            if (!databaseId) {
                showAlert('Select a database first.', 'warning');
                return;
            }

            if (!latestStatus || !latestStatus.canBackup) {
                if (!confirm('Prerequisites have not been confirmed. Run backup anyway?')) {
                    return;
                }
            }

            if (!confirm('Run gbak backup now?')) {
                return;
            }

            clearAlert();
            $backupBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Backing up...');
            $resultRow.hide();

            $.post('ibBackupToolAjax.php', {
                action: 'backup',
                database: databaseId,
                environment: $('input[name="environment"]:checked').val()
            }, null, 'json')
                .done(function(response) {
                    if (!response.success) {
                        throw new Error(response.error || 'Backup failed');
                    }
                    displayResult(response.result);
                    loadRecentBackups();
                    showAlert('Backup completed successfully.', 'success');
                })
                .fail(function(xhr, status, error) {
                    showAlert('Backup failed: ' + error, 'danger');
                })
                .always(function() {
                    $backupBtn.prop('disabled', false).html('<i class="bi bi-hdd"></i> Run Backup');
                });
        }

        function displayResult(result) {
            if (!result) {
                $resultRow.hide();
                return;
            }

            let sizeText = 'Unknown';
            if (typeof result.backupSizeMb !== 'undefined') {
                sizeText = `${result.backupSizeMb} MB`;
            } else if (result.backupSize) {
                sizeText = `${(result.backupSize / (1024 * 1024)).toFixed(2)} MB`;
            }
            const html = `
                <table class="table table-sm mb-0">
                    <tr><td><strong>Database</strong></td><td>${result.databaseName}</td></tr>
                    <tr><td><strong>Environment</strong></td><td>${result.environment}</td></tr>
                    <tr><td><strong>File</strong></td><td><code>${result.backupFileName || result.backupFile}</code></td></tr>
                    <tr><td><strong>Location</strong></td><td><code>${result.backupFile}</code></td></tr>
                    <tr><td><strong>Size</strong></td><td>${sizeText}</td></tr>
                    <tr><td><strong>Log</strong></td><td><code>${result.logFile || 'n/a'}</code></td></tr>
                    <tr><td><strong>Duration</strong></td><td>${result.duration || 'n/a'}</td></tr>
                </table>`;

            $resultBody.html(html);
            $resultRow.show();
        }

        function loadRecentBackups() {
            $('#recentBackups').html('<div class="text-center"><div class="spinner-border spinner-border-sm"></div></div>');

            $.getJSON('ibBackupToolAjax.php', { action: 'recent' })
                .done(function(response) {
                    if (!response.success) {
                        throw new Error(response.error || 'Failed to load backups');
                    }

                    const backups = response.backups || [];
                    if (backups.length === 0) {
                        $('#recentBackups').html('<p class="text-muted mb-0">No backups found.</p>');
                        return;
                    }

                    const rows = backups.map(item => `
                        <tr>
                            <td><code>${item.filename}</code></td>
                            <td>${item.environment}</td>
                            <td>${item.size}</td>
                            <td>${item.created}</td>
                        </tr>
                    `).join('');

                    const table = `
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead><tr><th>Filename</th><th>Env</th><th>Size</th><th>Created</th></tr></thead>
                                <tbody>${rows}</tbody>
                            </table>
                        </div>`;
                    $('#recentBackups').html(table);
                })
                .fail(function(xhr, status, error) {
                    $('#recentBackups').html('<div class="alert alert-danger mb-0">Failed to load backups: ' + error + '</div>');
                });
        }

        $checkBtn.on('click', runStatusCheck);
        $backupBtn.on('click', runBackup);
        $('#refreshBackups').on('click', loadRecentBackups);

        $('input[name="environment"]').on('change', function() {
            latestStatus = null;
            $backupBtn.prop('disabled', true);
            $statusPanel.html('<div class="text-muted">Run a check to refresh status.</div>');
        });

        loadDatabases();
        loadRecentBackups();
    </script>
</body>
</html>

