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

    case 'track_repo':
        // A discovered GitHub repo → a Trackie project (tasks, sessions, status).
        // Name/description/URL come from the synced record, not the browser.
        $row = fetchOne("SELECT payload FROM integration_data WHERE user_id=? AND provider='github' AND kind='repo' AND external_id=?",
                        [$uid, (string)(int)($_POST['repo_id'] ?? 0)]);
        $repo = $row ? (json_decode($row['payload'], true) ?: []) : null;
        if (!$repo || !preg_match('#^https://github\.com/#', (string)($repo['html_url'] ?? ''))) {
            json_out(['success' => false, 'error' => 'That repository is not in your last GitHub sync.'], 404);
        }
        $existing = fetchOne("SELECT id FROM projects WHERE user_id=? AND LOWER(TRIM(TRAILING '/' FROM github_url))=LOWER(?)", [$uid, rtrim($repo['html_url'], '/')]);
        if ($existing) json_out(['success' => true, 'id' => (int)$existing['id'], 'existing' => true]);
        require_once '../includes/providers.php';
        provider('github');   // loads the provider class
        $act = TrackieGithubProvider::activity($repo);
        $id = insert(
            "INSERT INTO projects (user_id,name,description,github_url,status) VALUES (?,?,?,?,?)",
            [$uid, mb_substr($repo['name'], 0, 120), $repo['description'] ? mb_substr($repo['description'], 0, 500) : null, $repo['html_url'],
             $act === 'archived' ? 'done' : ($act === 'active' ? 'active' : 'paused')]
        );
        json_out(['success' => true, 'id' => $id]);

    case 'update_status':
        $id     = (int)($_POST['project_id'] ?? 0);
        $status = sanitizeInput($_POST['status'] ?? '');
        if (!in_array($status, ['planning','active','paused','done'], true)) {
            json_out(['success' => false, 'error' => 'Invalid status.'], 422);
        }
        if (!fetchOne("SELECT id FROM projects WHERE id=? AND user_id=?", [$id, $uid])) json_out(['success' => false, 'error' => 'Not found.'], 404);
        update("UPDATE projects SET status=? WHERE id=? AND user_id=?", [$status, $id, $uid]);
        $newAch = [];
        $xp = null;
        require_once '../includes/activity.php';
        if ($status === 'done') {
            $xp = recordActivity($uid, 'project_done', 'project', $id);
            $newAch = function_exists('checkAchievements') ? checkAchievements($uid) : [];
        } else {
            undoActivity($uid, 'project_done', 'project', $id);
        }
        json_out(['success' => true, 'xp' => $xp, 'newAchievements' => $newAch]);

    case 'get':
        $p = fetchOne("SELECT id, name, description, github_url, status FROM projects WHERE id=? AND user_id=?", [(int)($_POST['project_id'] ?? 0), $uid]);
        if (!$p) json_out(['success' => false, 'error' => 'Not found.'], 404);
        json_out(['success' => true, 'project' => $p]);

    case 'edit':
        $id     = (int)($_POST['project_id'] ?? 0);
        $name   = mb_substr(trim(sanitizeInput($_POST['name'] ?? '')), 0, 120);
        $desc   = mb_substr(trim(sanitizeInput($_POST['description'] ?? '')), 0, 500);
        $github = trim(sanitizeInput($_POST['github_url'] ?? ''));
        if ($name === '') json_out(['success' => false, 'error' => 'Project name is required.'], 422);
        if ($github && !preg_match('#^https?://#i', $github)) $github = 'https://' . $github;
        if ($github && (!filter_var($github, FILTER_VALIDATE_URL) || !preg_match('#^https://#i', $github))) json_out(['success' => false, 'error' => 'Invalid repo URL.'], 422);
        if (!fetchOne("SELECT id FROM projects WHERE id=? AND user_id=?", [$id, $uid])) json_out(['success' => false, 'error' => 'Not found.'], 404);
        update("UPDATE projects SET name=?, description=?, github_url=? WHERE id=? AND user_id=?", [$name, $desc ?: null, $github ?: null, $id, $uid]);
        json_out(['success' => true]);

    case 'session_log':
        $min  = (int)($_POST['minutes'] ?? 0);
        if ($min < 1 || $min > 1440) json_out(['success' => false, 'error' => 'Minutes must be 1–1,440.'], 422);
        $date = (string)($_POST['session_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
        if ($date > date('Y-m-d')) json_out(['success' => false, 'error' => "A session can't be in the future."], 422);
        $pid = (int)($_POST['project_id'] ?? 0) ?: null;
        if ($pid && !fetchOne("SELECT id FROM projects WHERE id=? AND user_id=?", [$pid, $uid])) json_out(['success' => false, 'error' => 'Project not found.'], 404);
        $sid = (int)insert("INSERT INTO coding_sessions (user_id,project_id,session_date,minutes,language,notes) VALUES (?,?,?,?,?,?)",
            [$uid, $pid, $date, $min, mb_substr(trim(sanitizeInput($_POST['language'] ?? '')), 0, 40) ?: null, mb_substr(trim(sanitizeInput($_POST['notes'] ?? '')), 0, 500) ?: null]);
        require_once '../includes/activity.php';
        $xp = recordActivity($uid, 'coding_session', 'coding_day', (int)date('Ymd', strtotime($date)), ['date' => $date]);
        json_out(['success' => true, 'id' => $sid, 'xp' => $xp]);

    case 'session_delete':
        $s = fetchOne("SELECT session_date FROM coding_sessions WHERE id=? AND user_id=?", [(int)($_POST['session_id'] ?? 0), $uid]);
        if ($s) {
            delete("DELETE FROM coding_sessions WHERE id=? AND user_id=?", [(int)$_POST['session_id'], $uid]);
            if (!fetchOne("SELECT id FROM coding_sessions WHERE user_id=? AND session_date=? LIMIT 1", [$uid, $s['session_date']])) {
                require_once '../includes/activity.php';
                undoActivity($uid, 'coding_session', 'coding_day', (int)date('Ymd', strtotime($s['session_date'])));
            }
        }
        json_out(['success' => true]);

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
