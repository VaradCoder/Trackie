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
        $duration = (int)($_POST['duration_min'] ?? 0);
        if ($duration < 1) json_out(['success' => false, 'error' => 'Duration is required.'], 422);
        $id = insert(
            "INSERT INTO meditation_sessions (user_id,duration_min,mood_before,mood_after,notes,session_date) VALUES (?,?,?,?,?,?)",
            [$uid, $duration,
             $_POST['mood_before'] !== '' ? (int)$_POST['mood_before'] : null,
             $_POST['mood_after']  !== '' ? (int)$_POST['mood_after']  : null,
             sanitizeInput($_POST['notes'] ?? '') ?: null, date('Y-m-d')]
        );
        require_once '../includes/activity.php';
        $xp = recordActivity($uid, 'meditation_session', 'log:' . date('Y-m-d'), (int)$id);
        json_out(['success' => true, 'id' => $id, 'xp' => $xp]);

    case 'delete':
        $id = (int)($_POST['item_id'] ?? 0);
        delete("DELETE FROM meditation_sessions WHERE id=? AND user_id=?", [$id, $uid]);
        json_out(['success' => true]);

    default:
        json_out(['success' => false, 'error' => 'Unknown action.'], 400);
}
