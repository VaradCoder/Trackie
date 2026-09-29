<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();
verify_csrf();

$uid    = currentUserId();
$action = sanitizeInput($_POST['action'] ?? '');

// Normalise tag string — strip extra spaces/commas, lowercase
function normaliseTags(string $raw): string {
    $tags = array_filter(
        array_map('trim', explode(',', $raw)),
        fn($t) => $t !== ''
    );
    return implode(',', array_unique($tags));
}

switch ($action) {

    case 'add':
        $title    = sanitizeInput($_POST['title']       ?? '');
        $desc     = sanitizeInput($_POST['description'] ?? '');
        $due      = sanitizeInput($_POST['due_date']    ?? '') ?: null;
        $priority = sanitizeInput($_POST['priority']    ?? 'medium');
        $location = sanitizeInput($_POST['location']    ?? '');
        $recur    = sanitizeInput($_POST['recurring']   ?? 'none');
        $category = sanitizeInput($_POST['category']   ?? '') ?: null;
        $tags     = normaliseTags($_POST['tags']        ?? '');

        if (!$title) json_out(['success' => false, 'error' => 'Title is required.'], 422);
        if (!in_array($priority, ['low','medium','high'], true))        $priority = 'medium';
        if (!in_array($recur, ['none','daily','weekly','monthly'], true)) $recur   = 'none';

        $id = insert(
            "INSERT INTO todos (user_id,title,description,due_date,priority,location,recurring,category,tags)
             VALUES (?,?,?,?,?,?,?,?,?)",
            [$uid, $title, $desc, $due, $priority, $location, $recur, $category, $tags ?: null]
        );
        json_out(['success' => true, 'id' => $id]);

    case 'edit':
        $id       = (int)($_POST['todo_id']      ?? 0);
        $title    = sanitizeInput($_POST['title']       ?? '');
        $desc     = sanitizeInput($_POST['description'] ?? '');
        $due      = sanitizeInput($_POST['due_date']    ?? '') ?: null;
        $priority = sanitizeInput($_POST['priority']    ?? 'medium');
        $location = sanitizeInput($_POST['location']    ?? '');
        $recur    = sanitizeInput($_POST['recurring']   ?? 'none');
        $category = sanitizeInput($_POST['category']   ?? '') ?: null;
        $tags     = normaliseTags($_POST['tags']        ?? '');

        if (!$id || !$title) json_out(['success' => false, 'error' => 'Invalid input.'], 422);

        update(
            "UPDATE todos SET title=?,description=?,due_date=?,priority=?,location=?,
             recurring=?,category=?,tags=?
             WHERE id=? AND user_id=? AND deleted_at IS NULL",
            [$title, $desc, $due, $priority, $location, $recur, $category, $tags ?: null, $id, $uid]
        );
        json_out(['success' => true]);

    case 'toggle':
        $id        = (int)($_POST['todo_id']   ?? 0);
        $completed = (int)($_POST['completed'] ?? 0);
        if (!$id) json_out(['success' => false, 'error' => 'Invalid ID.'], 422);

        // Ownership first: XP used to be awarded for ANY id, even one that
        // belongs to someone else (the UPDATE below is scoped, the XP wasn't).
        if (!fetchOne("SELECT id FROM todos WHERE id=? AND user_id=? AND deleted_at IS NULL", [$id, $uid])) {
            json_out(['success' => false, 'error' => 'Todo not found.'], 404);
        }
        $completedAt = $completed ? date('Y-m-d H:i:s') : null;
        update(
            "UPDATE todos SET completed=?,completed_at=? WHERE id=? AND user_id=? AND deleted_at IS NULL",
            [$completed, $completedAt, $id, $uid]
        );
        $xp = null;
        $newAchievements = [];
        require_once '../includes/activity.php';
        if ($completed) {
            $xp = recordActivity($uid, 'todo', 'todo', $id);   // once per todo
            $newAchievements = achievementPayload(checkAchievements($uid));
        } else {
            undoActivity($uid, 'todo', 'todo', $id);
        }
        json_out(['success' => true, 'xp' => $xp, 'achievements' => $newAchievements]);

    case 'restore':
        // Undo a delete: todos are soft-deleted, so bring back the parent and
        // the subtasks that were deleted with it (within the last hour).
        $id = (int)($_POST['todo_id'] ?? 0);
        $n = update("UPDATE todos SET deleted_at=NULL
                      WHERE (id=? OR parent_id=?) AND user_id=? AND deleted_at >= NOW() - INTERVAL 1 HOUR", [$id, $id, $uid]);
        json_out(['success' => $n > 0, 'restored' => $n] + ($n > 0 ? [] : ['error' => 'Nothing to restore.']));

    case 'delete':
        $id = (int)($_POST['todo_id'] ?? 0);
        if (!$id) json_out(['success' => false, 'error' => 'Invalid ID.'], 422);
        // Soft-delete parent and all subtasks
        update("UPDATE todos SET deleted_at=NOW() WHERE (id=? OR parent_id=?) AND user_id=?", [$id, $id, $uid]);
        json_out(['success' => true]);

    case 'get':
        $id = (int)($_POST['todo_id'] ?? $_GET['id'] ?? 0);
        $row = fetchOne(
            "SELECT * FROM todos WHERE id=? AND user_id=? AND deleted_at IS NULL", [$id, $uid]
        );
        $row ? json_out(['success' => true, 'todo' => $row])
             : json_out(['success' => false, 'error' => 'Not found.'], 404);

    // ── Subtask actions ──────────────────────────────────────
    case 'add_subtask':
        $parentId = (int)($_POST['parent_id'] ?? 0);
        $title    = sanitizeInput($_POST['title'] ?? '');
        if (!$parentId || !$title) json_out(['success' => false, 'error' => 'Invalid input.'], 422);

        // Verify parent belongs to user
        $parent = fetchOne("SELECT id FROM todos WHERE id=? AND user_id=? AND deleted_at IS NULL", [$parentId, $uid]);
        if (!$parent) json_out(['success' => false, 'error' => 'Parent not found.'], 404);

        $subId = insert(
            "INSERT INTO todos (user_id, parent_id, title, priority, status) VALUES (?,?,?,'medium','today')",
            [$uid, $parentId, $title]
        );
        json_out(['success' => true, 'id' => $subId, 'title' => $title]);

    case 'subtasks':
        $parentId = (int)($_POST['parent_id'] ?? $_GET['parent_id'] ?? 0);
        if (!$parentId) json_out(['success' => false, 'error' => 'Invalid ID.'], 422);
        $subs = fetchAll(
            "SELECT id, title, completed FROM todos
             WHERE parent_id=? AND user_id=? AND deleted_at IS NULL
             ORDER BY created_at ASC",
            [$parentId, $uid]
        );
        json_out(['success' => true, 'subtasks' => $subs]);

    case 'update_status':
        $id     = (int)($_POST['todo_id'] ?? 0);
        $status = sanitizeInput($_POST['status'] ?? 'today');
        $valid  = ['backlog', 'today', 'in_progress', 'done'];
        if (!$id || !in_array($status, $valid, true))
            json_out(['success' => false, 'error' => 'Invalid input.'], 422);

        $completed   = $status === 'done' ? 1 : 0;
        $completedAt = $status === 'done' ? date('Y-m-d H:i:s') : null;
        update(
            "UPDATE todos SET status=?, completed=?, completed_at=? WHERE id=? AND user_id=? AND deleted_at IS NULL",
            [$status, $completed, $completedAt, $id, $uid]
        );
        json_out(['success' => true]);

    default:
        json_out(['success' => false, 'error' => 'Unknown action.'], 400);
}
