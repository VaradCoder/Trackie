<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();
verify_csrf();

$uid    = currentUserId();
$action = sanitizeInput($_POST['action'] ?? '');

$validCats = ['Fitness','Work','Study','Personal','Health','Break'];

require_once '../includes/habit_schedule.php';
/** Weekdays the routine is due ("1,3,5"), or null for every day — same rules as habits. */
function routineDaysInput(): ?string {
    return normalizeScheduleDays($_POST['schedule_days'] ?? '');
}

switch ($action) {

    case 'add':
        $title    = sanitizeInput($_POST['title']       ?? '');
        $time     = sanitizeInput($_POST['time_slot']   ?? '');
        $category = sanitizeInput($_POST['category']    ?? 'Personal');
        $desc     = sanitizeInput($_POST['description'] ?? '');

        if (!$title || !$time) json_out(['success' => false, 'error' => 'Title and time are required.'], 422);
        if (!in_array($category, $validCats, true)) $category = 'Personal';

        $id = insert(
            "INSERT INTO routines (user_id,title,time_slot,schedule_days,category,description) VALUES (?,?,?,?,?,?)",
            [$uid, $title, $time, routineDaysInput(), $category, $desc]
        );
        json_out(['success' => true, 'id' => $id]);

    case 'edit':
        $id       = (int)($_POST['routine_id']   ?? 0);
        $title    = sanitizeInput($_POST['title']       ?? '');
        $time     = sanitizeInput($_POST['time_slot']   ?? '');
        $category = sanitizeInput($_POST['category']    ?? 'Personal');
        $desc     = sanitizeInput($_POST['description'] ?? '');

        if (!$id || !$title || !$time) json_out(['success' => false, 'error' => 'Invalid input.'], 422);
        if (!in_array($category, $validCats, true)) $category = 'Personal';

        update(
            "UPDATE routines SET title=?,time_slot=?,schedule_days=?,category=?,description=? WHERE id=? AND user_id=?",
            [$title, $time, routineDaysInput(), $category, $desc, $id, $uid]
        );
        json_out(['success' => true]);

    case 'get':
        $id = (int)($_POST['routine_id'] ?? $_GET['id'] ?? 0);
        $row = fetchOne("SELECT * FROM routines WHERE id=? AND user_id=?", [$id, $uid]);
        $row ? json_out(['success' => true, 'routine' => $row])
             : json_out(['success' => false, 'error' => 'Not found.'], 404);

    case 'delete':
        $id = (int)($_POST['routine_id'] ?? 0);
        if (!$id) json_out(['success' => false, 'error' => 'Invalid ID.'], 422);
        delete("DELETE FROM routines WHERE id=? AND user_id=?", [$id, $uid]);
        json_out(['success' => true]);

    // ── Completion ───────────────────────────────────────────────────
    // Mirrors the habit-log contract: idempotent per day, XP granted once,
    // and the routine now feeds streaks, achievements and the Trackie Score.
    case 'complete':
        $id   = (int)($_POST['routine_id'] ?? 0);
        $date = sanitizeInput($_POST['date'] ?? date('Y-m-d'));
        if (!$id) json_out(['success' => false, 'error' => 'Invalid ID.'], 422);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
        // A routine can't be done in the future — each future date would
        // otherwise be a fresh XP award (farmable).
        if ($date > date('Y-m-d')) json_out(['success' => false, 'error' => "You can't complete a routine for a future day."], 422);
        if (!tableExists('routine_logs')) {
            json_out(['success' => false, 'error' => 'Routine tracking is not set up yet — run /pages/setup.php.'], 503);
        }

        // Ownership check before any write.
        if (!fetchOne("SELECT id FROM routines WHERE id=? AND user_id=?", [$id, $uid])) {
            json_out(['success' => false, 'error' => 'Not found.'], 404);
        }

        // INSERT IGNORE + the UNIQUE key makes a repeat tap a no-op rather
        // than an error, so an offline replay can't double-count.
        insert("INSERT IGNORE INTO routine_logs (user_id,routine_id,log_date) VALUES (?,?,?)",
               [$uid, $id, $date]);

        require_once '../includes/activity.php';
        $xp = recordActivity($uid, 'routine', 'routine:' . $date, $id, ['date' => $date]);
        checkAchievements($uid);

        json_out(['success' => true, 'completed' => true, 'date' => $date, 'xp' => $xp]);

    case 'uncomplete':
        $id   = (int)($_POST['routine_id'] ?? 0);
        $date = sanitizeInput($_POST['date'] ?? date('Y-m-d'));
        if (!$id) json_out(['success' => false, 'error' => 'Invalid ID.'], 422);
        if (!tableExists('routine_logs')) json_out(['success' => true, 'completed' => false]);

        delete("DELETE FROM routine_logs WHERE routine_id=? AND log_date=? AND user_id=?",
               [$id, $date, $uid]);
        // XP is deliberately NOT clawed back — awardXpOnce keys on the date,
        // so re-completing the same day grants nothing further. Removing XP
        // here would let a toggle drain a user's total. The ACTIVITY is
        // removed, so streaks and analytics reflect what's really done.
        require_once '../includes/activity.php';
        undoActivity($uid, 'routine', 'routine:' . $date, $id);
        json_out(['success' => true, 'completed' => false, 'date' => $date]);

    default:
        json_out(['success' => false, 'error' => 'Unknown action.'], 400);
}
