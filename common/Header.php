<?php
// ============================================================================
// AUTHENTICATION SYSTEM - REQUIRED FOR SECURITY
// ============================================================================
// Error logging for debugging 500 errors
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    $log = sprintf("[%s] PHP Error %d: %s in %s on line %d\n",
        date('Y-m-d H:i:s'), $errno, $errstr, $errfile, $errline);
    file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/dbtools/php_errors.log', $log, FILE_APPEND);
    return false; // Let PHP handle it normally too
});
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $log = sprintf("[%s] FATAL: %s in %s on line %d\n",
            date('Y-m-d H:i:s'), $error['message'], $error['file'], $error['line']);
        file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/dbtools/php_errors.log', $log, FILE_APPEND);
    }
});

include_once $_SERVER['DOCUMENT_ROOT'] . '/auth/includes/auth_functions.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/auth/includes/navbar_user.php';
$user = auth_checkProgramAccess('DBTOOLS', 'SUPERADMIN');
// ============================================================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="shortcut icon" href="/dbtools/database-icon.svg" />    
    <title><?php echo isset($pageTitle) ? $pageTitle . ' - ' : ''; ?>Database Tools</title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <!-- ⚠️ CRITICAL: Set APP_PROGRAM_ID BEFORE loading auth-monitor.js -->
    <script>window.APP_PROGRAM_ID = "DBTOOLS";</script>

    <!-- Auth Monitor - Session monitoring -->
    <script src="/auth/includes/auth-monitor.js"></script>

    <!-- Custom CSS -->
    <link rel="stylesheet" href="/dbtools/assets/style.css">

    <style>
        body {
            padding-top: 60px;
        }
        .navbar {
            position: fixed;
            top: 0;
            width: 100%;
            z-index: 1030;
        }
    </style>
</head>
<body>

<?php
// ============================================================================
// AUTHENTICATION SYSTEM - User Settings & Linked Accounts Modals
// ============================================================================
echo getNavbarUserModalsHTML('DBTOOLS');
// ============================================================================
?>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="/dbtools/">
            <i class="bi bi-database"></i> Database Tools
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="mysqlDropdown" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-database"></i> MySQL Tools
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="/dbtools/db-compare.php">
                            <i class="bi bi-columns-gap"></i> Database Comparison
                        </a></li>
                        <li><a class="dropdown-item" href="/dbtools/nightly-backup.php">
                            <i class="bi bi-cloud-check"></i> Nightly Backup (Google Drive)
                        </a></li>
                        <li><a class="dropdown-item" href="/dbtools/backup-tool.php">
                            <i class="bi bi-database-fill-down"></i> Backup Tool
                        </a></li>
                        <li><a class="dropdown-item" href="/dbtools/db-replicate.php">
                            <i class="bi bi-arrow-repeat"></i> Replication Tool
                        </a></li>
                    </ul>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="interbaseDropdown" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-hdd-network"></i> InterBase Tools
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="/dbtools/interbase/ibBackupTool.php">
                            <i class="bi bi-shield-lock"></i> Backup Tool
                        </a></li>
                        <li><a class="dropdown-item" href="/dbtools/interbase/ibRestoreTool.php">
                            <i class="bi bi-arrow-counterclockwise"></i> Restore Tool
                        </a></li>
                        <li><a class="dropdown-item" href="/dbtools/interbase/ibBackupToolReplicate.php">
                            <i class="bi bi-cloud-arrow-left-right"></i> Replication Tool
                        </a></li>
                    </ul>
                </li>
            </ul>

            <!-- ============================================================ -->
            <!-- AUTHENTICATION SYSTEM - User Display                         -->
            <!-- ============================================================ -->
            <div class="navbar-nav ms-auto">
                <?php echo getNavbarUserHTML('DBTOOLS'); ?>
            </div>
            <!-- ============================================================ -->
        </div>
    </div>
</nav>

<div class="container-fluid mt-4">
