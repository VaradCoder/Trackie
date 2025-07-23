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

// Get analytics data
try {
    // Get habits statistics
    $habits_sql = "SELECT 
        COUNT(*) as total_habits,
        COUNT(CASE WHEN frequency = 'daily' THEN 1 END) as daily_habits,
        COUNT(CASE WHEN frequency = 'weekly' THEN 1 END) as weekly_habits
        FROM habits WHERE user_id = ?";
    $habits_stats = fetchOne($habits_sql, [$user_id]);

    // Get habit completion data for last 7 days
    $completion_sql = "SELECT 
        DATE(l.date_completed) as date,
        COUNT(*) as completions
        FROM logs l 
        JOIN habits h ON l.habit_id = h.id 
        WHERE h.user_id = ? 
        AND l.date_completed >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY DATE(l.date_completed)
        ORDER BY date";
    $completion_data = fetchAll($completion_sql, [$user_id]);

    // Get goals statistics
    $goals_sql = "SELECT 
        COUNT(*) as total_goals,
        COUNT(CASE WHEN progress >= target_value THEN 1 END) as completed_goals,
        AVG(CASE WHEN target_value > 0 THEN (progress / target_value) * 100 ELSE 0 END) as avg_progress
        FROM goals WHERE user_id = ?";
    $goals_stats = fetchOne($goals_sql, [$user_id]);

    // Get routines statistics
    $routines_sql = "SELECT 
        COUNT(*) as total_routines,
        COUNT(CASE WHEN category = 'Fitness' THEN 1 END) as fitness_routines,
        COUNT(CASE WHEN category = 'Work' THEN 1 END) as work_routines,
        COUNT(CASE WHEN category = 'Study' THEN 1 END) as study_routines
        FROM routines WHERE user_id = ?";
    $routines_stats = fetchOne($routines_sql, [$user_id]);

    // Get top performing habits
    $top_habits_sql = "SELECT 
        h.name,
        COUNT(l.id) as completion_count
        FROM habits h 
        LEFT JOIN logs l ON h.id = l.habit_id 
        WHERE h.user_id = ? 
        GROUP BY h.id, h.name 
        ORDER BY completion_count DESC 
        LIMIT 5";
    $top_habits = fetchAll($top_habits_sql, [$user_id]);

    // Get recent activity
    $recent_activity_sql = "SELECT 
        'habit' as type,
        h.name as title,
        l.date_completed as date,
        'Completed habit' as action
        FROM logs l 
        JOIN habits h ON l.habit_id = h.id 
        WHERE h.user_id = ?
        UNION ALL
        SELECT 
        'goal' as type,
        g.goal_name as title,
        g.created_at as date,
        'Added goal' as action
        FROM goals g 
        WHERE g.user_id = ?
        ORDER BY date DESC 
        LIMIT 10";
    $recent_activity = fetchAll($recent_activity_sql, [$user_id, $user_id]);

} catch (Exception $e) {
    error_log("Analytics error: " . $e->getMessage());
    $habits_stats = ['total_habits' => 0, 'daily_habits' => 0, 'weekly_habits' => 0];
    $completion_data = [];
    $goals_stats = ['total_goals' => 0, 'completed_goals' => 0, 'avg_progress' => 0];
    $routines_stats = ['total_routines' => 0, 'fitness_routines' => 0, 'work_routines' => 0, 'study_routines' => 0];
    $top_habits = [];
    $recent_activity = [];
}

// Prepare chart data
$chart_labels = [];
$chart_data = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $chart_labels[] = date('M j', strtotime($date));
    
    $found = false;
    foreach ($completion_data as $data) {
        if ($data['date'] === $date) {
            $chart_data[] = (int)$data['completions'];
            $found = true;
            break;
        }
    }
    if (!$found) {
        $chart_data[] = 0;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics - Trackie.in</title>
    <link rel="icon" type="image/x-icon" href="/assets/images/Logo.png">
    <!-- Tailwind CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Bangers&family=Comic+Neue:wght@400;700&display=swap" rel="stylesheet">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gray-50 font-comic" style="font-family: 'Comic Neue', cursive;">

<?php include '../includes/sidebar.php'; ?>

<main class="min-h-screen p-4 pt-20 md:p-6 md:pt-20 pl-16">
  <div class="max-w-7xl mx-auto">
    
    <!-- Header -->
    <div class="mb-8">
      <h1 class="text-3xl md:text-4xl font-bold text-gray-900 mb-2">Analytics</h1>
      <p class="text-gray-600">Track your progress and gain insights into your habits and goals</p>
    </div>

    <!-- Overview Stats -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
      <!-- Habits Card -->
      <div class="bg-white rounded-xl shadow-lg p-6">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-gray-600">Total Habits</p>
            <p class="text-2xl font-bold text-gray-900"><?= $habits_stats['total_habits'] ?></p>
          </div>
          <div class="bg-red-100 p-3 rounded-lg">
            <i class="fas fa-check text-red-600 text-xl"></i>
          </div>
        </div>
        <div class="mt-4">
          <div class="flex justify-between text-sm">
            <span class="text-gray-600">Daily: <?= $habits_stats['daily_habits'] ?></span>
            <span class="text-gray-600">Weekly: <?= $habits_stats['weekly_habits'] ?></span>
          </div>
        </div>
      </div>

      <!-- Goals Card -->
      <div class="bg-white rounded-xl shadow-lg p-6">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-gray-600">Goals Progress</p>
            <p class="text-2xl font-bold text-gray-900"><?= round($goals_stats['avg_progress'], 1) ?>%</p>
          </div>
          <div class="bg-blue-100 p-3 rounded-lg">
            <i class="fas fa-bullseye text-blue-600 text-xl"></i>
          </div>
        </div>
        <div class="mt-4">
          <div class="flex justify-between text-sm">
            <span class="text-gray-600">Total: <?= $goals_stats['total_goals'] ?></span>
            <span class="text-green-600">Completed: <?= $goals_stats['completed_goals'] ?></span>
          </div>
        </div>
      </div>

      <!-- Routines Card -->
      <div class="bg-white rounded-xl shadow-lg p-6">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-gray-600">Active Routines</p>
            <p class="text-2xl font-bold text-gray-900"><?= $routines_stats['total_routines'] ?></p>
          </div>
          <div class="bg-green-100 p-3 rounded-lg">
            <i class="fas fa-calendar-alt text-green-600 text-xl"></i>
          </div>
        </div>
        <div class="mt-4">
          <div class="flex justify-between text-sm">
            <span class="text-gray-600">Fitness: <?= $routines_stats['fitness_routines'] ?></span>
            <span class="text-gray-600">Work: <?= $routines_stats['work_routines'] ?></span>
          </div>
        </div>
      </div>

      <!-- Streak Card -->
      <div class="bg-white rounded-xl shadow-lg p-6">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-gray-600">Current Streak</p>
            <p class="text-2xl font-bold text-gray-900">7 days</p>
          </div>
          <div class="bg-yellow-100 p-3 rounded-lg">
            <i class="fas fa-fire text-yellow-600 text-xl"></i>
          </div>
        </div>
        <div class="mt-4">
          <div class="text-sm text-gray-600">
            <span>Best: 15 days</span>
          </div>
        </div>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
      
      <!-- Habit Completion Chart -->
      <div class="bg-white rounded-xl shadow-lg p-6">
        <h2 class="text-xl font-bold text-gray-900 mb-4">Habit Completion (Last 7 Days)</h2>
        <div class="h-64">
          <canvas id="habitChart"></canvas>
        </div>
      </div>

      <!-- Top Performing Habits -->
      <div class="bg-white rounded-xl shadow-lg p-6">
        <h2 class="text-xl font-bold text-gray-900 mb-4">Top Performing Habits</h2>
        <?php if (empty($top_habits)): ?>
          <div class="text-center py-8">
            <div class="text-4xl text-gray-300 mb-2">📊</div>
            <p class="text-gray-600">No habit data available yet</p>
          </div>
        <?php else: ?>
          <div class="space-y-4">
            <?php foreach ($top_habits as $index => $habit): ?>
              <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                <div class="flex items-center space-x-3">
                  <div class="w-8 h-8 bg-red-100 rounded-full flex items-center justify-center">
                    <span class="text-red-600 font-bold text-sm"><?= $index + 1 ?></span>
                  </div>
                  <div>
                    <p class="font-medium text-gray-900"><?= htmlspecialchars($habit['name']) ?></p>
                    <p class="text-sm text-gray-600"><?= $habit['completion_count'] ?> completions</p>
                  </div>
                </div>
                <div class="text-right">
                  <div class="text-lg font-bold text-red-600"><?= $habit['completion_count'] ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Routine Categories -->
      <div class="bg-white rounded-xl shadow-lg p-6">
        <h2 class="text-xl font-bold text-gray-900 mb-4">Routine Categories</h2>
        <div class="space-y-4">
          <div class="flex items-center justify-between p-3 bg-red-50 rounded-lg">
            <div class="flex items-center space-x-3">
              <div class="w-10 h-10 bg-red-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-dumbbell text-red-600"></i>
              </div>
              <span class="font-medium text-gray-900">Fitness</span>
            </div>
            <span class="text-lg font-bold text-red-600"><?= $routines_stats['fitness_routines'] ?></span>
          </div>
          
          <div class="flex items-center justify-between p-3 bg-blue-50 rounded-lg">
            <div class="flex items-center space-x-3">
              <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-briefcase text-blue-600"></i>
              </div>
              <span class="font-medium text-gray-900">Work</span>
            </div>
            <span class="text-lg font-bold text-blue-600"><?= $routines_stats['work_routines'] ?></span>
          </div>
          
          <div class="flex items-center justify-between p-3 bg-green-50 rounded-lg">
            <div class="flex items-center space-x-3">
              <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-book text-green-600"></i>
              </div>
              <span class="font-medium text-gray-900">Study</span>
            </div>
            <span class="text-lg font-bold text-green-600"><?= $routines_stats['study_routines'] ?></span>
          </div>
        </div>
      </div>

      <!-- Recent Activity -->
      <div class="bg-white rounded-xl shadow-lg p-6">
        <h2 class="text-xl font-bold text-gray-900 mb-4">Recent Activity</h2>
        <?php if (empty($recent_activity)): ?>
          <div class="text-center py-8">
            <div class="text-4xl text-gray-300 mb-2">📝</div>
            <p class="text-gray-600">No recent activity</p>
          </div>
        <?php else: ?>
          <div class="space-y-3">
            <?php foreach ($recent_activity as $activity): ?>
              <div class="flex items-center space-x-3 p-3 hover:bg-gray-50 rounded-lg transition-colors">
                <div class="w-8 h-8 bg-gray-100 rounded-full flex items-center justify-center">
                  <?php if ($activity['type'] === 'habit'): ?>
                    <i class="fas fa-check text-green-600 text-sm"></i>
                  <?php else: ?>
                    <i class="fas fa-bullseye text-blue-600 text-sm"></i>
                  <?php endif; ?>
                </div>
                <div class="flex-1">
                  <p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($activity['title']) ?></p>
                  <p class="text-xs text-gray-600"><?= $activity['action'] ?> • <?= timeAgo($activity['date']) ?></p>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Insights Section -->
    <div class="mt-8 bg-white rounded-xl shadow-lg p-6">
      <h2 class="text-xl font-bold text-gray-900 mb-4">Insights & Recommendations</h2>
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <div class="bg-gradient-to-br from-red-50 to-red-100 p-4 rounded-lg">
          <div class="flex items-center space-x-3 mb-2">
            <i class="fas fa-lightbulb text-red-600"></i>
            <h3 class="font-semibold text-gray-900">Consistency is Key</h3>
          </div>
          <p class="text-sm text-gray-700">You're doing great with your daily habits! Keep up the momentum.</p>
        </div>
        
        <div class="bg-gradient-to-br from-blue-50 to-blue-100 p-4 rounded-lg">
          <div class="flex items-center space-x-3 mb-2">
            <i class="fas fa-chart-line text-blue-600"></i>
            <h3 class="font-semibold text-gray-900">Progress Tracking</h3>
          </div>
          <p class="text-sm text-gray-700">Your goals are <?= round($goals_stats['avg_progress'], 1) ?>% complete. You're on track!</p>
        </div>
        
        <div class="bg-gradient-to-br from-green-50 to-green-100 p-4 rounded-lg">
          <div class="flex items-center space-x-3 mb-2">
            <i class="fas fa-star text-green-600"></i>
            <h3 class="font-semibold text-gray-900">Achievement Unlocked</h3>
          </div>
          <p class="text-sm text-gray-700">You've completed <?= $goals_stats['completed_goals'] ?> goals. Amazing work!</p>
        </div>
      </div>
    </div>
  </div>
</main>

<script>
  // Habit Completion Chart
  const ctx = document.getElementById('habitChart').getContext('2d');
  new Chart(ctx, {
    type: 'line',
    data: {
      labels: <?= json_encode($chart_labels) ?>,
      datasets: [{
        label: 'Habit Completions',
        data: <?= json_encode($chart_data) ?>,
        borderColor: 'rgb(239, 68, 68)',
        backgroundColor: 'rgba(239, 68, 68, 0.1)',
        borderWidth: 3,
        fill: true,
        tension: 0.4,
        pointBackgroundColor: 'rgb(239, 68, 68)',
        pointBorderColor: '#fff',
        pointBorderWidth: 2,
        pointRadius: 6
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          display: false
        }
      },
      scales: {
        y: {
          beginAtZero: true,
          ticks: {
            stepSize: 1
          }
        }
      }
    }
  });
</script>

</body>
</html> 