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

/** Validated session fields from POST (exits 422 on bad input). */
function sportsInput(): array {
    $sport = mb_substr(trim(sanitizeInput($_POST['sport'] ?? '')), 0, 50);
    if ($sport === '') json_out(['success' => false, 'error' => 'Sport is required.'], 422);
    $type = in_array($_POST['session_type'] ?? '', ['training', 'match', 'practice', 'casual'], true) ? $_POST['session_type'] : 'training';
    $dur  = trim((string)($_POST['duration_min'] ?? '')) === '' ? null : (int)$_POST['duration_min'];
    if ($dur !== null && ($dur < 1 || $dur > 1440)) json_out(['success' => false, 'error' => 'Duration must be 1–1,440 minutes.'], 422);
    $date = (string)($_POST['session_date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
    if ($date > date('Y-m-d')) json_out(['success' => false, 'error' => "A session can't be in the future."], 422);
    // Result/score only mean something for matches.
    $result = $type === 'match' && in_array($_POST['result'] ?? '', ['win', 'loss', 'draw'], true) ? $_POST['result'] : null;
    $score  = $type === 'match' ? (mb_substr(trim(sanitizeInput($_POST['score'] ?? '')), 0, 40) ?: null) : null;
    $int    = (int)($_POST['intensity'] ?? 0);
    return [$sport, $type, $result, $score, ($int >= 1 && $int <= 5) ? $int : null, $dur,
            mb_substr(trim(sanitizeInput($_POST['notes'] ?? '')), 0, 500) ?: null, $date];
}

switch ($action) {

    case 'log':
        [$sport, $type, $result, $score, $int, $dur, $notes, $date] = sportsInput();
        $id = (int)insert(
            "INSERT INTO sports_sessions (user_id,sport,session_type,result,score,intensity,duration_min,notes,session_date) VALUES (?,?,?,?,?,?,?,?,?)",
            [$uid, $sport, $type, $result, $score, $int, $dur, $notes, $date]
        );
        // XP key kept as before (logged-on date) so nobody is paid twice; activity uses the session date.
        $xp = recordActivity($uid, 'sports_session', 'log:' . date('Y-m-d'), $id, ['date' => $date]);
        json_out(['success' => true, 'id' => $id, 'xp' => $xp]);

    case 'get':
        $s = fetchOne("SELECT * FROM sports_sessions WHERE id=? AND user_id=?", [(int)($_POST['item_id'] ?? 0), $uid]);
        if (!$s) json_out(['success' => false, 'error' => 'Session not found.'], 404);
        json_out(['success' => true, 'session' => $s]);

    case 'edit':
        $id = (int)($_POST['item_id'] ?? 0);
        if (!fetchOne("SELECT id FROM sports_sessions WHERE id=? AND user_id=?", [$id, $uid])) json_out(['success' => false, 'error' => 'Session not found.'], 404);
        [$sport, $type, $result, $score, $int, $dur, $notes, $date] = sportsInput();
        update("UPDATE sports_sessions SET sport=?,session_type=?,result=?,score=?,intensity=?,duration_min=?,notes=?,session_date=? WHERE id=? AND user_id=?",
               [$sport, $type, $result, $score, $int, $dur, $notes, $date, $id, $uid]);
        // Keep the activity on the (possibly new) date.
        update("UPDATE activity_log SET occurred_on=? WHERE user_id=? AND action='sports_session' AND ref_id=?", [$date, $uid, $id]);
        json_out(['success' => true]);

    case 'delete':
        $id = (int)($_POST['item_id'] ?? 0);
        $n = delete("DELETE FROM sports_sessions WHERE id=? AND user_id=?", [$id, $uid]);
        if ($n) update("DELETE FROM activity_log WHERE user_id=? AND action='sports_session' AND ref_id=?", [$uid, $id]);
        json_out(['success' => true]);

    default:
        json_out(['success' => false, 'error' => 'Unknown action.'], 400);
}
