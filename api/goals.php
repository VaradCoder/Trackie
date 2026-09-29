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
        $name   = sanitizeInput($_POST['goal_name']   ?? '');
        $desc   = sanitizeInput($_POST['description'] ?? '');
        $target = max(1, (int)($_POST['target_value'] ?? 100));
        $prog   = max(0, (int)($_POST['progress']     ?? 0));
        $prog   = min($prog, $target);
        $dead   = sanitizeInput($_POST['deadline']    ?? '') ?: null;

        if (!$name) json_out(['success' => false, 'error' => 'Goal name is required.'], 422);

        $id = insert(
            "INSERT INTO goals (user_id,goal_name,description,progress,target_value,deadline)
             VALUES (?,?,?,?,?,?)",
            [$uid, $name, $desc, $prog, $target, $dead]
        );
        json_out(['success' => true, 'id' => $id]);

    case 'update_progress':
        $id   = (int)($_POST['goal_id']  ?? 0);
        $prog = (int)($_POST['progress'] ?? 0);
        if (!$id) json_out(['success' => false, 'error' => 'Invalid ID.'], 422);

        $goal = fetchOne("SELECT target_value FROM goals WHERE id=? AND user_id=?", [$id, $uid]);
        if (!$goal) json_out(['success' => false, 'error' => 'Not found.'], 404);

        $prog = max(0, min($prog, (int)$goal['target_value']));
        update("UPDATE goals SET progress=? WHERE id=? AND user_id=?", [$prog, $id, $uid]);
        $xp = null;
        if ($prog >= (int)$goal['target_value']) {
            require_once '../includes/activity.php';
            $xp = recordActivity($uid, 'goal', 'goal', $id);   // once per goal completion
            checkAchievements($uid);
        }
        json_out(['success' => true, 'progress' => $prog, 'xp' => $xp]);

    case 'get':
        $g = fetchOne("SELECT id, goal_name, description, progress, target_value, deadline FROM goals WHERE id=? AND user_id=?", [(int)($_POST['goal_id'] ?? 0), $uid]);
        if (!$g) json_out(['success' => false, 'error' => 'Goal not found.'], 404);
        json_out(['success' => true, 'goal' => $g]);

    case 'edit':
        $id     = (int)($_POST['goal_id'] ?? 0);
        $name   = sanitizeInput($_POST['goal_name']   ?? '');
        $desc   = sanitizeInput($_POST['description'] ?? '');
        $target = max(1, (int)($_POST['target_value'] ?? 100));
        $prog   = min(max(0, (int)($_POST['progress'] ?? 0)), $target);
        $dead   = sanitizeInput($_POST['deadline']    ?? '') ?: null;
        if (!$name) json_out(['success' => false, 'error' => 'Goal name is required.'], 422);
        if (!fetchOne("SELECT id FROM goals WHERE id=? AND user_id=?", [$id, $uid])) json_out(['success' => false, 'error' => 'Goal not found.'], 404);
        update("UPDATE goals SET goal_name=?, description=?, progress=?, target_value=?, deadline=? WHERE id=? AND user_id=?",
               [$name, $desc, $prog, $target, $dead, $id, $uid]);
        $xp = null;
        if ($prog >= $target) { require_once '../includes/activity.php'; $xp = recordActivity($uid, 'goal', 'goal', $id); }
        json_out(['success' => true, 'xp' => $xp]);

    case 'delete':
        $id = (int)($_POST['goal_id'] ?? 0);
        if (!$id) json_out(['success' => false, 'error' => 'Invalid ID.'], 422);
        delete("DELETE FROM goals WHERE id=? AND user_id=?", [$id, $uid]);
        json_out(['success' => true]);

    case 'add_checkin':
        $goalId   = (int)($_POST['goal_id'] ?? 0);
        $note     = sanitizeInput($_POST['note'] ?? '');
        $progress = ($_POST['progress'] ?? '') !== '' ? (int)$_POST['progress'] : null;
        if (!$goalId) json_out(['success' => false, 'error' => 'Invalid goal.'], 422);
        if ($note === '' && $progress === null) json_out(['success' => false, 'error' => 'Add a note or a progress update.'], 422);

        $goal = fetchOne("SELECT id, target_value FROM goals WHERE id=? AND user_id=?", [$goalId, $uid]);
        if (!$goal) json_out(['success' => false, 'error' => 'Not found.'], 404);

        if ($progress !== null) {
            $progress = max(0, min($progress, (int)$goal['target_value']));
            update("UPDATE goals SET progress=? WHERE id=? AND user_id=?", [$progress, $goalId, $uid]);
        }

        $id = insert(
            "INSERT INTO goal_checkins (goal_id, user_id, note, progress_snapshot) VALUES (?,?,?,?)",
            [$goalId, $uid, $note !== '' ? $note : null, $progress]
        );
        json_out(['success' => true, 'id' => $id, 'progress' => $progress]);

    case 'checkins':
        $goalId = (int)($_POST['goal_id'] ?? $_GET['goal_id'] ?? 0);
        if (!$goalId) json_out(['success' => false, 'error' => 'Invalid goal.'], 422);

        $goal = fetchOne("SELECT id FROM goals WHERE id=? AND user_id=?", [$goalId, $uid]);
        if (!$goal) json_out(['success' => false, 'error' => 'Not found.'], 404);

        $rows = fetchAll(
            "SELECT id, note, progress_snapshot, created_at FROM goal_checkins
             WHERE goal_id=? AND user_id=? ORDER BY created_at DESC",
            [$goalId, $uid]
        );
        json_out(['success' => true, 'checkins' => $rows]);

    default:
        json_out(['success' => false, 'error' => 'Unknown action.'], 400);
}
