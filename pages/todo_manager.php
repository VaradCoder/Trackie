<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

require_once '../config/database.php';
require_once '../includes/functions.php';

// AJAX endpoint for toggling todo completion
if (isAjaxRequest() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $user_id = $_SESSION['user_id'];
    if ($_POST['action'] === 'toggle_todo') {
        $todo_id = $_POST['todo_id'] ?? '';
        $completed = $_POST['completed'] ?? 0;
        if ($todo_id) {
            $completed_at = $completed ? date('Y-m-d H:i:s') : null;
            $sql = "UPDATE todos SET completed = ?, completed_at = ? WHERE id = ? AND user_id = ?";
            $result = update($sql, [$completed, $completed_at, $todo_id, $user_id]);
            if ($result) {
                sendJsonResponse(['success' => true, 'completed' => $completed]);
            } else {
                sendJsonResponse(['success' => false, 'message' => 'Update failed.'], 500);
            }
        } else {
            sendJsonResponse(['success' => false, 'message' => 'Invalid todo ID.'], 400);
        }
    } elseif ($_POST['action'] === 'add_todo') {
        $title = sanitizeInput($_POST['title'] ?? '');
        $description = sanitizeInput($_POST['description'] ?? '');
        $due_date = sanitizeInput($_POST['due_date'] ?? null);
        $priority = sanitizeInput($_POST['priority'] ?? 'medium');
        if (!empty($title)) {
            $sql = "INSERT INTO todos (user_id, title, description, due_date, priority, created_at) VALUES (?, ?, ?, ?, ?, NOW())";
            $todo_id = insert($sql, [$user_id, $title, $description, $due_date, $priority]);
            if ($todo_id) {
                sendJsonResponse(['success' => true, 'todo_id' => $todo_id]);
            } else {
                sendJsonResponse(['success' => false, 'message' => 'Failed to add todo.'], 500);
            }
        } else {
            sendJsonResponse(['success' => false, 'message' => 'Title is required.'], 400);
        }
    } elseif ($_POST['action'] === 'delete_todo') {
        $todo_id = $_POST['todo_id'] ?? '';
        if ($todo_id) {
            $sql = "UPDATE todos SET deleted_at = NOW() WHERE id = ? AND user_id = ?";
            update($sql, [$todo_id, $user_id]);
            sendJsonResponse(['success' => true]);
        } else {
            sendJsonResponse(['success' => false, 'message' => 'Invalid todo ID.'], 400);
        }
    }
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'User';

// Get todos
$filter = $_GET['filter'] ?? 'all';
$search = sanitizeInput($_GET['search'] ?? '');

$sql = "SELECT * FROM todos WHERE user_id = ? AND deleted_at IS NULL";
$params = [$user_id];

switch ($filter) {
    case 'completed': 
        $sql .= " AND completed = 1"; 
        break;
    case 'pending': 
        $sql .= " AND completed = 0"; 
        break;
    case 'overdue': 
        $sql .= " AND completed = 0 AND due_date < CURDATE()"; 
        break;
    case 'today': 
        $sql .= " AND DATE(due_date) = CURDATE()"; 
        break;
}

if ($search) {
    $sql .= " AND (title LIKE ? OR description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY priority DESC, due_date ASC, created_at DESC";
$todos = fetchAll($sql, $params);

// Get statistics
$stats_sql = "SELECT 
    COUNT(*) as total, 
    SUM(CASE WHEN completed = 1 THEN 1 ELSE 0 END) as completed, 
    SUM(CASE WHEN completed = 0 THEN 1 ELSE 0 END) as pending, 
    SUM(CASE WHEN completed = 0 AND due_date < CURDATE() THEN 1 ELSE 0 END) as overdue 
    FROM todos WHERE user_id = ? AND deleted_at IS NULL";
$stats = fetchOne($stats_sql, [$user_id]);
$progress = ($stats['total'] > 0) ? round(($stats['completed'] / $stats['total']) * 100) : 0;

$flash = getFlashMessage();

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="max-w-6xl mx-auto pt-20 px-4">
  <div class="flex items-center justify-between mb-8">
    <h1 class="text-3xl font-extrabold text-red-500">Todo Manager</h1>
    <button onclick="openAddModal()" class="bg-red-500 text-white font-semibold rounded-full py-2.5 px-6 flex items-center gap-2 hover:bg-red-600 transition"><i class="fas fa-plus"></i>New Todo</button>
  </div>
  <?php if ($flash): ?>
    <div class="mb-6 p-4 rounded-lg <?= $flash['type'] === 'success' ? 'bg-green-100 border border-green-500 text-green-700' : 'bg-red-100 border border-red-500 text-red-700' ?> animate__animated animate__fadeInDown">
      <i class="fas <?= $flash['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?> mr-2"></i>
      <?= htmlspecialchars($flash['message']) ?>
    </div>
  <?php endif; ?>
  <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
    <div class="bg-white rounded-xl shadow p-4 flex flex-col items-center animate__animated animate__fadeIn">
      <div class="text-sm text-gray-600">Total</div>
      <div class="text-2xl font-bold text-black"><?= $stats['total'] ?? 0 ?></div>
      <i class="fas fa-tasks text-2xl text-black mt-2"></i>
    </div>
    <div class="bg-white rounded-xl shadow p-4 flex flex-col items-center animate__animated animate__fadeIn">
      <div class="text-sm text-gray-600">Completed</div>
      <div class="text-2xl font-bold text-black"><?= $stats['completed'] ?? 0 ?></div>
      <i class="fas fa-check-circle text-2xl text-black mt-2"></i>
    </div>
    <div class="bg-white rounded-xl shadow p-4 flex flex-col items-center animate__animated animate__fadeIn">
      <div class="text-sm text-gray-600">Pending</div>
      <div class="text-2xl font-bold text-black"><?= $stats['pending'] ?? 0 ?></div>
      <i class="fas fa-clock text-2xl text-black mt-2"></i>
    </div>
    <div class="bg-white rounded-xl shadow p-4 flex flex-col items-center animate__animated animate__fadeIn">
      <div class="text-sm text-gray-600">Overdue</div>
      <div class="text-2xl font-bold text-black"><?= $stats['overdue'] ?? 0 ?></div>
      <i class="fas fa-exclamation-circle text-2xl text-black mt-2"></i>
    </div>
  </div>
  <div class="mb-8">
    <div class="flex items-center justify-between mb-2">
      <span class="text-sm font-medium text-black">Overall Progress</span>
      <span class="text-sm font-bold text-black"><?= $progress ?>%</span>
    </div>
    <div class="w-full bg-gray-200 rounded-full h-2">
      <div class="bg-red-500 h-2 rounded-full transition-all duration-300" style="width: <?= $progress ?>%"></div>
    </div>
  </div>
  <div class="flex flex-wrap gap-4 mb-6 items-center">
    <div class="flex gap-1 bg-gray-100 rounded-full p-1">
      <a href="?filter=all" class="px-3 py-1 rounded-full font-bold text-black hover:bg-gray-200 transition <?= $filter === 'all' ? 'bg-gray-200' : '' ?>">All</a>
      <a href="?filter=pending" class="px-3 py-1 rounded-full font-bold text-black hover:bg-gray-200 transition <?= $filter === 'pending' ? 'bg-gray-200' : '' ?>">Pending</a>
      <a href="?filter=completed" class="px-3 py-1 rounded-full font-bold text-black hover:bg-gray-200 transition <?= $filter === 'completed' ? 'bg-gray-200' : '' ?>">Completed</a>
      <a href="?filter=overdue" class="px-3 py-1 rounded-full font-bold text-black hover:bg-gray-200 transition <?= $filter === 'overdue' ? 'bg-gray-200' : '' ?>">Overdue</a>
      <a href="?filter=today" class="px-3 py-1 rounded-full font-bold text-black hover:bg-gray-200 transition <?= $filter === 'today' ? 'bg-gray-200' : '' ?>">Today</a>
    </div>
    <form method="GET" class="flex-1 max-w-md">
      <div class="relative">
        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search todos..." class="form-input w-full pl-10 pr-4">
        <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
      </div>
    </form>
  </div>
  <div class="bg-white rounded-xl shadow p-6 animate__animated animate__fadeIn">
    <?php if (empty($todos)): ?>
      <div class="text-center py-12">
        <i class="fas fa-clipboard-list text-6xl text-gray-400 mb-4"></i>
        <h3 class="text-xl font-bold text-black mb-2">No todos found</h3>
        <p class="text-gray-600 mb-4"><?= $search ? 'No todos match your search.' : 'Start by adding your first todo!' ?></p>
        <?php if (!$search): ?>
          <button onclick="openAddModal()" class="bg-red-500 text-white font-semibold rounded-full py-2.5 px-6 flex items-center gap-2 hover:bg-red-600 transition"><i class="fas fa-plus"></i>Add Your First Todo</button>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="space-y-4" id="todosList">
        <?php foreach ($todos as $todo): ?>
          <div class="todo-item flex items-center gap-4 <?= $todo['completed'] ? 'opacity-50 line-through' : '' ?> bg-gray-50 rounded-xl p-4 shadow hover:shadow-lg transition-all animate__animated animate__fadeInUp">
            <input type="checkbox" class="w-5 h-5 rounded border-gray-300 todo-checkbox" data-todo-id="<?= $todo['id'] ?>"<?= $todo['completed'] ? ' checked disabled' : '' ?> />
            <div class="flex-1">
              <div class="flex items-center gap-2">
                <h3 class="font-bold text-lg text-black <?= $todo['completed'] ? 'line-through' : '' ?>">
                  <?= htmlspecialchars($todo['title']) ?>
                </h3>
                <?php if ($todo['priority'] === 'high'): ?>
                  <span class="badge bg-red-500 text-white px-2 py-1 rounded-full text-xs">High</span>
                <?php elseif ($todo['priority'] === 'low'): ?>
                  <span class="badge bg-blue-500 text-white px-2 py-1 rounded-full text-xs">Low</span>
                <?php endif; ?>
              </div>
              <?php if ($todo['description']): ?>
                <p class="text-gray-600 mt-1"><?= htmlspecialchars($todo['description']) ?></p>
              <?php endif; ?>
              <div class="flex items-center gap-4 mt-2 text-xs text-gray-600">
                <?php if ($todo['due_date']): ?>
                  <span><i class="fas fa-calendar mr-1"></i>Due: <?= htmlspecialchars($todo['due_date']) ?></span>
                <?php endif; ?>
                <span><i class="fas fa-clock mr-1"></i>Created: <?= date('M j, Y', strtotime($todo['created_at'])) ?></span>
              </div>
            </div>
            <button class="text-red-500 hover:text-red-600 transition-colors delete-todo-btn" data-todo-id="<?= $todo['id'] ?>" title="Delete"><i class="fas fa-trash"></i></button>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
<!-- Add Todo Modal -->
<div id="addModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
  <div class="flex items-center justify-center min-h-screen p-4">
    <div class="bg-white rounded-xl p-8 max-w-md w-full relative">
      <button onclick="closeAddModal()" class="absolute top-3 right-3 text-gray-400 hover:text-red-500"><i class="fas fa-times text-xl"></i></button>
      <h2 class="text-2xl font-bold text-red-500 mb-6">Add New Todo</h2>
      <form id="addTodoForm">
        <input type="hidden" name="action" value="add_todo">
        <div class="mb-4">
          <label class="block text-sm font-medium text-gray-700 mb-2">Title *</label>
          <input type="text" name="title" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500" placeholder="Enter todo title">
        </div>
        <div class="mb-4">
          <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
          <textarea name="description" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500" placeholder="Enter description (optional)"></textarea>
        </div>
        <div class="mb-4">
          <label class="block text-sm font-medium text-gray-700 mb-2">Due Date</label>
          <input type="date" name="due_date" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500">
        </div>
        <div class="mb-6">
          <label class="block text-sm font-medium text-gray-700 mb-2">Priority</label>
          <select name="priority" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500">
            <option value="low">Low</option>
            <option value="medium" selected>Medium</option>
            <option value="high">High</option>
          </select>
        </div>
        <div class="flex gap-3">
          <button type="submit" class="bg-red-500 text-white rounded-full py-2 px-6 font-semibold hover:bg-red-600 flex-1"><i class="fas fa-plus mr-2"></i>Add Todo</button>
          <button type="button" onclick="closeAddModal()" class="border border-gray-300 rounded-full py-2 px-6 font-semibold text-sm hover:bg-gray-50">Cancel</button>
        </div>
      </form>
      <div id="addTodoMsg" class="mt-4 text-center text-sm"></div>
    </div>
  </div>
</div>
<script>
function openAddModal() {
  document.getElementById('addModal').classList.remove('hidden');
}
function closeAddModal() {
  document.getElementById('addModal').classList.add('hidden');
  document.getElementById('addTodoForm').reset();
  document.getElementById('addTodoMsg').textContent = '';
}
document.getElementById('addTodoForm').addEventListener('submit', function(e) {
  e.preventDefault();
  var form = this;
  var msg = document.getElementById('addTodoMsg');
  msg.textContent = '';
  var formData = new FormData(form);
  fetch('todo_manager.php', {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body: formData
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      msg.textContent = 'Todo added successfully!';
      msg.className = 'mt-4 text-center text-green-600';
      setTimeout(() => { closeAddModal(); location.reload(); }, 1000);
    } else {
      msg.textContent = data.message || 'Failed to add todo.';
      msg.className = 'mt-4 text-center text-red-600';
    }
  })
  .catch(() => {
    msg.textContent = 'Network error.';
    msg.className = 'mt-4 text-center text-red-600';
  });
});
document.querySelectorAll('.todo-checkbox').forEach(function(checkbox) {
  checkbox.addEventListener('change', function() {
    var todoId = this.getAttribute('data-todo-id');
    var completed = this.checked ? 1 : 0;
    var checkbox = this;
    checkbox.disabled = true;
    fetch('todo_manager.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
      body: 'action=toggle_todo&todo_id=' + encodeURIComponent(todoId) + '&completed=' + completed
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        var parent = checkbox.closest('.todo-item');
        if (completed) {
          parent.classList.add('opacity-50', 'line-through');
        } else {
          parent.classList.remove('opacity-50', 'line-through');
        }
      } else {
        alert('Failed to update todo: ' + (data.message || 'Unknown error'));
        checkbox.checked = !completed;
      }
      checkbox.disabled = completed ? true : false;
    })
    .catch(() => {
      alert('Network error.');
      checkbox.checked = !completed;
      checkbox.disabled = false;
    });
  });
});
document.querySelectorAll('.delete-todo-btn').forEach(function(btn) {
  btn.addEventListener('click', function(e) {
    e.preventDefault();
    if (!confirm('Are you sure you want to delete this todo?')) return;
    var todoId = this.getAttribute('data-todo-id');
    fetch('todo_manager.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
      body: 'action=delete_todo&todo_id=' + encodeURIComponent(todoId)
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        this.closest('.todo-item').remove();
      } else {
        alert('Failed to delete todo: ' + (data.message || 'Unknown error'));
      }
    });
  });
});
document.getElementById('addModal').addEventListener('click', function(e) {
  if (e.target === this) closeAddModal();
});
</script>
<?php include '../includes/footer.php'; ?> 