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

    case 'add':
        $name  = sanitizeInput($_POST['name']      ?? '');
        $freq  = sanitizeInput($_POST['frequency'] ?? 'daily');
        $color = sanitizeInput($_POST['color']     ?? '#ef4444');

        if (!$name) json_out(['success' => false, 'error' => 'Name is required.'], 422);
        if (!in_array($freq, ['daily','weekly'], true)) $freq = 'daily';
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#ef4444';

        $id = insert(
            "INSERT INTO habits (user_id,name,frequency,color) VALUES (?,?,?,?)",
            [$uid, $name, $freq, $color]
        );
        json_out(['success' => true, 'id' => $id]);

    case 'log':
        $id     = (int)($_POST['habit_id']       ?? 0);
        $date   = sanitizeInput($_POST['date']   ?? date('Y-m-d'));
        $status = sanitizeInput($_POST['status'] ?? 'done');
        if (!in_array($status, ['done', 'fail', 'skip'], true)) $status = 'done';

        if (!$id) json_out(['success' => false, 'error' => 'Invalid habit.'], 422);

        // Verify ownership
        $habit = fetchOne("SELECT id FROM habits WHERE id=? AND user_id=?", [$id, $uid]);
        if (!$habit) json_out(['success' => false, 'error' => 'Not found.'], 404);

        // A day can only carry one status — clear whichever table isn't the target first.
        delete("DELETE FROM logs WHERE habit_id=? AND date_completed=? AND user_id=?", [$id, $date, $uid]);
        delete("DELETE FROM habit_status_log WHERE habit_id=? AND log_date=? AND user_id=?", [$id, $date, $uid]);

        if ($status === 'done') {
            insert(
                "INSERT INTO logs (user_id,habit_id,date_completed) VALUES (?,?,?)",
                [$uid, $id, $date]
            );
            require_once '../includes/activity.php';
            // Once per habit per day; ref_type carries the date so each day counts.
            $xp = recordActivity($uid, 'habit', 'log:' . $date, $id, ['date' => $date]);
            awardStreakMilestones($uid);
            checkAchievements($uid);
            json_out(['success' => true, 'status' => 'done', 'xp' => $xp]);
        }

        // fail / skip — no XP, just recorded so the day shows a clear state.
        // A day can only carry one status, so a previous 'done' is no longer an activity.
        require_once '../includes/activity.php';
        undoActivity($uid, 'habit', 'log:' . $date, $id);
        insert(
            "INSERT INTO habit_status_log (user_id,habit_id,log_date,status) VALUES (?,?,?,?)",
            [$uid, $id, $date, $status]
        );
        json_out(['success' => true, 'status' => $status]);

    case 'unlog':
        $id   = (int)($_POST['habit_id']      ?? 0);
        $date = sanitizeInput($_POST['date']  ?? date('Y-m-d'));
        if (!$id) json_out(['success' => false, 'error' => 'Invalid habit.'], 422);

        delete(
            "DELETE FROM logs WHERE habit_id=? AND date_completed=? AND user_id=?",
            [$id, $date, $uid]
        );
        delete(
            "DELETE FROM habit_status_log WHERE habit_id=? AND log_date=? AND user_id=?",
            [$id, $date, $uid]
        );
        require_once '../includes/activity.php';
        undoActivity($uid, 'habit', 'log:' . $date, $id);
        json_out(['success' => true]);

    case 'delete':
        $id = (int)($_POST['habit_id'] ?? 0);
        if (!$id) json_out(['success' => false, 'error' => 'Invalid ID.'], 422);
        delete("DELETE FROM habits WHERE id=? AND user_id=?", [$id, $uid]);
        json_out(['success' => true]);

    case 'history':
        $id   = (int)($_POST['habit_id'] ?? $_GET['habit_id'] ?? 0);
        $days = (int)($_POST['days'] ?? $_GET['days'] ?? 90);
        if ($days < 1 || $days > 365) $days = 90;
        if (!$id) json_out(['success' => false, 'error' => 'Invalid habit.'], 422);

        // Verify habit belongs to user
        $habit = fetchOne("SELECT id FROM habits WHERE id=? AND user_id=?", [$id, $uid]);
        if (!$habit) json_out(['success' => false, 'error' => 'Not found.'], 404);

        $logs = fetchAll(
            "SELECT date_completed FROM logs WHERE habit_id=? AND user_id=?
             AND date_completed >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             ORDER BY date_completed ASC",
            [$id, $uid, $days]
        );
        json_out(['success' => true, 'dates' => array_column($logs, 'date_completed')]);

    default:
        json_out(['success' => false, 'error' => 'Unknown action.'], 400);
}
