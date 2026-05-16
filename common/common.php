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

// Include database functions
require_once __DIR__ . '/../common-dbfunctions.php';
?>
