<?php
$pageTitle = 'InterBase Restore Tool';
include $_SERVER['DOCUMENT_ROOT'] . '/dbtools/common/Header.php';
?>



    <div class="container py-4">



        <div class="row mb-4">



            <div class="col">



                <nav aria-label="breadcrumb">



                    <ol class="breadcrumb">



                        <li class="breadcrumb-item"><a href="../index.php">Database Tools</a></li>



                        <li class="breadcrumb-item active">InterBase Restore</li>



                    </ol>



                </nav>



                <h1 class="h3">InterBase Restore Tool</h1>



                <p class="text-muted">Restore InterBase databases from existing backups.</p>



            </div>



        </div>







        <div id="alertContainer"></div>







        <div class="alert alert-danger">



            <i class="bi bi-exclamation-octagon"></i>



            <strong>Warning:</strong> Restoring a backup overwrites the target database file. Double-check your selections before running.



        </div>







        <div class="row g-4 mb-4">



            <div class="col-lg-6">



                <div class="card shadow-sm h-100">



                    <div class="card-header bg-primary text-white">



                        <h5 class="mb-0"><i class="bi bi-arrow-counterclockwise"></i> Restore Options</h5>



                    </div>



                    <div class="card-body">



                        <div class="mb-3">



                            <label class="form-label">Environment</label>



                            <div class="btn-group" role="group" aria-label="Environment selection">



                                <input type="radio" class="btn-check" name="environment" id="envProd" value="prod" autocomplete="off" checked>



                                <label class="btn btn-outline-primary" for="envProd">PROD</label>



                                <input type="radio" class="btn-check" name="environment" id="envTest" value="test" autocomplete="off">



                                <label class="btn btn-outline-primary" for="envTest">TEST</label>



                            </div>



                        </div>







                        <div class="mb-3">



                            <label for="databaseSelect" class="form-label">InterBase Database</label>



                            <select id="databaseSelect" class="form-select" disabled>



                                <option value="">Loading databases...</option>



                            </select>



                        </div>







                        <div class="mb-3">



                            <label for="targetPath" class="form-label">Target Database Path</label>



                            <input type="text" id="targetPath" class="form-control" placeholder="Select a database to autofill" disabled>



                            <div class="form-text">Update this path if you need to restore to a different location.</div>



                        </div>







                        <div class="mb-3">



                            <label for="backupSelect" class="form-label">Backup File</label>



                            <select id="backupSelect" class="form-select" disabled>



                                <option value="">Loading backups...</option>



                            </select>



                        </div>







                        <div id="backupDetails" class="small text-muted border rounded p-2 mb-3">



                            Select a backup to see details.



                        </div>







                        <p class="small text-muted mb-3">



                            Backups are read from <code id="backupDirDisplay">(loading)</code>



                        </p>







                        <div class="d-flex flex-column flex-md-row gap-2">



                            <button class="btn btn-outline-secondary" id="checkBtn" disabled>



                                <i class="bi bi-search"></i> Check Prerequisites



                            </button>



                            <button class="btn btn-success btn-lg" id="restoreBtn" disabled>



                                <i class="bi bi-arrow-counterclockwise"></i> Run Restore



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



                        <h5 class="mb-0"><i class="bi bi-check-circle"></i> Restore Complete</h5>



                    </div>



                    <div class="card-body" id="resultBody"></div>



                </div>



            </div>



        </div>







        <div class="card shadow-sm">



            <div class="card-header">



                <h5 class="mb-0">



                    <i class="bi bi-archive"></i> Available Backups



                    <button class="btn btn-sm btn-outline-secondary float-end" id="refreshBackups">



                        <i class="bi bi-arrow-clockwise"></i> Refresh



                    </button>



                </h5>



            </div>



            <div class="card-body" id="backupsContainer">



                <div class="text-muted">Loading backups...</div>



            </div>



        </div>



    </div>







    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>



    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>



    <script>



        const $database = $('#databaseSelect');



        const $targetPath = $('#targetPath');



        const $backupSelect = $('#backupSelect');



        const $backupDetails = $('#backupDetails');



        const $checkBtn = $('#checkBtn');



        const $restoreBtn = $('#restoreBtn');



        const $statusPanel = $('#statusPanel');



        const $alertContainer = $('#alertContainer');



        const $backupDirDisplay = $('#backupDirDisplay');



        const $backupsContainer = $('#backupsContainer');



        const $resultRow = $('#resultRow');



        const $resultBody = $('#resultBody');



        const $refreshBackups = $('#refreshBackups');







        let databaseMap = {};



        let backupsMap = {};



        let latestStatus = null;







        function getSelectedDatabase() {



            const id = $database.val();



            return id ? (databaseMap[id] || null) : null;



        }







        function getSelectedBackup() {



            const name = $backupSelect.val();



            return name ? (backupsMap[name] || null) : null;



        }







        function backupMatchesDatabase(backup, database) {



            if (!backup || !database) {



                return false;



            }



            if (backup.databaseId && backup.databaseId === database.id) {



                return true;



            }



            const fileName = (backup.filename || '').toLowerCase();



            if (!fileName) {



                return false;



            }



            const base = fileName.split('.')[0];



            const prefix = base ? base.split('-')[0] : '';



            if (!prefix) {



                return false;



            }



            const candidates = [];



            if (database.id) {



                candidates.push(String(database.id).toLowerCase());



            }



            if (database.name) {



                candidates.push(String(database.name).toLowerCase());



            }



            return candidates.some(candidate => candidate && prefix === candidate);



        }







        function refreshActionButtons() {



            const database = getSelectedDatabase();



            const backup = getSelectedBackup();



            const target = $targetPath.val().trim();







            const hasSelections = Boolean(database && backup && target);



            const matches = hasSelections ? backupMatchesDatabase(backup, database) : false;



            const readyForActions = hasSelections && matches;







            $checkBtn.prop('disabled', !readyForActions);







            const statusAllowsRestore = latestStatus ? latestStatus.canRestore === true : true;



            $restoreBtn.prop('disabled', !readyForActions || !statusAllowsRestore);



        }







        function showAlert(message, type = 'info') {



            const html = `<div class="alert alert-${type} alert-dismissible fade show" role="alert">${message}<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>`;



            $alertContainer.html(html);



        }







        function clearAlert() {



            $alertContainer.empty();



        }







        function badge(ok) {



            return ok ? '<span class="badge bg-success">OK</span>' : '<span class="badge bg-danger">Issue</span>';



        }







        function updateStatusPrompt() {



            if (latestStatus) {



                return;



            }



            if (!databaseMap || Object.keys(databaseMap).length === 0) {



                return;



            }



            const database = getSelectedDatabase();



            if (!database) {



                $statusPanel.html('<div class="text-muted">Select a database to begin.</div>');



                return;



            }



            const backup = getSelectedBackup();



            if (!backup) {



                const name = database.name || database.id;



                $statusPanel.html(`<div class="text-muted">Choose a backup file for ${name}.</div>`);



                return;



            }



            if (!backupMatchesDatabase(backup, database)) {



                $statusPanel.html('<div class="alert alert-warning mb-0">The selected backup does not match the chosen database. Pick a backup created for this database.</div>');



                return;



            }



            if (!$targetPath.val().trim()) {



                $statusPanel.html('<div class="text-muted">Provide the destination database path.</div>');



                return;



            }



            $statusPanel.html('<div class="text-muted">Click Check Prerequisites to verify restore readiness.</div>');



        }function updateBackupDetails(backup) {



            if (!backup) {



                $backupDetails.html('Select a backup to see details.');



                refreshActionButtons();



                return;



            }







            const dbLabel = backup.database || backup.databaseId || 'Unknown';



            const envLabel = backup.environment || 'Unknown';



            const sizeLabel = backup.size || 'Unknown';



            const createdLabel = backup.created || 'Unknown';







            const selectedDatabase = getSelectedDatabase();



            const matches = selectedDatabase ? backupMatchesDatabase(backup, selectedDatabase) : false;







            let html = `



                <div><strong>Filename:</strong> <code>${backup.filename}</code></div>



                <div><strong>Database:</strong> ${dbLabel}</div>



                <div><strong>Environment:</strong> ${envLabel}</div>



                <div><strong>Size:</strong> ${sizeLabel}</div>



                <div><strong>Created:</strong> ${createdLabel}</div>



            `;







            if (selectedDatabase) {



                const badgeHtml = matches



                    ? '<span class="badge bg-success">Matches selection</span>'



                    : '<span class="badge bg-danger">Mismatch</span>';



                html += `<div class="mt-2">${badgeHtml}</div>`;



                if (!matches) {



                    const friendly = selectedDatabase.name || selectedDatabase.id;



                    html = `<div class="alert alert-warning mb-2">This backup name does not match ${friendly}. Choose a backup created for that database.</div>` + html;



                }



            }







            $backupDetails.html(html);



            refreshActionButtons();



            if (!latestStatus) {



                updateStatusPrompt();



            }



        }function populateDatabases(databases) {



            const options = ['<option value="">-- Select a database --</option>'];



            databaseMap = {};



            (databases || []).forEach(db => {



                databaseMap[db.id] = db;



                const label = db.name || db.id;



                options.push(`<option value="${db.id}">${label}</option>`);



            });



            if (options.length === 1) {



                options[0] = '<option value="">No databases configured</option>';



                $database.prop('disabled', true);



                $targetPath.prop('disabled', true);



            } else {



                $database.prop('disabled', false);



                $targetPath.prop('disabled', false);



            }



            $database.html(options.join(''));



            refreshActionButtons();



        }function populateBackups(backups) {



            backupsMap = {};



            $backupSelect.prop('disabled', false);







            if (!backups || backups.length === 0) {



                $backupSelect.html('<option value="">No backups found</option>');



                $backupSelect.prop('disabled', true);



                $backupsContainer.html('<p class="text-muted mb-0">No backups found.</p>');



                updateBackupDetails(null);



                refreshActionButtons();



                return;



            }







            const options = ['<option value="">-- Select a backup --</option>'];



            const rows = backups.map(item => {



                backupsMap[item.filename] = item;



                options.push(`<option value="${item.filename}">${item.filename}</option>`);



                const env = item.environment || 'Unknown';



                const db = item.database || item.databaseId || 'Unknown';



                const recognized = item.databaseId ? '<span class="badge bg-success ms-2">Matched</span>' : '';



                return `



                    <tr>



                        <td><code>${item.filename}</code>${recognized}</td>



                        <td>${db}</td>



                        <td>${env}</td>



                        <td>${item.size || ''}</td>



                        <td>${item.created || ''}</td>



                    </tr>`;



            });







            $backupSelect.html(options.join(''));







            const table = `



                <div class="table-responsive">



                    <table class="table table-sm table-hover mb-0">



                        <thead><tr><th>Filename</th><th>Database</th><th>Env</th><th>Size</th><th>Created</th></tr></thead>



                        <tbody>${rows.join('')}</tbody>



                    </table>



                </div>`;



            $backupsContainer.html(table);



            refreshActionButtons();



        }function renderStatus(status) {



            latestStatus = status;



            const hostBadge = status.hostReachable === true ? '<span class="badge bg-success">Reachable</span>' : (status.hostReachable === false ? '<span class="badge bg-danger">Unreachable</span>' : '<span class="badge bg-secondary">Unknown</span>');



            const readyBadge = status.canRestore ? '<span class="badge bg-success">Ready</span>' : '<span class="badge bg-warning text-dark">Not Ready</span>';



            const items = [];



            items.push(`<li><strong>Database:</strong> ${status.databaseName || status.databaseId}</li>`);



            items.push(`<li><strong>Environment:</strong> ${status.environment}</li>`);



            items.push(`<li><strong>Host:</strong> ${status.host || '(local)'} ${hostBadge}</li>`);



            if (status.destination) {



                items.push(`<li><strong>Destination:</strong> <code>${status.destination}</code></li>`);



            } else {



                items.push(`<li><strong>Target path:</strong> <code>${status.targetPath || ''}</code></li>`);



            }



            items.push(`<li><strong>Backup file:</strong> <code>${status.backupFile || ''}</code> ${badge(status.backupExists)}</li>`);



            if (typeof status.backupSizeMb !== 'undefined' && status.backupSizeMb !== null) {



                items.push(`<li><strong>Backup size:</strong> ${status.backupSizeMb} MB</li>`);



            } else if (typeof status.backupSize !== 'undefined' && status.backupSize !== null) {



                const sizeMb = (status.backupSize / (1024 * 1024)).toFixed(2);



                items.push(`<li><strong>Backup size:</strong> ${sizeMb} MB</li>`);



            }



            items.push(`<li><strong>gbak:</strong> <code>${status.gbakPath || 'Not found'}</code> ${badge(status.gbakExists)}</li>`);



            if (typeof status.targetPathExists !== 'undefined') {



                const targetExistsLabel = status.targetPathExists === true ? 'Yes' : (status.targetPathExists === false ? 'No' : 'Unknown');



                items.push(`<li><strong>Target file exists:</strong> ${targetExistsLabel}</li>`);



            }



            if (typeof status.targetDirExists !== 'undefined') {



                const dirExistsLabel = status.targetDirExists === true ? 'Yes' : (status.targetDirExists === false ? 'No' : 'Unknown');



                items.push(`<li><strong>Target directory exists:</strong> ${dirExistsLabel}</li>`);



            }







            const html = `



                <div class="mb-2"><strong>Status:</strong> ${readyBadge}</div>



                <ul class="small mb-2">${items.map(item => `<li>${item}</li>`).join('')}</ul>



                <div class="text-muted">${status.message || ''}</div>



            `;







            $statusPanel.html(html);



            $restoreBtn.prop('disabled', !status.canRestore);



            refreshActionButtons();



        }







        function displayResult(result) {



            if (!result) {



                $resultRow.hide();



                return;



            }







            let restoredSizeText = 'Unknown';



            if (typeof result.restoredSizeMb !== 'undefined' && result.restoredSizeMb !== null) {



                restoredSizeText = `${result.restoredSizeMb} MB`;



            } else if (result.restoredSize) {



                restoredSizeText = `${(result.restoredSize / (1024 * 1024)).toFixed(2)} MB`;



            }







            const lines = [];



            lines.push(`<tr><td><strong>Database</strong></td><td>${result.databaseName}</td></tr>`);



            lines.push(`<tr><td><strong>Environment</strong></td><td>${result.environment}</td></tr>`);



            lines.push(`<tr><td><strong>Backup File</strong></td><td><code>${result.backupFile}</code></td></tr>`);



            lines.push(`<tr><td><strong>Restored To</strong></td><td><code>${result.targetPath}</code></td></tr>`);



            lines.push(`<tr><td><strong>Log</strong></td><td><code>${result.logFile || 'n/a'}</code></td></tr>`);



            if (result.duration) {



                lines.push(`<tr><td><strong>Duration</strong></td><td>${result.duration}</td></tr>`);



            }



            lines.push(`<tr><td><strong>Database Size</strong></td><td>${restoredSizeText}</td></tr>`);







            const html = `



                <table class="table table-sm mb-0">



                    ${lines.join('')}



                </table>



            `;







            $resultBody.html(html);



            $resultRow.show();



        }







        function loadInitialData() {



            $.getJSON('ibRestoreToolAjax.php', { action: 'init' })



                .done(function(response) {



                    if (!response.success) {



                        throw new Error(response.error || 'Failed to load configuration');



                    }



                    populateDatabases(response.databases || []);



                    populateBackups(response.backups || []);



                    if (response.backupDir) {



                        $backupDirDisplay.text(response.backupDir);



                    }



                    updateStatusPrompt();



                })



                .fail(function(xhr, status, error) {



                    $statusPanel.html('<div class="alert alert-danger mb-0">Failed to load restore configuration.</div>');



                    showAlert('Unable to load restore configuration: ' + error, 'danger');



                    refreshActionButtons();



                });



        }function loadBackups() {



            $backupsContainer.html('<div class="text-center"><div class="spinner-border spinner-border-sm"></div></div>');



            $.getJSON('ibRestoreToolAjax.php', { action: 'backups' })



                .done(function(response) {



                    if (!response.success) {



                        throw new Error(response.error || 'Failed to load backups');



                    }



                    const selectedName = $backupSelect.val();



                    populateBackups(response.backups || []);



                    if (selectedName && backupsMap[selectedName]) {



                        $backupSelect.val(selectedName);



                        updateBackupDetails(backupsMap[selectedName]);



                    } else {



                        $backupSelect.val('');



                        updateBackupDetails(null);



                        updateStatusPrompt();



                    }



                })



                .fail(function(xhr, status, error) {



                    $backupsContainer.html('<div class="alert alert-danger mb-0">Failed to load backups: ' + error + '</div>');



                    showAlert('Unable to load backups: ' + error, 'danger');



                    refreshActionButtons();



                });



        }function getSelectedEnvironment() {



            return $('input[name="environment"]:checked').val();



        }







        function autofillTargetPath() {



            const dbId = $database.val();



            const env = getSelectedEnvironment();



            if (!dbId) {



                $targetPath.val('');



                return;



            }



            const db = databaseMap[dbId];



            if (db && db.paths && db.paths[env]) {



                $targetPath.val(db.paths[env]);



            }



        }







        function runStatusCheck() {

            const databaseId = $database.val();

            const backupName = $backupSelect.val();

            const targetPath = $targetPath.val().trim();

            const environment = getSelectedEnvironment();

            const database = getSelectedDatabase();

            const backup = getSelectedBackup();



            if (!databaseId) {

                showAlert('Select a database first.', 'warning');

                return;

            }

            if (!backupName) {

                showAlert('Select a backup file to restore.', 'warning');

                return;

            }

            if (!targetPath) {

                showAlert('Provide a destination path for the restore.', 'warning');

                return;

            }

            if (database && backup && !backupMatchesDatabase(backup, database)) {

                showAlert('The selected backup does not match the chosen database. Pick a matching backup before running the check.', 'warning');

                updateBackupDetails(backup);

                return;

            }



            clearAlert();

            latestStatus = null;

            refreshActionButtons();

            $statusPanel.html('<div class="text-center"><div class="spinner-border spinner-border-sm"></div><span class="ms-2">Checking...</span></div>');



            $.post('ibRestoreToolAjax.php', {

                action: 'check',

                database: databaseId,

                environment: environment,

                backup: backupName,

                targetPath: targetPath

            }, null, 'json')

                .done(function(response) {

                    if (!response.success) {

                        throw new Error(response.error || 'Check failed');

                    }

                    renderStatus(response.status);

                })

                .fail(function(xhr, status, error) {

                    $statusPanel.html('<div class="alert alert-danger mb-0">' + (error || 'Check failed') + '</div>');

                    refreshActionButtons();

                });

        }function runRestore() {

            const databaseId = $database.val();

            const backupName = $backupSelect.val();

            const targetPath = $targetPath.val().trim();

            const environment = getSelectedEnvironment();

            const database = getSelectedDatabase();

            const backup = getSelectedBackup();



            if (!databaseId) {

                showAlert('Select a database first.', 'warning');

                return;

            }

            if (!backupName) {

                showAlert('Select a backup file to restore.', 'warning');

                return;

            }

            if (!targetPath) {

                showAlert('Provide a destination path for the restore.', 'warning');

                return;

            }

            if (database && backup && !backupMatchesDatabase(backup, database)) {

                showAlert('The selected backup does not match the chosen database. Pick a matching backup before restoring.', 'warning');

                updateBackupDetails(backup);

                return;

            }



            const envLabel = environment.toUpperCase();



            if (!latestStatus || !latestStatus.canRestore) {

                const confirmMessage = environment === 'prod'

                    ? 'Prerequisites have not been confirmed. Restore to PROD anyway?'

                    : 'Prerequisites have not been confirmed. Restore anyway?';

                if (!confirm(confirmMessage)) {

                    return;

                }

            }



            if (environment === 'prod') {

                if (!confirm('WARNING: This will overwrite the PROD database at ' + targetPath + '. Are you absolutely sure?')) {

                    return;

                }

                if (!confirm('FINAL WARNING: You are about to restore to PROD. Click OK only if you intend to overwrite PROD.')) {

                    return;

                }

            }



clearAlert();

            $restoreBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Restoring...');

            $resultRow.hide();



            $.post('ibRestoreToolAjax.php', {

                action: 'restore',

                database: databaseId,

                environment: environment,

                backup: backupName,

                targetPath: targetPath

            }, null, 'json')

                .done(function(response) {

                    if (!response.success) {

                        throw new Error(response.error || 'Restore failed');

                    }

                    displayResult(response.result);

                    showAlert('Restore completed successfully.', 'success');

                    loadBackups();

                })

                .fail(function(xhr, status, error) {

                    showAlert('Restore failed: ' + error, 'danger');

                })

                .always(function() {

                    $restoreBtn.html('<i class="bi bi-arrow-counterclockwise"></i> Run Restore');

                    refreshActionButtons();

                    updateStatusPrompt();

                });

        }$('input[name="environment"]').on('change', function() {



            autofillTargetPath();



            latestStatus = null;



            $resultRow.hide();



            updateBackupDetails(getSelectedBackup());



            updateStatusPrompt();



        });







        $database.on('change', function() {



            autofillTargetPath();



            latestStatus = null;



            $resultRow.hide();



            updateBackupDetails(getSelectedBackup());



            updateStatusPrompt();



        });







        $targetPath.on('input', function() {



            latestStatus = null;



            updateStatusPrompt();



            refreshActionButtons();



        });







        $backupSelect.on('change', function() {



            const selected = $(this).val();



            const backup = selected ? backupsMap[selected] : null;



            latestStatus = null;



            updateBackupDetails(backup || null);



            if (backup && backup.databaseId && databaseMap[backup.databaseId]) {



                if ($database.val() !== backup.databaseId) {



                    $database.val(backup.databaseId);



                    autofillTargetPath();



                }



            }



            updateStatusPrompt();



        });







        $checkBtn.on('click', runStatusCheck);



        $restoreBtn.on('click', runRestore);



        $refreshBackups.on('click', function() {



            loadBackups();



        });







        loadInitialData();



    </script>



</body>



</html>







