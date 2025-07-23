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
            case 'add_routine':
                $title = sanitizeInput($_POST['title'] ?? '');
                $time_slot = sanitizeInput($_POST['time_slot'] ?? '');
                $task_date = sanitizeInput($_POST['task_date'] ?? '');
                $category = sanitizeInput($_POST['category'] ?? '');
                $description = sanitizeInput($_POST['description'] ?? '');

                if (empty($title) || empty($time_slot)) {
                    $error = 'Please fill in all required fields.';
                } else {
                    try {
                        $sql = "INSERT INTO routines (user_id, title, time_slot, task_date, category, description) VALUES (?, ?, ?, ?, ?, ?)";
                        $routine_id = insert($sql, [$user_id, $title, $time_slot, $task_date, $category, $description]);
                        
                        if ($routine_id) {
                            $success = 'Routine added successfully!';
                            logActivity('routine_added', ['routine_id' => $routine_id, 'title' => $title]);
                        } else {
                            $error = 'Failed to add routine.';
                        }
                    } catch (Exception $e) {
                        $error = 'An error occurred. Please try again.';
                        error_log("Add routine error: " . $e->getMessage());
                    }
                }
                break;

            case 'delete_routine':
                $routine_id = (int)sanitizeInput($_POST['routine_id'] ?? 0);
                if ($routine_id > 0) {
                    try {
                        $sql = "DELETE FROM routines WHERE id = ? AND user_id = ?";
                        $deleted = delete($sql, [$routine_id, $user_id]);
                        
                        if ($deleted) {
                            $success = 'Routine deleted successfully!';
                            logActivity('routine_deleted', ['routine_id' => $routine_id]);
                        } else {
                            $error = 'Failed to delete routine.';
                        }
                    } catch (Exception $e) {
                        $error = 'An error occurred. Please try again.';
                        error_log("Delete routine error: " . $e->getMessage());
                    }
                }
                break;
        }
    }
}

// Get user's routines
try {
    $sql = "SELECT * FROM routines WHERE user_id = ? ORDER BY time_slot ASC";
    $routines = fetchAll($sql, [$user_id]);
} catch (Exception $e) {
    $routines = [];
    error_log("Get routines error: " . $e->getMessage());
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
    <title>Routines - Trackie.in</title>
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
      <h1 class="text-3xl md:text-4xl font-bold text-gray-900 mb-2">My Routines</h1>
      <p class="text-gray-600">Manage your daily routines and schedules</p>
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
      
      <!-- Add New Routine Form -->
      <div class="lg:col-span-1">
        <div class="bg-white rounded-xl shadow-lg p-6">
          <h2 class="text-xl font-bold text-gray-900 mb-4">Add New Routine</h2>
          
          <form method="POST" action="" class="space-y-4">
            <input type="hidden" name="action" value="add_routine">
            
            <div>
              <label for="title" class="block text-sm font-medium text-gray-700 mb-2">Routine Title *</label>
              <input 
                type="text" 
                id="title" 
                name="title" 
                required 
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-red-500"
                placeholder="e.g., Morning Exercise"
              >
            </div>

            <div>
              <label for="time_slot" class="block text-sm font-medium text-gray-700 mb-2">Time *</label>
              <input 
                type="time" 
                id="time_slot" 
                name="time_slot" 
                required 
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-red-500"
              >
            </div>

            <div>
              <label for="task_date" class="block text-sm font-medium text-gray-700 mb-2">Date</label>
              <input 
                type="date" 
                id="task_date" 
                name="task_date" 
                value="<?= date('Y-m-d') ?>"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-red-500"
              >
            </div>

            <div>
              <label for="category" class="block text-sm font-medium text-gray-700 mb-2">Category</label>
              <select 
                id="category" 
                name="category" 
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-red-500"
              >
                <option value="">Select Category</option>
                <option value="Fitness">Fitness</option>
                <option value="Work">Work</option>
                <option value="Study">Study</option>
                <option value="Personal">Personal</option>
                <option value="Health">Health</option>
                <option value="Break">Break</option>
              </select>
            </div>

            <div>
              <label for="description" class="block text-sm font-medium text-gray-700 mb-2">Description</label>
              <textarea 
                id="description" 
                name="description" 
                rows="3"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-red-500"
                placeholder="Add any additional details..."
              ></textarea>
            </div>

            <button 
              type="submit" 
              class="w-full bg-red-600 text-white py-2 px-4 rounded-lg font-medium hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition-colors"
            >
              <i class="fas fa-plus mr-2"></i>Add Routine
            </button>
          </form>
        </div>
      </div>

      <!-- Routines List -->
      <div class="lg:col-span-2">
        <div class="bg-white rounded-xl shadow-lg p-6">
          <div class="flex justify-between items-center mb-6">
            <h2 class="text-xl font-bold text-gray-900">Your Routines</h2>
            <div class="flex space-x-2">
              <button id="viewToday" class="px-3 py-1 bg-red-100 text-red-600 rounded-lg text-sm font-medium">Today</button>
              <button id="viewAll" class="px-3 py-1 bg-gray-100 text-gray-600 rounded-lg text-sm font-medium">All</button>
            </div>
          </div>

          <?php if (empty($routines)): ?>
            <div class="text-center py-12">
              <div class="text-6xl text-gray-300 mb-4">📅</div>
              <h3 class="text-lg font-medium text-gray-900 mb-2">No routines yet</h3>
              <p class="text-gray-600 mb-4">Start building your daily routine to stay organized and productive!</p>
              <button onclick="document.getElementById('title').focus()" class="bg-red-600 text-white px-4 py-2 rounded-lg font-medium hover:bg-red-700 transition-colors">
                Add Your First Routine
              </button>
            </div>
          <?php else: ?>
            <div class="space-y-4">
              <?php foreach ($routines as $routine): ?>
                <div class="border border-gray-200 rounded-lg p-4 hover:shadow-md transition-shadow">
                  <div class="flex justify-between items-start">
                    <div class="flex-1">
                      <div class="flex items-center space-x-3 mb-2">
                        <div class="text-sm font-medium text-gray-500 bg-gray-100 px-2 py-1 rounded">
                          <?= htmlspecialchars($routine['time_slot']) ?>
                        </div>
                        <?php if ($routine['category']): ?>
                          <span class="text-xs bg-red-100 text-red-600 px-2 py-1 rounded-full">
                            <?= htmlspecialchars($routine['category']) ?>
                          </span>
                        <?php endif; ?>
                      </div>
                      
                      <h3 class="text-lg font-semibold text-gray-900 mb-1">
                        <?= htmlspecialchars($routine['title']) ?>
                      </h3>
                      
                      <?php if ($routine['description']): ?>
                        <p class="text-gray-600 text-sm mb-2">
                          <?= htmlspecialchars($routine['description']) ?>
                        </p>
                      <?php endif; ?>
                      
                      <?php if ($routine['task_date']): ?>
                        <p class="text-xs text-gray-500">
                          <i class="fas fa-calendar mr-1"></i>
                          <?= formatDate($routine['task_date'], 'M j, Y') ?>
                        </p>
                      <?php endif; ?>
                    </div>
                    
                    <div class="flex space-x-2">
                      <button 
                        onclick="editRoutine(<?= $routine['id'] ?>)" 
                        class="text-blue-600 hover:text-blue-800 p-1"
                        title="Edit"
                      >
                        <i class="fas fa-edit"></i>
                      </button>
                      <form method="POST" action="" class="inline" onsubmit="return confirm('Are you sure you want to delete this routine?')">
                        <input type="hidden" name="action" value="delete_routine">
                        <input type="hidden" name="routine_id" value="<?= $routine['id'] ?>">
                        <button 
                          type="submit" 
                          class="text-red-600 hover:text-red-800 p-1"
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
  document.getElementById('viewToday').addEventListener('click', function() {
    // Filter to show only today's routines
    this.classList.remove('bg-gray-100', 'text-gray-600');
    this.classList.add('bg-red-100', 'text-red-600');
    document.getElementById('viewAll').classList.remove('bg-red-100', 'text-red-600');
    document.getElementById('viewAll').classList.add('bg-gray-100', 'text-gray-600');
  });

  document.getElementById('viewAll').addEventListener('click', function() {
    // Show all routines
    this.classList.remove('bg-gray-100', 'text-gray-600');
    this.classList.add('bg-red-100', 'text-red-600');
    document.getElementById('viewToday').classList.remove('bg-red-100', 'text-red-600');
    document.getElementById('viewToday').classList.add('bg-gray-100', 'text-gray-600');
  });

  // Edit routine function (placeholder)
  function editRoutine(routineId) {
    alert('Edit functionality will be implemented soon! Routine ID: ' + routineId);
  }

  // Auto-focus on title field
  document.getElementById('title').focus();
</script>

</body>
</html> 