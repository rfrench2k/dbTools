<?php
// Debug: Log that we reached index.php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

// Catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        file_put_contents(__DIR__ . '/php_errors.log',
            "[" . date('Y-m-d H:i:s') . "] FATAL ERROR: {$error['message']} in {$error['file']} on line {$error['line']}\n",
            FILE_APPEND);
    }
});

// Log entry point
file_put_contents(__DIR__ . '/php_errors.log',
    "[" . date('Y-m-d H:i:s') . "] index.php STARTED - DOCUMENT_ROOT=" . ($_SERVER['DOCUMENT_ROOT'] ?? 'NOT SET') . "\n",
    FILE_APPEND);

$pageTitle = 'Database Management Tools';

$headerFile = $_SERVER['DOCUMENT_ROOT'] . '/dbtools/common/Header.php';
file_put_contents(__DIR__ . '/php_errors.log',
    "[" . date('Y-m-d H:i:s') . "] About to include: $headerFile (exists: " . (file_exists($headerFile) ? 'YES' : 'NO') . ")\n",
    FILE_APPEND);

include $headerFile;
?>

        <div class="text-center mb-5">
            <h1 class="display-4">Database Management Tools</h1>
            <p class="lead text-muted">Comprehensive database comparison and management utilities</p>
        </div>

        <ul class="nav nav-pills justify-content-center mb-4" id="toolTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="mysql-tab" data-bs-toggle="tab" data-bs-target="#mysql" type="button" role="tab" aria-controls="mysql" aria-selected="true">
                    <i class="bi bi-database"></i> MySQL Tools
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="interbase-tab" data-bs-toggle="tab" data-bs-target="#interbase" type="button" role="tab" aria-controls="interbase" aria-selected="false">
                    <i class="bi bi-hdd-network"></i> InterBase Tools
                </button>
            </li>
        </ul>

        <div class="tab-content" id="toolTabsContent">
            <div class="tab-pane fade show active" id="mysql" role="tabpanel" aria-labelledby="mysql-tab" tabindex="0">
                <div class="row g-4">
                    <div class="col-12">
                        <div class="card shadow-sm border-primary">
                            <div class="card-body d-flex flex-wrap align-items-center gap-3">
                                <div class="flex-grow-1">
                                    <h5 class="card-title mb-1"><i class="bi bi-cloud-check"></i> Nightly Backup (Google Drive)</h5>
                                    <p class="card-text mb-0">What is backed up every night, when each app was last copied, what is NOT backed up, and how to restore.</p>
                                </div>
                                <a href="nightly-backup.php" class="btn btn-primary"><i class="bi bi-cloud-check"></i> Open Nightly Backup</a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card h-100 shadow-sm border-primary">
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="flex-grow-1">
                                        <h5 class="card-title mb-0"><i class="bi bi-columns-gap"></i> Database Comparison Tool</h5>
                                    </div>
                                    <span class="badge bg-success">Active</span>
                                </div>
                                <p class="card-text">
                                    Compare TEST and PROD database structures including tables, views, stored procedures,
                                    functions, triggers, and events. Identify differences and generate update scripts with
                                    risk assessment.
                                </p>
                                <ul class="list-unstyled small">
                                    <li><strong>✓</strong> Compare all database objects</li>
                                    <li><strong>✓</strong> Side-by-side structure comparison</li>
                                    <li><strong>✓</strong> Risk assessment for changes</li>
                                    <li><strong>✓</strong> Automatic backup before updates</li>
                                    <li><strong>✓</strong> Direct execution with confirmation</li>
                                </ul>
                                <a href="db-compare.php" class="btn btn-primary mt-3">Open Comparison Tool</a>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card h-100 shadow-sm border-success">
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="flex-grow-1">
                                        <h5 class="card-title mb-0"><i class="bi bi-database-fill-down"></i> Manual Backup Tool</h5>
                                    </div>
                                    <span class="badge bg-success">Active</span>
                                </div>
                                <p class="card-text">
                                    Make a one-off backup of a database to d:\dumps\ with proper mysqldump flags (stored
                                    procedures, triggers, all objects). The automatic nightly backup is separate: see
                                    <a href="nightly-backup.php">Nightly Backup</a>.
                                </p>
                                <ul class="list-unstyled small">
                                    <li><strong>✓</strong> Full database backup with all objects</li>
                                    <li><strong>✓</strong> Proper flags: --routines, --triggers, --single-transaction</li>
                                    <li><strong>✓</strong> Backup location: d:\dumps\</li>
                                </ul>
                                <a href="backup-tool.php" class="btn btn-success mt-3"><i class="bi bi-download"></i> Open Backup Tool</a>
                                <a href="test-backup.php" class="btn btn-outline-success mt-2 w-100"><i class="bi bi-gear"></i> Test Backup System</a>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card h-100 shadow-sm border-warning">
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="flex-grow-1">
                                        <h5 class="card-title mb-0"><i class="bi bi-arrow-repeat"></i> Database Replication Tool</h5>
                                    </div>
                                    <span class="badge bg-warning text-dark">Use with Caution</span>
                                </div>
                                <p class="card-text">
                                    Copy entire PROD database to TEST, overwriting existing TEST data. Automatically backs up TEST prior to replication
                                    to ensure you can roll back if needed.
                                </p>
                                <ul class="list-unstyled small">
                                    <li><strong>✓</strong> Validates database exists in both environments</li>
                                    <li><strong>✓</strong> Automatic backup of TEST before overwrite</li>
                                    <li><strong>✓</strong> Complete data replication (PROD → TEST)</li>
                                    <li><strong>✓</strong> Multiple safety confirmations</li>
                                    <li><strong>⚠</strong> <em>Overwrites all TEST data!</em></li>
                                </ul>
                                <a href="db-replicate.php" class="btn btn-warning mt-3"><i class="bi bi-arrow-repeat"></i> Open Replication Tool</a>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card h-100 shadow-sm border-dashed">
                            <div class="card-body text-center">
                                <div class="text-muted mb-3">
                                    <svg width="64" height="64" fill="currentColor" class="bi bi-plus-circle-dotted" viewBox="0 0 16 16">
                                        <path d="M8 0c-.176 0-.35.006-.523.017l.064.998a7.117 7.117 0 0 1 .918 0l.064-.998A8.113 8.113 0 0 0 8 0zM6.44.152c-.346.069-.684.16-1.012.27l.321.948c.287-.098.582-.177.884-.237L6.44.152zm4.132.271a7.946 7.946 0 0 0-1.011-.27l-.194.98c.302.06.597.14.884.237l.321-.947zm1.873.925a8 8 0 0 0-.906-.524l-.443.896c.275.136.54.29.793.459l.556-.831zM4.46.824c-.314.155-.616.33-.905.524l.556.83a7.07 7.07 0 0 1 .793-.458L4.46.824zM2.725 1.985c-.262.23-.51.478-.74.74l.752.66c.202-.23.418-.446.648-.648l-.66-.752zm11.29.74a8.058 8.058 0 0 0-.74-.74l-.66.752c.23.202.447.418.648.648l.752-.66zm1.161 1.735a7.98 7.98 0 0 0-.524-.905l-.83.556c.169.253.322.518.458.793l.896-.443zM1.348 3.555c-.194.289-.37.591-.524.906l.896.443c.136-.275.29-.54.459-.793l-.831-.556zM.423 5.428a7.945 7.945 0 0 0-.27 1.011l.98.194c.06-.302.14-.597.237-.884l-.947-.321zM15.848 6.44a7.943 7.943 0 0 0-.27-1.012l-.948.321c.098.287.177.582.237.884l.98-.194zM.017 7.477a8.113 8.113 0 0 0 0 1.046l.998-.064a7.117 7.117 0 0 1 0-.918l-.998-.064zM16 8a8.1 8.1 0 0 0-.017-.523l-.998.064a7.11 7.11 0 0 1 0 .918l.998.064A8.1 8.1 0 0 0 16 8zM.152 9.56c.069.346.16.684.27 1.012l.948-.321a6.944 6.944 0 0 1-.237-.884l-.98.194zm15.425 1.012c.112-.328.202-.666.27-1.011l-.98-.194c-.06.302-.14.597-.237.884l.947.321zM.824 11.54a8 8 0 0 0 .524.905l.83-.556a6.999 6.999 0 0 1-.458-.793l-.896.443zm13.828.905c.194-.289.37-.591.524-.906l-.896-.443c-.136.275-.29.54-.459.793l.831.556zm-12.667.83c.23.262.478.51.74.74l.66-.752a7.047 7.047 0 0 1-.648-.648l-.752.66zm11.29.74c.262-.23.51-.478.74-.74l-.752-.66c-.201.23-.418.447-.648.648l.66.752zm-1.735 1.161c.314-.155.616-.33.905-.524l-.556-.83a7.07 7.07 0 0 1-.793.458l.443.896zm-7.985-.524c.289.194.591.37.906.524l.443-.896a6.998 6.998 0 0 1-.793-.459l-.556.831zm1.873.925c.328.112.666.202 1.011.27l.194-.98a6.953 6.953 0 0 1-.884-.237l-.321.947zm4.132.271a7.944 7.944 0 0 0 1.012-.27l-.321-.948a6.954 6.954 0 0 1-.884.237l.194.98zm-2.083.135a8.1 8.1 0 0 0 1.046 0l-.064-.998a7.11 7.11 0 0 1-.918 0l-.064.998zM8.5 4.5a.5.5 0 0 0-1 0v3h-3a.5.5 0 0 0 0 1h3v3a.5.5 0 0 0 1 0v-3h3a.5.5 0 0 0 0-1h-3v-3z"/>
                                    </svg>
                                </div>
                                <h5 class="text-muted">Add New Tool</h5>
                                <p class="small text-muted mb-0">This toolkit is designed to grow with your needs. Additional MySQL utilities can be added here.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade" id="interbase" role="tabpanel" aria-labelledby="interbase-tab" tabindex="0">
                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="card h-100 shadow-sm border-success">
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="flex-grow-1">
                                        <h5 class="card-title mb-0"><i class="bi bi-shield-lock"></i> InterBase Backup Tool</h5>
                                    </div>
                                    <span class="badge bg-success">New</span>
                                </div>
                                <p class="card-text">
                                    Create transportable gbak backups for PROD or TEST InterBase databases defined in <code>interbase/ib-config.json</code>.
                                    Select the environment, choose a database, and archive the result to the configured backup directory.
                                </p>
                                <ul class="list-unstyled small">
                                    <li><strong>✓</strong> Uses gbak with environment-specific credentials</li>
                                    <li><strong>✓</strong> Writes .fbk files to the configured backup directory</li>
                                    <li><strong>✓</strong> Displays prerequisite checks and recent backups</li>
                                    <li><strong>✓</strong> Shares tooling with InterBase replication workflow</li>
                                </ul>
                                <a href="interbase/ibBackupTool.php" class="btn btn-success mt-3"><i class="bi bi-hdd-fill"></i> Open InterBase Backup</a>
                            </div>
                        </div>
                    </div>                    <div class="col-md-6">
                        <div class="card h-100 shadow-sm border-warning">
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="flex-grow-1">
                                        <h5 class="card-title mb-0"><i class="bi bi-arrow-counterclockwise"></i> InterBase Restore Tool</h5>
                                    </div>
                                    <span class="badge bg-warning text-dark">Preview</span>
                                </div>
                                <p class="card-text">
                                    Restore .fbk backups to either TEST or PROD destinations using the new restore workflow. Choose a backup, confirm the target path, and issue gbak <code>-rep</code> restores with audit logging.
                                </p>
                                <ul class="list-unstyled small">
                                    <li><strong>&bull;</strong> Pulls backup catalog directly from the shared backup directory</li>
                                    <li><strong>&bull;</strong> Verifies gbak path, destination access, and file existence before restore</li>
                                    <li><strong>&bull;</strong> Requires two explicit confirmations before writing to PROD</li>
                                    <li><strong>&bull;</strong> Captures restore details (size, duration, log path) for review</li>
                                </ul>
                                <a href="interbase/ibRestoreTool.php" class="btn btn-warning mt-3 text-dark"><i class="bi bi-arrow-counterclockwise"></i> Open InterBase Restore</a>
                            </div>
                        </div>
                    </div>



                    <div class="col-md-6">
                        <div class="card h-100 shadow-sm border-info">
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="flex-grow-1">
                                        <h5 class="card-title mb-0"><i class="bi bi-cloud-arrow-left-right"></i> InterBase Replication Tool</h5>
                                    </div>
                                    <span class="badge bg-info text-dark">Beta</span>
                                </div>
                                <p class="card-text">
                                    Refresh TEST InterBase databases with dumps taken directly from PROD. Uses gbak transportable backups to ensure
                                    compatibility and keeps timestamped .fbk copies for recovery.
                                </p>
                                <ul class="list-unstyled small">
                                    <li><strong>&bull;</strong> Reads configured PROD/TEST database list</li>
                                    <li><strong>&bull;</strong> Validates tool paths and connectivity</li>
                                    <li><strong>&bull;</strong> Executes gbak backup/restore via PowerShell</li>
                                    <li><strong>&bull;</strong> Preserves refresh logs and backup files</li>
                                    <li><strong>&bull;</strong> Requires exclusive access to TEST database</li>
                                </ul>
                                <a href="interbase/ibBackupToolReplicate.php" class="btn btn-info mt-3 text-white"><i class="bi bi-cloud-arrow-left-right"></i> Open InterBase Replication</a>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card h-100 shadow-sm border-dashed">
                            <div class="card-body text-center">
                                <div class="text-muted mb-3">
                                    <svg width="64" height="64" fill="currentColor" class="bi bi-plus-circle-dotted" viewBox="0 0 16 16">
                                        <path d="M8 0c-.176 0-.35.006-.523.017l.064.998a7.117 7.117 0 0 1 .918 0l.064-.998A8.113 8.113 0 0 0 8 0zM6.44.152c-.346.069-.684.16-1.012.27l.321.948c.287-.098.582-.177.884-.237L6.44.152zm4.132.271a7.946 7.946 0 0 0-1.011-.27l-.194.98c.302.06.597.14.884.237l.321-.947zm1.873.925a8 8 0 0 0-.906-.524l-.443.896c.275.136.54.29.793.459l.556-.831zM4.46.824c-.314.155-.616.33-.905.524l.556.83a7.07 7.07 0 0 1 .793-.458L4.46.824zM2.725 1.985c-.262.23-.51.478-.74.74l.752.66c.202-.23.418-.446.648-.648l-.66-.752zm11.29.74a8.058 8.058 0 0 0-.74-.74l-.66.752c.23.202.447.418.648.648l.752-.66zm1.161 1.735a7.98 7.98 0 0 0-.524-.905l-.83.556c.169.253.322.518.458.793l.896-.443zM1.348 3.555c-.194.289-.37.591-.524.906l.896.443c.136-.275.29-.54.459-.793l-.831-.556zM.423 5.428a7.945 7.945 0 0 0-.27 1.011l.98.194c.06-.302.14-.597.237-.884l-.947-.321zM15.848 6.44a7.943 7.943 0 0 0-.27-1.012l-.948.321c.098.287.177.582.237.884l.98-.194zM.017 7.477a8.113 8.113 0 0 0 0 1.046l.998-.064a7.117 7.117 0 0 1 0-.918l-.998-.064zM16 8a8.1 8.1 0 0 0-.017-.523l-.998.064a7.11 7.11 0 0 1 0 .918l.998.064A8.1 8.1 0 0 0 16 8zM.152 9.56c.069.346.16.684.27 1.012l.948-.321a6.944 6.944 0 0 1-.237-.884l-.98.194zm15.425 1.012c.112-.328.202-.666.27-1.011l-.98-.194c-.06.302-.14.597-.237.884l.947.321zM.824 11.54a8 8 0 0 0 .524.905l.83-.556a6.999 6.999 0 0 1-.458-.793l-.896.443zm13.828.905c.194-.289.37-.591.524-.906l-.896-.443c-.136.275-.29.54-.459.793l.831.556zm-12.667.83c.23.262.478.51.74.74l.66-.752a7.047 7.047 0 0 1-.648-.648l-.752.66zm11.29.74c.262-.23.51-.478.74-.74l-.752-.66c-.201.23-.418.447-.648.648l.66.752zm-1.735 1.161c.314-.155.616-.33.905-.524l-.556-.83a7.07 7.07 0 0 1-.793.458l.443.896zm-7.985-.524c.289.194.591.37.906.524l.443-.896a6.998 6.998 0 0 1-.793-.459l-.556.831zm1.873.925c.328.112.666.202 1.011.27l.194-.98a6.953 6.953 0 0 1-.884-.237l-.321.947zm4.132.271a7.944 7.944 0 0 0 1.012-.27l-.321-.948a6.954 6.954 0 0 1-.884.237l.194.98zm-2.083.135a8.1 8.1 0 0 0 1.046 0l-.064-.998a7.11 7.11 0 0 1-.918 0l-.064.998zM8.5 4.5a.5.5 0 0 0-1 0v3h-3a.5.5 0 0 0 0 1h3v3a.5.5 0 0 0 1 0v-3h3a.5.5 0 0 0 0-1h-3v-3z"/>
                                    </svg>
                                </div>
                                <h5 class="text-muted">Coming Soon</h5>
                                <p class="small text-muted mb-0">Additional InterBase utilities can plug in here without disturbing shared helpers.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-5 text-center text-muted">
            <p class="small">
                <strong>Environment Info:</strong><br>
                TEST: localhost | PROD: <?php echo defined('DB_PROD_HOST') ? htmlspecialchars(DB_PROD_HOST) : 'see config.php'; ?>
            </p>
        </div>
    </div>
</div>

</body>
</html>


