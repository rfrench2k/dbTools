<?php
// Error handling
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Application Settings
define('APP_ROOT', dirname(__DIR__));
define('LOG_FILE_PATH', 'D:/AdvancedVentures/logs/dbtools/dbtools.log');

/**
 * Enhanced logging function
 */
function logMessage($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');

    if (is_array($message)) {
        $message = print_r($message, true);
    }

    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
    $caller = 'unknown:0';
    if (isset($backtrace[1]['file'], $backtrace[1]['line'])) {
        $caller = basename($backtrace[1]['file']) . ':' . $backtrace[1]['line'];
    }

    $logMessage = sprintf(
        "[%s] [%s] [%s] %s\n",
        $timestamp,
        strtoupper($level),
        $caller,
        $message
    );

    file_put_contents(LOG_FILE_PATH, $logMessage, FILE_APPEND | LOCK_EX);
}

/**
 * Custom error handler
 */
function errorHandler($errno, $errstr, $errfile, $errline) {
    $errorType = match($errno) {
        E_ERROR => 'ERROR',
        E_WARNING => 'WARNING',
        E_NOTICE => 'NOTICE',
        default => 'DEBUG'
    };

    logMessage("$errstr in $errfile on line $errline", $errorType);
    return true;
}

set_error_handler("errorHandler");
?>
