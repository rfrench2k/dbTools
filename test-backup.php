<?php
$pageTitle = 'Backup System Test';
include $_SERVER['DOCUMENT_ROOT'] . '/dbtools/common/Header.php';
?>
    <div class="container py-4">
        <div class="row mb-4">
            <div class="col">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="index.php">Database Tools</a></li>
                        <li class="breadcrumb-item active">Backup System Test</li>
                    </ol>
                </nav>
                <h1 class="h3">Backup System Test</h1>
                <p class="text-muted">Test and verify the backup functionality before using it on production data</p>
            </div>
        </div>

        <!-- System Check Results -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-clipboard-check"></i> System Environment Check</h5>
                    </div>
                    <div class="card-body">
                        <div id="systemCheck">
                            <div class="text-center">
                                <div class="spinner-border" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <p class="mt-2">Checking system...</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Test Backup Section -->
        <div class="row mb-4" id="testBackupSection" style="display: none;">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="bi bi-database-fill-check"></i> Test Backup</h5>
                    </div>
                    <div class="card-body">
                        <p>Run a test backup to verify the system is working correctly.</p>

                        <div class="mb-3">
                            <label for="testDatabase" class="form-label">Select Database to Test</label>
                            <select class="form-select" id="testDatabase">
                                <option value="">Loading databases...</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Backup Type</label>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="testBackupType" id="testBackupFull" value="full" checked>
                                <label class="form-check-label" for="testBackupFull">
                                    Full database backup
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="testBackupType" id="testBackupSingleTable" value="single">
                                <label class="form-check-label" for="testBackupSingleTable">
                                    Single table backup (for testing)
                                </label>
                            </div>
                        </div>

                        <div class="mb-3" id="tableSelectDiv" style="display: none;">
                            <label for="testTable" class="form-label">Select Table</label>
                            <select class="form-select" id="testTable">
                                <option value="">Select a database first...</option>
                            </select>
                        </div>

                        <button class="btn btn-success" id="runTestBackupBtn" disabled>
                            <i class="bi bi-play-fill"></i> Run Test Backup
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Backup Results -->
        <div class="row mb-4" id="backupResultsSection" style="display: none;">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-file-earmark-text"></i> Backup Results</h5>
                    </div>
                    <div class="card-body">
                        <div id="backupResults"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Existing Backups -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-folder"></i> Existing Backups
                            <button class="btn btn-sm btn-outline-secondary float-end" id="refreshBackupsBtn">
                                <i class="bi bi-arrow-clockwise"></i> Refresh
                            </button>
                        </h5>
                    </div>
                    <div class="card-body">
                        <div id="existingBackups">
                            <div class="text-center">
                                <div class="spinner-border" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
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
        $(document).ready(function() {
            checkSystem();
            loadExistingBackups();

            $('#testDatabase').change(function() {
                const db = $(this).val();
                if (db) {
                    loadTables(db);
                    $('#runTestBackupBtn').prop('disabled', false);
                } else {
                    $('#runTestBackupBtn').prop('disabled', true);
                }
            });

            $('input[name="testBackupType"]').change(function() {
                if ($(this).val() === 'single') {
                    $('#tableSelectDiv').show();
                } else {
                    $('#tableSelectDiv').hide();
                }
            });

            $('#runTestBackupBtn').click(function() {
                runTestBackup();
            });

            $('#refreshBackupsBtn').click(function() {
                loadExistingBackups();
            });
        });

        function checkSystem() {
            $.ajax({
                url: 'ajax-test-backup.php',
                method: 'POST',
                data: { action: 'check_system' },
                dataType: 'json',
                success: function(response) {
                    displaySystemCheck(response);
                },
                error: function(xhr, status, error) {
                    $('#systemCheck').html(`
                        <div class="alert alert-danger">
                            <strong>Error:</strong> Failed to check system: ${error}
                        </div>
                    `);
                }
            });
        }

        function displaySystemCheck(data) {
            let html = '<table class="table table-sm">';

            html += '<tr>';
            html += '<td><strong>Backup Directory:</strong></td>';
            html += `<td><code>${data.backupDir}</code></td>`;
            html += `<td>${data.backupDirExists ? '<span class="badge bg-success">Exists</span>' : '<span class="badge bg-danger">Does NOT exist</span>'}</td>`;
            html += '</tr>';

            html += '<tr>';
            html += '<td><strong>Directory Writable:</strong></td>';
            html += `<td></td>`;
            html += `<td>${data.backupDirWritable ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-danger">No - Check permissions!</span>'}</td>`;
            html += '</tr>';

            html += '<tr>';
            html += '<td><strong>mysqldump Available:</strong></td>';
            html += `<td><code>${data.mysqldumpPath || 'Not found'}</code></td>`;
            html += `<td>${data.mysqldumpAvailable ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-danger">No - Need to install or add to PATH!</span>'}</td>`;
            html += '</tr>';

            html += '<tr>';
            html += '<td><strong>TEST Connection:</strong></td>';
            html += `<td>${data.testConnection.host}</td>`;
            html += `<td>${data.testConnection.success ? '<span class="badge bg-success">Connected</span>' : '<span class="badge bg-danger">Failed: ' + data.testConnection.error + '</span>'}</td>`;
            html += '</tr>';

            html += '<tr>';
            html += '<td><strong>PROD Connection:</strong></td>';
            html += `<td>${data.prodConnection.host}</td>`;
            html += `<td>${data.prodConnection.success ? '<span class="badge bg-success">Connected</span>' : '<span class="badge bg-danger">Failed: ' + data.prodConnection.error + '</span>'}</td>`;
            html += '</tr>';

            html += '</table>';

            // Overall status
            const allGood = data.backupDirExists && data.backupDirWritable && data.mysqldumpAvailable &&
                           data.testConnection.success && data.prodConnection.success;

            if (allGood) {
                html += '<div class="alert alert-success"><i class="bi bi-check-circle"></i> <strong>All systems ready!</strong> You can proceed with testing backups.</div>';
                $('#testBackupSection').show();
                loadDatabases();
            } else {
                html += '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <strong>System not ready!</strong> Please fix the issues above before proceeding.</div>';

                // Provide specific help
                if (!data.mysqldumpAvailable) {
                    html += '<div class="alert alert-warning">';
                    html += '<strong>How to fix mysqldump:</strong><br>';
                    html += '1. If MySQL is installed, find mysqldump.exe (usually in C:\\Program Files\\MySQL\\MySQL Server X.X\\bin\\)<br>';
                    html += '2. Add that directory to your system PATH, OR<br>';
                    html += '3. We can modify the code to use the full path to mysqldump.exe';
                    html += '</div>';
                }
            }

            $('#systemCheck').html(html);
        }

        function loadDatabases() {
            $.ajax({
                url: 'ajax-get-databases.php',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        let options = '<option value="">-- Select a database --</option>';
                        response.databases.forEach(db => {
                            if (db.inProd) {  // Only show databases that exist in PROD
                                options += `<option value="${db.name}">${db.name} (PROD)</option>`;
                            }
                        });
                        $('#testDatabase').html(options);
                    }
                }
            });
        }

        function loadTables(database) {
            $.ajax({
                url: 'ajax-test-backup.php',
                method: 'POST',
                data: { action: 'get_tables', database: database },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        let options = '<option value="">-- Select a table --</option>';
                        response.tables.forEach(table => {
                            options += `<option value="${table}">${table}</option>`;
                        });
                        $('#testTable').html(options);
                    }
                }
            });
        }

        function runTestBackup() {
            const database = $('#testDatabase').val();
            const backupType = $('input[name="testBackupType"]:checked').val();
            const table = $('#testTable').val();

            if (!database) {
                alert('Please select a database');
                return;
            }

            if (backupType === 'single' && !table) {
                alert('Please select a table');
                return;
            }

            $('#runTestBackupBtn').prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Running backup...');

            $.ajax({
                url: 'ajax-test-backup.php',
                method: 'POST',
                data: {
                    action: 'test_backup',
                    database: database,
                    backupType: backupType,
                    table: table
                },
                dataType: 'json',
                success: function(response) {
                    displayBackupResults(response);
                    loadExistingBackups();
                },
                error: function(xhr, status, error) {
                    $('#backupResults').html(`
                        <div class="alert alert-danger">
                            <strong>Error:</strong> ${error}
                        </div>
                    `);
                    $('#backupResultsSection').show();
                },
                complete: function() {
                    $('#runTestBackupBtn').prop('disabled', false).html('<i class="bi bi-play-fill"></i> Run Test Backup');
                }
            });
        }

        function displayBackupResults(result) {
            let html = '';

            if (result.success) {
                html += '<div class="alert alert-success"><i class="bi bi-check-circle"></i> <strong>Backup successful!</strong></div>';

                html += '<table class="table table-sm">';
                html += `<tr><td><strong>Backup File:</strong></td><td><code>${result.backupFile}</code></td></tr>`;
                html += `<tr><td><strong>File Size:</strong></td><td>${result.fileSize}</td></tr>`;
                html += `<tr><td><strong>Created:</strong></td><td>${result.timestamp}</td></tr>`;
                html += `<tr><td><strong>Command Used:</strong></td><td><code>${result.command}</code></td></tr>`;
                html += '</table>';

                if (result.preview) {
                    html += '<h6>File Preview (first 50 lines):</h6>';
                    html += `<div class="code-block" style="max-height: 300px; overflow-y: auto;"><pre>${escapeHtml(result.preview)}</pre></div>`;
                }
            } else {
                html += `<div class="alert alert-danger"><i class="bi bi-x-circle"></i> <strong>Backup failed!</strong><br>${result.error}</div>`;

                if (result.output) {
                    html += '<h6>Error Output:</h6>';
                    html += `<div class="code-block"><pre>${escapeHtml(result.output)}</pre></div>`;
                }
            }

            $('#backupResults').html(html);
            $('#backupResultsSection').show();
        }

        function loadExistingBackups() {
            $('#existingBackups').html('<div class="text-center"><div class="spinner-border" role="status"></div></div>');

            $.ajax({
                url: 'ajax-test-backup.php',
                method: 'POST',
                data: { action: 'list_backups' },
                dataType: 'json',
                success: function(response) {
                    displayExistingBackups(response);
                }
            });
        }

        function displayExistingBackups(data) {
            if (!data.success || data.backups.length === 0) {
                $('#existingBackups').html('<p class="text-muted">No backups found in backup directory.</p>');
                return;
            }

            let html = '<div class="table-responsive"><table class="table table-sm table-hover">';
            html += '<thead><tr><th>Filename</th><th>Size</th><th>Created</th><th>Actions</th></tr></thead>';
            html += '<tbody>';

            data.backups.forEach(backup => {
                html += '<tr>';
                html += `<td><code>${backup.filename}</code></td>`;
                html += `<td>${backup.size}</td>`;
                html += `<td>${backup.created}</td>`;
                html += `<td>
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteBackup('${backup.filename}')">
                        <i class="bi bi-trash"></i> Delete
                    </button>
                </td>`;
                html += '</tr>';
            });

            html += '</tbody></table></div>';
            html += `<p class="text-muted small">Total backups: ${data.backups.length} | Total size: ${data.totalSize}</p>`;

            $('#existingBackups').html(html);
        }

        function deleteBackup(filename) {
            if (!confirm(`Are you sure you want to delete backup: ${filename}?`)) {
                return;
            }

            $.ajax({
                url: 'ajax-test-backup.php',
                method: 'POST',
                data: {
                    action: 'delete_backup',
                    filename: filename
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        alert('Backup deleted successfully');
                        loadExistingBackups();
                    } else {
                        alert('Error deleting backup: ' + response.error);
                    }
                }
            });
        }

        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, m => map[m]);
        }
    </script>
</body>
</html>
