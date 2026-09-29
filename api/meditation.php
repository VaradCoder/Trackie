<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/activity.php';

requireAuth();
verify_csrf();

$uid    = currentUserId();
$action = sanitizeInput($_POST['action'] ?? '');

const MED_TECHNIQUES = ['breath', 'body_scan', 'loving_kindness', 'visualization', 'mantra', 'walking', 'unguided'];

function medInput(): array {
    $dur = (int)($_POST['duration_min'] ?? 0);
    if ($dur < 1 || $dur > 600) json_out(['success' => false, 'error' => 'Duration must be 1–600 minutes.'], 422);
    $mood = static function (string $k): ?int {
        $v = trim((string)($_POST[$k] ?? ''));
        if ($v === '') return null;
        $v = (int)$v;
        if ($v < 1 || $v > 5) json_out(['success' => false, 'error' => 'Mood must be 1–5.'], 422);
        return $v;
    };
    $date = (string)($_POST['session_date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
    if ($date > date('Y-m-d')) json_out(['success' => false, 'error' => "A session can't be in the future."], 422);
    $tech = in_array($_POST['technique'] ?? '', MED_TECHNIQUES, true) ? $_POST['technique'] : null;
    return [$dur, $tech, $mood('mood_before'), $mood('mood_after'), mb_substr(trim(sanitizeInput($_POST['notes'] ?? '')), 0, 500) ?: null, $date];
}

switch ($action) {

    case 'log':
        [$dur, $tech, $before, $after, $notes, $date] = medInput();
        $id = (int)insert(
            "INSERT INTO meditation_sessions (user_id,duration_min,technique,mood_before,mood_after,notes,session_date) VALUES (?,?,?,?,?,?,?)",
            [$uid, $dur, $tech, $before, $after, $notes, $date]
        );
        $xp = recordActivity($uid, 'meditation_session', 'log:' . date('Y-m-d'), $id, ['date' => $date]);
        json_out(['success' => true, 'id' => $id, 'xp' => $xp]);

    case 'get':
        $s = fetchOne("SELECT * FROM meditation_sessions WHERE id=? AND user_id=?", [(int)($_POST['item_id'] ?? 0), $uid]);
        if (!$s) json_out(['success' => false, 'error' => 'Session not found.'], 404);
        json_out(['success' => true, 'session' => $s]);

    case 'edit':
        $id = (int)($_POST['item_id'] ?? 0);
        if (!fetchOne("SELECT id FROM meditation_sessions WHERE id=? AND user_id=?", [$id, $uid])) json_out(['success' => false, 'error' => 'Session not found.'], 404);
        [$dur, $tech, $before, $after, $notes, $date] = medInput();
        update("UPDATE meditation_sessions SET duration_min=?,technique=?,mood_before=?,mood_after=?,notes=?,session_date=? WHERE id=? AND user_id=?",
               [$dur, $tech, $before, $after, $notes, $date, $id, $uid]);
        update("UPDATE activity_log SET occurred_on=? WHERE user_id=? AND action='meditation_session' AND ref_id=?", [$date, $uid, $id]);
        json_out(['success' => true]);

    case 'delete':
        $id = (int)($_POST['item_id'] ?? 0);
        if (delete("DELETE FROM meditation_sessions WHERE id=? AND user_id=?", [$id, $uid])) {
            update("DELETE FROM activity_log WHERE user_id=? AND action='meditation_session' AND ref_id=?", [$uid, $id]);
        }
        json_out(['success' => true]);

    default:
        json_out(['success' => false, 'error' => 'Unknown action.'], 400);
}
