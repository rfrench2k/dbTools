<?php
$pageTitle = 'Database Backup Tool';
include $_SERVER['DOCUMENT_ROOT'] . '/dbtools/common/Header.php';
?>
    <div class="container py-4">
        <div class="row mb-4">
            <div class="col">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="index.php">Database Tools</a></li>
                        <li class="breadcrumb-item active">Backup Tool</li>
                    </ol>
                </nav>
                <h1 class="h3">Database Backup Tool</h1>
                <p class="text-muted">Backup databases from any environment</p>
            </div>
        </div>

        <!-- Backup Form -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-database-fill-gear"></i> Create Backup</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label fw-bold">MySQL Version</label>
                            <div id="versionPills" class="d-flex flex-wrap gap-2">
                                <span class="text-muted">Loading...</span>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Environment</label>
                            <div id="envPills" class="d-flex flex-wrap gap-2">
                                <span class="text-muted">Select version first...</span>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Database</label>
                            <div id="databasePills" class="d-flex flex-wrap gap-2">
                                <span class="text-muted">Select environment first...</span>
                            </div>
                            <!-- Fallback dropdown for many databases -->
                            <select class="form-select mt-2" id="databaseSelect" style="display: none;">
                                <option value="">-- Select a database --</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <p class="small text-muted mb-2">
                                <i class="bi bi-info-circle"></i>
                                Backup will be saved to: <strong id="backupDirLabel">Loading...</strong><br>
                                Format: <code>DatabaseName-EnvName-YYYY-MM-DD-HHMM.sql</code>
                            </p>
                        </div>

                        <button class="btn btn-success btn-lg w-100" id="backupBtn" disabled>
                            <i class="bi bi-download"></i> Backup Database Now
                        </button>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card shadow-sm">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-info-circle"></i> System Status</h5>
                    </div>
                    <div class="card-body">
                        <div id="systemStatus">
                            <div class="text-muted small">Select an environment to check system status</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Alert Container -->
        <div id="alertContainer"></div>

        <!-- Backup Results -->
        <div id="backupResults" style="display: none;">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="bi bi-check-circle"></i> Backup Complete</h5>
                </div>
                <div class="card-body">
                    <div id="backupResultsContent"></div>
                </div>
            </div>
        </div>

        <!-- Recent Backups -->
        <div class="row">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-clock-history"></i> Recent Backups
                            <button class="btn btn-sm btn-outline-secondary float-end" id="refreshBtn">
                                <i class="bi bi-arrow-clockwise"></i> Refresh
                            </button>
                        </h5>
                    </div>
                    <div class="card-body">
                        <div id="recentBackups">
                            <div class="text-center">
                                <div class="spinner-border spinner-border-sm" role="status"></div>
                                <span class="ms-2">Loading...</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let allEnvironments = [];
        let currentEnvId = '';
        let currentEnvLabel = '';

        let currentDatabase = '';

        $(document).ready(function() {
            loadEnvironments();
            loadRecentBackups();

            // Version pill click handler
            $(document).on('click', '#versionPills .btn', function() {
                $('#versionPills .btn').removeClass('active');
                $(this).addClass('active');
                updateEnvironmentPills($(this).data('version'));
            });

            // Environment pill click handler
            $(document).on('click', '#envPills .btn', function() {
                $('#envPills .btn').removeClass('active');
                $(this).addClass('active');
                const envId = $(this).data('env');
                currentEnvId = envId;
                const env = allEnvironments.find(e => e.id === envId);
                currentEnvLabel = env ? env.label : envId;
                checkSystem(envId);
                loadDatabases(envId);
            });

            // Database pill click handler
            $(document).on('click', '#databasePills .btn', function() {
                $('#databasePills .btn').removeClass('active');
                $(this).addClass('active');
                currentDatabase = $(this).data('db');
                $('#backupBtn').prop('disabled', false);
            });

            // Database dropdown handler (fallback for many databases)
            $('#databaseSelect').change(function() {
                currentDatabase = $(this).val();
                $('#backupBtn').prop('disabled', !currentDatabase);
            });

            $('#backupBtn').click(function() {
                runBackup();
            });

            $('#refreshBtn').click(function() {
                loadRecentBackups();
            });
        });

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
                $('#envPills').html('<span class="text-muted">Select version first...</span>');
                $('#databasePills').html('<span class="text-muted">Select environment first...</span>');
                $('#databaseSelect').hide();
                $('#backupBtn').prop('disabled', true);
                currentEnvId = '';
                currentEnvLabel = '';
                return;
            }

            // Convert to string - jQuery .data() converts "8.4" to number 8.4
            const versionStr = String(version);
            const envs = allEnvironments.filter(e => e.version === versionStr);
            let envPillsHtml = '';
            envs.forEach(env => {
                envPillsHtml += `<button type="button" class="btn btn-outline-secondary" data-env="${env.id}">${env.label}</button>`;
            });

            $('#envPills').html(envPillsHtml);
            $('#databasePills').html('<span class="text-muted">Select environment first...</span>');
            $('#databaseSelect').hide();
            $('#backupBtn').prop('disabled', true);
            currentEnvId = '';
            currentEnvLabel = '';
            $('#systemStatus').html('<div class="text-muted small">Select an environment to check system status</div>');
        }

        function checkSystem(envId) {
            $('#systemStatus').html('<div class="text-center"><div class="spinner-border spinner-border-sm"></div> Checking...</div>');

            $.ajax({
                url: 'ajax-backup-tool.php',
                method: 'POST',
                data: { action: 'check_system', env: envId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        displaySystemStatus(response);
                    } else {
                        $('#systemStatus').html('<div class="alert alert-danger mb-0">' + response.error + '</div>');
                    }
                },
                error: function() {
                    $('#systemStatus').html('<div class="alert alert-danger mb-0">Failed to check system status</div>');
                }
            });
        }

        function displaySystemStatus(data) {
            let html = '<div class="small">';

            html += '<div class="mb-2">';
            html += '<i class="bi bi-folder"></i> <strong>Backup Directory:</strong><br>';
            html += '<code>' + data.backupDir + '</code> ';
            html += data.backupDirExists ? '<span class="badge bg-success">Exists</span>' : '<span class="badge bg-danger">Not Found</span>';
            html += '</div>';

            // Update the backup dir label
            $('#backupDirLabel').text(data.backupDir);

            html += '<div class="mb-2">';
            html += '<i class="bi bi-tools"></i> <strong>mysqldump:</strong> ';
            html += data.mysqldumpAvailable ? '<span class="badge bg-success">Available</span>' : '<span class="badge bg-danger">Not Found</span>';
            if (data.mysqldumpPath) {
                html += '<br><small class="text-muted">' + data.mysqldumpPath + '</small>';
            }
            html += '</div>';

            html += '<div>';
            html += '<i class="bi bi-hdd-network"></i> <strong>Connection:</strong> ';
            html += data.envConnection.success ? '<span class="badge bg-success">Connected</span>' : '<span class="badge bg-danger">Failed</span>';
            if (data.envConnection.label) {
                html += ' <small class="text-muted">(' + data.envConnection.label + ')</small>';
            }
            html += '</div>';

            html += '</div>';

            const allGood = data.backupDirExists && data.mysqldumpAvailable && data.envConnection.success;
            if (!allGood) {
                html += '<div class="alert alert-warning mt-3 mb-0 small">';
                html += '<i class="bi bi-exclamation-triangle"></i> System not ready. Fix issues above.';
                html += '</div>';
            }

            $('#systemStatus').html(html);
        }

        function loadDatabases(envId) {
            $('#databasePills').html('<span class="text-muted">Loading databases...</span>');
            $('#databaseSelect').hide();

            $.ajax({
                url: 'ajax-get-databases.php',
                method: 'GET',
                data: { env: envId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        const databases = response.databases;
                        const maxPillCount = 10; // Show pills if <= 10 databases

                        if (databases.length <= maxPillCount) {
                            // Show as pills
                            let pillsHtml = '';
                            databases.forEach(db => {
                                pillsHtml += `<button type="button" class="btn btn-outline-info" data-db="${db.name}">${db.name}</button>`;
                            });
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
                        $('#backupBtn').prop('disabled', true);
                        currentDatabase = '';
                    }
                }
            });
        }

        function runBackup() {
            const database = currentDatabase || $('#databaseSelect').val();
            if (!database || !currentEnvId) {
                alert('Please select an environment and database');
                return;
            }

            if (!confirm('Create backup of database: ' + database + ' from ' + currentEnvLabel + '?')) {
                return;
            }

            $('#backupBtn').prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Creating backup...');
            $('#backupResults').hide();
            $('#alertContainer').empty();

            $.ajax({
                url: 'ajax-backup-tool.php',
                method: 'POST',
                data: {
                    action: 'backup',
                    database: database,
                    env: currentEnvId
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        displayBackupSuccess(response);
                        loadRecentBackups();
                    } else {
                        showAlert('Backup failed: ' + response.error, 'danger');
                    }
                },
                error: function(xhr, status, error) {
                    showAlert('Error: ' + error, 'danger');
                },
                complete: function() {
                    $('#backupBtn').prop('disabled', false).html('<i class="bi bi-download"></i> Backup Database Now');
                }
            });
        }

        function displayBackupSuccess(data) {
            let html = '<table class="table table-sm mb-0">';
            html += '<tr><td><strong>Database:</strong></td><td>' + data.database + '</td></tr>';
            html += '<tr><td><strong>Backup File:</strong></td><td><code>' + data.filename + '</code></td></tr>';
            html += '<tr><td><strong>File Size:</strong></td><td>' + data.fileSize + '</td></tr>';
            html += '<tr><td><strong>Location:</strong></td><td><code>' + data.filepath + '</code></td></tr>';
            html += '<tr><td><strong>Created:</strong></td><td>' + data.timestamp + '</td></tr>';
            html += '</table>';

            $('#backupResultsContent').html(html);
            $('#backupResults').show();
            showAlert('Backup created successfully!', 'success');
        }

        function loadRecentBackups() {
            $('#recentBackups').html('<div class="text-center"><div class="spinner-border spinner-border-sm"></div></div>');

            $.ajax({
                url: 'ajax-backup-tool.php',
                method: 'POST',
                data: { action: 'list_backups' },
                dataType: 'json',
                success: function(response) {
                    if (response.success && response.backups.length > 0) {
                        displayRecentBackups(response.backups);
                    } else {
                        $('#recentBackups').html('<p class="text-muted mb-0">No backups found</p>');
                    }
                }
            });
        }

        function displayRecentBackups(backups) {
            // Show only last 10
            backups = backups.slice(0, 10);

            let html = '<div class="table-responsive"><table class="table table-sm table-hover mb-0">';
            html += '<thead><tr><th>Filename</th><th>Size</th><th>Created</th></tr></thead><tbody>';

            backups.forEach(backup => {
                html += '<tr>';
                html += '<td><code>' + backup.filename + '</code></td>';
                html += '<td>' + backup.size + '</td>';
                html += '<td>' + backup.created + '</td>';
                html += '</tr>';
            });

            html += '</tbody></table></div>';
            $('#recentBackups').html(html);
        }

        function showAlert(message, type) {
            const html = '<div class="alert alert-' + type + ' alert-dismissible fade show" role="alert">' +
                message +
                '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            $('#alertContainer').html(html);

            setTimeout(function() {
                $('.alert').alert('close');
            }, 5000);
        }
    </script>
</body>
</html>
