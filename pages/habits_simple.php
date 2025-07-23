<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

require_once '../config/database.php';
require_once '../includes/functions.php';

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'User';

// AJAX endpoint for adding a habit
if (isAjaxRequest() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_habit') {
    $name = sanitizeInput($_POST['name'] ?? '');
    $frequency = sanitizeInput($_POST['frequency'] ?? 'daily');
    if (!empty($name)) {
        $sql = "INSERT INTO habits (user_id, name, frequency) VALUES (?, ?, ?)";
        $habit_id = insert($sql, [$user_id, $name, $frequency]);
        if ($habit_id) {
            sendJsonResponse(['success' => true, 'habit_id' => $habit_id]);
        } else {
            sendJsonResponse(['success' => false, 'message' => 'Failed to add habit.'], 500);
        }
    } else {
        sendJsonResponse(['success' => false, 'message' => 'Habit name is required.'], 400);
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    switch ($action) {
        case 'log_habit':
            $habit_id = $_POST['habit_id'] ?? '';
            $date_completed = $_POST['date_completed'] ?? date('Y-m-d');
            if ($habit_id) {
                $check_sql = "SELECT id FROM logs WHERE habit_id = ? AND date_completed = ?";
                $existing = fetchOne($check_sql, [$habit_id, $date_completed]);
                if (!$existing) {
                    $sql = "INSERT INTO logs (user_id, habit_id, date_completed) VALUES (?, ?, ?)";
                    $log_id = insert($sql, [$user_id, $habit_id, $date_completed]);
                    if ($log_id) {
                        setFlashMessage('success', 'Habit logged successfully!');
                    } else {
                        setFlashMessage('error', 'Failed to log habit.');
                    }
                } else {
                    setFlashMessage('error', 'Habit already logged for this date.');
                }
            }
            break;
        case 'delete_habit':
            $habit_id = $_POST['habit_id'] ?? '';
            if ($habit_id) {
                $delete_logs_sql = "DELETE FROM logs WHERE habit_id = ?";
                delete($delete_logs_sql, [$habit_id]);
                $delete_habit_sql = "DELETE FROM habits WHERE id = ? AND user_id = ?";
                $deleted = delete($delete_habit_sql, [$habit_id, $user_id]);
                if ($deleted) {
                    setFlashMessage('success', 'Habit deleted successfully!');
                } else {
                    setFlashMessage('error', 'Failed to delete habit.');
                }
            }
            break;
    }
    header('Location: habits_simple.php');
    exit();
}

// Get user's habits with completion data
$sql = "SELECT h.*, 
        COUNT(l.id) as total_logs,
        COUNT(CASE WHEN l.date_completed >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 END) as logs_this_week,
        COUNT(CASE WHEN l.date_completed = CURDATE() THEN 1 END) as logged_today
        FROM habits h 
        LEFT JOIN logs l ON h.id = l.habit_id 
        WHERE h.user_id = ? 
        GROUP BY h.id 
        ORDER BY h.created_at DESC";
$habits = fetchAll($sql, [$user_id]);

$flash = getFlashMessage();

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="max-w-6xl mx-auto pt-20 px-4">
  <div class="flex items-center justify-between mb-8">
    <div>
      <h1 class="text-3xl font-extrabold text-red-500">My Habits</h1>
      <p class="text-gray-600 mt-2">Track and build positive habits for a better life. <span class='ml-2 text-xs text-gray-400'>Logged in as <?= htmlspecialchars($user_name) ?></span></p>
    </div>
    <button onclick="openAddModal()" class="bg-red-500 text-white font-semibold rounded-full py-2.5 px-6 flex items-center gap-2 hover:bg-red-600 transition"><i class="fas fa-plus"></i>New Habit</button>
  </div>
  <?php if ($flash): ?>
    <div class="mb-6 p-4 rounded-lg <?= $flash['type'] === 'success' ? 'bg-green-100 border border-green-500 text-green-700' : 'bg-red-100 border border-red-500 text-red-700' ?> animate__animated animate__fadeInDown">
      <i class="fas <?= $flash['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?> mr-2"></i>
      <?= htmlspecialchars($flash['message']) ?>
    </div>
  <?php endif; ?>
  <div class="bg-white rounded-xl shadow p-6 animate__animated animate__fadeIn">
    <?php if (empty($habits)): ?>
      <div class="text-center py-12">
        <i class="fas fa-heart text-6xl text-gray-300 mb-4 animate__animated animate__pulse animate__infinite"></i>
        <h3 class="text-xl font-bold text-gray-700 mb-2">No habits yet</h3>
        <p class="text-gray-400 mb-4">Start building positive habits today!</p>
        <button onclick="openAddModal()" class="bg-red-500 text-white font-semibold rounded-full py-2.5 px-6 flex items-center gap-2 hover:bg-red-600 transition"><i class="fas fa-plus"></i>Add Your First Habit</button>
      </div>
    <?php else: ?>
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($habits as $habit): ?>
          <div class="bg-gray-50 rounded-xl p-6 shadow hover:shadow-lg transition-all animate__animated animate__fadeInUp">
            <div class="flex items-start justify-between mb-4">
              <div class="flex-1">
                <h3 class="text-lg font-bold text-gray-900 mb-2 flex items-center gap-2">
                  <span class="inline-block w-2 h-2 rounded-full <?= $habit['logged_today'] ? 'bg-green-500' : 'bg-gray-300' ?>" title="<?= $habit['logged_today'] ? 'Logged today' : 'Not logged today' ?>"></span>
                  <?= htmlspecialchars($habit['name']) ?>
                </h3>
                <p class="text-gray-500 text-sm mb-3"><i class="fas fa-calendar mr-1"></i><?= ucfirst($habit['frequency']) ?></p>
              </div>
              <form method="POST" onsubmit="return confirm('Are you sure you want to delete this habit?')">
                <input type="hidden" name="action" value="delete_habit">
                <input type="hidden" name="habit_id" value="<?= $habit['id'] ?>">
                <button type="submit" class="text-red-400 hover:text-red-600 transition-colors" title="Delete"><i class="fas fa-trash"></i></button>
              </form>
            </div>
            <div class="space-y-3">
              <div class="flex justify-between text-sm">
                <span class="text-gray-400">Total logs:</span>
                <span class="text-gray-900 font-bold"><?= $habit['total_logs'] ?></span>
              </div>
              <div class="flex justify-between text-sm">
                <span class="text-gray-400">This week:</span>
                <span class="text-gray-900 font-bold"><?= $habit['logs_this_week'] ?></span>
              </div>
              <div class="flex justify-between text-sm">
                <span class="text-gray-400">Today:</span>
                <span class="text-gray-900 font-bold"><?= $habit['logged_today'] ?></span>
              </div>
              <div class="w-full bg-gray-200 rounded-full h-2">
                <?php $progress = min(100, ($habit['logs_this_week'] / 7) * 100); ?>
                <div class="bg-red-500 h-2 rounded-full transition-all duration-300" style="width: <?= $progress ?>%"></div>
              </div>
            </div>
            <div class="mt-4 pt-4 border-t border-gray-200">
              <form method="POST" class="flex gap-2">
                <input type="hidden" name="action" value="log_habit">
                <input type="hidden" name="habit_id" value="<?= $habit['id'] ?>">
                <input type="hidden" name="date_completed" value="<?= date('Y-m-d') ?>">
                <?php if ($habit['logged_today'] > 0): ?>
                  <button type="button" class="bg-green-100 text-green-700 rounded-full w-full py-2 font-semibold cursor-not-allowed" disabled><i class="fas fa-check mr-2"></i>Already Logged</button>
                <?php else: ?>
                  <button type="submit" class="bg-red-500 text-white rounded-full w-full py-2 font-semibold hover:bg-red-600 transition"><i class="fas fa-plus mr-2"></i>Log Today</button>
                <?php endif; ?>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
<!-- Add Habit Modal -->
<div id="addModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
  <div class="flex items-center justify-center min-h-screen p-4">
    <div class="bg-white rounded-xl p-8 max-w-md w-full relative">
      <button onclick="closeAddModal()" class="absolute top-3 right-3 text-gray-400 hover:text-red-500"><i class="fas fa-times text-xl"></i></button>
      <h2 class="text-2xl font-bold text-red-500 mb-6">Add New Habit</h2>
      <form id="addHabitForm">
        <input type="hidden" name="action" value="add_habit">
        <div class="mb-4">
          <label class="block text-sm font-medium text-gray-700 mb-2">Habit Name *</label>
          <input type="text" name="name" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500" placeholder="e.g., Exercise, Read, Meditate">
        </div>
        <div class="mb-6">
          <label class="block text-sm font-medium text-gray-700 mb-2">Frequency</label>
          <select name="frequency" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500">
            <option value="daily">Daily</option>
            <option value="weekly">Weekly</option>
          </select>
        </div>
        <div class="flex gap-3">
          <button type="submit" class="bg-red-500 text-white rounded-full py-2 px-6 font-semibold hover:bg-red-600 flex-1"><i class="fas fa-plus mr-2"></i>Add Habit</button>
          <button type="button" onclick="closeAddModal()" class="border border-gray-300 rounded-full py-2 px-6 font-semibold text-sm hover:bg-gray-50">Cancel</button>
        </div>
      </form>
      <div id="addHabitMsg" class="mt-4 text-center text-sm"></div>
    </div>
  </div>
</div>
<script>
function openAddModal() {
  document.getElementById('addModal').classList.remove('hidden');
}
function closeAddModal() {
  document.getElementById('addModal').classList.add('hidden');
  document.getElementById('addHabitForm').reset();
  document.getElementById('addHabitMsg').textContent = '';
}
document.getElementById('addHabitForm').addEventListener('submit', function(e) {
  e.preventDefault();
  var form = this;
  var msg = document.getElementById('addHabitMsg');
  msg.textContent = '';
  var formData = new FormData(form);
  fetch('habits_simple.php', {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body: formData
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      msg.textContent = 'Habit added successfully!';
      msg.className = 'mt-4 text-center text-green-600';
      setTimeout(() => { closeAddModal(); location.reload(); }, 1000);
    } else {
      msg.textContent = data.message || 'Failed to add habit.';
      msg.className = 'mt-4 text-center text-red-600';
    }
  })
  .catch(() => {
    msg.textContent = 'Network error.';
    msg.className = 'mt-4 text-center text-red-600';
  });
});
document.getElementById('addModal').addEventListener('click', function(e) {
  if (e.target === this) closeAddModal();
});
</script>
<?php include '../includes/footer.php'; ?> 