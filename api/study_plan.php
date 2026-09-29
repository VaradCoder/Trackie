<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();
verify_csrf();

$uid    = currentUserId();
$action = sanitizeInput($_POST['action'] ?? '');

$validTypes    = ['study','homework','practice','project','exam','reading','revision','other'];
$validPriority = ['high','medium','low'];

switch ($action) {

    case 'add':
        $title    = sanitizeInput($_POST['title']       ?? '');
        $desc     = sanitizeInput($_POST['description'] ?? '');
        $subject  = sanitizeInput($_POST['subject']     ?? '');
        $due      = sanitizeInput($_POST['due_date']    ?? '') ?: null;
        $type     = sanitizeInput($_POST['type']        ?? 'study');
        $priority = sanitizeInput($_POST['priority']    ?? 'medium');
        $resource = sanitizeInput($_POST['resource']    ?? '');

        if (!$title) json_out(['success' => false, 'error' => 'Title is required.'], 422);
        if (!in_array($type, $validTypes, true))       $type     = 'study';
        if (!in_array($priority, $validPriority, true)) $priority = 'medium';

        $id = insert(
            "INSERT INTO study_plan (user_id,title,description,subject,due_date,type,priority,resource)
             VALUES (?,?,?,?,?,?,?,?)",
            [$uid, $title, $desc, $subject, $due, $type, $priority, $resource]
        );
        json_out(['success' => true, 'id' => $id]);

    case 'toggle':
        $id        = (int)($_POST['task_id']   ?? 0);
        $completed = (int)($_POST['completed'] ?? 0);
        if (!$id) json_out(['success' => false, 'error' => 'Invalid ID.'], 422);

        // Ownership first: XP used to be awarded for ANY id, even one that
        // belongs to someone else (the UPDATE below is scoped, the XP wasn't).
        if (!fetchOne("SELECT id FROM study_plan WHERE id=? AND user_id=?", [$id, $uid])) {
            json_out(['success' => false, 'error' => 'Task not found.'], 404);
        }
        $completedAt = $completed ? date('Y-m-d H:i:s') : null;
        update(
            "UPDATE study_plan SET completed=?,completed_at=? WHERE id=? AND user_id=?",
            [$completed, $completedAt, $id, $uid]
        );
        $xp = null;
        require_once '../includes/activity.php';
        if ($completed) {
            $xp = recordActivity($uid, 'study', 'study', $id);   // once per study task
            checkAchievements($uid);
        } else {
            undoActivity($uid, 'study', 'study', $id);
        }
        json_out(['success' => true, 'xp' => $xp]);

    case 'delete':
        $id = (int)($_POST['task_id'] ?? 0);
        if (!$id) json_out(['success' => false, 'error' => 'Invalid ID.'], 422);
        delete("DELETE FROM study_plan WHERE id=? AND user_id=?", [$id, $uid]);
        json_out(['success' => true]);

    default:
        json_out(['success' => false, 'error' => 'Unknown action.'], 400);
}
