<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();

$uid   = currentUserId();
$q     = sanitizeInput($_GET['q'] ?? '');
$limit = 5;  // results per section

if (strlen($q) < 2) {
    json_out(['success' => true, 'results' => [], 'total' => 0]);
}

$like   = "%{$q}%";
$groups = [];

// ── Todos ─────────────────────────────────────────────────────
$todos = fetchAll(
    "SELECT id, title, priority, due_date, completed FROM todos
     WHERE user_id=? AND deleted_at IS NULL AND (title LIKE ? OR description LIKE ?)
     ORDER BY completed ASC, due_date ASC LIMIT ?",
    [$uid, $like, $like, $limit]
);
if ($todos) {
    $groups[] = [
        'label' => 'Todos',
        'icon'  => 'fa-check-square',
        'items' => array_map(fn($r) => [
            'id'      => $r['id'],
            'title'   => $r['title'],
            'sub'     => $r['due_date'] ? date('M j', strtotime($r['due_date'])) : '',
            'url'     => APP_BASE . '/pages/todos.php',
            'done'    => (bool)$r['completed'],
            'badge'   => $r['priority'],
        ], $todos),
    ];
}

// ── Habits ────────────────────────────────────────────────────
$habits = fetchAll(
    "SELECT id, name, frequency FROM habits
     WHERE user_id=? AND name LIKE ?
     ORDER BY name LIMIT ?",
    [$uid, $like, $limit]
);
if ($habits) {
    $groups[] = [
        'label' => 'Habits',
        'icon'  => 'fa-heart',
        'items' => array_map(fn($r) => [
            'id'    => $r['id'],
            'title' => $r['name'],
            'sub'   => ucfirst($r['frequency']),
            'url'   => APP_BASE . '/pages/habits.php',
        ], $habits),
    ];
}

// ── Goals ─────────────────────────────────────────────────────
$goals = fetchAll(
    "SELECT id, goal_name, progress, target_value FROM goals
     WHERE user_id=? AND (goal_name LIKE ? OR description LIKE ?)
     ORDER BY created_at DESC LIMIT ?",
    [$uid, $like, $like, $limit]
);
if ($goals) {
    $groups[] = [
        'label' => 'Goals',
        'icon'  => 'fa-bullseye',
        'items' => array_map(function($r) {
            $pct = $r['target_value'] > 0
                ? round($r['progress'] / $r['target_value'] * 100) : 0;
            return [
                'id'    => $r['id'],
                'title' => $r['goal_name'],
                'sub'   => "{$pct}% complete",
                'url'   => APP_BASE . '/pages/goals.php',
            ];
        }, $goals),
    ];
}

// ── Study Plan ────────────────────────────────────────────────
$study = fetchAll(
    "SELECT id, title, subject, due_date, type FROM study_plan
     WHERE user_id=? AND (title LIKE ? OR subject LIKE ?)
     ORDER BY due_date ASC LIMIT ?",
    [$uid, $like, $like, $limit]
);
if ($study) {
    $groups[] = [
        'label' => 'Study Plan',
        'icon'  => 'fa-book-open',
        'items' => array_map(fn($r) => [
            'id'    => $r['id'],
            'title' => $r['title'],
            'sub'   => trim(($r['subject'] ? $r['subject'] . ' · ' : '') . ucfirst($r['type'])),
            'url'   => APP_BASE . '/pages/study_plan.php',
        ], $study),
    ];
}

$total = array_sum(array_map(fn($g) => count($g['items']), $groups));
json_out(['success' => true, 'results' => $groups, 'total' => $total, 'query' => $q]);
