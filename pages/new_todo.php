<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_name = $_SESSION['user_name'] ?? 'User';
$profile_pic = $_SESSION['profile_pic'] ?? '';
$current_path = $_SERVER['PHP_SELF'];
$is_in_pages = strpos($current_path, '/pages/') !== false;
$assets_base = $is_in_pages ? '../' : '';
if ($profile_pic && file_exists($assets_base . $profile_pic)) {
    $img_src = $assets_base . $profile_pic;
} else {
    $img_src = $assets_base . "assets/images/default-user.png";
}
require_once '../config/database.php';

// AJAX handlers for add/edit/delete/toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    $user_id = $_SESSION['user_id'];
    $action = $_POST['action'] ?? '';
    $response = ['success' => false];
    switch ($action) {
        case 'add':
            $title = sanitizeInput($_POST['title'] ?? '');
            $description = sanitizeInput($_POST['description'] ?? '');
            $due_date = sanitizeInput($_POST['due_date'] ?? null);
            $priority = sanitizeInput($_POST['priority'] ?? 'medium');
            $location = sanitizeInput($_POST['location'] ?? '');
            $recurring = sanitizeInput($_POST['recurring'] ?? 'none');
            if ($title) {
                $sql = "INSERT INTO todos (user_id, title, description, due_date, priority, location, recurring, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
                $todo_id = insert($sql, [$user_id, $title, $description, $due_date, $priority, $location, $recurring]);
                $response = ['success' => true, 'id' => $todo_id];
            }
            break;
        case 'edit':
            $todo_id = sanitizeInput($_POST['todo_id'] ?? '');
            $title = sanitizeInput($_POST['title'] ?? '');
            $description = sanitizeInput($_POST['description'] ?? '');
            $due_date = sanitizeInput($_POST['due_date'] ?? null);
            $priority = sanitizeInput($_POST['priority'] ?? 'medium');
            $location = sanitizeInput($_POST['location'] ?? '');
            $recurring = sanitizeInput($_POST['recurring'] ?? 'none');
            if ($todo_id && $title) {
                $sql = "UPDATE todos SET title = ?, description = ?, due_date = ?, priority = ?, location = ?, recurring = ? WHERE id = ? AND user_id = ?";
                update($sql, [$title, $description, $due_date, $priority, $location, $recurring, $todo_id, $user_id]);
                $response = ['success' => true];
            }
            break;
        case 'delete':
            $todo_id = sanitizeInput($_POST['todo_id'] ?? '');
            if ($todo_id) {
                $sql = "UPDATE todos SET deleted_at = NOW() WHERE id = ? AND user_id = ?";
                update($sql, [$todo_id, $user_id]);
                $response = ['success' => true];
            }
            break;
        case 'toggle':
            $todo_id = sanitizeInput($_POST['todo_id'] ?? '');
            $completed = sanitizeInput($_POST['completed'] ?? 0);
            if ($todo_id) {
                $sql = "UPDATE todos SET completed = ?, completed_at = ? WHERE id = ? AND user_id = ?";
                $completed_at = $completed ? date('Y-m-d H:i:s') : null;
                update($sql, [$completed, $completed_at, $todo_id, $user_id]);
                $response = ['success' => true];
            }
            break;
    }
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

$user_id = $_SESSION['user_id'];
$filter = $_GET['filter'] ?? 'all';
$search = sanitizeInput($_GET['search'] ?? '');
$sql = "SELECT * FROM todos WHERE user_id = ? AND deleted_at IS NULL";
$params = [$user_id];
switch ($filter) {
    case 'completed': $sql .= " AND completed = 1"; break;
    case 'pending': $sql .= " AND completed = 0"; break;
    case 'overdue': $sql .= " AND completed = 0 AND due_date < CURDATE()"; break;
    case 'today': $sql .= " AND DATE(due_date) = CURDATE()"; break;
}
if ($search) {
    $sql .= " AND (title LIKE ? OR description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$sql .= " ORDER BY priority DESC, due_date ASC, created_at DESC";
$todos = fetchAll($sql, $params);
$stats_sql = "SELECT COUNT(*) as total, SUM(CASE WHEN completed = 1 THEN 1 ELSE 0 END) as completed, SUM(CASE WHEN completed = 0 THEN 1 ELSE 0 END) as pending, SUM(CASE WHEN completed = 0 AND due_date < CURDATE() THEN 1 ELSE 0 END) as overdue FROM todos WHERE user_id = ? AND deleted_at IS NULL";
$stats = fetchOne($stats_sql, [$user_id]);
$progress = ($stats['total'] > 0) ? round(($stats['completed'] / $stats['total']) * 100) : 0;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Next-Gen Todos - Trackie.in</title>
    <link rel="icon" type="image/x-icon" href="<?= $assets_base ?>assets/images/Logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Bangers&family=Comic+Neue:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="font-comic">
<?php include '../includes/sidebar.php'; ?>
<header class="fixed top-0 left-0 w-full z-30 glassmorphism shadow-luxury flex items-center justify-between px-6 py-3 border-b border-gold animate-fade-in">
    <div class="flex items-center gap-4">
        <img src="<?= htmlspecialchars($img_src) ?>" alt="Avatar" class="w-12 h-12 rounded-full border-2 border-gold shadow-luxury">
        <div>
            <div class="font-bold text-gold text-xl">Hi, <?= htmlspecialchars($user_name) ?>!</div>
            <div class="text-xs text-silver">Streak: <span id="streakCount">12</span> days <span class="badge bg-gold text-black px-2 py-1 rounded-full ml-2">🔥</span></div>
        </div>
    </div>
    <div class="flex items-center gap-4">
        <button id="darkModeToggle" class="p-2 rounded-full bg-glass hover:bg-silver transition border border-gold"><i class="fa fa-moon text-gold"></i></button>
        <button id="quickAddBtn" class="btn-primary">+ Add Todo</button>
    </div>
</header>
<main class="pt-28 max-w-5xl mx-auto px-4">
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8 fade-in">
        <div class="luxury-card stat-card">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-silver">Total</p>
                    <p class="text-2xl font-bold text-gold" id="statTotal"><?= $stats['total'] ?? 0 ?></p>
                </div>
                <i class="fas fa-tasks text-2xl text-gold"></i>
            </div>
        </div>
        <div class="luxury-card stat-card">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-silver">Completed</p>
                    <p class="text-2xl font-bold text-gold" id="statCompleted"><?= $stats['completed'] ?? 0 ?></p>
                </div>
                <i class="fas fa-check-circle text-2xl text-gold"></i>
            </div>
        </div>
        <div class="luxury-card stat-card">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-silver">Pending</p>
                    <p class="text-2xl font-bold text-gold" id="statPending"><?= $stats['pending'] ?? 0 ?></p>
                </div>
                <i class="fas fa-clock text-2xl text-gold"></i>
            </div>
        </div>
        <div class="luxury-card stat-card">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-silver">Overdue</p>
                    <p class="text-2xl font-bold text-gold" id="statOverdue"><?= $stats['overdue'] ?? 0 ?></p>
                </div>
                <i class="fas fa-exclamation-circle text-2xl text-gold"></i>
            </div>
        </div>
    </div>
    <div class="mb-8 fade-in">
        <div class="flex items-center justify-between mb-1">
            <span class="text-sm font-medium text-gold">Today's Progress</span>
            <span class="text-sm font-bold text-gold" id="progressPercent"><?= $progress ?>%</span>
        </div>
        <div class="progress-container">
            <div class="progress-bar" style="width: <?= $progress ?>%"></div>
        </div>
    </div>
    <div class="flex flex-wrap gap-2 mb-6 items-center fade-in">
        <div class="flex gap-1 bg-glass rounded-full p-1 shadow-inner">
            <a href="?filter=all" class="px-3 py-1 rounded-full font-bold text-gold hover:bg-silver transition <?= $filter==='all'?'bg-silver':'' ?>">All</a>
            <a href="?filter=completed" class="px-3 py-1 rounded-full font-bold text-gold hover:bg-silver transition <?= $filter==='completed'?'bg-silver':'' ?>">Completed</a>
            <a href="?filter=pending" class="px-3 py-1 rounded-full font-bold text-gold hover:bg-silver transition <?= $filter==='pending'?'bg-silver':'' ?>">Pending</a>
            <a href="?filter=overdue" class="px-3 py-1 rounded-full font-bold text-gold hover:bg-silver transition <?= $filter==='overdue'?'bg-silver':'' ?>">Overdue</a>
            <a href="?filter=today" class="px-3 py-1 rounded-full font-bold text-gold hover:bg-silver transition <?= $filter==='today'?'bg-silver':'' ?>">Today</a>
        </div>
        <form method="GET" class="flex-1 flex justify-end">
            <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search todos..." class="px-3 py-1 rounded-full border border-green-200 focus:ring-2 focus:ring-green-400 focus:outline-none ml-2 w-48">
            <button type="submit" class="ml-2 px-3 py-1 rounded-full bg-green-600 text-white font-bold hover:bg-green-700 transition"><i class="fa fa-search"></i></button>
        </form>
    </div>
    <!-- Calendar View Placeholder -->
    <div class="bg-white rounded-xl shadow p-4 mb-8 fade-in">
        <div class="flex items-center justify-between mb-2">
            <div class="font-bold text-green-700 text-lg">Calendar View</div>
            <span class="text-xs text-green-400">(Coming soon: drag to reschedule!)</span>
        </div>
        <div class="h-32 flex items-center justify-center text-green-300 italic">[Animated calendar will appear here]</div>
    </div>
    <ul id="todoList" class="space-y-3 mb-8 fade-in">
        <?php if (empty($todos)): ?>
            <li class="text-gray-400 text-center py-8">No todos found. <span class="block text-green-500 mt-2">Stay motivated! 🌱</span></li>
        <?php else: ?>
            <?php foreach ($todos as $todo): ?>
                <li class="flex items-center gap-3 bg-white rounded-xl shadow p-4 todo-animate priority-<?= htmlspecialchars($todo['priority']) ?> <?= $todo['completed'] ? 'completed' : '' ?>" style="animation-delay: <?= ($todo['id']%5)*0.1 ?>s; animation-name: fadeIn;">
                    <input type="checkbox" class="accent-green-600 w-5 h-5 transition-all duration-200" <?= $todo['completed'] ? 'checked' : '' ?> onchange="toggleTodo(<?= $todo['id'] ?>, this.checked)">
                    <div class="flex-1">
                        <div class="font-bold text-lg text-green-800 flex items-center gap-2">
                            <?= htmlspecialchars($todo['title']) ?>
                            <?php if ($todo['due_date']): ?>
                                <span class="text-xs text-green-500 ml-2">Due: <?= htmlspecialchars($todo['due_date']) ?></span>
                            <?php endif; ?>
                            <?php if ($todo['recurring'] && $todo['recurring'] !== 'none'): ?>
                                <span class="ml-2 px-2 py-1 bg-green-100 text-green-700 rounded-full text-xs badge">⟳ <?= htmlspecialchars(ucfirst($todo['recurring'])) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($todo['description']): ?>
                            <div class="text-sm text-green-700 mt-1"> <?= htmlspecialchars($todo['description']) ?> </div>
                        <?php endif; ?>
                        <?php if ($todo['location']): ?>
                            <div class="text-xs text-green-400 mt-1"><i class="fa fa-map-marker-alt"></i> <?= htmlspecialchars($todo['location']) ?></div>
                        <?php endif; ?>
                    </div>
                    <button class="p-2 rounded-full hover:bg-green-100 transition" onclick="editTodo(<?= $todo['id'] ?>)"><i class="fa fa-edit text-green-600"></i></button>
                    <button class="p-2 rounded-full hover:bg-red-100 transition" onclick="deleteTodo(<?= $todo['id'] ?>)"><i class="fa fa-trash text-red-600"></i></button>
                </li>
            <?php endforeach; ?>
        <?php endif; ?>
    </ul>
    <div class="bg-green-50 rounded-xl p-4 shadow text-center text-green-700 font-bold mb-8 animate-pulse fade-in">
        "The secret of getting ahead is getting started." – Mark Twain
    </div>
    <div class="flex flex-wrap gap-2 mb-8 fade-in">
        <span class="badge bg-green-200 text-green-800 px-3 py-1 rounded-full">🏅 Streak: 12 days</span>
        <span class="badge bg-yellow-200 text-yellow-800 px-3 py-1 rounded-full">⭐ 5 Todos Completed Today</span>
        <span class="badge bg-blue-200 text-blue-800 px-3 py-1 rounded-full">🎯 100% Completion</span>
    </div>
    <div id="todoModal" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 hidden">
        <div class="bg-white rounded-xl shadow-lg p-8 w-full max-w-md relative animate-fade-in">
            <button class="absolute top-2 right-2 text-green-600 hover:text-green-800 text-xl" onclick="closeTodoModal()"><i class="fa fa-times"></i></button>
            <h2 id="modalTitle" class="text-2xl font-bold mb-4 text-green-700">Add Todo</h2>
            <form id="todoForm" class="space-y-4">
                <input type="hidden" name="todo_id" id="modalTodoId">
                <div>
                    <label class="block text-sm font-bold text-green-700 mb-1">Title *</label>
                    <input type="text" name="title" id="modalTitleInput" class="w-full px-4 py-2 border border-green-200 rounded-lg focus:ring-2 focus:ring-green-400 focus:outline-none" required>
                </div>
                <div>
                    <label class="block text-sm font-bold text-green-700 mb-1">Description</label>
                    <textarea name="description" id="modalDescInput" class="w-full px-4 py-2 border border-green-200 rounded-lg focus:ring-2 focus:ring-green-400 focus:outline-none"></textarea>
                </div>
                <div class="flex gap-2">
                    <div class="flex-1">
                        <label class="block text-sm font-bold text-green-700 mb-1">Due Date</label>
                        <input type="date" name="due_date" id="modalDueInput" class="w-full px-4 py-2 border border-green-200 rounded-lg focus:ring-2 focus:ring-green-400 focus:outline-none">
                    </div>
                    <div class="flex-1">
                        <label class="block text-sm font-bold text-green-700 mb-1">Priority</label>
                        <select name="priority" id="modalPriorityInput" class="w-full px-4 py-2 border border-green-200 rounded-lg focus:ring-2 focus:ring-green-400 focus:outline-none">
                            <option value="high">High</option>
                            <option value="medium">Medium</option>
                            <option value="low">Low</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-bold text-green-700 mb-1">Location</label>
                    <input type="text" name="location" id="modalLocationInput" class="w-full px-4 py-2 border border-green-200 rounded-lg focus:ring-2 focus:ring-green-400 focus:outline-none">
                </div>
                <div>
                    <label class="block text-sm font-bold text-green-700 mb-1">Recurring</label>
                    <select name="recurring" id="modalRecurringInput" class="w-full px-4 py-2 border border-green-200 rounded-lg focus:ring-2 focus:ring-green-400 focus:outline-none">
                        <option value="none">None</option>
                        <option value="daily">Daily</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly">Monthly</option>
                    </select>
                </div>
                <div class="flex gap-2 mt-4">
                    <button type="submit" class="flex-1 bg-green-600 text-white py-2 px-4 rounded-lg font-semibold hover:bg-green-700 transition">Save</button>
                    <button type="button" class="flex-1 bg-gray-200 text-green-700 py-2 px-4 rounded-lg font-semibold hover:bg-gray-300 transition" onclick="closeTodoModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    <div id="toast" class="fixed bottom-6 right-6 bg-green-600 text-white px-6 py-3 rounded-lg shadow-lg z-50 hidden animate-fade-in"></div>
</main>
<script>
const darkModeToggle = document.getElementById('darkModeToggle');
darkModeToggle.addEventListener('click', () => {
    document.body.classList.toggle('dark');
});
const todoModal = document.getElementById('todoModal');
const quickAddBtn = document.getElementById('quickAddBtn');
quickAddBtn.addEventListener('click', () => {
    openTodoModal();
});
function openTodoModal(todo = null) {
    document.getElementById('modalTitle').textContent = todo ? 'Edit Todo' : 'Add Todo';
    document.getElementById('modalTodoId').value = todo ? todo.id : '';
    document.getElementById('modalTitleInput').value = todo ? todo.title : '';
    document.getElementById('modalDescInput').value = todo ? todo.description : '';
    document.getElementById('modalDueInput').value = todo ? todo.due_date : '';
    document.getElementById('modalPriorityInput').value = todo ? todo.priority : 'medium';
    document.getElementById('modalLocationInput').value = todo ? todo.location : '';
    document.getElementById('modalRecurringInput').value = todo ? todo.recurring : 'none';
    todoModal.classList.remove('hidden');
}
function closeTodoModal() {
    todoModal.classList.add('hidden');
}
function editTodo(id) {
    // For demo, just open modal. For real, fetch todo data via AJAX.
    openTodoModal();
}
function deleteTodo(id) {
    if (confirm('Delete this todo?')) {
        fetch('', {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: `ajax=1&action=delete&todo_id=${id}`})
            .then(r => r.json()).then(data => {
                if (data.success) showToast('Todo deleted!');
                setTimeout(() => location.reload(), 800);
            });
    }
}
function toggleTodo(id, completed) {
    fetch('', {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: `ajax=1&action=toggle&todo_id=${id}&completed=${completed?1:0}`})
        .then(r => r.json()).then(data => {
            if (data.success) showToast('Todo updated!');
            setTimeout(() => location.reload(), 800);
        });
}
function showToast(msg) {
    const toast = document.getElementById('toast');
    toast.textContent = msg;
    toast.classList.remove('hidden');
    setTimeout(() => toast.classList.add('hidden'), 2000);
}
const todoForm = document.getElementById('todoForm');
todoForm.addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(todoForm);
    formData.append('ajax', '1');
    formData.append('action', formData.get('todo_id') ? 'edit' : 'add');
    fetch('', {method: 'POST', body: formData})
        .then(r => r.json()).then(data => {
            if (data.success) showToast('Todo saved!');
            setTimeout(() => location.reload(), 800);
        });
});
</script>
</body>
</html> 