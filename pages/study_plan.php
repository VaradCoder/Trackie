<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

require_once '../config/database.php';
require_once '../includes/functions.php';

$user_id = $_SESSION['user_id'];

// Motivational quotes
$quotes = [
    "Success is the sum of small efforts, repeated day in and day out. – Robert Collier",
    "The secret of getting ahead is getting started. – Mark Twain",
    "Don’t watch the clock; do what it does. Keep going. – Sam Levenson",
    "The future depends on what you do today. – Mahatma Gandhi",
    "It always seems impossible until it’s done. – Nelson Mandela",
    "Push yourself, because no one else is going to do it for you.",
    "Great things never come from comfort zones.",
    "Dream bigger. Do bigger.",
    "Don’t stop when you’re tired. Stop when you’re done."
];
$quote = $quotes[array_rand($quotes)];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    switch ($action) {
        case 'add_task':
            $title = sanitizeInput($_POST['title'] ?? '');
            $description = sanitizeInput($_POST['description'] ?? '');
            $due_date = sanitizeInput($_POST['due_date'] ?? null);
            $type = sanitizeInput($_POST['type'] ?? 'study');
            $subject = sanitizeInput($_POST['subject'] ?? '');
            $priority = sanitizeInput($_POST['priority'] ?? 'medium');
            if (!empty($title)) {
                $sql = "INSERT INTO study_plan (user_id, title, description, due_date, type, subject, priority, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
                insert($sql, [$user_id, $title, $description, $due_date, $type, $subject, $priority]);
            }
            break;
        case 'toggle_task':
            $task_id = $_POST['task_id'] ?? '';
            $completed = $_POST['completed'] ?? 0;
            $completed_at = $completed ? date('Y-m-d H:i:s') : null;
            if ($task_id) {
                $sql = "UPDATE study_plan SET completed = ?, completed_at = ? WHERE id = ? AND user_id = ?";
                update($sql, [$completed, $completed_at, $task_id, $user_id]);
            }
            break;
        case 'delete_task':
            $task_id = $_POST['task_id'] ?? '';
            if ($task_id) {
                $sql = "DELETE FROM study_plan WHERE id = ? AND user_id = ?";
                delete($sql, [$task_id, $user_id]);
            }
            break;
    }
    header('Location: study_plan.php');
    exit();
}

// Get filter options
$type_filter = $_GET['type'] ?? 'all';
$subject_filter = $_GET['subject'] ?? 'all';
$priority_filter = $_GET['priority'] ?? 'all';
$today = date('Y-m-d');

// Get all subjects for filter dropdown
$subjects = fetchAll("SELECT DISTINCT subject FROM study_plan WHERE user_id = ? AND subject IS NOT NULL AND subject != ''", [$user_id]);

// Get tasks
$sql = "SELECT * FROM study_plan WHERE user_id = ?";
$params = [$user_id];
if ($type_filter !== 'all') {
    $sql .= " AND type = ?";
    $params[] = $type_filter;
}
if ($subject_filter !== 'all') {
    $sql .= " AND subject = ?";
    $params[] = $subject_filter;
}
if ($priority_filter !== 'all') {
    $sql .= " AND priority = ?";
    $params[] = $priority_filter;
}
$sql .= " ORDER BY due_date ASC, priority DESC, created_at DESC";
$tasks = fetchAll($sql, $params);

$today_tasks = array_filter($tasks, function($t) use ($today) { return $t['due_date'] === $today; });
$upcoming_tasks = array_filter($tasks, function($t) use ($today) { return $t['due_date'] > $today; });

// Calculate past tasks
$past_tasks = array_filter($tasks, function($t) use ($today) { return $t['due_date'] < $today; });

// Progress for today
$today_total = count($today_tasks);
$today_done = array_reduce($today_tasks, function($c, $t) { return $c + ($t['completed'] ? 1 : 0); }, 0);
$today_progress = $today_total > 0 ? round(($today_done / $today_total) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Study Plan - Trackie.in</title>
    <link rel="icon" type="image/x-icon" href="../assets/images/Logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="font-sans">
<?php include '../includes/sidebar.php'; ?>
<div class="pt-20 px-4 max-w-5xl mx-auto">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-8 gap-4">
        <div>
            <h1 class="text-3xl font-bold text-black">Study Plan</h1>
            <div class="text-gray-500 mt-2 italic text-sm">"<?= htmlspecialchars($quote) ?>"</div>
        </div>
        <button onclick="openAddModal()" class="btn btn-primary"><i class="fas fa-plus mr-2"></i>Add Task</button>
    </div>
    <div class="mb-6 flex flex-wrap gap-2 items-center">
        <div class="flex gap-1 bg-gray-100 rounded-full p-1">
            <a href="?type=all" class="px-3 py-1 rounded-full font-bold text-black hover:bg-gray-200 transition <?= $type_filter==='all'?'bg-gray-200':'' ?>">All Types</a>
            <a href="?type=study" class="px-3 py-1 rounded-full font-bold text-black hover:bg-gray-200 transition <?= $type_filter==='study'?'bg-gray-200':'' ?>">Study</a>
            <a href="?type=homework" class="px-3 py-1 rounded-full font-bold text-black hover:bg-gray-200 transition <?= $type_filter==='homework'?'bg-gray-200':'' ?>">Homework</a>
            <a href="?type=practice" class="px-3 py-1 rounded-full font-bold text-black hover:bg-gray-200 transition <?= $type_filter==='practice'?'bg-gray-200':'' ?>">Practice</a>
            <a href="?type=project" class="px-3 py-1 rounded-full font-bold text-black hover:bg-gray-200 transition <?= $type_filter==='project'?'bg-gray-200':'' ?>">Project</a>
            <a href="?type=exam" class="px-3 py-1 rounded-full font-bold text-black hover:bg-gray-200 transition <?= $type_filter==='exam'?'bg-gray-200':'' ?>">Exam</a>
            <a href="?type=reading" class="px-3 py-1 rounded-full font-bold text-black hover:bg-gray-200 transition <?= $type_filter==='reading'?'bg-gray-200':'' ?>">Reading</a>
            <a href="?type=revision" class="px-3 py-1 rounded-full font-bold text-black hover:bg-gray-200 transition <?= $type_filter==='revision'?'bg-gray-200':'' ?>">Revision</a>
            <a href="?type=other" class="px-3 py-1 rounded-full font-bold text-black hover:bg-gray-200 transition <?= $type_filter==='other'?'bg-gray-200':'' ?>">Other</a>
        </div>
        <form method="GET" class="flex gap-2 items-center">
            <select name="subject" class="form-input" onchange="this.form.submit()">
                <option value="all">All Subjects</option>
                <?php foreach ($subjects as $s): ?>
                    <option value="<?= htmlspecialchars($s['subject']) ?>" <?= $subject_filter === $s['subject'] ? 'selected' : '' ?>><?= htmlspecialchars($s['subject']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="priority" class="form-input" onchange="this.form.submit()">
                <option value="all">All Priorities</option>
                <option value="high" <?= $priority_filter==='high'?'selected':'' ?>>High</option>
                <option value="medium" <?= $priority_filter==='medium'?'selected':'' ?>>Medium</option>
                <option value="low" <?= $priority_filter==='low'?'selected':'' ?>>Low</option>
            </select>
        </form>
    </div>
    <div class="mb-6">
        <div class="flex items-center justify-between mb-2">
            <span class="text-sm font-medium text-black">Today's Progress</span>
            <span class="text-sm font-bold text-black"><?= $today_progress ?>%</span>
        </div>
        <div class="progress-container">
            <div class="progress-bar" style="width: <?= $today_progress ?>%"></div>
        </div>
    </div>
    <div class="mb-4 p-3 bg-yellow-50 border border-yellow-200 rounded text-xs text-yellow-900">
        <strong>Debug Info:</strong><br>
        Total tasks fetched: <?= count($tasks) ?><br>
        Today's date: <?= $today ?><br>
        Type filter: <?= htmlspecialchars($type_filter) ?> | Subject filter: <?= htmlspecialchars($subject_filter) ?> | Priority filter: <?= htmlspecialchars($priority_filter) ?><br>
        <ul>
            <?php foreach ($tasks as $t): ?>
                <li>ID: <?= $t['id'] ?> | Title: <?= htmlspecialchars($t['title']) ?> | Due: <?= $t['due_date'] ?> | Type: <?= $t['type'] ?> | Subject: <?= $t['subject'] ?> | Priority: <?= $t['priority'] ?> | Completed: <?= $t['completed'] ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-8">
        <div class="card p-6">
            <div class="font-bold text-black text-lg mb-2 flex items-center gap-2">Today's Tasks <span class="badge">Today</span></div>
            <ul class="space-y-3">
                <?php if (empty($today_tasks)): ?>
                    <li class="text-gray-400 text-center py-8">No tasks for today!</li>
                <?php else: ?>
                    <?php foreach ($today_tasks as $task): ?>
                        <li class="todo-item flex items-center gap-3 <?= $task['completed'] ? 'completed' : '' ?>">
                            <form method="POST" class="flex items-center gap-3 flex-1">
                                <input type="hidden" name="action" value="toggle_task">
                                <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                <input type="hidden" name="completed" value="<?= $task['completed'] ? '0' : '1' ?>">
                                <input type="checkbox" class="w-5 h-5 accent-red-500" <?= $task['completed'] ? 'checked' : '' ?> onchange="this.form.submit()">
                                <div class="flex-1">
                                    <div class="font-bold text-lg text-black flex items-center gap-2">
                                        <?= htmlspecialchars($task['title']) ?>
                                        <span class="badge"><?= ucfirst($task['type']) ?></span>
                                        <?php if ($task['subject']): ?><span class="ml-2 text-xs text-gray-500"><i class="fa fa-book mr-1"></i><?= htmlspecialchars($task['subject']) ?></span><?php endif; ?>
                                        <span class="ml-2 text-xs <?= $task['priority']==='high'?'text-red-500':($task['priority']==='medium'?'text-yellow-600':'text-green-600') ?> font-bold"><i class="fa fa-flag mr-1"></i><?= ucfirst($task['priority']) ?></span>
                                    </div>
                                    <?php if ($task['description']): ?>
                                        <div class="text-sm text-gray-600 mt-1"> <?= htmlspecialchars($task['description']) ?> </div>
                                    <?php endif; ?>
                                </div>
                            </form>
                            <form method="POST" class="flex gap-2" onsubmit="return confirm('Delete this task?')">
                                <input type="hidden" name="action" value="delete_task">
                                <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                <button type="submit" class="text-red-500 hover:text-red-600 transition-colors" title="Delete"><i class="fas fa-trash"></i></button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>
        <div class="card p-6">
            <div class="font-bold text-black text-lg mb-2 flex items-center gap-2">Upcoming Tasks <span class="badge">Upcoming</span></div>
            <ul class="space-y-3">
                <?php if (empty($upcoming_tasks)): ?>
                    <li class="text-gray-400 text-center py-8">No upcoming tasks!</li>
                <?php else: ?>
                    <?php foreach ($upcoming_tasks as $task): ?>
                        <li class="todo-item flex items-center gap-3 <?= $task['completed'] ? 'completed' : '' ?>">
                            <form method="POST" class="flex items-center gap-3 flex-1">
                                <input type="hidden" name="action" value="toggle_task">
                                <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                <input type="hidden" name="completed" value="<?= $task['completed'] ? '0' : '1' ?>">
                                <input type="checkbox" class="w-5 h-5 accent-red-500" <?= $task['completed'] ? 'checked' : '' ?> onchange="this.form.submit()">
                                <div class="flex-1">
                                    <div class="font-bold text-lg text-black flex items-center gap-2">
                                        <?= htmlspecialchars($task['title']) ?>
                                        <span class="badge"><?= ucfirst($task['type']) ?></span>
                                        <?php if ($task['subject']): ?><span class="ml-2 text-xs text-gray-500"><i class="fa fa-book mr-1"></i><?= htmlspecialchars($task['subject']) ?></span><?php endif; ?>
                                        <span class="ml-2 text-xs <?= $task['priority']==='high'?'text-red-500':($task['priority']==='medium'?'text-yellow-600':'text-green-600') ?> font-bold"><i class="fa fa-flag mr-1"></i><?= ucfirst($task['priority']) ?></span>
                                        <span class="text-xs text-gray-500 ml-2">Due: <?= htmlspecialchars($task['due_date']) ?></span>
                                    </div>
                                    <?php if ($task['description']): ?>
                                        <div class="text-sm text-gray-600 mt-1"> <?= htmlspecialchars($task['description']) ?> </div>
                                    <?php endif; ?>
                                </div>
                            </form>
                            <form method="POST" class="flex gap-2" onsubmit="return confirm('Delete this task?')">
                                <input type="hidden" name="action" value="delete_task">
                                <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                <button type="submit" class="text-red-500 hover:text-red-600 transition-colors" title="Delete"><i class="fas fa-trash"></i></button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>
    </div>
    <div class="card p-6 mb-8">
        <div class="font-bold text-black text-lg mb-2 flex items-center gap-2">Past Tasks <span class="badge">Past</span></div>
        <ul class="space-y-3">
            <?php if (empty($past_tasks)): ?>
                <li class="text-gray-400 text-center py-8">No past tasks!</li>
            <?php else: ?>
                <?php foreach ($past_tasks as $task): ?>
                    <li class="todo-item flex items-center gap-3 <?= $task['completed'] ? 'completed' : '' ?>">
                        <form method="POST" class="flex items-center gap-3 flex-1">
                            <input type="hidden" name="action" value="toggle_task">
                            <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                            <input type="hidden" name="completed" value="<?= $task['completed'] ? '0' : '1' ?>">
                            <input type="checkbox" class="w-5 h-5 accent-red-500" <?= $task['completed'] ? 'checked' : '' ?> onchange="this.form.submit()">
                            <div class="flex-1">
                                <div class="font-bold text-lg text-black flex items-center gap-2">
                                    <?= htmlspecialchars($task['title']) ?>
                                    <span class="badge"><?= ucfirst($task['type']) ?></span>
                                    <?php if ($task['subject']): ?><span class="ml-2 text-xs text-gray-500"><i class="fa fa-book mr-1"></i><?= htmlspecialchars($task['subject']) ?></span><?php endif; ?>
                                    <span class="ml-2 text-xs <?= $task['priority']==='high'?'text-red-500':($task['priority']==='medium'?'text-yellow-600':'text-green-600') ?> font-bold"><i class="fa fa-flag mr-1"></i><?= ucfirst($task['priority']) ?></span>
                                    <span class="text-xs text-gray-500 ml-2">Due: <?= htmlspecialchars($task['due_date']) ?></span>
                                </div>
                                <?php if ($task['description']): ?>
                                    <div class="text-sm text-gray-600 mt-1"> <?= htmlspecialchars($task['description']) ?> </div>
                                <?php endif; ?>
                            </div>
                        </form>
                        <form method="POST" class="flex gap-2" onsubmit="return confirm('Delete this task?')">
                            <input type="hidden" name="action" value="delete_task">
                            <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                            <button type="submit" class="text-red-500 hover:text-red-600 transition-colors" title="Delete"><i class="fas fa-trash"></i></button>
                        </form>
                    </li>
                <?php endforeach; ?>
            <?php endif; ?>
        </ul>
    </div>
</div>
<!-- Add Task Modal -->
<div id="addModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="card p-8 w-full max-w-2xl overflow-x-auto" style="min-width:320px;">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-2xl font-bold text-black">Add Study Task</h2>
                <button onclick="closeAddModal()" class="text-gray-400 hover:text-black">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="add_task">
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-600 mb-2">Title *</label>
                    <input type="text" name="title" required class="form-input w-full" placeholder="Enter task title">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-600 mb-2">Description</label>
                    <textarea name="description" rows="3" class="form-input w-full" placeholder="Describe the task (optional)"></textarea>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-600 mb-2">Subject/Course</label>
                    <input type="text" name="subject" class="form-input w-full" placeholder="e.g. Math, Physics, English">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-600 mb-2">Due Date</label>
                    <input type="date" name="due_date" class="form-input w-full">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-600 mb-2">Type</label>
                    <select name="type" class="form-input w-full">
                        <option value="study">Study</option>
                        <option value="homework">Homework</option>
                        <option value="practice">Practice</option>
                        <option value="project">Project</option>
                        <option value="exam">Exam</option>
                        <option value="reading">Reading</option>
                        <option value="revision">Revision</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-600 mb-2">Priority</label>
                    <select name="priority" class="form-input w-full">
                        <option value="high">High</option>
                        <option value="medium" selected>Medium</option>
                        <option value="low">Low</option>
                    </select>
                </div>
                <div class="flex gap-3">
                    <button type="submit" class="btn btn-primary flex-1"><i class="fas fa-plus mr-2"></i>Add Task</button>
                    <button type="button" onclick="closeAddModal()" class="btn btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script src="../assets/js/app.js"></script>
<script>
    function openAddModal() {
        document.getElementById('addModal').classList.remove('hidden');
    }
    function closeAddModal() {
        document.getElementById('addModal').classList.add('hidden');
    }
    document.getElementById('addModal').addEventListener('click', function(e) {
        if (e.target === this) closeAddModal();
    });
</script>
</body>
</html> 