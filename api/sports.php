<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();
verify_csrf();

$uid    = currentUserId();
$action = sanitizeInput($_POST['action'] ?? '');

switch ($action) {

    case 'log':
        $sport = sanitizeInput($_POST['sport'] ?? '');
        if (!$sport) json_out(['success' => false, 'error' => 'Sport is required.'], 422);
        $id = insert(
            "INSERT INTO sports_sessions (user_id,sport,session_type,duration_min,notes,session_date) VALUES (?,?,?,?,?,?)",
            [$uid, $sport, sanitizeInput($_POST['session_type'] ?? 'training'),
             $_POST['duration_min'] !== '' ? (int)$_POST['duration_min'] : null,
             sanitizeInput($_POST['notes'] ?? '') ?: null, sanitizeInput($_POST['session_date'] ?? date('Y-m-d'))]
        );
        require_once '../includes/activity.php';
        $sessDate = sanitizeInput($_POST['session_date'] ?? '') ?: date('Y-m-d');
        $xp = recordActivity($uid, 'sports_session', 'log:' . date('Y-m-d'), (int)$id,
                             ['date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $sessDate) ? $sessDate : date('Y-m-d')]);
        json_out(['success' => true, 'id' => $id, 'xp' => $xp]);

    case 'delete':
        $id = (int)($_POST['item_id'] ?? 0);
        delete("DELETE FROM sports_sessions WHERE id=? AND user_id=?", [$id, $uid]);
        json_out(['success' => true]);

    default:
        json_out(['success' => false, 'error' => 'Unknown action.'], 400);
}
