<?php
/**
 * Admin API — restricted to admins. CSRF-protected.
 */
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAdmin();
verify_csrf();

$uid    = currentUserId();
$action = sanitizeInput($_POST['action'] ?? '');

switch ($action) {

    case 'toggle_admin':
        $id = (int)($_POST['user_id'] ?? 0);
        if (!$id) json_out(['success' => false, 'error' => 'Invalid user.'], 422);
        if ($id === $uid) json_out(['success' => false, 'error' => "You can't change your own role."], 422);

        $u = fetchOne("SELECT is_admin FROM users WHERE id=?", [$id]);
        if (!$u) json_out(['success' => false, 'error' => 'User not found.'], 404);

        $new = $u['is_admin'] ? 0 : 1;
        update("UPDATE users SET is_admin=? WHERE id=?", [$new, $id]);
        json_out(['success' => true, 'is_admin' => $new]);

    default:
        json_out(['success' => false, 'error' => 'Unknown action.'], 400);
}
