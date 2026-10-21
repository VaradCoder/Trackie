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

    // Change only what's sent — due_date and/or priority — for one-tap actions
    // (snooze to tomorrow, bump priority). 'edit' rewrites every column, so
    // using it for a quick change would wipe the description, tags, location…
    case 'patch':
        $id = (int)($_POST['todo_id'] ?? 0);
        if (!$id) json_out(['success' => false, 'error' => 'Invalid ID.'], 422);
        $set = []; $vals = [];
        if (array_key_exists('due_date', $_POST)) {
            $due = trim((string)$_POST['due_date']);
            if ($due !== '' && !(preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) && strtotime($due))) {
                json_out(['success' => false, 'error' => 'That date isn’t valid.'], 422);
            }
            $set[] = 'due_date=?'; $vals[] = $due === '' ? null : $due;
        }
        if (array_key_exists('priority', $_POST)) {
            $p = (string)$_POST['priority'];
            if (!in_array($p, ['low', 'medium', 'high'], true)) json_out(['success' => false, 'error' => 'Unknown priority.'], 422);
            $set[] = 'priority=?'; $vals[] = $p;
        }
        if (!$set) json_out(['success' => false, 'error' => 'Nothing to change.'], 422);
        if (!fetchOne("SELECT id FROM todos WHERE id=? AND user_id=? AND deleted_at IS NULL", [$id, $uid])) {
            json_out(['success' => false, 'error' => 'Todo not found.'], 404);
        }
        update("UPDATE todos SET " . implode(',', $set) . " WHERE id=? AND user_id=? AND deleted_at IS NULL", array_merge($vals, [$id, $uid]));
        json_out(['success' => true]);

    case 'toggle':
        $id        = (int)($_POST['todo_id']   ?? 0);
        $completed = (int)($_POST['completed'] ?? 0);
        if (!$id) json_out(['success' => false, 'error' => 'Invalid ID.'], 422);

        // Ownership is checked inside (scoped SELECT) before anything is written
        // or any XP is paid. Recurring todos get one activity row per period.
        $r = setTodoCompleted($uid, $id, (bool)$completed);
        if (!$r['found']) json_out(['success' => false, 'error' => 'Todo not found.'], 404);
        json_out(['success' => true, 'xp' => $r['xp'], 'achievements' => $r['achievements']]);

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

        // Moving a card to/from Done is a completion like the checkbox: it used
        // to change the row only, so board completions had no activity or XP.
        $r = setTodoCompleted($uid, $id, $status === 'done', $status);
        if (!$r['found']) json_out(['success' => false, 'error' => 'Todo not found.'], 404);
        json_out(['success' => true, 'xp' => $r['xp'], 'achievements' => $r['achievements']]);

    default:
        json_out(['success' => false, 'error' => 'Unknown action.'], 400);
}
