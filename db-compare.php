<?php
$pageTitle = 'Database Comparison Tool';
include $_SERVER['DOCUMENT_ROOT'] . '/dbtools/common/Header.php';
?>
    <style>
        /* Collapse icon rotation */
        .collapse-icon {
            transition: transform 0.2s ease;
        }
        [aria-expanded="true"] .collapse-icon {
            transform: rotate(90deg);
        }
        #recordCountTable tbody tr:hover {
            background-color: rgba(0, 123, 255, 0.1);
        }
    </style>

    <!-- Loading Spinner -->
    <div class="spinner-overlay" id="loadingSpinner">
        <div class="text-center">
            <div class="spinner-border text-light" role="status" style="width: 4rem; height: 4rem;">
                <span class="visually-hidden">Loading...</span>
            </div>
            <div class="text-light mt-3" id="loadingText">Loading...</div>
        </div>
    </div>

    <div class="container-fluid py-4">
        <!-- Header -->
        <div class="row mb-4">
            <div class="col">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="index.php">Database Tools</a></li>
                        <li class="breadcrumb-item active">Database Comparison</li>
                    </ol>
                </nav>
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h1 class="h3">Database Comparison Tool</h1>
                        <p class="text-muted">Compare database structures between environments and generate update scripts</p>
                    </div>
                    <div>
                        <a href="view-logs.php" class="btn btn-warning" target="_blank">
                            <i class="bi bi-file-text"></i> View Error Logs
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Environment and Database Selection -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <!-- MySQL Version Selection -->
                        <div class="mb-4">
                            <label class="form-label fw-bold">MySQL Version</label>
                            <div id="versionPills" class="d-flex flex-wrap gap-2">
                                <span class="text-muted">Loading...</span>
                            </div>
                        </div>

                        <!-- Environment Selection -->
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Source Environment <small class="text-muted fw-normal">(compare FROM)</small></label>
                                <div id="sourceEnvPills" class="d-flex flex-wrap gap-2">
                                    <span class="text-muted">Select version first...</span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Target Environment <small class="text-muted fw-normal">(compare TO)</small></label>
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

                        <button class="btn btn-primary btn-lg" id="compareBtn" disabled>
                            <i class="bi bi-arrow-left-right"></i> Compare Databases
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Alert Container -->
        <div id="alertContainer"></div>

        <!-- Comparison Results -->
        <div id="comparisonResults" style="display: none;">
            <!-- Database Existence Warning -->
            <div id="existenceWarning" style="display: none;"></div>

            <!-- Summary -->
            <div class="row mb-4" id="summarySection" style="display: none;">
                <div class="col-12 mb-3">
                    <h4>Comparison Summary</h4>
                </div>
                <div class="col-md-2 col-sm-4 col-6 mb-3">
                    <div class="card summary-card border-primary">
                        <div class="card-body">
                            <div class="display-4 text-primary" id="totalObjects">0</div>
                            <div class="small text-muted">Total Objects</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-2 col-sm-4 col-6 mb-3">
                    <div class="card summary-card border-danger">
                        <div class="card-body">
                            <div class="display-4 text-danger" id="missingInTarget">0</div>
                            <div class="small text-muted" id="missingInTargetLabel">Missing in Target</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-2 col-sm-4 col-6 mb-3">
                    <div class="card summary-card border-warning">
                        <div class="card-body">
                            <div class="display-4 text-warning" id="different">0</div>
                            <div class="small text-muted">Different</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-2 col-sm-4 col-6 mb-3">
                    <div class="card summary-card border-info">
                        <div class="card-body">
                            <div class="display-4 text-info" id="missingInSource">0</div>
                            <div class="small text-muted" id="missingInSourceLabel">Missing in Source</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-2 col-sm-4 col-6 mb-3">
                    <div class="card summary-card border-success">
                        <div class="card-body">
                            <div class="display-4 text-success" id="identical">0</div>
                            <div class="small text-muted">Identical</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Expandable Record Count Comparison -->
            <div class="row mb-4" id="recordCountSection" style="display: none;">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header" style="cursor: pointer;" data-bs-toggle="collapse" data-bs-target="#recordCountCollapse" aria-expanded="false">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="bi bi-chevron-right collapse-icon me-2"></i>
                                    Table Record Counts
                                    <span class="badge bg-secondary ms-2" id="recordCountTableCount">0 tables</span>
                                </h5>
                                <small class="text-muted">Click to expand</small>
                            </div>
                        </div>
                        <div class="collapse" id="recordCountCollapse">
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover table-sm" id="recordCountTable">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Table Name</th>
                                                <th class="text-end" id="recordCountSourceHeader">Source</th>
                                                <th class="text-end" id="recordCountTargetHeader">Target</th>
                                                <th class="text-end">Difference</th>
                                                <th class="text-center">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody id="recordCountTableBody">
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="row mb-4" id="actionButtons" style="display: none;">
                <div class="col-12">
                    <button class="btn btn-success btn-lg" id="generatePlanBtn">
                        <i class="bi bi-file-earmark-text"></i> Generate Update Plan for <span id="generatePlanTargetLabel">Target</span>
                    </button>
                </div>
            </div>

            <!-- Detailed Comparison Sections -->
            <div id="detailedComparison">
                <!-- Tab Navigation -->
                <ul class="nav nav-tabs" id="comparisonTabs" role="tablist">
                    <li class="nav-item" role="presentation" id="missingInTargetTab" style="display: none;">
                        <button class="nav-link active" id="tab-missing-target" data-bs-toggle="tab" data-bs-target="#tab-content-missing-target" type="button" role="tab">
                            <i class="bi bi-exclamation-triangle text-danger"></i> <span class="tab-label-missing-target">Missing in Target</span>
                            <span class="badge bg-danger ms-2" id="missingInTargetCount">0</span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation" id="differentTab" style="display: none;">
                        <button class="nav-link" id="tab-different" data-bs-toggle="tab" data-bs-target="#tab-content-different" type="button" role="tab">
                            <i class="bi bi-exclamation-circle text-warning"></i> Different
                            <span class="badge bg-warning text-dark ms-2" id="differentCount">0</span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation" id="missingInSourceTab" style="display: none;">
                        <button class="nav-link" id="tab-missing-source" data-bs-toggle="tab" data-bs-target="#tab-content-missing-source" type="button" role="tab">
                            <i class="bi bi-info-circle text-info"></i> <span class="tab-label-missing-source">Missing in Source</span>
                            <span class="badge bg-info ms-2" id="missingInSourceCount">0</span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation" id="identicalTab" style="display: none;">
                        <button class="nav-link" id="tab-identical" data-bs-toggle="tab" data-bs-target="#tab-content-identical" type="button" role="tab">
                            <i class="bi bi-check-circle text-success"></i> Identical
                            <span class="badge bg-success ms-2" id="identicalCount">0</span>
                        </button>
                    </li>
                </ul>

                <!-- Tab Content -->
                <div class="tab-content border border-top-0 p-3" id="comparisonTabContent">
                    <!-- Missing in Target -->
                    <div class="tab-pane fade show active" id="tab-content-missing-target" role="tabpanel">
                        <div id="missingInTargetContent"></div>
                    </div>

                    <!-- Different Objects -->
                    <div class="tab-pane fade" id="tab-content-different" role="tabpanel">
                        <div id="differentContent"></div>
                    </div>

                    <!-- Missing in Source -->
                    <div class="tab-pane fade" id="tab-content-missing-source" role="tabpanel">
                        <div id="missingInSourceContent"></div>
                    </div>

                    <!-- Identical Objects -->
                    <div class="tab-pane fade" id="tab-content-identical" role="tabpanel">
                        <div id="identicalContent"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Update Plan Section -->
        <div id="updatePlanSection" style="display: none;">
            <div class="row mb-4">
                <div class="col-12">
                    <h3>Update Plan for <span id="updatePlanTargetLabel">Target</span></h3>
                </div>
            </div>

            <!-- Risk Assessment -->
            <div class="row mb-4">
                <div class="col-12">
                    <h5>Risk Assessment</h5>
                </div>
                <div class="col-md-4 mb-3" id="highRiskCard" style="display: none;">
                    <div class="card risk-high">
                        <div class="card-body">
                            <h6 class="text-danger"><i class="bi bi-exclamation-octagon"></i> High Risk Changes</h6>
                            <div id="highRiskContent"></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 mb-3" id="mediumRiskCard" style="display: none;">
                    <div class="card risk-medium">
                        <div class="card-body">
                            <h6 class="text-warning"><i class="bi bi-exclamation-triangle"></i> Medium Risk Changes</h6>
                            <div id="mediumRiskContent"></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 mb-3" id="lowRiskCard" style="display: none;">
                    <div class="card risk-low">
                        <div class="card-body">
                            <h6 class="text-success"><i class="bi bi-check-circle"></i> Low Risk Changes</h6>
                            <div id="lowRiskContent"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Impact Analysis -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Impact Analysis</h5>
                        </div>
                        <div class="card-body">
                            <div id="impactAnalysisContent"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Backup Options -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Backup Options</h5>
                        </div>
                        <div class="card-body">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="backupOption" id="backupFull" value="full" checked>
                                <label class="form-check-label" for="backupFull">
                                    <strong>Backup entire target database before changes</strong> (Recommended)
                                </label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="backupOption" id="backupTables" value="tables">
                                <label class="form-check-label" for="backupTables">
                                    <strong>Backup only affected tables</strong>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="backupOption" id="backupNone" value="none">
                                <label class="form-check-label text-danger" for="backupNone">
                                    <strong>Skip backup</strong> (Not recommended - requires extra confirmation)
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SQL Preview -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">SQL Statements to Execute</h5>
                        </div>
                        <div class="card-body">
                            <div class="code-block" id="sqlPreview"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Execute Button -->
            <div class="row mb-4">
                <div class="col-12">
                    <button class="btn btn-danger btn-lg" id="executeBtn">
                        <i class="bi bi-play-fill"></i> Execute Updates on <span id="executeBtnTargetLabel">Target</span>
                    </button>
                    <button class="btn btn-secondary btn-lg" id="cancelPlanBtn">
                        <i class="bi bi-x-circle"></i> Cancel
                    </button>
                </div>
            </div>
        </div>

        <!-- Execution Results -->
        <div id="executionResults" style="display: none;">
            <div class="row mb-4">
                <div class="col-12">
                    <h3>Execution Results</h3>
                </div>
            </div>

            <div id="executionProgress"></div>
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
        let comparisonData = null;
        let updatePlan = null;
        let allEnvironments = [];

        $(document).ready(function() {
            loadEnvironments();

            // Version pill click handler
            $(document).on('click', '#versionPills .btn', function() {
                $('#versionPills .btn').removeClass('active');
                $(this).addClass('active');
                const version = $(this).data('version');
                updateEnvironmentPills(version);
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
                $('#compareBtn').prop('disabled', false);
            });

            // Database dropdown handler (fallback for many databases)
            $('#databaseSelect').change(function() {
                currentDatabase = $(this).val();
                $('#compareBtn').prop('disabled', !currentDatabase);
            });

            // Compare button handler
            $('#compareBtn').click(function() {
                if (currentDatabase) {
                    compareDatabase(currentDatabase);
                }
            });

            // Generate plan button handler
            $('#generatePlanBtn').click(function() {
                generateUpdatePlan();
            });

            // Cancel plan button handler
            $('#cancelPlanBtn').click(function() {
                $('#updatePlanSection').hide();
                $('#comparisonResults').show();
            });

            // Execute button handler
            $('#executeBtn').click(function() {
                confirmAndExecute();
            });

            // Handle collapse toggle for icon rotation
            $('#recordCountCollapse').on('show.bs.collapse', function() {
                $(this).prev('.card-header').attr('aria-expanded', 'true');
            }).on('hide.bs.collapse', function() {
                $(this).prev('.card-header').attr('aria-expanded', 'false');
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

            // Keep error messages visible longer (15 seconds instead of 5)
            const timeout = (type === 'danger' || type === 'warning') ? 15000 : 5000;
            setTimeout(() => {
                $('.alert').alert('close');
            }, timeout);
        }

        function loadEnvironments() {
            showLoading('Loading environments...');
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
                    } else {
                        showAlert('Error loading environments: ' + response.error, 'danger');
                    }
                },
                error: function(xhr, status, error) {
                    showAlert('Failed to load environments: ' + error, 'danger');
                },
                complete: function() {
                    hideLoading();
                }
            });
        }

        function updateEnvironmentPills(version) {
            if (!version) {
                $('#sourceEnvPills').html('<span class="text-muted">Select version first...</span>');
                $('#targetEnvPills').html('<span class="text-muted">Select version first...</span>');
                $('#databasePills').html('<span class="text-muted">Select both environments first...</span>');
                $('#databaseSelect').hide();
                $('#compareBtn').prop('disabled', true);
                return;
            }

            // Filter environments by version
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
            $('#compareBtn').prop('disabled', true);
        }

        function updateDatabaseSelection() {
            const sourceEnv = $('#sourceEnvPills .btn.active').data('env');
            const targetEnv = $('#targetEnvPills .btn.active').data('env');

            if (!sourceEnv || !targetEnv) {
                $('#databasePills').html('<span class="text-muted">Select both environments first...</span>');
                $('#databaseSelect').hide();
                $('#compareBtn').prop('disabled', true);
                return;
            }

            if (sourceEnv === targetEnv) {
                showAlert('Source and target environments must be different', 'warning');
                return;
            }

            // Store selected environments
            currentSourceEnv = sourceEnv;
            currentTargetEnv = targetEnv;

            // Get environment labels
            const sourceEnvData = allEnvironments.find(e => e.id === sourceEnv);
            const targetEnvData = allEnvironments.find(e => e.id === targetEnv);
            sourceEnvLabel = sourceEnvData ? sourceEnvData.label : 'Source';
            targetEnvLabel = targetEnvData ? targetEnvData.label : 'Target';

            showLoading('Loading databases...');
            $.ajax({
                url: 'ajax-get-databases.php',
                method: 'GET',
                data: { sourceEnv: sourceEnv, targetEnv: targetEnv },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        const databases = response.databases;
                        const maxPillCount = 10; // Show pills if <= 10 databases

                        if (databases.length <= maxPillCount) {
                            // Show as pills
                            let pillsHtml = '';
                            databases.forEach(db => {
                                let btnClass = 'btn-outline-info';
                                if (db.status === 'source-only') {
                                    btnClass = 'btn-outline-warning';
                                } else if (db.status === 'target-only') {
                                    btnClass = 'btn-outline-secondary';
                                }
                                pillsHtml += `<button type="button" class="btn ${btnClass}" data-db="${db.name}" title="${db.statusLabel}">${db.name}</button>`;
                            });
                            $('#databasePills').html(pillsHtml);
                            $('#databaseSelect').hide();
                        } else {
                            // Show as dropdown for many databases
                            $('#databasePills').html('<span class="text-muted">Many databases - use dropdown below</span>');
                            const select = $('#databaseSelect');
                            select.html('<option value="">-- Select a database --</option>');
                            databases.forEach(db => {
                                select.append(`<option value="${db.name}">${db.name} - ${db.statusLabel}</option>`);
                            });
                            select.show();
                        }
                    } else {
                        showAlert('Error loading databases: ' + response.error, 'danger');
                    }
                },
                error: function(xhr, status, error) {
                    showAlert('Failed to load databases: ' + error, 'danger');
                },
                complete: function() {
                    hideLoading();
                }
            });
        }

        function compareDatabase(database) {
            currentDatabase = database;
            showLoading('Comparing database structures...');

            // Hide all tabs initially
            $('#missingInTargetTab, #differentTab, #missingInSourceTab, #identicalTab').hide();

            // Update labels
            updateDynamicLabels();

            $.ajax({
                url: 'ajax-compare.php',
                method: 'POST',
                data: {
                    database: database,
                    sourceEnv: currentSourceEnv,
                    targetEnv: currentTargetEnv
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        comparisonData = response;
                        displayComparisonResults(response);
                    } else {
                        showAlert('Error comparing databases: ' + response.error, 'danger');
                    }
                },
                error: function(xhr, status, error) {
                    showAlert('Failed to compare databases: ' + error, 'danger');
                },
                complete: function() {
                    hideLoading();
                }
            });
        }

        function updateDynamicLabels() {
            // Update summary labels
            $('#missingInTargetLabel').text('Missing in ' + targetEnvLabel);
            $('#missingInSourceLabel').text('Missing in ' + sourceEnvLabel);

            // Update tab labels
            $('.tab-label-missing-target').text('Missing in ' + targetEnvLabel);
            $('.tab-label-missing-source').text('Missing in ' + sourceEnvLabel);

            // Update button labels
            $('#generatePlanTargetLabel').text(targetEnvLabel);
            $('#updatePlanTargetLabel').text(targetEnvLabel);
            $('#executeBtnTargetLabel').text(targetEnvLabel);
        }

        function displayComparisonResults(data) {
            // Check if database exists in both environments
            if (!data.existsInBoth) {
                let message = '';
                if (!data.existsInSource && !data.existsInTarget) {
                    message = `<div class="alert alert-danger">Database <strong>${data.database}</strong> does not exist in either environment!</div>`;
                } else if (!data.existsInTarget) {
                    message = `<div class="alert alert-warning">Database <strong>${data.database}</strong> exists in ${sourceEnvLabel} but NOT in ${targetEnvLabel}. You may need to create it first.</div>`;
                } else if (!data.existsInSource) {
                    message = `<div class="alert alert-info">Database <strong>${data.database}</strong> exists in ${targetEnvLabel} but NOT in ${sourceEnvLabel}.</div>`;
                }
                $('#existenceWarning').html(message).show();
                $('#summarySection').hide();
                $('#actionButtons').hide();
                $('#comparisonResults').show();
                return;
            }

            $('#existenceWarning').hide();

            // Display summary
            $('#totalObjects').text(data.summary.totalObjects);
            $('#missingInTarget').text(data.summary.missingInTarget);
            $('#different').text(data.summary.different);
            $('#missingInSource').text(data.summary.missingInSource);
            $('#identical').text(data.summary.identical);
            $('#summarySection').show();

            // Show action buttons if there are differences
            if (data.summary.missingInTarget > 0 || data.summary.different > 0) {
                $('#actionButtons').show();
            } else {
                $('#actionButtons').hide();
            }

            // Display detailed comparison
            displayObjectList(data, 'missing-in-target', null, '#missingInTargetContent', '#missingInTargetCount');
            displayObjectList(data, 'different', null, '#differentContent', '#differentCount');
            displayObjectList(data, 'missing-in-source', null, '#missingInSourceContent', '#missingInSourceCount');
            displayObjectList(data, 'identical', null, '#identicalContent', '#identicalCount');

            // Activate the first visible tab
            const firstVisibleTab = $('#comparisonTabs .nav-item:visible:first button');
            if (firstVisibleTab.length > 0) {
                firstVisibleTab.tab('show');
            }

            // Display record count comparison
            displayRecordCountComparison(data);

            $('#comparisonResults').show();
            $('#updatePlanSection').hide();
        }

        function displayRecordCountComparison(data) {
            const tables = data.tables || [];

            if (tables.length === 0) {
                $('#recordCountSection').hide();
                return;
            }

            // Build array of tables with record counts and calculate differences
            const tableRecords = tables.map(table => {
                const sourceCount = table.sourceRowCount !== undefined ? table.sourceRowCount : null;
                const targetCount = table.targetRowCount !== undefined ? table.targetRowCount : null;

                let difference = 0;
                let absDifference = 0;

                if (sourceCount !== null && targetCount !== null) {
                    difference = sourceCount - targetCount;
                    absDifference = Math.abs(difference);
                } else if (sourceCount !== null) {
                    difference = sourceCount;
                    absDifference = sourceCount;
                } else if (targetCount !== null) {
                    difference = -targetCount;
                    absDifference = targetCount;
                }

                return {
                    name: table.name,
                    sourceCount: sourceCount,
                    targetCount: targetCount,
                    difference: difference,
                    absDifference: absDifference,
                    status: table.status
                };
            });

            // Sort by absolute difference (largest first)
            tableRecords.sort((a, b) => b.absDifference - a.absDifference);

            // Update headers with environment labels
            $('#recordCountSourceHeader').text(sourceEnvLabel);
            $('#recordCountTargetHeader').text(targetEnvLabel);
            $('#recordCountTableCount').text(tableRecords.length + ' tables');

            // Build table rows
            let html = '';
            tableRecords.forEach(table => {
                const sourceDisplay = table.sourceCount !== null ? formatNumber(table.sourceCount) : '<span class="text-muted">N/A</span>';
                const targetDisplay = table.targetCount !== null ? formatNumber(table.targetCount) : '<span class="text-muted">N/A</span>';

                let diffClass = '';
                let diffDisplay = '';

                if (table.sourceCount === null || table.targetCount === null) {
                    diffDisplay = '<span class="text-muted">-</span>';
                } else if (table.difference > 0) {
                    diffClass = 'text-success';
                    diffDisplay = `<span class="${diffClass}">+${formatNumber(table.difference)}</span>`;
                } else if (table.difference < 0) {
                    diffClass = 'text-danger';
                    diffDisplay = `<span class="${diffClass}">${formatNumber(table.difference)}</span>`;
                } else {
                    diffDisplay = '<span class="text-muted">0</span>';
                }

                // Status badge
                let statusBadge = '';
                switch(table.status) {
                    case 'identical':
                        statusBadge = '<span class="badge bg-success">Identical</span>';
                        break;
                    case 'different':
                        statusBadge = '<span class="badge bg-warning text-dark">Different</span>';
                        break;
                    case 'missing-in-target':
                        statusBadge = `<span class="badge bg-danger">Missing in ${targetEnvLabel}</span>`;
                        break;
                    case 'missing-in-source':
                        statusBadge = `<span class="badge bg-info">Missing in ${sourceEnvLabel}</span>`;
                        break;
                    default:
                        statusBadge = '<span class="badge bg-secondary">Unknown</span>';
                }

                // Highlight rows with large differences
                let rowClass = '';
                if (table.absDifference > 1000) {
                    rowClass = 'table-warning';
                } else if (table.absDifference > 100) {
                    rowClass = 'table-light';
                }

                html += `
                    <tr class="${rowClass}">
                        <td><strong>${escapeHtml(table.name)}</strong></td>
                        <td class="text-end">${sourceDisplay}</td>
                        <td class="text-end">${targetDisplay}</td>
                        <td class="text-end">${diffDisplay}</td>
                        <td class="text-center">${statusBadge}</td>
                    </tr>
                `;
            });

            $('#recordCountTableBody').html(html);
            $('#recordCountSection').show();
        }

        function displayObjectList(data, status, sectionId, contentId, countId) {
            const objects = getAllObjectsByStatus(data, status);

            // Map status to tab ID
            const tabMap = {
                'missing-in-target': 'missingInTargetTab',
                'different': 'differentTab',
                'missing-in-source': 'missingInSourceTab',
                'identical': 'identicalTab'
            };

            const tabId = tabMap[status];

            if (objects.length === 0) {
                $(`#${tabId}`).hide();
                return;
            }

            $(countId).text(objects.length);

            let html = '';

            // Add section controls for actionable statuses
            if (status === 'missing-in-target' || status === 'different') {
                const actionText = status === 'missing-in-target' ? `Add All Selected to ${targetEnvLabel}` : `Update All Selected in ${targetEnvLabel}`;
                const btnClass = status === 'missing-in-target' ? 'btn-danger' : 'btn-warning';
                const actionIcon = status === 'missing-in-target' ? 'plus-circle-fill' : 'arrow-repeat';

                html += `
                    <div class="mb-3 p-3 bg-light border rounded">
                        <div class="d-flex align-items-center justify-content-between">
                            <div class="form-check">
                                <input class="form-check-input select-all-checkbox" type="checkbox" id="selectAll-${status}" data-status="${status}">
                                <label class="form-check-label fw-bold" for="selectAll-${status}">
                                    Select All
                                </label>
                            </div>
                            <button class="btn ${btnClass} bulk-action-btn" data-status="${status}" disabled>
                                <i class="bi bi-${actionIcon}"></i> ${actionText}
                            </button>
                        </div>
                    </div>
                `;
            }

            objects.forEach(obj => {
                html += renderObject(obj);
            });

            $(contentId).html(html);
            $(`#${tabId}`).show();
        }

        function getAllObjectsByStatus(data, status) {
            const objects = [];
            const types = ['tables', 'views', 'procedures', 'functions', 'triggers', 'events'];

            types.forEach(type => {
                if (data[type]) {
                    data[type].forEach(obj => {
                        if (obj.status === status) {
                            objects.push(obj);
                        }
                    });
                }
            });

            return objects;
        }

        function renderObject(obj) {
            const statusClass = `status-${obj.status}`;
            const typeIcon = getTypeIcon(obj.type);
            const showActions = (obj.status === 'missing-in-target' || obj.status === 'different');
            const objId = `${obj.type}-${obj.name.replace(/[^a-zA-Z0-9]/g, '_')}`;

            let html = `
                <div class="card object-item ${statusClass} mb-2" data-object-type="${obj.type}" data-object-name="${obj.name}" data-object-status="${obj.status}">
                    <div class="card-body">
                        <div class="d-flex align-items-start">
            `;

            // Add checkbox for actionable items
            if (showActions) {
                html += `
                            <div class="form-check me-3 mt-1">
                                <input class="form-check-input object-checkbox" type="checkbox" id="check-${objId}" data-status="${obj.status}">
                            </div>
                `;
            }

            html += `
                            <div class="flex-grow-1">
                                <h6>
                                    <i class="bi ${typeIcon}"></i>
                                    ${obj.name}
                                    <span class="badge bg-secondary">${obj.type}</span>
            `;

            if (obj.sourceRowCount !== undefined) {
                html += `<span class="badge bg-info row-count-badge">${sourceEnvLabel}: ${formatNumber(obj.sourceRowCount)} rows</span>`;
            }
            if (obj.targetRowCount !== undefined) {
                html += `<span class="badge bg-info row-count-badge">${targetEnvLabel}: ${formatNumber(obj.targetRowCount)} rows</span>`;
            }

            html += `</h6>`;

            if (obj.differences && obj.differences.length > 0) {
                html += `<ul class="difference-list">`;
                obj.differences.forEach(diff => {
                    html += `<li>${escapeHtml(diff)}</li>`;
                });
                html += `</ul>`;
            }

            // Show structure comparison for tables with differences
            if (obj.type === 'table' && obj.status === 'different' && obj.sourceStructure && obj.targetStructure) {
                html += renderStructureComparison(obj);
            }

            html += `</div>`; // Close flex-grow-1

            // Add action button for individual updates
            if (showActions) {
                const actionText = obj.status === 'missing-in-target' ? `Add to ${targetEnvLabel}` : `Update ${targetEnvLabel}`;
                const actionIcon = obj.status === 'missing-in-target' ? 'plus-circle' : 'arrow-repeat';
                const btnClass = obj.status === 'missing-in-target' ? 'btn-danger' : 'btn-warning';

                html += `
                            <div class="ms-2">
                                <button class="btn ${btnClass} btn-sm execute-single-btn"
                                        data-type="${obj.type}"
                                        data-name="${obj.name}"
                                        data-status="${obj.status}"
                                        title="${actionText}">
                                    <i class="bi bi-${actionIcon}"></i> ${actionText}
                                </button>
                            </div>
                `;
            }

            html += `
                        </div>
                    </div>
                </div>
            `;

            return html;
        }

        function renderStructureComparison(obj) {
            const sourceCols = {};
            const targetCols = {};

            obj.sourceStructure.forEach(col => sourceCols[col.Field] = col);
            obj.targetStructure.forEach(col => targetCols[col.Field] = col);

            const allCols = new Set([...Object.keys(sourceCols), ...Object.keys(targetCols)]);

            let html = `
                <div class="mt-2">
                    <small class="text-muted">Structure Comparison:</small>
                    <div class="table-responsive">
                        <table class="table table-sm structure-comparison">
                            <thead>
                                <tr>
                                    <th>Column</th>
                                    <th>${sourceEnvLabel} Type</th>
                                    <th>${targetEnvLabel} Type</th>
                                    <th>Null</th>
                                    <th>Default</th>
                                </tr>
                            </thead>
                            <tbody>
            `;

            allCols.forEach(colName => {
                const sourceCol = sourceCols[colName];
                const targetCol = targetCols[colName];
                const isDiff = !sourceCol || !targetCol || sourceCol.Type !== targetCol.Type;

                html += `<tr ${isDiff ? 'class="col-diff"' : ''}>`;
                html += `<td><strong>${colName}</strong></td>`;
                html += `<td>${sourceCol ? escapeHtml(sourceCol.Type) : '<em class="text-danger">Missing</em>'}</td>`;
                html += `<td>${targetCol ? escapeHtml(targetCol.Type) : '<em class="text-danger">Missing</em>'}</td>`;
                html += `<td>${sourceCol ? sourceCol.Null : '-'} / ${targetCol ? targetCol.Null : '-'}</td>`;
                html += `<td>${sourceCol ? (sourceCol.Default || 'NULL') : '-'} / ${targetCol ? (targetCol.Default || 'NULL') : '-'}</td>`;
                html += `</tr>`;
            });

            html += `
                            </tbody>
                        </table>
                    </div>
                </div>
            `;

            return html;
        }

        function getTypeIcon(type) {
            const icons = {
                'table': 'bi-table',
                'view': 'bi-eye',
                'procedure': 'bi-gear',
                'function': 'bi-calculator',
                'trigger': 'bi-lightning',
                'event': 'bi-clock'
            };
            return icons[type] || 'bi-question-circle';
        }

        function formatNumber(num) {
            return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
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

        function generateUpdatePlan() {
            showLoading('Generating update plan...');

            $.ajax({
                url: 'ajax-generate-plan.php',
                method: 'POST',
                data: {
                    database: currentDatabase,
                    sourceEnv: currentSourceEnv,
                    targetEnv: currentTargetEnv,
                    comparisonData: JSON.stringify(comparisonData)
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        updatePlan = response;
                        displayUpdatePlan(response);
                    } else {
                        showAlert('Error generating plan: ' + response.error, 'danger');
                    }
                },
                error: function(xhr, status, error) {
                    showAlert('Failed to generate plan: ' + error, 'danger');
                },
                complete: function() {
                    hideLoading();
                }
            });
        }

        function displayUpdatePlan(plan) {
            // Display risk assessment
            if (plan.risks.high.length > 0) {
                $('#highRiskContent').html('<ul class="mb-0">' + plan.risks.high.map(r => `<li>${escapeHtml(r)}</li>`).join('') + '</ul>');
                $('#highRiskCard').show();
            } else {
                $('#highRiskCard').hide();
            }

            if (plan.risks.medium.length > 0) {
                $('#mediumRiskContent').html('<ul class="mb-0">' + plan.risks.medium.map(r => `<li>${escapeHtml(r)}</li>`).join('') + '</ul>');
                $('#mediumRiskCard').show();
            } else {
                $('#mediumRiskCard').hide();
            }

            if (plan.risks.low.length > 0) {
                $('#lowRiskContent').html('<ul class="mb-0">' + plan.risks.low.map(r => `<li>${escapeHtml(r)}</li>`).join('') + '</ul>');
                $('#lowRiskCard').show();
            } else {
                $('#lowRiskCard').hide();
            }

            // Display impact analysis
            let impactHtml = `
                <ul>
                    <li><strong>Tables affected:</strong> ${plan.impact.tablesAffected.join(', ') || 'None'}</li>
                    <li><strong>Total statements:</strong> ${plan.sqlStatements.length}</li>
                    <li><strong>Estimated execution time:</strong> ${plan.impact.estimatedTime}</li>
                </ul>
            `;
            $('#impactAnalysisContent').html(impactHtml);

            // Display SQL preview
            let sqlHtml = plan.sqlStatements.map((sql, idx) => `-- Statement ${idx + 1}\n${sql}`).join('\n\n');
            $('#sqlPreview').text(sqlHtml);

            $('#comparisonResults').hide();
            $('#updatePlanSection').show();
        }

        function confirmAndExecute() {
            const backupOption = $('input[name="backupOption"]:checked').val();

            let confirmMessage = `Are you sure you want to execute these updates on ${targetEnvLabel}?\n\n`;
            confirmMessage += `Database: ${currentDatabase}\n`;
            confirmMessage += `Target Environment: ${targetEnvLabel}\n`;
            confirmMessage += `Statements to execute: ${updatePlan.sqlStatements.length}\n`;
            confirmMessage += `Backup option: ${backupOption}\n\n`;

            if (backupOption === 'none') {
                confirmMessage += 'WARNING: You have chosen to skip backup. This is NOT recommended!\n\n';
                if (!confirm('Are you ABSOLUTELY sure you want to skip the backup?')) {
                    return;
                }
            }

            if (updatePlan.risks.high.length > 0) {
                confirmMessage += 'WARNING: This plan contains HIGH RISK changes!\n\n';
            }

            if (!confirm(confirmMessage + 'Type YES to confirm:')) {
                return;
            }

            executeUpdatePlan(backupOption);
        }

        function executeUpdatePlan(backupOption) {
            showLoading('Executing update plan...');

            $.ajax({
                url: 'ajax-execute-plan.php',
                method: 'POST',
                data: {
                    database: currentDatabase,
                    sourceEnv: currentSourceEnv,
                    targetEnv: currentTargetEnv,
                    updatePlan: JSON.stringify(updatePlan),
                    backupOption: backupOption
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        displayExecutionResults(response);
                    } else {
                        showAlert('Error executing plan: ' + response.error, 'danger');
                    }
                },
                error: function(xhr, status, error) {
                    showAlert('Failed to execute plan: ' + error, 'danger');
                },
                complete: function() {
                    hideLoading();
                }
            });
        }

        function displayExecutionResults(results) {
            let html = '';

            // Backup result
            if (results.backupResult) {
                const backupClass = results.backupResult.success ? 'success' : 'danger';
                const backupIcon = results.backupResult.success ? 'check-circle' : 'x-circle';
                html += `
                    <div class="alert alert-${backupClass}">
                        <i class="bi bi-${backupIcon}"></i>
                        <strong>Backup:</strong> ${results.backupResult.message}
                    </div>
                `;
            }

            // SQL execution results
            html += '<h5>SQL Execution Results:</h5>';
            results.sqlResults.forEach((result, idx) => {
                const resultClass = result.success ? 'success' : 'danger';
                const resultIcon = result.success ? 'check-circle' : 'x-circle';
                html += `
                    <div class="alert alert-${resultClass}">
                        <i class="bi bi-${resultIcon}"></i>
                        <strong>Statement ${idx + 1}:</strong> ${result.message}
                    </div>
                `;
            });

            // Final status
            const allSuccess = results.sqlResults.every(r => r.success);
            const statusClass = allSuccess ? 'success' : 'warning';
            const statusMessage = allSuccess ? 'All updates completed successfully!' : 'Some updates failed. Please review the results above.';

            html += `
                <div class="alert alert-${statusClass}">
                    <h5>${statusMessage}</h5>
                </div>
                <button class="btn btn-primary" onclick="location.reload()">Start New Comparison</button>
            `;

            $('#executionProgress').html(html);
            $('#updatePlanSection').hide();
            $('#executionResults').show();
        }

        // ==================== NEW: Individual Update Functions ====================

        // Handle Select All checkbox
        $(document).on('change', '.select-all-checkbox', function() {
            const status = $(this).data('status');
            const isChecked = $(this).is(':checked');

            // Check/uncheck all checkboxes with matching status
            $(`.object-checkbox[data-status="${status}"]`).prop('checked', isChecked);

            // Update bulk action button state
            updateBulkActionButton(status);
        });

        // Handle individual checkbox changes
        $(document).on('change', '.object-checkbox', function() {
            const status = $(this).data('status');

            // Update Select All checkbox state
            const totalCheckboxes = $(`.object-checkbox[data-status="${status}"]`).length;
            const checkedCheckboxes = $(`.object-checkbox[data-status="${status}"]:checked`).length;

            $(`#selectAll-${status}`).prop('checked', totalCheckboxes === checkedCheckboxes);

            // Update bulk action button state
            updateBulkActionButton(status);
        });

        // Update bulk action button enabled/disabled state
        function updateBulkActionButton(status) {
            const checkedCount = $(`.object-checkbox[data-status="${status}"]:checked`).length;
            $(`.bulk-action-btn[data-status="${status}"]`).prop('disabled', checkedCount === 0);
        }

        // Handle individual execute button clicks
        $(document).on('click', '.execute-single-btn', function() {
            const btn = $(this);
            const objectType = btn.data('type');
            const objectName = btn.data('name');
            const status = btn.data('status');

            executeSingleUpdate(objectType, objectName, status, btn);
        });

        // Handle bulk action button clicks
        $(document).on('click', '.bulk-action-btn', function() {
            const btn = $(this);
            const status = btn.data('status');
            const checkedBoxes = $(`.object-checkbox[data-status="${status}"]:checked`);

            if (checkedBoxes.length === 0) {
                return;
            }

            const actionText = status === 'missing-in-target' ? 'add' : 'update';
            if (!confirm(`Are you sure you want to ${actionText} ${checkedBoxes.length} selected object(s) to ${targetEnvLabel}?`)) {
                return;
            }

            executeBulkUpdates(checkedBoxes, status);
        });

        // Execute a single update
        function executeSingleUpdate(objectType, objectName, status, btn) {
            const actionText = status === 'missing-in-target' ? 'add' : 'update';

            if (!confirm(`Are you sure you want to ${actionText} "${objectName}" (${objectType}) to ${targetEnvLabel}?`)) {
                return;
            }

            const originalHtml = btn.html();
            btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Processing...');

            $.ajax({
                url: 'ajax-execute-single.php',
                method: 'POST',
                data: {
                    database: currentDatabase,
                    objectType: objectType,
                    objectName: objectName,
                    objectStatus: status,
                    sourceEnv: currentSourceEnv,
                    targetEnv: currentTargetEnv
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        let alertMessage = response.message || `Successfully ${actionText}ed ${objectName} to ${targetEnvLabel}`;

                        // Show warning if dependencies exist
                        if (response.warning) {
                            showAlert(`${alertMessage}<br><strong>${response.warning}</strong>`, 'warning');
                        } else {
                            showAlert(alertMessage, 'success');
                        }

                        // Refresh the comparison to show updated state
                        setTimeout(function() {
                            showLoading('Refreshing comparison...');
                            compareDatabase(currentDatabase);
                        }, 1000);
                    } else {
                        showAlert(`Error ${actionText}ing ${objectName}: ${response.error}`, 'danger');
                        btn.prop('disabled', false).html(originalHtml);
                    }
                },
                error: function(xhr, status, error) {
                    let errorMsg = error;
                    if (xhr.responseJSON && xhr.responseJSON.error) {
                        errorMsg = xhr.responseJSON.error;
                    } else if (xhr.responseText) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.error) {
                                errorMsg = response.error;
                            }
                        } catch (e) {
                            // If not JSON, use the raw text
                            errorMsg = xhr.responseText.substring(0, 500);
                        }
                    }
                    showAlert(`<strong>Failed to ${actionText} ${objectName}:</strong><br><pre style="white-space: pre-wrap; font-size: 0.9em;">${escapeHtml(errorMsg)}</pre>`, 'danger');
                    btn.prop('disabled', false).html(originalHtml);
                }
            });
        }

        // Execute bulk updates
        function executeBulkUpdates(checkedBoxes, status) {
            const total = checkedBoxes.length;
            let completed = 0;
            let succeeded = 0;
            let failed = 0;

            showLoading(`Analyzing dependencies...`);

            const updates = [];
            const tableNames = [];

            checkedBoxes.each(function() {
                const checkbox = $(this);
                const card = checkbox.closest('.object-item');
                const objectType = card.data('object-type');
                const objectName = card.data('object-name');

                updates.push({
                    type: objectType,
                    name: objectName,
                    checkbox: checkbox,
                    card: card
                });

                if (objectType === 'table') {
                    tableNames.push(objectName);
                }
            });

            // If there are tables, order them by dependencies first
            if (tableNames.length > 1 && status === 'missing-in-target') {
                $.ajax({
                    url: 'ajax-order-tables.php',
                    method: 'POST',
                    data: {
                        database: currentDatabase,
                        sourceEnv: currentSourceEnv,
                        tables: JSON.stringify(tableNames)
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success && response.orderedTables) {
                            // Reorder updates based on table dependencies
                            const orderedUpdates = reorderUpdatesByDependencies(updates, response.orderedTables);
                            processUpdatesSequentially(orderedUpdates, total, status);
                        } else {
                            // If ordering fails, process as-is
                            processUpdatesSequentially(updates, total, status);
                        }
                    },
                    error: function() {
                        // If ordering fails, process as-is
                        processUpdatesSequentially(updates, total, status);
                    }
                });
            } else {
                // No need to order, process as-is
                processUpdatesSequentially(updates, total, status);
            }

            // Reorder updates based on table dependency order
            function reorderUpdatesByDependencies(updates, orderedTableNames) {
                const tables = [];
                const nonTables = [];

                updates.forEach(update => {
                    if (update.type === 'table') {
                        tables.push(update);
                    } else {
                        nonTables.push(update);
                    }
                });

                // Sort tables by the ordered list
                tables.sort((a, b) => {
                    const indexA = orderedTableNames.indexOf(a.name);
                    const indexB = orderedTableNames.indexOf(b.name);
                    return indexA - indexB;
                });

                // Tables first (in dependency order), then non-tables
                return [...tables, ...nonTables];
            }

            // Process updates sequentially
            function processUpdatesSequentially(orderedUpdates, total, status) {
                function processNext(index) {
                    if (index >= orderedUpdates.length) {
                        hideLoading();
                        const actionText = status === 'missing-in-target' ? 'added' : 'updated';
                        showAlert(`Bulk update complete: ${succeeded} succeeded, ${failed} failed`,
                                 failed > 0 ? 'warning' : 'success');

                        // Refresh the comparison to show updated state
                        setTimeout(function() {
                            showLoading('Refreshing comparison...');
                            compareDatabase(currentDatabase);
                        }, 1500);
                        return;
                    }

                    const update = orderedUpdates[index];
                    $('#loadingText').text(`Processing ${index + 1} of ${total}: ${update.name}...`);

                    $.ajax({
                        url: 'ajax-execute-single.php',
                        method: 'POST',
                        data: {
                            database: currentDatabase,
                            objectType: update.type,
                            objectName: update.name,
                            objectStatus: status,
                            sourceEnv: currentSourceEnv,
                            targetEnv: currentTargetEnv
                        },
                        dataType: 'json',
                        success: function(response) {
                            completed++;
                            if (response.success) {
                                succeeded++;
                                update.card.fadeOut(300, function() {
                                    $(this).remove();
                                });
                            } else {
                                failed++;
                                update.checkbox.prop('checked', false);
                            }
                        },
                        error: function() {
                            completed++;
                            failed++;
                            update.checkbox.prop('checked', false);
                        },
                        complete: function() {
                            processNext(index + 1);
                        }
                    });
                }

                processNext(0);
            }
        }

        // Update section counts after items are removed
        function updateSectionCounts(status) {
            let tabId, countId, contentId;

            if (status === 'missing-in-target') {
                tabId = '#missingInTargetTab';
                countId = '#missingInTargetCount';
                contentId = '#missingInTargetContent';
            } else if (status === 'different') {
                tabId = '#differentTab';
                countId = '#differentCount';
                contentId = '#differentContent';
            }

            const remainingCount = $(`${contentId} .object-item`).length;
            $(countId).text(remainingCount);
            $(`#${status === 'missing-in-target' ? 'missingInTarget' : 'different'}`).text(remainingCount);

            // Hide tab if no items remain
            if (remainingCount === 0) {
                $(tabId).fadeOut();

                // Switch to the next visible tab
                const visibleTabs = $('#comparisonTabs .nav-item:visible');
                if (visibleTabs.length > 0) {
                    $(visibleTabs[0]).find('button').tab('show');
                }

                // Check if we should hide action buttons
                const totalActionable = $('#missingInTargetContent .object-item').length +
                                       $('#differentContent .object-item').length;
                if (totalActionable === 0) {
                    $('#actionButtons').fadeOut();
                }
            }
        }

        // ==================== END: Individual Update Functions ====================
    </script>
</body>
</html>
