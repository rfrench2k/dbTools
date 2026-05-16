<?php
$pageTitle = 'InterBase Replication Tool';
include $_SERVER['DOCUMENT_ROOT'] . '/dbtools/common/Header.php';
?>
    <div class="container py-4">
        <div class="row mb-4">
            <div class="col">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="../index.php">Database Tools</a></li>
                        <li class="breadcrumb-item active">InterBase Replication</li>
                    </ol>
                </nav>
                <h1 class="h3">InterBase Replication Tool</h1>
                <p class="text-muted">Use gbak to refresh TEST InterBase databases with dumps taken directly from PROD.</p>
            </div>
        </div>

        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle"></i>
            <strong>Heads up:</strong> This process overwrites the selected TEST database with data from PROD.
            Make sure no one is connected to the TEST database before starting.
        </div>

        <div class="row g-4">
            <div class="col-lg-6">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h5 class="card-title">Select Database</h5>
                        <p class="text-muted small">Databases are defined in <code>ib-config.json</code>.</p>
                        <div class="mb-3">
                            <label for="ibDatabase" class="form-label">InterBase Database</label>
                            <select id="ibDatabase" class="form-select" disabled></select>
                        </div>
                        <button id="ibCheckBtn" class="btn btn-primary" disabled>
                            <i class="bi bi-search"></i> Check Status
                        </button>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card shadow-sm" id="ibStatusCard" style="display:none;">
                    <div class="card-header">
                        <strong>Status</strong>
                    </div>
                    <div class="card-body" id="ibStatusBody"></div>
                </div>

                <div class="card shadow-sm border-danger" id="ibConfirmCard" style="display:none;">
                    <div class="card-header bg-danger text-white">
                        <strong><i class="bi bi-exclamation-octagon"></i> Confirm Refresh</strong>
                    </div>
                    <div class="card-body">
                        <p class="mb-3">Back up anything you need from TEST before confirming.</p>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="ibConfirmCheckbox">
                            <label class="form-check-label" for="ibConfirmCheckbox">
                                I understand this will overwrite the TEST database.
                            </label>
                        </div>
                        <button class="btn btn-danger" id="ibExecuteBtn" disabled>
                            <i class="bi bi-arrow-repeat"></i> Run Replication
                        </button>
                        <button class="btn btn-secondary ms-2" id="ibCancelBtn">
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-4" id="ibProgressRow" style="display:none;">
            <div class="col">
                <div class="card shadow-sm">
                    <div class="card-header"><strong>Progress</strong></div>
                    <div class="card-body" id="ibProgressBody">
                        <div class="text-muted">Waiting to start...</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-4" id="ibResultRow" style="display:none;">
            <div class="col">
                <div class="card shadow-sm">
                    <div class="card-header"><strong>Result</strong></div>
                    <div class="card-body" id="ibResultBody"></div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const $database = $('#ibDatabase');
        const $checkBtn = $('#ibCheckBtn');
        const $statusCard = $('#ibStatusCard');
        const $statusBody = $('#ibStatusBody');
        const $confirmCard = $('#ibConfirmCard');
        const $confirmCheckbox = $('#ibConfirmCheckbox');
        const $executeBtn = $('#ibExecuteBtn');
        const $cancelBtn = $('#ibCancelBtn');
        const $progressRow = $('#ibProgressRow');
        const $progressBody = $('#ibProgressBody');
        const $resultRow = $('#ibResultRow');
        const $resultBody = $('#ibResultBody');

        let selectedId = '';

        function resetUI() {
            $statusCard.hide();
            $statusBody.empty();
            $confirmCard.hide();
            $confirmCheckbox.prop('checked', false);
            $executeBtn.prop('disabled', true);
            $progressRow.hide();
            $progressBody.empty();
            $resultRow.hide();
            $resultBody.empty();
        }

        function loadDatabases() {
            $.getJSON('ibBackupToolReplicateAjax.php', { action: 'list' })
                .done(function (response) {
                    if (!response.success) {
                        throw new Error(response.error || 'Unable to load databases');
                    }

                    $database.empty();
                    $database.append('<option value="">Select a database...</option>');

                    (response.databases || []).forEach(function (db) {
                        const label = db.name || db.id;
                        $database.append(`<option value="${db.id}">${label}</option>`);
                    });

                    $database.prop('disabled', false);
                    $checkBtn.prop('disabled', false);
                })
                .fail(function (xhr, status, error) {
                    alert('Failed to load databases: ' + error);
                });
        }

        function renderStatus(status) {
            const items = [];
            items.push(`<li><strong>Database:</strong> ${status.databaseName || selectedId}</li>`);
            items.push(`<li><strong>PROD host:</strong> ${status.prodHost || 'n/a'} (${status.prodPath || 'path unknown'})</li>`);
            items.push(`<li><strong>TEST host:</strong> ${status.testHost || 'n/a'} (${status.testPath || 'path unknown'})</li>`);
            items.push(`<li><strong>gbak:</strong> ${status.tools && status.tools.gbak ? (status.tools.gbakExists ? 'Available' : 'Missing') : 'Not configured'}</li>`);
            items.push(`<li><strong>gfix:</strong> ${status.tools && status.tools.gfix ? (status.tools.gfixExists ? 'Available' : 'Missing') : 'Not configured'}</li>`);
            items.push(`<li><strong>Backup folder:</strong> ${status.backupDir || ''} (${status.backupDirExists ? 'exists' : 'missing'})</li>`);
            items.push(`<li><strong>Test DB file accessible:</strong> ${status.testPathExists ? 'Yes' : 'No'}</li>`);

            let prodReachable = 'Unknown';
            if (status.prodReachable === true) prodReachable = 'Reachable';
            if (status.prodReachable === false) prodReachable = 'Unreachable';
            items.push(`<li><strong>PROD host reachable:</strong> ${prodReachable}</li>`);

            if (status.lastBackupFile) {
                items.push(`<li><strong>Last backup:</strong> ${status.lastBackupFile}</li>`);
            }

            $statusBody.html(`<ul class="mb-0 small">${items.map(item => `<li>${item}</li>`).join('')}</ul>`);
            $statusCard.show();

            if (status.canReplicate) {
                $confirmCard.show();
            } else {
                $confirmCard.hide();
            }
        }

        function appendProgress(message, type) {
            const badgeClass = type === 'error' ? 'danger' : (type === 'success' ? 'success' : 'info');
            const html = `<div class="alert alert-${badgeClass} py-2 mb-2">${message}</div>`;
            $progressBody.append(html);
        }

        function displayResult(result) {
            const lines = [];
            lines.push(`<li><strong>Log file:</strong> ${result.logFile || 'n/a'}</li>`);
            lines.push(`<li><strong>Backup file:</strong> ${result.backupFile || 'n/a'}</li>`);
            if (result.duration) {
                lines.push(`<li><strong>Duration:</strong> ${result.duration}</li>`);
            }

            let stepsHtml = '';
            if (Array.isArray(result.steps)) {
                stepsHtml = '<h6 class="mt-3">Steps</h6><ul class="small">';
                result.steps.forEach(step => {
                    const icon = step.success ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-x-circle text-danger"></i>';
                    stepsHtml += `<li>${icon} ${step.message}</li>`;
                });
                stepsHtml += '</ul>';
            }

            $resultBody.html(`
                <div class="alert alert-success"><i class="bi bi-check-circle"></i> Refresh completed.</div>
                <ul class="mb-0 small">${lines.map(l => `<li>${l}</li>`).join('')}</ul>
                ${stepsHtml}
            `);
            $resultRow.show();
        }

        $checkBtn.on('click', function () {
            selectedId = $database.val();
            if (!selectedId) {
                alert('Select a database first.');
                return;
            }

            resetUI();
            $statusBody.html('<div class="text-muted">Checking configuration...</div>');
            $statusCard.show();

            $.post('ibBackupToolReplicateAjax.php', { action: 'check', database: selectedId }, null, 'json')
                .done(function (response) {
                    if (!response.success) {
                        throw new Error(response.error || 'Check failed');
                    }
                    renderStatus(response);
                    if (!response.canReplicate) {
                        $confirmCard.hide();
                        alert(response.message || 'Replication prerequisites not met.');
                    }
                })
                .fail(function (xhr, status, error) {
                    $statusBody.html(`<div class="alert alert-danger">${error}</div>`);
                });
        });

        $confirmCheckbox.on('change', function () {
            $executeBtn.prop('disabled', !this.checked);
        });

        $cancelBtn.on('click', function () {
            resetUI();
        });

        $executeBtn.on('click', function () {
            if (!selectedId) {
                return;
            }

            if (!confirm('This will overwrite the TEST database. Continue?')) {
                return;
            }

            $progressRow.show();
            $progressBody.empty();
            appendProgress('Starting replication...', 'info');
            $executeBtn.prop('disabled', true);

            $.post('ibBackupToolReplicateAjax.php', { action: 'replicate', database: selectedId }, null, 'json')
                .done(function (response) {
                    if (!response.success) {
                        throw new Error(response.error || 'Replication failed');
                    }
                    appendProgress('Replication finished.', 'success');
                    displayResult(response);
                })
                .fail(function (xhr, status, error) {
                    appendProgress('Replication failed: ' + error, 'error');
                })
                .always(function () {
                    $confirmCheckbox.prop('checked', false);
                });
        });

        loadDatabases();
    </script>
</body>
</html>
