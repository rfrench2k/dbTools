<?php
$pageTitle = 'Database Replication Tool';
include $_SERVER['DOCUMENT_ROOT'] . '/dbtools/common/Header.php';
?>
    <!-- Loading Spinner -->
    <div class="spinner-overlay" id="loadingSpinner">
        <div class="text-center">
            <div class="spinner-border text-light" role="status" style="width: 4rem; height: 4rem;">
                <span class="visually-hidden">Loading...</span>
            </div>
            <div class="text-light mt-3" id="loadingText">Loading...</div>
        </div>
    </div>

    <div class="container py-4">
        <!-- Header -->
        <div class="row mb-4">
            <div class="col">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="index.php">Database Tools</a></li>
                        <li class="breadcrumb-item active">Database Replication</li>
                    </ol>
                </nav>
                <h1 class="h3">Database Replication Tool</h1>
                <p class="text-muted">Copy database from one environment to another (overwrites target data)</p>
            </div>
        </div>

        <!-- Warning Alert -->
        <div class="alert alert-warning" id="warningAlert">
            <i class="bi bi-exclamation-triangle"></i>
            <strong>Warning:</strong> This tool will <strong>OVERWRITE</strong> the target database with source data.
            All existing data in target will be lost. A backup of target will be created first.
        </div>

        <!-- Environment and Database Selection -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h5 class="card-title">Select Environments and Database</h5>

                        <!-- MySQL Version -->
                        <div class="mb-4">
                            <label class="form-label fw-bold">MySQL Version</label>
                            <div id="versionPills" class="d-flex flex-wrap gap-2">
                                <span class="text-muted">Loading...</span>
                            </div>
                            <div class="form-text">Select MySQL version (must match for replication)</div>
                        </div>

                        <!-- Source and Target Environments -->
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Source Environment <small class="text-muted fw-normal">(copy FROM)</small></label>
                                <div id="sourceEnvPills" class="d-flex flex-wrap gap-2">
                                    <span class="text-muted">Select version first...</span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Target Environment <small class="text-muted fw-normal">(copy TO)</small></label>
                                <div id="targetEnvPills" class="d-flex flex-wrap gap-2">
                                    <span class="text-muted">Select version first...</span>
                                </div>
                            </div>
                        </div>

                        <!-- Database Selection -->
                        <div class="mb-4">
                            <label class="form-label fw-bold">Database</label>
                            <div id="databasePills" class="d-flex flex-wrap gap-2">
                                <span class="text-muted">Select both environments first...</span>
                            </div>
                            <!-- Fallback dropdown for many databases -->
                            <select class="form-select mt-2" id="databaseSelect" style="display: none;">
                                <option value="">-- Select a database --</option>
                            </select>
                        </div>

                        <button class="btn btn-primary" id="checkBtn" disabled>
                            <i class="bi bi-search"></i> Check Database Status
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Alert Container -->
        <div id="alertContainer"></div>

        <!-- Database Status -->
        <div id="statusSection" style="display: none;">
            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Database Status</h5>
                </div>
                <div class="card-body">
                    <div id="statusContent"></div>
                </div>
            </div>
        </div>

        <!-- Replication Confirmation -->
        <div id="confirmSection" style="display: none;">
            <div class="card shadow-sm border-danger mb-4">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0"><i class="bi bi-exclamation-octagon"></i> Confirm Replication</h5>
                </div>
                <div class="card-body">
                    <div id="confirmContent"></div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="confirmCheckbox">
                        <label class="form-check-label" for="confirmCheckbox">
                            <strong>I understand that <span id="confirmTargetLabel">target</span> database will be completely overwritten with <span id="confirmSourceLabel">source</span> data</strong>
                        </label>
                    </div>

                    <button class="btn btn-danger btn-lg" id="executeBtn" disabled>
                        <i class="bi bi-arrow-repeat"></i> Execute Replication (<span id="executeBtnSourceLabel">Source</span> → <span id="executeBtnTargetLabel">Target</span>)
                    </button>
                    <button class="btn btn-secondary btn-lg ms-2" id="cancelBtn">
                        <i class="bi bi-x-circle"></i> Cancel
                    </button>
                </div>
            </div>
        </div>

        <!-- Progress Section -->
        <div id="progressSection" style="display: none;">
            <div class="card shadow-sm">
                <div class="card-header">
                    <h5 class="mb-0">Replication Progress</h5>
                </div>
                <div class="card-body">
                    <div id="progressContent"></div>
                </div>
            </div>
        </div>

        <!-- Results Section -->
        <div id="resultsSection" style="display: none;">
            <div class="card shadow-sm">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="bi bi-check-circle"></i> Replication Complete</h5>
                </div>
                <div class="card-body">
                    <div id="resultsContent"></div>
                    <button class="btn btn-primary mt-3" onclick="location.reload()">
                        Start New Replication
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentDatabase = '';
        let currentSourceEnv = '';
        let currentTargetEnv = '';
        let sourceEnvLabel = 'Source';
        let targetEnvLabel = 'Target';
        let allEnvironments = [];

        $(document).ready(function() {
            loadEnvironments();

            // Version pill click handler
            $(document).on('click', '#versionPills .btn', function() {
                $('#versionPills .btn').removeClass('active');
                $(this).addClass('active');
                updateEnvironmentPills($(this).data('version'));
            });

            // Source environment pill click handler
            $(document).on('click', '#sourceEnvPills .btn', function() {
                $('#sourceEnvPills .btn').removeClass('active');
                $(this).addClass('active');
                updateDatabaseSelection();
            });

            // Target environment pill click handler
            $(document).on('click', '#targetEnvPills .btn', function() {
                $('#targetEnvPills .btn').removeClass('active');
                $(this).addClass('active');
                updateDatabaseSelection();
            });

            // Database pill click handler
            $(document).on('click', '#databasePills .btn', function() {
                $('#databasePills .btn').removeClass('active');
                $(this).addClass('active');
                currentDatabase = $(this).data('db');
                $('#checkBtn').prop('disabled', false);
                hideAllSections();
            });

            // Database dropdown handler (fallback for many databases)
            $('#databaseSelect').change(function() {
                currentDatabase = $(this).val();
                $('#checkBtn').prop('disabled', !currentDatabase);
                hideAllSections();
            });

            $('#checkBtn').click(function() {
                checkDatabase();
            });

            $('#confirmCheckbox').change(function() {
                $('#executeBtn').prop('disabled', !$(this).is(':checked'));
            });

            $('#executeBtn').click(function() {
                executeReplication();
            });

            $('#cancelBtn').click(function() {
                hideAllSections();
            });
        });

        function showLoading(text = 'Loading...') {
            $('#loadingText').text(text);
            $('#loadingSpinner').addClass('show');
        }

        function hideLoading() {
            $('#loadingSpinner').removeClass('show');
        }

        function showAlert(message, type = 'info') {
            const alertHtml = `
                <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
            $('#alertContainer').html(alertHtml);
        }

        function hideAllSections() {
            $('#statusSection').hide();
            $('#confirmSection').hide();
            $('#progressSection').hide();
            $('#resultsSection').hide();
            $('#alertContainer').empty();
        }

        function loadEnvironments() {
            $.ajax({
                url: 'ajax-get-databases.php',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        allEnvironments = response.environments;

                        // Render version pills
                        let versionPillsHtml = '';
                        response.versions.forEach(ver => {
                            versionPillsHtml += `<button type="button" class="btn btn-outline-primary" data-version="${ver.id}">${ver.label}</button>`;
                        });
                        $('#versionPills').html(versionPillsHtml);
                    }
                }
            });
        }

        function updateEnvironmentPills(version) {
            if (!version) {
                $('#sourceEnvPills').html('<span class="text-muted">Select version first...</span>');
                $('#targetEnvPills').html('<span class="text-muted">Select version first...</span>');
                $('#databasePills').html('<span class="text-muted">Select both environments first...</span>');
                $('#databaseSelect').hide();
                $('#checkBtn').prop('disabled', true);
                return;
            }

            // Convert to string - jQuery .data() converts "8.4" to number 8.4
            const versionStr = String(version);
            const envs = allEnvironments.filter(e => e.version === versionStr);
            let sourcePillsHtml = '';
            let targetPillsHtml = '';

            envs.forEach(env => {
                sourcePillsHtml += `<button type="button" class="btn btn-outline-secondary" data-env="${env.id}">${env.label}</button>`;
                targetPillsHtml += `<button type="button" class="btn btn-outline-secondary" data-env="${env.id}">${env.label}</button>`;
            });

            $('#sourceEnvPills').html(sourcePillsHtml);
            $('#targetEnvPills').html(targetPillsHtml);
            $('#databasePills').html('<span class="text-muted">Select both environments first...</span>');
            $('#databaseSelect').hide();
            $('#checkBtn').prop('disabled', true);
        }

        function updateDatabaseSelection() {
            const sourceEnv = $('#sourceEnvPills .btn.active').data('env');
            const targetEnv = $('#targetEnvPills .btn.active').data('env');

            if (!sourceEnv || !targetEnv) {
                $('#databasePills').html('<span class="text-muted">Select both environments first...</span>');
                $('#databaseSelect').hide();
                $('#checkBtn').prop('disabled', true);
                return;
            }

            if (sourceEnv === targetEnv) {
                showAlert('Source and target environments must be different', 'warning');
                return;
            }

            currentSourceEnv = sourceEnv;
            currentTargetEnv = targetEnv;

            // Get labels
            const sourceEnvData = allEnvironments.find(e => e.id === sourceEnv);
            const targetEnvData = allEnvironments.find(e => e.id === targetEnv);
            sourceEnvLabel = sourceEnvData ? sourceEnvData.label : 'Source';
            targetEnvLabel = targetEnvData ? targetEnvData.label : 'Target';

            // Update dynamic labels
            updateDynamicLabels();

            showLoading('Loading databases...');
            $.ajax({
                url: 'ajax-get-databases.php',
                method: 'GET',
                data: { sourceEnv: sourceEnv, targetEnv: targetEnv },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Filter to only databases that exist in both environments
                        const databases = response.databases.filter(db => db.status === 'both');
                        const maxPillCount = 10; // Show pills if <= 10 databases

                        if (databases.length <= maxPillCount) {
                            // Show as pills
                            let pillsHtml = '';
                            databases.forEach(db => {
                                pillsHtml += `<button type="button" class="btn btn-outline-info" data-db="${db.name}">${db.name}</button>`;
                            });
                            if (databases.length === 0) {
                                pillsHtml = '<span class="text-muted">No common databases found</span>';
                            }
                            $('#databasePills').html(pillsHtml);
                            $('#databaseSelect').hide();
                        } else {
                            // Show as dropdown for many databases
                            $('#databasePills').html('<span class="text-muted">Many databases - use dropdown below</span>');
                            const select = $('#databaseSelect');
                            select.html('<option value="">-- Select a database --</option>');
                            databases.forEach(db => {
                                select.append(`<option value="${db.name}">${db.name}</option>`);
                            });
                            select.show();
                        }
                        $('#checkBtn').prop('disabled', true);
                        currentDatabase = '';
                    }
                },
                complete: function() {
                    hideLoading();
                }
            });
        }

        function updateDynamicLabels() {
            $('#confirmSourceLabel').text(sourceEnvLabel);
            $('#confirmTargetLabel').text(targetEnvLabel);
            $('#executeBtnSourceLabel').text(sourceEnvLabel);
            $('#executeBtnTargetLabel').text(targetEnvLabel);
        }

        function checkDatabase() {
            // Use currentDatabase (set by pill click) or fallback to dropdown
            if (!currentDatabase) {
                currentDatabase = $('#databaseSelect').val();
            }
            if (!currentDatabase) return;

            showLoading('Checking database status...');
            hideAllSections();

            $.ajax({
                url: 'ajax-replicate.php',
                method: 'POST',
                data: {
                    action: 'check',
                    database: currentDatabase,
                    sourceEnv: currentSourceEnv,
                    targetEnv: currentTargetEnv
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        displayStatus(response);
                    } else {
                        showAlert('Error: ' + response.error, 'danger');
                    }
                },
                error: function(xhr, status, error) {
                    showAlert('Failed to check database: ' + error, 'danger');
                },
                complete: function() {
                    hideLoading();
                }
            });
        }

        function displayStatus(data) {
            let html = '<table class="table table-sm">';
            html += `<tr><td><strong>Database:</strong></td><td>${data.database}</td></tr>`;
            html += `<tr><td><strong>Exists in ${sourceEnvLabel}:</strong></td><td>${data.existsInSource ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-danger">No</span>'}</td></tr>`;
            html += `<tr><td><strong>Exists in ${targetEnvLabel}:</strong></td><td>${data.existsInTarget ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-danger">No</span>'}</td></tr>`;
            html += `<tr><td><strong>${sourceEnvLabel} Row Count:</strong></td><td>${data.sourceRowCount ? data.sourceRowCount.toLocaleString() : 'N/A'}</td></tr>`;
            html += `<tr><td><strong>${targetEnvLabel} Row Count:</strong></td><td>${data.targetRowCount ? data.targetRowCount.toLocaleString() : 'N/A'}</td></tr>`;
            html += '</table>';

            $('#statusContent').html(html);
            $('#statusSection').show();

            if (data.canReplicate) {
                displayConfirmation(data);
            } else {
                showAlert(data.message || 'Database cannot be replicated', 'warning');
            }
        }

        function displayConfirmation(data) {
            let html = '<div class="alert alert-danger">';
            html += '<h6><i class="bi bi-exclamation-triangle"></i> This action will:</h6>';
            html += '<ol>';
            html += `<li>Create a backup of ${targetEnvLabel} database</li>`;
            html += `<li>Drop and recreate ${targetEnvLabel} database</li>`;
            html += `<li>Copy all data from ${sourceEnvLabel} (${data.sourceRowCount ? data.sourceRowCount.toLocaleString() : '0'} rows)</li>`;
            html += `<li>Overwrite all ${targetEnvLabel} data</li>`;
            html += '</ol>';
            html += '</div>';

            $('#confirmContent').html(html);
            $('#confirmSection').show();
            $('#confirmCheckbox').prop('checked', false);
            $('#executeBtn').prop('disabled', true);
        }

        function executeReplication() {
            if (!confirm(`ARE YOU ABSOLUTELY SURE you want to overwrite ${targetEnvLabel} database "${currentDatabase}" with ${sourceEnvLabel} data?`)) {
                return;
            }

            showLoading('Executing replication...');
            hideAllSections();

            $('#progressSection').show();
            updateProgress('Starting replication...', 'info');

            $.ajax({
                url: 'ajax-replicate.php',
                method: 'POST',
                data: {
                    action: 'replicate',
                    database: currentDatabase,
                    sourceEnv: currentSourceEnv,
                    targetEnv: currentTargetEnv
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        displayResults(response);
                    } else {
                        updateProgress('Replication failed: ' + response.error, 'danger');
                        showAlert('Replication failed: ' + response.error, 'danger');
                    }
                },
                error: function(xhr, status, error) {
                    updateProgress('Error: ' + error, 'danger');
                    showAlert('Replication error: ' + error, 'danger');
                },
                complete: function() {
                    hideLoading();
                }
            });
        }

        function updateProgress(message, type = 'info') {
            const timestamp = new Date().toLocaleTimeString();
            const html = `
                <div class="alert alert-${type} mb-2">
                    <small class="text-muted">[${timestamp}]</small> ${message}
                </div>
            `;
            $('#progressContent').append(html);
        }

        function displayResults(data) {
            let html = '<div class="alert alert-success">';
            html += '<h5><i class="bi bi-check-circle"></i> Replication Successful!</h5>';
            html += '</div>';

            html += '<table class="table table-sm">';
            html += `<tr><td><strong>Database:</strong></td><td>${data.database}</td></tr>`;
            html += `<tr><td><strong>Source:</strong></td><td>${data.sourceEnv || sourceEnvLabel}</td></tr>`;
            html += `<tr><td><strong>Target:</strong></td><td>${data.targetEnv || targetEnvLabel}</td></tr>`;
            if (data.targetBackup) {
                html += `<tr><td><strong>Target Backup:</strong></td><td>${data.targetBackup}</td></tr>`;
            }
            if (data.sourceBackup) {
                html += `<tr><td><strong>Source Dump:</strong></td><td>${data.sourceBackup}</td></tr>`;
            }
            html += `<tr><td><strong>Duration:</strong></td><td>${data.duration || 'N/A'}</td></tr>`;
            html += '</table>';

            if (data.steps && data.steps.length > 0) {
                html += '<h6>Execution Steps:</h6><ul>';
                data.steps.forEach(step => {
                    const icon = step.success ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-x-circle text-danger"></i>';
                    html += `<li>${icon} ${step.message}</li>`;
                });
                html += '</ul>';
            }

            $('#resultsContent').html(html);
            $('#progressSection').hide();
            $('#resultsSection').show();
        }
    </script>
</body>
</html>
