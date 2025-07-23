<?php
session_start();

// Include configuration and functions
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_name = $_SESSION['user_name'] ?? 'User';
$profile_pic = $_SESSION['profile_pic'] ?? '';

// Determine the base path for assets
$current_path = $_SERVER['PHP_SELF'];
$is_in_pages = strpos($current_path, '/pages/') !== false;
$assets_base = $is_in_pages ? '../' : '';

if ($profile_pic && file_exists($assets_base . $profile_pic)) {
    $img_src = $assets_base . $profile_pic;
} else {
    $img_src = $assets_base . "assets/images/default-user.png";
}

$user_id = getCurrentUserId();
$error = '';
$success = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_habit':
                $name = sanitizeInput($_POST['name'] ?? '');
                $frequency = sanitizeInput($_POST['frequency'] ?? 'daily');

                if (empty($name)) {
                    $error = 'Please enter a habit name.';
                } else {
                    try {
                        $sql = "INSERT INTO habits (user_id, name, frequency) VALUES (?, ?, ?)";
                        $habit_id = insert($sql, [$user_id, $name, $frequency]);
                        
                        if ($habit_id) {
                            $success = 'Habit added successfully!';
                            logActivity('habit_added', ['habit_id' => $habit_id, 'name' => $name]);
                        } else {
                            $error = 'Failed to add habit.';
                        }
                    } catch (Exception $e) {
                        $error = 'An error occurred. Please try again.';
                        error_log("Add habit error: " . $e->getMessage());
                    }
                }
                break;

            case 'log_habit':
                $habit_id = (int)sanitizeInput($_POST['habit_id'] ?? 0);
                $date_completed = sanitizeInput($_POST['date_completed'] ?? date('Y-m-d'));

                if ($habit_id > 0) {
                    try {
                        // Check if already logged for this date
                        $check_sql = "SELECT id FROM logs WHERE habit_id = ? AND date_completed = ?";
                        $existing = fetchOne($check_sql, [$habit_id, $date_completed]);
                        
                        if (!$existing) {
                            $sql = "INSERT INTO logs (user_id, habit_id, date_completed) VALUES (?, ?, ?)";
                            $log_id = insert($sql, [$user_id, $habit_id, $date_completed]);
                            
                            if ($log_id) {
                                $success = 'Habit logged successfully!';
                                logActivity('habit_logged', ['habit_id' => $habit_id, 'date' => $date_completed]);
                            } else {
                                $error = 'Failed to log habit.';
                            }
                        } else {
                            $error = 'Habit already logged for this date.';
                        }
                    } catch (Exception $e) {
                        $error = 'An error occurred. Please try again.';
                        error_log("Log habit error: " . $e->getMessage());
                    }
                }
                break;

            case 'delete_habit':
                $habit_id = (int)sanitizeInput($_POST['habit_id'] ?? 0);
                if ($habit_id > 0) {
                    try {
                        // Delete associated logs first
                        $delete_logs_sql = "DELETE FROM logs WHERE habit_id = ?";
                        delete($delete_logs_sql, [$habit_id]);
                        
                        // Delete habit
                        $delete_habit_sql = "DELETE FROM habits WHERE id = ? AND user_id = ?";
                        $deleted = delete($delete_habit_sql, [$habit_id, $user_id]);
                        
                        if ($deleted) {
                            $success = 'Habit deleted successfully!';
                            logActivity('habit_deleted', ['habit_id' => $habit_id]);
                        } else {
                            $error = 'Failed to delete habit.';
                        }
                    } catch (Exception $e) {
                        $error = 'An error occurred. Please try again.';
                        error_log("Delete habit error: " . $e->getMessage());
                    }
                }
                break;
        }
    }
}

// Get user's habits with completion data
try {
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
} catch (Exception $e) {
    $habits = [];
    error_log("Get habits error: " . $e->getMessage());
}

// Get flash message
$flash = getFlashMessage();
if ($flash) {
    if ($flash['type'] === 'success') {
        $success = $flash['message'];
    } else {
        $error = $flash['message'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Habits - Trackie.in</title>
    <link rel="icon" type="image/x-icon" href="/assets/images/Logo.png">
    <!-- Tailwind CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Bangers&family=Comic+Neue:wght@400;700&display=swap" rel="stylesheet">
</head>
<body class="bg-gray-50 font-comic" style="font-family: 'Comic Neue', cursive;">

<?php include '../includes/sidebar.php'; ?>

<main class="min-h-screen p-4 pt-20 md:p-6 md:pt-20 pl-16">
  <div class="max-w-7xl mx-auto">
    
    <!-- Header -->
    <div class="mb-8">
      <h1 class="text-3xl md:text-4xl font-bold text-gray-900 mb-2">My Habits</h1>
      <p class="text-gray-600">Track and build positive habits for a better life</p>
    </div>

    <!-- Error/Success Messages -->
    <?php if ($error): ?>
        <div class="mb-6 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg flex items-center">
            <i class="fas fa-exclamation-circle mr-2"></i>
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="mb-6 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg flex items-center">
            <i class="fas fa-check-circle mr-2"></i>
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
      
      <!-- Add New Habit Form -->
      <div class="lg:col-span-1">
        <div class="bg-white rounded-xl shadow-lg p-6">
          <h2 class="text-xl font-bold text-gray-900 mb-4">Add New Habit</h2>
          
          <form method="POST" action="" class="space-y-4">
            <input type="hidden" name="action" value="add_habit">
            
            <div>
              <label for="name" class="block text-sm font-medium text-gray-700 mb-2">Habit Name *</label>
              <input 
                type="text" 
                id="name" 
                name="name" 
                required 
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-red-500"
                placeholder="e.g., Drink 8 glasses of water"
              >
            </div>

            <div>
              <label for="frequency" class="block text-sm font-medium text-gray-700 mb-2">Frequency</label>
              <select 
                id="frequency" 
                name="frequency" 
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-red-500"
              >
                <option value="daily">Daily</option>
                <option value="weekly">Weekly</option>
              </select>
            </div>

            <button 
              type="submit" 
              class="w-full bg-red-600 text-white py-2 px-4 rounded-lg font-medium hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition-colors"
            >
              <i class="fas fa-plus mr-2"></i>Add Habit
            </button>
          </form>
        </div>

        <!-- Quick Stats -->
        <div class="bg-white rounded-xl shadow-lg p-6 mt-6">
          <h3 class="text-lg font-bold text-gray-900 mb-4">Quick Stats</h3>
          <div class="space-y-3">
            <div class="flex justify-between items-center">
              <span class="text-gray-600">Total Habits</span>
              <span class="font-bold text-gray-900"><?= count($habits) ?></span>
            </div>
            <div class="flex justify-between items-center">
              <span class="text-gray-600">Completed Today</span>
              <span class="font-bold text-green-600">
                <?= array_sum(array_column($habits, 'logged_today')) ?>
              </span>
            </div>
            <div class="flex justify-between items-center">
              <span class="text-gray-600">This Week</span>
              <span class="font-bold text-blue-600">
                <?= array_sum(array_column($habits, 'logs_this_week')) ?>
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- Habits List -->
      <div class="lg:col-span-2">
        <div class="bg-white rounded-xl shadow-lg p-6">
          <div class="flex justify-between items-center mb-6">
            <h2 class="text-xl font-bold text-gray-900">Your Habits</h2>
            <div class="flex space-x-2">
              <button id="viewAll" class="px-3 py-1 bg-red-100 text-red-600 rounded-lg text-sm font-medium">All</button>
              <button id="viewToday" class="px-3 py-1 bg-gray-100 text-gray-600 rounded-lg text-sm font-medium">Today</button>
            </div>
          </div>

          <?php if (empty($habits)): ?>
            <div class="text-center py-12">
              <div class="text-6xl text-gray-300 mb-4">✅</div>
              <h3 class="text-lg font-medium text-gray-900 mb-2">No habits yet</h3>
              <p class="text-gray-600 mb-4">Start building positive habits to improve your life!</p>
              <button onclick="document.getElementById('name').focus()" class="bg-red-600 text-white px-4 py-2 rounded-lg font-medium hover:bg-red-700 transition-colors">
                Add Your First Habit
              </button>
            </div>
          <?php else: ?>
            <div class="space-y-4">
              <?php foreach ($habits as $habit): ?>
                <div class="border border-gray-200 rounded-lg p-4 hover:shadow-md transition-shadow">
                  <div class="flex justify-between items-start">
                    <div class="flex-1">
                      <div class="flex items-center space-x-3 mb-2">
                        <h3 class="text-lg font-semibold text-gray-900">
                          <?= htmlspecialchars($habit['name']) ?>
                        </h3>
                        <span class="text-xs bg-gray-100 text-gray-600 px-2 py-1 rounded-full">
                          <?= ucfirst($habit['frequency']) ?>
                        </span>
                      </div>
                      
                      <div class="flex items-center space-x-4 text-sm text-gray-600 mb-3">
                        <span><i class="fas fa-calendar-check mr-1"></i>Total: <?= $habit['total_logs'] ?></span>
                        <span><i class="fas fa-calendar-week mr-1"></i>This Week: <?= $habit['logs_this_week'] ?></span>
                        <?php if ($habit['logged_today']): ?>
                          <span class="text-green-600"><i class="fas fa-check mr-1"></i>Done Today</span>
                        <?php endif; ?>
                      </div>
                      
                      <!-- Progress Bar -->
                      <div class="w-full bg-gray-200 rounded-full h-2 mb-3">
                        <?php 
                        $progress = 0;
                        if ($habit['frequency'] === 'daily') {
                            $progress = min(100, ($habit['logs_this_week'] / 7) * 100);
                        } else {
                            $progress = min(100, ($habit['logs_this_week'] / 1) * 100);
                        }
                        ?>
                        <div class="bg-red-600 h-2 rounded-full transition-all duration-300" style="width: <?= $progress ?>%"></div>
                      </div>
                    </div>
                    
                    <div class="flex space-x-2">
                      <?php if (!$habit['logged_today']): ?>
                        <form method="POST" action="" class="inline">
                          <input type="hidden" name="action" value="log_habit">
                          <input type="hidden" name="habit_id" value="<?= $habit['id'] ?>">
                          <button 
                            type="submit" 
                            class="text-green-600 hover:text-green-800 p-2 bg-green-50 rounded-lg hover:bg-green-100 transition-colors"
                            title="Mark as completed"
                          >
                            <i class="fas fa-check"></i>
                          </button>
                        </form>
                      <?php endif; ?>
                      
                      <button 
                        onclick="viewHabitDetails(<?= $habit['id'] ?>)" 
                        class="text-blue-600 hover:text-blue-800 p-2 bg-blue-50 rounded-lg hover:bg-blue-100 transition-colors"
                        title="View Details"
                      >
                        <i class="fas fa-eye"></i>
                      </button>
                      
                      <form method="POST" action="" class="inline" onsubmit="return confirm('Are you sure you want to delete this habit?')">
                        <input type="hidden" name="action" value="delete_habit">
                        <input type="hidden" name="habit_id" value="<?= $habit['id'] ?>">
                        <button 
                          type="submit" 
                          class="text-red-600 hover:text-red-800 p-2 bg-red-50 rounded-lg hover:bg-red-100 transition-colors"
                          title="Delete"
                        >
                          <i class="fas fa-trash"></i>
                        </button>
                      </form>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</main>

<script>
  // View filters
  document.getElementById('viewAll').addEventListener('click', function() {
    this.classList.remove('bg-gray-100', 'text-gray-600');
    this.classList.add('bg-red-100', 'text-red-600');
    document.getElementById('viewToday').classList.remove('bg-red-100', 'text-red-600');
    document.getElementById('viewToday').classList.add('bg-gray-100', 'text-gray-600');
  });

  document.getElementById('viewToday').addEventListener('click', function() {
    this.classList.remove('bg-gray-100', 'text-gray-600');
    this.classList.add('bg-red-100', 'text-red-600');
    document.getElementById('viewAll').classList.remove('bg-red-100', 'text-red-600');
    document.getElementById('viewAll').classList.add('bg-gray-100', 'text-gray-600');
  });

  // View habit details function (placeholder)
  function viewHabitDetails(habitId) {
    alert('Habit details view will be implemented soon! Habit ID: ' + habitId);
  }

  // Auto-focus on name field
  document.getElementById('name').focus();
</script>

</body>
</html> 