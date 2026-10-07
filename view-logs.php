<?php
$pageTitle = 'Error Logs';
include $_SERVER['DOCUMENT_ROOT'] . '/dbtools/common/Header.php';
?>
    <style>
        .log-content {
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 20px;
            border-radius: 5px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            white-space: pre-wrap;
            word-wrap: break-word;
            max-height: 600px;
            overflow-y: auto;
        }
        .log-header {
            background: #f8f9fa;
            padding: 15px;
            border-left: 4px solid #0d6efd;
            margin-bottom: 20px;
        }
        .error-line {
            background: #3a1f1f;
            padding: 2px 0;
        }
        .success-line {
            background: #1f3a1f;
            padding: 2px 0;
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <!-- Header -->
        <div class="row mb-4">
            <div class="col">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="index.php">Database Tools</a></li>
                        <li class="breadcrumb-item active">Error Logs</li>
                    </ol>
                </nav>
                <h1 class="h3">Error Logs</h1>
            </div>
        </div>

        <!-- Controls -->
        <div class="row mb-3">
            <div class="col">
                <button class="btn btn-primary" onclick="location.reload()">
                    <i class="bi bi-arrow-clockwise"></i> Refresh
                </button>
                <button class="btn btn-danger" onclick="clearLogs()">
                    <i class="bi bi-trash"></i> Clear All Logs
                </button>
                <a href="db-compare.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Comparison Tool
                </a>
            </div>
        </div>

        <?php
        $errorLogFile = 'D:/AdvancedVentures/logs/dbtools/error.log';
        $sqlLogFile = 'D:/AdvancedVentures/logs/dbtools/sql-execution.log';
        ?>

        <!-- Error Log -->
        <div class="row mb-4">
            <div class="col">
                <div class="card">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0">
                            <i class="bi bi-exclamation-triangle"></i> General Error Log
                            <span class="float-end">
                                <?php
                                if (file_exists($errorLogFile)) {
                                    $size = filesize($errorLogFile);
                                    echo round($size / 1024, 2) . ' KB';
                                } else {
                                    echo 'No log file';
                                }
                                ?>
                            </span>
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="log-content">
<?php
if (file_exists($errorLogFile) && filesize($errorLogFile) > 0) {
    $content = file_get_contents($errorLogFile);
    // Highlight error lines
    $content = preg_replace('/^(.*ERROR.*|.*FAILED.*)$/m', '<span class="error-line">$1</span>', $content);
    echo htmlspecialchars($content);
} else {
    echo "No errors logged yet.";
}
?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- SQL Execution Log -->
        <div class="row mb-4">
            <div class="col">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="bi bi-database"></i> SQL Execution Log
                            <span class="float-end">
                                <?php
                                if (file_exists($sqlLogFile)) {
                                    $size = filesize($sqlLogFile);
                                    echo round($size / 1024, 2) . ' KB';
                                } else {
                                    echo 'No log file';
                                }
                                ?>
                            </span>
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="log-content">
<?php
if (file_exists($sqlLogFile) && filesize($sqlLogFile) > 0) {
    $content = file_get_contents($sqlLogFile);
    // Highlight success/failed lines
    $content = preg_replace('/^(.*FAILED.*)$/m', '<span class="error-line">$1</span>', $content);
    $content = preg_replace('/^(.*SUCCESS.*)$/m', '<span class="success-line">$1</span>', $content);
    echo htmlspecialchars($content);
} else {
    echo "No SQL executions logged yet.";
}
?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Log Files Info -->
        <div class="row">
            <div class="col">
                <div class="alert alert-info">
                    <strong>Log File Locations:</strong><br>
                    <code><?php echo $errorLogFile; ?></code><br>
                    <code><?php echo $sqlLogFile; ?></code>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function clearLogs() {
            if (confirm('Are you sure you want to clear all logs? This cannot be undone.')) {
                $.post('ajax-clear-logs.php', function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error clearing logs: ' + response.error);
                    }
                }, 'json');
            }
        }

        // Auto-scroll to bottom of logs
        $(document).ready(function() {
            $('.log-content').each(function() {
                $(this).scrollTop($(this)[0].scrollHeight);
            });
        });
    </script>
</body>
</html>
