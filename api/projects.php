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
        $name   = sanitizeInput($_POST['name']        ?? '');
        $desc   = sanitizeInput($_POST['description'] ?? '');
        $github = sanitizeInput($_POST['github_url']  ?? '');
        $status = sanitizeInput($_POST['status']      ?? 'planning');

        if (!$name) json_out(['success' => false, 'error' => 'Project name is required.'], 422);
        if (!in_array($status, ['planning','active','paused','done'], true)) $status = 'planning';
        if ($github && !preg_match('#^https?://#i', $github)) $github = 'https://' . $github;
        if ($github && !filter_var($github, FILTER_VALIDATE_URL)) {
            json_out(['success' => false, 'error' => 'Invalid GitHub URL.'], 422);
        }

        $id = insert(
            "INSERT INTO projects (user_id,name,description,github_url,status) VALUES (?,?,?,?,?)",
            [$uid, $name, $desc ?: null, $github ?: null, $status]
        );
        json_out(['success' => true, 'id' => $id]);

    case 'update_status':
        $id     = (int)($_POST['project_id'] ?? 0);
        $status = sanitizeInput($_POST['status'] ?? '');
        if (!in_array($status, ['planning','active','paused','done'], true)) {
            json_out(['success' => false, 'error' => 'Invalid status.'], 422);
        }
        update("UPDATE projects SET status=? WHERE id=? AND user_id=?", [$status, $id, $uid]);
        $newAch = [];
        if ($status === 'done') {
            require_once '../includes/gamification.php';
            $newAch = function_exists('checkAchievements') ? checkAchievements($uid) : [];
        }
        json_out(['success' => true, 'newAchievements' => $newAch]);

    case 'delete':
        $id = (int)($_POST['project_id'] ?? 0);
        if (!$id) json_out(['success' => false, 'error' => 'Invalid project.'], 422);
        delete("DELETE FROM projects WHERE id=? AND user_id=?", [$id, $uid]);
        json_out(['success' => true]);

    case 'add_task':
        $projectId = (int)($_POST['project_id'] ?? 0);
        $title     = sanitizeInput($_POST['title'] ?? '');
        if (!$title) json_out(['success' => false, 'error' => 'Task title is required.'], 422);

        $project = fetchOne("SELECT id FROM projects WHERE id=? AND user_id=?", [$projectId, $uid]);
        if (!$project) json_out(['success' => false, 'error' => 'Not found.'], 404);

        $maxOrder = (int)(fetchOne("SELECT MAX(sort_order) m FROM project_tasks WHERE project_id=?", [$projectId])['m'] ?? 0);
        $id = insert(
            "INSERT INTO project_tasks (project_id,title,sort_order) VALUES (?,?,?)",
            [$projectId, $title, $maxOrder + 1]
        );
        json_out(['success' => true, 'id' => $id]);

    case 'update_task_status':
        $id     = (int)($_POST['task_id'] ?? 0);
        $status = sanitizeInput($_POST['status'] ?? '');
        if (!in_array($status, ['todo','doing','done'], true)) json_out(['success' => false, 'error' => 'Invalid status.'], 422);

        $task = fetchOne(
            "SELECT t.id FROM project_tasks t JOIN projects p ON p.id=t.project_id WHERE t.id=? AND p.user_id=?",
            [$id, $uid]
        );
        if (!$task) json_out(['success' => false, 'error' => 'Not found.'], 404);

        update("UPDATE project_tasks SET status=? WHERE id=?", [$status, $id]);
        json_out(['success' => true]);

    case 'delete_task':
        $id = (int)($_POST['task_id'] ?? 0);
        delete(
            "DELETE t FROM project_tasks t JOIN projects p ON p.id=t.project_id WHERE t.id=? AND p.user_id=?",
            [$id, $uid]
        );
        json_out(['success' => true]);

    case 'tasks':
        $projectId = (int)($_POST['project_id'] ?? $_GET['project_id'] ?? 0);
        $project = fetchOne("SELECT id FROM projects WHERE id=? AND user_id=?", [$projectId, $uid]);
        if (!$project) json_out(['success' => false, 'error' => 'Not found.'], 404);

        $tasks = fetchAll(
            "SELECT * FROM project_tasks WHERE project_id=? ORDER BY sort_order ASC, id ASC",
            [$projectId]
        );
        json_out(['success' => true, 'tasks' => $tasks]);

    default:
        json_out(['success' => false, 'error' => 'Unknown action.'], 400);
}
