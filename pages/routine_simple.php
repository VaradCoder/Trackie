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
        case 'add_routine':
            $routine_name = sanitizeInput($_POST['routine_name'] ?? '');
            $description = sanitizeInput($_POST['description'] ?? '');
            $time_of_day = sanitizeInput($_POST['time_of_day'] ?? 'morning');
            
            if (!empty($routine_name)) {
                $sql = "INSERT INTO routines (user_id, routine_name, description, time_of_day) VALUES (?, ?, ?, ?)";
                $routine_id = insert($sql, [$user_id, $routine_name, $description, $time_of_day]);
                
                if ($routine_id) {
                    setFlashMessage('success', 'Routine added successfully!');
                } else {
                    setFlashMessage('error', 'Failed to add routine.');
                }
            } else {
                setFlashMessage('error', 'Routine name is required.');
            }
            break;
            
        case 'delete_routine':
            $routine_id = $_POST['routine_id'] ?? '';
            
            if ($routine_id) {
                $sql = "DELETE FROM routines WHERE id = ? AND user_id = ?";
                $deleted = delete($sql, [$routine_id, $user_id]);
                
                if ($deleted) {
                    setFlashMessage('success', 'Routine deleted successfully!');
                } else {
                    setFlashMessage('error', 'Failed to delete routine.');
                }
            }
            break;
    }
    
    // Redirect to prevent form resubmission
    header('Location: routine_simple.php');
    exit();
}

// Get user's routines
$sql = "SELECT * FROM routines WHERE user_id = ? ORDER BY time_of_day, created_at DESC";
$routines = fetchAll($sql, [$user_id]);

// Group routines by time of day
$routines_by_time = [];
foreach ($routines as $routine) {
    $time = $routine['time_of_day'];
    if (!isset($routines_by_time[$time])) {
        $routines_by_time[$time] = [];
    }
    $routines_by_time[$time][] = $routine;
}

// Get flash messages
$flash = getFlashMessage();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Routines - Trackie.in</title>
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
                <h1 class="text-3xl font-bold text-gold">My Routines</h1>
                <p class="text-silver mt-2">Organize your daily activities and build consistent habits</p>
            </div>
            <button onclick="openAddModal()" class="btn-primary">
                <i class="fas fa-plus mr-2"></i>Add New Routine
            </button>
        </div>

        <!-- Flash Messages -->
        <?php if ($flash): ?>
            <div class="mb-6 p-4 rounded-lg <?= $flash['type'] === 'success' ? 'bg-green-900/20 border border-green-500 text-green-300' : 'bg-red-900/20 border border-red-500 text-red-300' ?>">
                <i class="fas <?= $flash['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?> mr-2"></i>
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>

        <!-- Routines by Time of Day -->
        <?php if (empty($routines)): ?>
            <div class="card p-6">
                <div class="text-center py-12">
                    <i class="fas fa-clock text-6xl text-silver mb-4"></i>
                    <h3 class="text-xl font-bold text-white mb-2">No routines yet</h3>
                    <p class="text-silver mb-4">Start organizing your day with structured routines!</p>
                    <button onclick="openAddModal()" class="btn btn-primary">
                        <i class="fas fa-plus mr-2"></i>Add Your First Routine
                    </button>
                </div>
            </div>
        <?php else: ?>
            <div class="space-y-8">
                <?php 
                $time_labels = [
                    'morning' => ['icon' => 'fa-sun', 'label' => 'Morning Routine', 'color' => 'text-yellow-400'],
                    'afternoon' => ['icon' => 'fa-cloud-sun', 'label' => 'Afternoon Routine', 'color' => 'text-orange-400'],
                    'evening' => ['icon' => 'fa-moon', 'label' => 'Evening Routine', 'color' => 'text-blue-400'],
                    'night' => ['icon' => 'fa-star', 'label' => 'Night Routine', 'color' => 'text-purple-400']
                ];
                
                foreach ($time_labels as $time => $info):
                    if (isset($routines_by_time[$time])):
                ?>
                    <div class="card p-6">
                        <div class="flex items-center mb-6">
                            <i class="fas <?= $info['icon'] ?> text-2xl <?= $info['color'] ?> mr-3"></i>
                            <h2 class="text-xl font-bold text-black"><?= $info['label'] ?></h2>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <?php foreach ($routines_by_time[$time] as $routine): ?>
                                <div class="bg-glass rounded-lg p-4 border border-gold/20">
                                    <div class="flex items-start justify-between mb-3">
                                        <div class="flex-1">
                                            <h3 class="font-bold text-lg text-black mb-1"><?= htmlspecialchars($routine['routine_name']) ?></h3>
                                            <?php if ($routine['description']): ?>
                                                <p class="text-silver text-sm"><?= htmlspecialchars($routine['description']) ?></p>
                                            <?php endif; ?>
                                        </div>
                                        <form method="POST" onsubmit="return confirm('Are you sure you want to delete this routine?')">
                                            <input type="hidden" name="action" value="delete_routine">
                                            <input type="hidden" name="routine_id" value="<?= $routine['id'] ?>">
                                            <button type="submit" class="text-gold hover:text-red-400 transition-colors" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                    
                                    <div class="text-xs text-silver">
                                        <i class="fas fa-calendar mr-1"></i>
                                        Created: <?= date('M j, Y', strtotime($routine['created_at'])) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php 
                    endif;
                endforeach; 
                ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Add Routine Modal -->
    <div id="addModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="card p-8 max-w-md w-full">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-2xl font-bold text-gold">Add New Routine</h2>
                    <button onclick="closeAddModal()" class="text-silver hover:text-gold">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <form method="POST">
                    <input type="hidden" name="action" value="add_routine">
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-silver mb-2">Routine Name *</label>
                        <input type="text" name="routine_name" required 
                               class="form-input w-full" 
                               placeholder="e.g., Morning Exercise, Evening Reading">
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-silver mb-2">Description</label>
                        <textarea name="description" rows="3" 
                                  class="form-input w-full" 
                                  placeholder="Describe your routine (optional)"></textarea>
                    </div>
                    
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-silver mb-2">Time of Day</label>
                        <select name="time_of_day" class="form-input w-full">
                            <option value="morning">Morning</option>
                            <option value="afternoon">Afternoon</option>
                            <option value="evening">Evening</option>
                            <option value="night">Night</option>
                        </select>
                    </div>
                    
                    <div class="flex gap-3">
                        <button type="submit" class="btn btn-primary flex-1">
                            <i class="fas fa-plus mr-2"></i>Add Routine
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