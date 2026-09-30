<?php
/**
 * Focus API — Pomodoro sessions. Reuses the focus_sessions table.
 */
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/gamification.php';

requireAuth();
verify_csrf();

$uid    = currentUserId();
$action = sanitizeInput($_POST['action'] ?? '');

if (!tableExists('focus_sessions')) {
    json_out(['success' => false, 'error' => 'Focus sessions are not set up yet — run /pages/setup.php.'], 503);
}

switch ($action) {

    // Record a COMPLETED focus session (timer runs client-side; we log the result).
    case 'complete':
        $type     = in_array($_POST['type'] ?? '', ['focus','short_break','long_break'], true) ? $_POST['type'] : 'focus';
        $duration = max(1, min(180, (int)($_POST['duration'] ?? 25)));
        $mode     = mb_substr(sanitizeInput($_POST['mode'] ?? ''), 0, 40);  // Study/Work/Coding/Reading/custom label

        // When the session ended. Sent by the client so an offline session
        // replayed later keeps its real time; clamped to the last 7 days.
        $endTs = (int)($_POST['ended_at'] ?? 0);
        if ($endTs <= 0 || $endTs > time() + 60 || $endTs < time() - 7 * 86400) $endTs = time();
        $endTs   = min($endTs, time());
        $startTs = $endTs - $duration * 60;

        // Sessions can't overlap in time (breaks included). Without this, a
        // repeated or scripted 'complete' logged unlimited +20 XP sessions.
        $clash = fetchOne(
            "SELECT id FROM focus_sessions WHERE user_id=? AND completed=1 AND started_at < ? AND completed_at > ? LIMIT 1",
            [$uid, date('Y-m-d H:i:s', $endTs - 30), date('Y-m-d H:i:s', $startTs + 30)]
        );
        if ($clash) json_out(['success' => false, 'error' => 'This session overlaps one that is already saved.'], 409);

        // Optional: the task this session was for (must be the user's own).
        $todoId = (int)($_POST['todo_id'] ?? 0) ?: null;
        if ($todoId && !fetchOne("SELECT id FROM todos WHERE id=? AND user_id=? AND deleted_at IS NULL", [$todoId, $uid])) $todoId = null;

        $sessionId = insert(
            "INSERT INTO focus_sessions (user_id, todo_id, duration_min, type, mode, started_at, completed_at, completed)
             VALUES (?,?,?,?,?,?,?,1)",
            [$uid, $todoId, $duration, $type, $mode !== '' ? $mode : null, date('Y-m-d H:i:s', $startTs), date('Y-m-d H:i:s', $endTs)]
        );

        $xp = null;
        if ($type === 'focus') {
            require_once '../includes/activity.php';
            $xp = recordActivity($uid, 'focus', 'focus_session', (int)$sessionId, ['date' => date('Y-m-d', $endTs)]);   // +20 XP per focus session
            checkAchievements($uid);                            // unlocks Focus Warrior at 10
        }
        $stats = focusStats($uid);
        json_out(['success' => true, 'xp' => $xp, 'stats' => $stats]);

    case 'stats':
        json_out(['success' => true, 'stats' => focusStats($uid)]);

    case 'breakdown':
        $days = (int)($_POST['days'] ?? $_GET['days'] ?? 30);
        if ($days < 1 || $days > 365) $days = 30;

        $rows = fetchAll(
            "SELECT COALESCE(NULLIF(mode,''), 'Other') AS mode, SUM(duration_min) AS minutes, COUNT(*) AS sessions
             FROM focus_sessions
             WHERE user_id=? AND type='focus' AND completed=1
               AND started_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             GROUP BY mode
             ORDER BY minutes DESC",
            [$uid, $days]
        );
        json_out(['success' => true, 'breakdown' => $rows]);

    default:
        json_out(['success' => false, 'error' => 'Unknown action.'], 400);
}
