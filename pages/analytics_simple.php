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

// Get statistics
$stats = [];

// Todo statistics
$todo_stats_sql = "SELECT 
    COUNT(*) as total_todos,
    SUM(CASE WHEN completed = 1 THEN 1 ELSE 0 END) as completed_todos,
    SUM(CASE WHEN completed = 0 THEN 1 ELSE 0 END) as pending_todos,
    SUM(CASE WHEN completed = 0 AND due_date < CURDATE() THEN 1 ELSE 0 END) as overdue_todos
    FROM todos WHERE user_id = ? AND deleted_at IS NULL";
$todo_stats = fetchOne($todo_stats_sql, [$user_id]);
$stats['todos'] = $todo_stats;

// Habit statistics
$habit_stats_sql = "SELECT 
    COUNT(*) as total_habits,
    SUM(CASE WHEN l.date_completed = CURDATE() THEN 1 ELSE 0 END) as completed_today
    FROM habits h 
    LEFT JOIN logs l ON h.id = l.habit_id AND l.date_completed = CURDATE()
    WHERE h.user_id = ?";
$habit_stats = fetchOne($habit_stats_sql, [$user_id]);
$stats['habits'] = $habit_stats;

// Goal statistics
$goal_stats_sql = "SELECT 
    COUNT(*) as total_goals,
    SUM(CASE WHEN progress >= target_value THEN 1 ELSE 0 END) as completed_goals,
    AVG(CASE WHEN target_value > 0 THEN (progress / target_value) * 100 ELSE 0 END) as avg_progress
    FROM goals WHERE user_id = ?";
$goal_stats = fetchOne($goal_stats_sql, [$user_id]);
$stats['goals'] = $goal_stats;

// Routine statistics
$routine_stats_sql = "SELECT COUNT(*) as total_routines FROM routines WHERE user_id = ?";
$routine_stats = fetchOne($routine_stats_sql, [$user_id]);
$stats['routines'] = $routine_stats;

// Recent activity
$recent_activity_sql = "SELECT 
    'todo' as type,
    t.title as name,
    t.created_at as date,
    'Added new todo' as action
    FROM todos t 
    WHERE t.user_id = ? AND t.deleted_at IS NULL
    UNION ALL
    SELECT 
    'habit' as type,
    h.name as name,
    l.date_completed as date,
    'Logged habit' as action
    FROM logs l 
    JOIN habits h ON l.habit_id = h.id 
    WHERE l.user_id = ?
    ORDER BY date DESC 
    LIMIT 10";
$recent_activity = fetchAll($recent_activity_sql, [$user_id, $user_id]);

// Weekly progress for habits
$weekly_habit_sql = "SELECT 
    h.name,
    COUNT(l.id) as logs_this_week
    FROM habits h 
    LEFT JOIN logs l ON h.id = l.habit_id AND l.date_completed >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    WHERE h.user_id = ?
    GROUP BY h.id, h.name
    ORDER BY logs_this_week DESC";
$weekly_habits = fetchAll($weekly_habit_sql, [$user_id]);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics - Trackie.in</title>
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
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gold">Analytics Dashboard</h1>
            <p class="text-silver mt-2">Track your progress and performance across all areas</p>
        </div>

        <!-- Overview Statistics -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
            <div class="card stat-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-silver">Total Todos</p>
                        <p class="text-2xl font-bold text-gold"><?= $stats['todos']['total_todos'] ?? 0 ?></p>
                    </div>
                    <i class="fas fa-tasks text-2xl text-gold"></i>
                </div>
                <div class="mt-2">
                    <div class="flex justify-between text-xs text-silver">
                        <span>Completed: <?= $stats['todos']['completed_todos'] ?? 0 ?></span>
                        <span>Pending: <?= $stats['todos']['pending_todos'] ?? 0 ?></span>
                    </div>
                </div>
            </div>
            
            <div class="card stat-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-silver">Active Habits</p>
                        <p class="text-2xl font-bold text-gold"><?= $stats['habits']['total_habits'] ?? 0 ?></p>
                    </div>
                    <i class="fas fa-heart text-2xl text-gold"></i>
                </div>
                <div class="mt-2">
                    <div class="text-xs text-silver">
                        <span>Completed today: <?= $stats['habits']['completed_today'] ?? 0 ?></span>
                    </div>
                </div>
            </div>
            
            <div class="card stat-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-silver">Active Goals</p>
                        <p class="text-2xl font-bold text-gold"><?= $stats['goals']['total_goals'] ?? 0 ?></p>
                    </div>
                    <i class="fas fa-bullseye text-2xl text-gold"></i>
                </div>
                <div class="mt-2">
                    <div class="text-xs text-silver">
                        <span>Completed: <?= $stats['goals']['completed_goals'] ?? 0 ?></span>
                    </div>
                </div>
            </div>
            
            <div class="card stat-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-silver">Routines</p>
                        <p class="text-2xl font-bold text-gold"><?= $stats['routines']['total_routines'] ?? 0 ?></p>
                    </div>
                    <i class="fas fa-clock text-2xl text-gold"></i>
                </div>
                <div class="mt-2">
                    <div class="text-xs text-silver">
                        <span>Daily structure</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Progress Overview -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
            <!-- Todo Progress -->
            <div class="card p-6">
                <h2 class="text-xl font-bold text-black mb-4">Todo Progress</h2>
                <?php 
                $todo_progress = $stats['todos']['total_todos'] > 0 ? 
                    round(($stats['todos']['completed_todos'] / $stats['todos']['total_todos']) * 100) : 0;
                ?>
                <div class="mb-4">
                    <div class="flex justify-between text-sm mb-2">
                        <span class="text-silver">Overall Completion</span>
                        <span class="text-gold font-bold"><?= $todo_progress ?>%</span>
                    </div>
                    <div class="progress-container">
                        <div class="progress-bar" style="width: <?= $todo_progress ?>%"></div>
                    </div>
                </div>
                
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-silver">Completed</span>
                        <span class="text-green-400"><?= $stats['todos']['completed_todos'] ?? 0 ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-silver">Pending</span>
                        <span class="text-yellow-400"><?= $stats['todos']['pending_todos'] ?? 0 ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-silver">Overdue</span>
                        <span class="text-red-400"><?= $stats['todos']['overdue_todos'] ?? 0 ?></span>
                    </div>
                </div>
            </div>

            <!-- Goal Progress -->
            <div class="card p-6">
                <h2 class="text-xl font-bold text-black mb-4">Goal Progress</h2>
                <?php 
                $goal_progress = $stats['goals']['avg_progress'] ?? 0;
                ?>
                <div class="mb-4">
                    <div class="flex justify-between text-sm mb-2">
                        <span class="text-silver">Average Progress</span>
                        <span class="text-gold font-bold"><?= round($goal_progress) ?>%</span>
                    </div>
                    <div class="progress-container">
                        <div class="progress-bar" style="width: <?= $goal_progress ?>%"></div>
                    </div>
                </div>
                
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-silver">Total Goals</span>
                        <span class="text-gold"><?= $stats['goals']['total_goals'] ?? 0 ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-silver">Completed</span>
                        <span class="text-green-400"><?= $stats['goals']['completed_goals'] ?? 0 ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-silver">In Progress</span>
                        <span class="text-blue-400"><?= ($stats['goals']['total_goals'] ?? 0) - ($stats['goals']['completed_goals'] ?? 0) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Weekly Habit Performance -->
        <div class="card p-6 mb-8">
            <h2 class="text-xl font-bold text-black mb-4">Weekly Habit Performance</h2>
            <?php if (empty($weekly_habits)): ?>
                <div class="text-center py-8">
                    <i class="fas fa-chart-line text-4xl text-silver mb-2"></i>
                    <p class="text-silver">No habit data available</p>
                </div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($weekly_habits as $habit): ?>
                        <div class="flex items-center justify-between p-3 bg-glass rounded-lg">
                            <div class="flex items-center">
                                <i class="fas fa-heart text-gold mr-3"></i>
                                <span class="text-black font-medium"><?= htmlspecialchars($habit['name']) ?></span>
                            </div>
                            <div class="flex items-center">
                                <span class="text-gold font-bold mr-2"><?= $habit['logs_this_week'] ?></span>
                                <span class="text-silver text-sm">times this week</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Recent Activity -->
        <div class="card p-6">
            <h2 class="text-xl font-bold text-black mb-4">Recent Activity</h2>
            <?php if (empty($recent_activity)): ?>
                <div class="text-center py-8">
                    <i class="fas fa-history text-4xl text-silver mb-2"></i>
                    <p class="text-silver">No recent activity</p>
                </div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($recent_activity as $activity): ?>
                        <div class="flex items-center p-3 bg-glass rounded-lg">
                            <div class="flex items-center mr-3">
                                <?php if ($activity['type'] === 'todo'): ?>
                                    <i class="fas fa-tasks text-gold"></i>
                                <?php else: ?>
                                    <i class="fas fa-heart text-gold"></i>
                                <?php endif; ?>
                            </div>
                            <div class="flex-1">
                                <div class="text-black font-medium"><?= htmlspecialchars($activity['name']) ?></div>
                                <div class="text-silver text-sm"><?= $activity['action'] ?></div>
                            </div>
                            <div class="text-silver text-sm">
                                <?= date('M j, Y', strtotime($activity['date'])) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="../assets/js/app.js"></script>
</body>
</html> 