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
            case 'add_goal':
                $goal_name = sanitizeInput($_POST['goal_name'] ?? '');
                $target_value = (int)sanitizeInput($_POST['target_value'] ?? 100);
                $current_progress = (int)sanitizeInput($_POST['current_progress'] ?? 0);
                $deadline = sanitizeInput($_POST['deadline'] ?? '');
                $description = sanitizeInput($_POST['description'] ?? '');

                if (empty($goal_name)) {
                    $error = 'Please enter a goal name.';
                } elseif ($target_value <= 0) {
                    $error = 'Target value must be greater than 0.';
                } elseif ($current_progress > $target_value) {
                    $error = 'Current progress cannot exceed target value.';
                } else {
                    try {
                        $sql = "INSERT INTO goals (user_id, goal_name, progress, target_value, deadline, description) VALUES (?, ?, ?, ?, ?, ?)";
                        $goal_id = insert($sql, [$user_id, $goal_name, $current_progress, $target_value, $deadline, $description]);
                        
                        if ($goal_id) {
                            $success = 'Goal added successfully!';
                            logActivity('goal_added', ['goal_id' => $goal_id, 'name' => $goal_name]);
                        } else {
                            $error = 'Failed to add goal.';
                        }
                    } catch (Exception $e) {
                        $error = 'An error occurred. Please try again.';
                        error_log("Add goal error: " . $e->getMessage());
                    }
                }
                break;

            case 'update_progress':
                $goal_id = (int)sanitizeInput($_POST['goal_id'] ?? 0);
                $new_progress = (int)sanitizeInput($_POST['new_progress'] ?? 0);

                if ($goal_id > 0) {
                    try {
                        // Get current goal data
                        $sql = "SELECT * FROM goals WHERE id = ? AND user_id = ?";
                        $goal = fetchOne($sql, [$goal_id, $user_id]);
                        
                        if ($goal) {
                            $target_value = $goal['target_value'] ?? 100;
                            $new_progress = min($new_progress, $target_value); // Ensure progress doesn't exceed target
                            
                            $update_sql = "UPDATE goals SET progress = ? WHERE id = ? AND user_id = ?";
                            $updated = update($update_sql, [$new_progress, $goal_id, $user_id]);
                            
                            if ($updated) {
                                $success = 'Progress updated successfully!';
                                logActivity('goal_progress_updated', [
                                    'goal_id' => $goal_id, 
                                    'old_progress' => $goal['progress'], 
                                    'new_progress' => $new_progress
                                ]);
                            } else {
                                $error = 'Failed to update progress.';
                            }
                        } else {
                            $error = 'Goal not found.';
                        }
                    } catch (Exception $e) {
                        $error = 'An error occurred. Please try again.';
                        error_log("Update progress error: " . $e->getMessage());
                    }
                }
                break;

            case 'delete_goal':
                $goal_id = (int)sanitizeInput($_POST['goal_id'] ?? 0);
                if ($goal_id > 0) {
                    try {
                        $sql = "DELETE FROM goals WHERE id = ? AND user_id = ?";
                        $deleted = delete($sql, [$goal_id, $user_id]);
                        
                        if ($deleted) {
                            $success = 'Goal deleted successfully!';
                            logActivity('goal_deleted', ['goal_id' => $goal_id]);
                        } else {
                            $error = 'Failed to delete goal.';
                        }
                    } catch (Exception $e) {
                        $error = 'An error occurred. Please try again.';
                        error_log("Delete goal error: " . $e->getMessage());
                    }
                }
                break;
        }
    }
}

// Get user's goals
try {
    $sql = "SELECT * FROM goals WHERE user_id = ? ORDER BY created_at DESC";
    $goals = fetchAll($sql, [$user_id]);
} catch (Exception $e) {
    $goals = [];
    error_log("Get goals error: " . $e->getMessage());
}

// Calculate overall progress
$total_goals = count($goals);
$completed_goals = 0;
$total_progress = 0;

foreach ($goals as $goal) {
    $target = $goal['target_value'] ?? 100;
    $progress = $goal['progress'] ?? 0;
    $total_progress += ($progress / $target) * 100;
    
    if ($progress >= $target) {
        $completed_goals++;
    }
}

$overall_progress = $total_goals > 0 ? $total_progress / $total_goals : 0;

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
    <title>Goals - Trackie.in</title>
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
      <h1 class="text-3xl md:text-4xl font-bold text-gray-900 mb-2">My Goals</h1>
      <p class="text-gray-600">Set, track, and achieve your personal goals</p>
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

    <!-- Overall Progress Card -->
    <div class="bg-white rounded-xl shadow-lg p-6 mb-8">
      <h2 class="text-xl font-bold text-gray-900 mb-4">Overall Progress</h2>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="text-center">
          <div class="text-3xl font-bold text-red-600 mb-2"><?= $total_goals ?></div>
          <div class="text-gray-600">Total Goals</div>
        </div>
        <div class="text-center">
          <div class="text-3xl font-bold text-green-600 mb-2"><?= $completed_goals ?></div>
          <div class="text-gray-600">Completed</div>
        </div>
        <div class="text-center">
          <div class="text-3xl font-bold text-blue-600 mb-2"><?= round($overall_progress, 1) ?>%</div>
          <div class="text-gray-600">Overall Progress</div>
        </div>
      </div>
      
      <!-- Overall Progress Bar -->
      <div class="mt-6">
        <div class="flex justify-between text-sm text-gray-600 mb-2">
          <span>Progress</span>
          <span><?= round($overall_progress, 1) ?>%</span>
        </div>
        <div class="w-full bg-gray-200 rounded-full h-3">
          <div class="bg-gradient-to-r from-red-500 to-red-600 h-3 rounded-full transition-all duration-500" style="width: <?= $overall_progress ?>%"></div>
        </div>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
      
      <!-- Add New Goal Form -->
      <div class="lg:col-span-1">
        <div class="bg-white rounded-xl shadow-lg p-6">
          <h2 class="text-xl font-bold text-gray-900 mb-4">Add New Goal</h2>
          
          <form method="POST" action="" class="space-y-4">
            <input type="hidden" name="action" value="add_goal">
            
            <div>
              <label for="goal_name" class="block text-sm font-medium text-gray-700 mb-2">Goal Name *</label>
              <input 
                type="text" 
                id="goal_name" 
                name="goal_name" 
                required 
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-red-500"
                placeholder="e.g., Read 50 books this year"
              >
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div>
                <label for="target_value" class="block text-sm font-medium text-gray-700 mb-2">Target Value *</label>
                <input 
                  type="number" 
                  id="target_value" 
                  name="target_value" 
                  required 
                  min="1"
                  value="100"
                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-red-500"
                >
              </div>
              <div>
                <label for="current_progress" class="block text-sm font-medium text-gray-700 mb-2">Current Progress</label>
                <input 
                  type="number" 
                  id="current_progress" 
                  name="current_progress" 
                  min="0"
                  value="0"
                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-red-500"
                >
              </div>
            </div>

            <div>
              <label for="deadline" class="block text-sm font-medium text-gray-700 mb-2">Deadline</label>
              <input 
                type="date" 
                id="deadline" 
                name="deadline" 
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-red-500"
              >
            </div>

            <div>
              <label for="description" class="block text-sm font-medium text-gray-700 mb-2">Description</label>
              <textarea 
                id="description" 
                name="description" 
                rows="3"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-red-500"
                placeholder="Add details about your goal..."
              ></textarea>
            </div>

            <button 
              type="submit" 
              class="w-full bg-red-600 text-white py-2 px-4 rounded-lg font-medium hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition-colors"
            >
              <i class="fas fa-plus mr-2"></i>Add Goal
            </button>
          </form>
        </div>
      </div>

      <!-- Goals List -->
      <div class="lg:col-span-2">
        <div class="bg-white rounded-xl shadow-lg p-6">
          <div class="flex justify-between items-center mb-6">
            <h2 class="text-xl font-bold text-gray-900">Your Goals</h2>
            <div class="flex space-x-2">
              <button id="viewAll" class="px-3 py-1 bg-red-100 text-red-600 rounded-lg text-sm font-medium">All</button>
              <button id="viewActive" class="px-3 py-1 bg-gray-100 text-gray-600 rounded-lg text-sm font-medium">Active</button>
              <button id="viewCompleted" class="px-3 py-1 bg-gray-100 text-gray-600 rounded-lg text-sm font-medium">Completed</button>
            </div>
          </div>

          <?php if (empty($goals)): ?>
            <div class="text-center py-12">
              <div class="text-6xl text-gray-300 mb-4">🎯</div>
              <h3 class="text-lg font-medium text-gray-900 mb-2">No goals yet</h3>
              <p class="text-gray-600 mb-4">Start setting goals to achieve your dreams and track your progress!</p>
              <button onclick="document.getElementById('goal_name').focus()" class="bg-red-600 text-white px-4 py-2 rounded-lg font-medium hover:bg-red-700 transition-colors">
                Add Your First Goal
              </button>
            </div>
          <?php else: ?>
            <div class="space-y-4">
              <?php foreach ($goals as $goal): ?>
                <?php 
                $progress_percentage = 0;
                $target = $goal['target_value'] ?? 100;
                $progress = $goal['progress'] ?? 0;
                if ($target > 0) {
                    $progress_percentage = min(100, ($progress / $target) * 100);
                }
                $is_completed = $progress >= $target;
                ?>
                <div class="border border-gray-200 rounded-lg p-4 hover:shadow-md transition-shadow <?= $is_completed ? 'bg-green-50 border-green-200' : '' ?>">
                  <div class="flex justify-between items-start">
                    <div class="flex-1">
                      <div class="flex items-center space-x-3 mb-2">
                        <h3 class="text-lg font-semibold text-gray-900">
                          <?= htmlspecialchars($goal['goal_name']) ?>
                        </h3>
                        <?php if ($is_completed): ?>
                          <span class="text-xs bg-green-100 text-green-600 px-2 py-1 rounded-full">
                            <i class="fas fa-trophy mr-1"></i>Completed
                          </span>
                        <?php endif; ?>
                      </div>
                      
                      <?php if ($goal['description']): ?>
                        <p class="text-gray-600 text-sm mb-3">
                          <?= htmlspecialchars($goal['description']) ?>
                        </p>
                      <?php endif; ?>
                      
                      <div class="flex items-center space-x-4 text-sm text-gray-600 mb-3">
                        <span>Progress: <?= $progress ?> / <?= $target ?></span>
                        <span class="font-medium text-blue-600"><?= round($progress_percentage, 1) ?>%</span>
                        <?php if ($goal['deadline']): ?>
                          <span><i class="fas fa-calendar mr-1"></i><?= formatDate($goal['deadline'], 'M j, Y') ?></span>
                        <?php endif; ?>
                      </div>
                      
                      <!-- Progress Bar -->
                      <div class="w-full bg-gray-200 rounded-full h-3 mb-3">
                        <div class="bg-gradient-to-r from-red-500 to-red-600 h-3 rounded-full transition-all duration-500 <?= $is_completed ? 'from-green-500 to-green-600' : '' ?>" style="width: <?= $progress_percentage ?>%"></div>
                      </div>
                      
                      <!-- Update Progress Form -->
                      <?php if (!$is_completed): ?>
                        <form method="POST" action="" class="flex items-center space-x-2">
                          <input type="hidden" name="action" value="update_progress">
                          <input type="hidden" name="goal_id" value="<?= $goal['id'] ?>">
                          <input 
                            type="number" 
                            name="new_progress" 
                            min="0" 
                            max="<?= $target ?>"
                            value="<?= $progress ?>"
                            class="w-20 px-2 py-1 text-sm border border-gray-300 rounded focus:outline-none focus:ring-2 focus:ring-red-500"
                          >
                          <button 
                            type="submit" 
                            class="text-sm bg-blue-600 text-white px-3 py-1 rounded hover:bg-blue-700 transition-colors"
                          >
                            Update
                          </button>
                        </form>
                      <?php endif; ?>
                    </div>
                    
                    <div class="flex space-x-2">
                      <button 
                        onclick="viewGoalDetails(<?= $goal['id'] ?>)" 
                        class="text-blue-600 hover:text-blue-800 p-2 bg-blue-50 rounded-lg hover:bg-blue-100 transition-colors"
                        title="View Details"
                      >
                        <i class="fas fa-eye"></i>
                      </button>
                      
                      <form method="POST" action="" class="inline" onsubmit="return confirm('Are you sure you want to delete this goal?')">
                        <input type="hidden" name="action" value="delete_goal">
                        <input type="hidden" name="goal_id" value="<?= $goal['id'] ?>">
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
  const filterButtons = ['viewAll', 'viewActive', 'viewCompleted'];
  filterButtons.forEach(buttonId => {
    document.getElementById(buttonId).addEventListener('click', function() {
      // Reset all buttons
      filterButtons.forEach(id => {
        const btn = document.getElementById(id);
        btn.classList.remove('bg-red-100', 'text-red-600');
        btn.classList.add('bg-gray-100', 'text-gray-600');
      });
      
      // Activate clicked button
      this.classList.remove('bg-gray-100', 'text-gray-600');
      this.classList.add('bg-red-100', 'text-red-600');
    });
  });

  // View goal details function (placeholder)
  function viewGoalDetails(goalId) {
    alert('Goal details view will be implemented soon! Goal ID: ' + goalId);
  }

  // Auto-focus on goal name field
  document.getElementById('goal_name').focus();
</script>

</body>
</html> 