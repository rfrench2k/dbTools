<?php
require_once __DIR__ . '/baseFunctions.php';

// Include auth functions and get authenticated user
require_once $_SERVER['DOCUMENT_ROOT'] . '/auth/includes/auth_functions.php';

if (php_sapi_name() !== 'cli') {
    $GLOBALS['sessionUser'] = auth_getAuthenticatedUser('DBTOOLS');
    // Note: We set $GLOBALS['sessionUser'] but don't exit
    // - HTML files: Header.php handles redirect if not authenticated
    // - Code.php files: Use getCurrentUserId() which returns null if not authenticated
}

/**
 * Get current user ID from session
 */
function getCurrentUserId() {
    static $currentUserId = null;

    if ($currentUserId === null) {
        if (isset($GLOBALS['sessionUser']['user_id'])) {
            $currentUserId = $GLOBALS['sessionUser']['user_id'];
            logMessage('getCurrentUserId: Got user_id from session: ' . $currentUserId, 'DEBUG');
        } else {
            logMessage('getCurrentUserId: No session user available', 'DEBUG');
            return null;
        }
    }

    return $currentUserId;
}

/**
 * Every DB Tools action (ajax-*.php, interbase/*Ajax.php): SUPERADMIN only, and only from DB Tools' own pages
 * (their jQuery calls send X-Requested-With; another site cannot, so a page elsewhere can't fire these through
 * the browser of a signed-in owner). Answers 401/403 JSON and stops.
 */
function dbtoolsRequireAction(): void {
    if (php_sapi_name() === 'cli') return;
    $level = $GLOBALS['sessionUser']['user_level'] ?? '';
    $fromPage = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    if (!getCurrentUserId() || !auth_checkPermissionLevel($level, 'SUPERADMIN') || !$fromPage) {
        if (!headers_sent()) { header('Content-Type: application/json'); http_response_code(getCurrentUserId() ? 403 : 401); }
        echo json_encode(['success' => false, 'error' => getCurrentUserId() ? 'Not allowed' : 'Not authenticated']);
        exit;
    }
}

// Include database functions
require_once __DIR__ . '/../common-dbfunctions.php';
?>
