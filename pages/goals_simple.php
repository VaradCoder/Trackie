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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'add_goal':
            $goal_name = sanitizeInput($_POST['goal_name'] ?? '');
            $description = sanitizeInput($_POST['description'] ?? '');
            $target_value = (int)($_POST['target_value'] ?? 100);
            $deadline = sanitizeInput($_POST['deadline'] ?? null);
            
            if (!empty($goal_name)) {
                $sql = "INSERT INTO goals (user_id, goal_name, description, target_value, deadline) VALUES (?, ?, ?, ?, ?)";
                $goal_id = insert($sql, [$user_id, $goal_name, $description, $target_value, $deadline]);
                
                if ($goal_id) {
                    setFlashMessage('success', 'Goal added successfully!');
                } else {
                    setFlashMessage('error', 'Failed to add goal.');
                }
            } else {
                setFlashMessage('error', 'Goal name is required.');
            }
            break;
            
        case 'update_progress':
            $goal_id = $_POST['goal_id'] ?? '';
            $progress = (int)($_POST['progress'] ?? 0);
            
            if ($goal_id && $progress >= 0) {
                $sql = "UPDATE goals SET progress = ? WHERE id = ? AND user_id = ?";
                $updated = update($sql, [$progress, $goal_id, $user_id]);
                
                if ($updated) {
                    setFlashMessage('success', 'Progress updated successfully!');
                } else {
                    setFlashMessage('error', 'Failed to update progress.');
                }
            }
            break;
            
        case 'delete_goal':
            $goal_id = $_POST['goal_id'] ?? '';
            
            if ($goal_id) {
                $sql = "DELETE FROM goals WHERE id = ? AND user_id = ?";
                $deleted = delete($sql, [$goal_id, $user_id]);
                
                if ($deleted) {
                    setFlashMessage('success', 'Goal deleted successfully!');
                } else {
                    setFlashMessage('error', 'Failed to delete goal.');
                }
            }
            break;
    }
    
    // Redirect to prevent form resubmission
    header('Location: goals_simple.php');
    exit();
}

// Get user's goals
$sql = "SELECT * FROM goals WHERE user_id = ? ORDER BY created_at DESC";
$goals = fetchAll($sql, [$user_id]);

// Get flash messages
$flash = getFlashMessage();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Goals - Trackie.in</title>
    <link rel="icon" type="image/x-icon" href="../assets/images/Logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Bangers&family=Comic+Neue:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="font-comic">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="pt-20 px-4 max-w-6xl mx-auto">
        <!-- Header -->
        <div class="flex items-center justify-between mb-8">
            <div>
                <h1 class="text-3xl font-bold text-gold">My Goals</h1>
                <p class="text-silver mt-2">Set and track your personal goals</p>
            </div>
            <button onclick="openAddModal()" class="btn-primary">
                <i class="fas fa-plus mr-2"></i>Add New Goal
            </button>
        </div>

        <!-- Flash Messages -->
        <?php if ($flash): ?>
            <div class="mb-6 p-4 rounded-lg <?= $flash['type'] === 'success' ? 'bg-green-900/20 border border-green-500 text-green-300' : 'bg-red-900/20 border border-red-500 text-red-300' ?>">
                <i class="fas <?= $flash['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?> mr-2"></i>
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>

        <!-- Goals List -->
        <div class="card p-6">
            <?php if (empty($goals)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-bullseye text-6xl text-silver mb-4"></i>
                    <h3 class="text-xl font-bold text-white mb-2">No goals yet</h3>
                    <p class="text-silver mb-4">Start setting and achieving your goals today!</p>
                    <button onclick="openAddModal()" class="btn-primary">
                        <i class="fas fa-plus mr-2"></i>Add Your First Goal
                    </button>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($goals as $goal): ?>
                        <div class="card p-6">
                            <div class="flex items-start justify-between mb-4">
                                <div class="flex-1">
                                    <h3 class="text-lg font-bold text-black mb-2"><?= htmlspecialchars($goal['goal_name']) ?></h3>
                                    <?php if ($goal['description']): ?>
                                        <p class="text-silver text-sm mb-3"><?= htmlspecialchars($goal['description']) ?></p>
                                    <?php endif; ?>
                                </div>
                                <form method="POST" onsubmit="return confirm('Are you sure you want to delete this goal?')">
                                    <input type="hidden" name="action" value="delete_goal">
                                    <input type="hidden" name="goal_id" value="<?= $goal['id'] ?>">
                                    <button type="submit" class="text-gold hover:text-red-400 transition-colors" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                            
                            <div class="space-y-3">
                                <div class="flex justify-between text-sm">
                                    <span class="text-silver">Progress:</span>
                                    <span class="text-gold font-bold"><?= $goal['progress'] ?> / <?= $goal['target_value'] ?></span>
                                </div>
                                
                                <div class="progress-container">
                                    <?php 
                                    $percentage = $goal['target_value'] > 0 ? round(($goal['progress'] / $goal['target_value']) * 100) : 0;
                                    $percentage = min($percentage, 100); // Cap at 100%
                                    ?>
                                    <div class="progress-bar" style="width: <?= $percentage ?>%"></div>
                                </div>
                                
                                <div class="text-center text-sm">
                                    <span class="text-gold font-bold"><?= $percentage ?>%</span>
                                    <?php if ($percentage >= 100): ?>
                                        <span class="text-green-400 ml-2">🎉 Completed!</span>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if ($goal['deadline']): ?>
                                    <div class="text-center text-xs text-silver">
                                        <i class="fas fa-calendar mr-1"></i>
                                        Deadline: <?= date('M j, Y', strtotime($goal['deadline'])) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="mt-4 pt-4 border-t border-silver">
                                <form method="POST" class="flex gap-2">
                                    <input type="hidden" name="action" value="update_progress">
                                    <input type="hidden" name="goal_id" value="<?= $goal['id'] ?>">
                                    
                                    <input type="number" name="progress" min="0" max="<?= $goal['target_value'] ?>" 
                                           value="<?= $goal['progress'] ?>" 
                                           class="form-input flex-1 text-center">
                                    
                                    <button type="submit" class="btn-primary">
                                        <i class="fas fa-save"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add Goal Modal -->
    <div id="addModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="card p-8 max-w-md w-full">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-2xl font-bold text-gold">Add New Goal</h2>
                    <button onclick="closeAddModal()" class="text-silver hover:text-gold">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <form method="POST">
                    <input type="hidden" name="action" value="add_goal">
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-silver mb-2">Goal Name *</label>
                        <input type="text" name="goal_name" required 
                               class="form-input w-full" 
                               placeholder="e.g., Read 50 books, Save $10,000">
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-silver mb-2">Description</label>
                        <textarea name="description" rows="3" 
                                  class="form-input w-full" 
                                  placeholder="Describe your goal (optional)"></textarea>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-silver mb-2">Target Value</label>
                        <input type="number" name="target_value" value="100" min="1" 
                               class="form-input w-full" 
                               placeholder="e.g., 50 books, 10000 dollars">
                    </div>
                    
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-silver mb-2">Deadline</label>
                        <input type="date" name="deadline" 
                               class="form-input w-full">
                    </div>
                    
                    <div class="flex gap-3">
                        <button type="submit" class="btn-primary flex-1">
                            <i class="fas fa-plus mr-2"></i>Add Goal
                        </button>
                        <button type="button" onclick="closeAddModal()" class="btn-secondary">
                            Cancel
                        </button>
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
        
        // Close modal when clicking outside
        document.getElementById('addModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeAddModal();
            }
        });
    </script>
</body>
</html> 