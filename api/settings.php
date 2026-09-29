<?php
/** User preferences (user_settings). */
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/settings.php';

requireAuth();
verify_csrf();

$uid    = currentUserId();
$action = sanitizeInput($_POST['action'] ?? '');

if (!tableExists('user_settings')) {
    json_out(['success' => false, 'error' => 'Preferences need a database update — open Setup once.'], 503);
}

try {
    switch ($action) {
        case 'get':
            json_out(['success' => true, 'settings' => userSettings($uid)]);

        case 'save':
            $fields = array_intersect_key($_POST, SETTINGS_DEFAULTS);
            json_out(['success' => true, 'settings' => saveUserSettings($uid, $fields)]);

        default:
            json_out(['success' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (InvalidArgumentException $e) {
    json_out(['success' => false, 'error' => $e->getMessage()], 422);
}
