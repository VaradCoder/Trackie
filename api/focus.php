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
        $mode     = sanitizeInput($_POST['mode'] ?? '');  // Study/Work/Coding/Reading
        $started  = date('Y-m-d H:i:s', time() - $duration * 60);

        insert(
            "INSERT INTO focus_sessions (user_id, duration_min, type, mode, started_at, completed_at, completed)
             VALUES (?,?,?,?,?,NOW(),1)",
            [$uid, $duration, $type, $mode !== '' ? $mode : null, $started]
        );

        $xp = null;
        if ($type === 'focus') {
            $xp = awardXp($uid, 'focus', 20, 'focus', null);   // +20 XP per focus session
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
