<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: pages/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'User';
$today = date('j M Y, h:i a'); // Correct time format
$month = date('F, Y');
$day_of_week = date('l');

// Fetch today's todos
$todays_todos = fetchAll(
    "SELECT * FROM todos WHERE user_id = ? AND DATE(due_date) = CURDATE() AND deleted_at IS NULL ORDER BY completed ASC, priority DESC, due_date ASC, created_at DESC",
    [$user_id]
);

// Analytics: count of completed, pending, overdue todos
$stats = fetchOne(
    "SELECT COUNT(*) as total, SUM(CASE WHEN completed = 1 THEN 1 ELSE 0 END) as completed, SUM(CASE WHEN completed = 0 THEN 1 ELSE 0 END) as pending, SUM(CASE WHEN completed = 0 AND due_date < CURDATE() THEN 1 ELSE 0 END) as overdue FROM todos WHERE user_id = ? AND deleted_at IS NULL",
    [$user_id]
);
$progress = ($stats['total'] > 0) ? round(($stats['completed'] / $stats['total']) * 100) : 0;

// Favorite habits: top 7 by completion count
$fav_habits = fetchAll(
    "SELECT h.name, COUNT(l.id) as count FROM habits h LEFT JOIN logs l ON h.id = l.habit_id WHERE h.user_id = ? GROUP BY h.id ORDER BY count DESC, h.name ASC LIMIT 7",
    [$user_id]
);

// Fetch top 3 goals for the user (by most recently created)
$dashboard_goals = fetchAll(
    "SELECT * FROM goals WHERE user_id = ? ORDER BY created_at DESC LIMIT 3",
    [$user_id]
);

// Fetch user profile picture
$profile_pic = $_SESSION['profile_pic'] ?? '';
$profile_pic_path = $profile_pic && file_exists($profile_pic) ? $profile_pic : 'assets/images/default-user.png';
?>
<!DOCTYPE html>
<html lang="en">
 <head>
  <meta charset="utf-8"/>
  <meta content="width=device-width, initial-scale=1" name="viewport"/>
  <title>Dashboard Schedule</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet"/>
  <style>
   body { font-family: 'Inter', sans-serif; }
   .scrollbar-hide::-webkit-scrollbar { display: none; }
   .scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }
  </style>
 </head>
 <body class="bg-white text-gray-900 antialiased">
 <?php include 'includes/header.php'; ?>
 <?php include 'includes/navbar.php'; ?>
  <?php include 'includes/sidebar.php'; ?>
  <div class="max-w-[1280px] mx-auto p-4 sm:p-6 md:p-8">
   <div class="grid grid-cols-1 md:grid-cols-12 gap-6">
    <!-- Left column -->
    <div class="md:col-span-3 flex flex-col space-y-6">
     <div>
      <h1 class="font-extrabold text-3xl leading-[1.1] mb-1">
       Happy<br/>
       <?= htmlspecialchars($day_of_week) ?>
       <span class="inline-block animate-wave">👋</span>
      </h1>
      <p class="text-sm text-gray-600 font-semibold" id="localTime"></p>
      <script>
function updateLocalTime() {
    const now = new Date();
    // Format: 22 Jul 2025, 03:45 pm
    const options = { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true };
    document.getElementById('localTime').textContent = now.toLocaleString('en-US', options);
}
setInterval(updateLocalTime, 1000);
updateLocalTime();
</script>
     </div>
     <button class="w-full bg-red-500 text-white font-semibold rounded-full py-2.5 px-6 flex items-center justify-center gap-2 hover:bg-red-600 transition" type="button">
      <a href="<?= $pages_base ?>habits_simple.php" style="text-decoration: none;"><i class="fas fa-plus"></i>New Habits</a>
     </button>
     <button class="w-full border border-gray-300 rounded-full py-2.5 px-6 font-semibold text-sm hover:bg-gray-50 transition" type="button">
      Browse Popular Habits
     </button>
     <div>
      <h2 class="font-semibold text-lg mb-3">
       <?= htmlspecialchars($month) ?>
      </h2>
      <div class="grid grid-cols-7 gap-2 text-center text-xs text-gray-600 font-semibold select-none">
       <?php
       $days_in_month = date('t');
       $today_num = date('j');
       for ($d = 1; $d <= $days_in_month; $d++) {
         $is_today = ($d == $today_num);
         echo '<button class="rounded-full border '.($is_today ? 'border-red-500 text-red-500 bg-red-100' : 'border-gray-300 text-gray-600 hover:bg-gray-100').' py-2 text-[11px] font-semibold '.($is_today ? '' : '').'"'.($is_today ? ' aria-current="date"' : '').'>';
         echo $d;
         echo '</button>';
       }
       ?>
      </div>
     </div>
    </div>
    <!-- Middle column -->
    <div class="md:col-span-5 flex flex-col space-y-6">
     <!-- Weather card (static for now) -->
     <div class="bg-red-100 rounded-xl p-5 relative overflow-hidden">
      <div class="flex justify-between items-center mb-3">
       <h3 class="font-semibold text-base">Weather</h3>
       <button class="text-xs text-gray-500 hover:underline" type="button">View Details</button>
      </div>
      <div class="flex items-center gap-4">
       <img alt="Weather icon showing sun and rain" class="rounded-md" height="60" loading="lazy" src="https://storage.googleapis.com/a1aa/image/93bfa6b4-13b7-4542-9e4f-da57694e6a69.jpg" width="60"/>
       <div class="text-4xl font-extrabold leading-none select-none">12<span class="text-2xl">°C</span></div>
      </div>
      <div class="flex justify-between mt-4 text-xs font-semibold text-gray-700 select-none">
       <div><div class="font-bold">Wind</div><div class="text-sm font-normal">2-4 km/h</div></div>
       <div><div class="font-bold">Pressure</div><div class="text-sm font-normal">102m</div></div>
       <div><div class="font-bold">Humidity</div><div class="text-sm font-normal">42%</div></div>
      </div>

     </div>
     <!-- Goals cards -->
     <div>
       <div class="flex justify-between items-center mb-3">
         <h3 class="font-semibold text-base">Goals</h3>
         <a href="<?= $pages_base ?>goals_simple.php"><button class="text-xs text-gray-500 hover:underline" type="button">View Details</button></a>
       </div>
       <div class="space-y-3">
         <?php if (empty($dashboard_goals)): ?>
           <div class="text-gray-400 text-center py-8">No goals yet! <span class="block text-accent mt-2">Set your first goal! 🎯</span></div>
         <?php else: ?>
           <?php foreach ($dashboard_goals as $goal): ?>
             <?php 
               $target = $goal['target_value'] ?? 100;
               $progress = $goal['progress'] ?? 0;
               $percent = $target > 0 ? min(100, round(($progress / $target) * 100)) : 0;
               $is_completed = $progress >= $target;
             ?>
             <div class="bg-white rounded-xl shadow p-4 flex flex-col gap-2 animate__animated animate__fadeInUp <?= $is_completed ? 'border border-green-400' : '' ?>">
               <div class="flex items-center gap-2 mb-1">
                 <span class="text-lg">🎯</span>
                 <span class="font-bold text-gray-900 text-base flex-1"><?= htmlspecialchars($goal['goal_name']) ?></span>
                 <?php if ($is_completed): ?><span class="text-xs bg-green-100 text-green-600 px-2 py-1 rounded-full"><i class="fas fa-trophy mr-1"></i>Completed</span><?php endif; ?>
               </div>
               <?php if ($goal['description']): ?>
                 <div class="text-xs text-gray-500 mb-1"><?= htmlspecialchars($goal['description']) ?></div>
               <?php endif; ?>
               <div class="flex items-center gap-3 text-xs text-gray-600">
                 <span>Progress: <?= $progress ?> / <?= $target ?></span>
                 <span class="font-medium text-blue-600"><?= $percent ?>%</span>
                 <?php if (!empty($goal['deadline'])): ?>
                   <span><i class="fas fa-calendar mr-1"></i><?= date('M j, Y', strtotime($goal['deadline'])) ?></span>
                 <?php endif; ?>
               </div>
               <div class="w-full bg-gray-200 rounded-full h-2">
                 <div class="bg-red-500 h-2 rounded-full transition-all duration-300" style="width: <?= $percent ?>%"></div>
               </div>
             </div>
           <?php endforeach; ?>
         <?php endif; ?>
       </div>
     </div>
     <!-- Running Competition card (static for now) -->
     <div>
      <h3 class="font-semibold text-base mb-3">Running Competition</h3>
      <div class="bg-white rounded-xl shadow-[0_0_0_1px_rgba(0,0,0,0.1)] p-4 select-none relative">
       <div class="flex items-center text-xs text-gray-500 font-semibold mb-3 space-x-4">
        <div class="flex items-center gap-1"><i class="far fa-calendar-alt"></i>31 Dec</div>
        <div class="flex items-center gap-1"><i class="fas fa-map-marker-alt"></i>20miles</div>
        <div class="flex items-center gap-1"><i class="far fa-clock"></i>09:00</div>
       </div>
       <img alt="Map illustration with a yellow pin labeled Starting Point" class="rounded-lg w-full h-auto object-cover" height="180" loading="lazy" src="https://storage.googleapis.com/a1aa/image/b5537cd8-e8c5-44ba-9f23-5b0b98bf0963.jpg" width="400"/>
       <button aria-label="Refresh running competition" class="absolute bottom-4 right-4 bg-red-500 text-white rounded-full p-3 hover:bg-red-600 transition" type="button"><i class="fas fa-sync-alt"></i></button>
      </div>
     </div>
    </div>
    <!-- Right column -->
    <div class="md:col-span-4 flex flex-col space-y-6">
     <!-- Today's Todos -->
     <div>
      <div class="flex justify-between items-center mb-3">
       <h3 class="font-semibold text-base">Today's Todos</h3>
       <a href="<?= $pages_base ?>todo_manager.php"><button class="text-xs text-gray-500 hover:underline" type="button">View Details</button></a>
      </div>
      <div class="bg-white rounded-xl shadow-[0_0_0_1px_rgba(0,0,0,0.1)] p-4 space-y-3 select-none">
       <?php if (empty($todays_todos)): ?>
        <div class="text-gray-400 text-center py-8">No todos for today! <span class="block text-accent mt-2">Stay motivated! 🌱</span></div>
       <?php else: ?>
        <?php foreach ($todays_todos as $todo): ?>
         <div class="flex items-center justify-between gap-3<?= $todo['completed'] ? ' opacity-50 line-through' : '' ?>">
          <div class="flex items-center gap-3">
           <span class="text-2xl">
            <?php
            // Emoji or icon based on todo title or category (simple example)
            $emoji = '📝';
            if (stripos($todo['title'], 'study') !== false) $emoji = '😴';
            elseif (stripos($todo['title'], 'grocer') !== false) $emoji = '<i class=\'fas fa-shopping-cart\'></i>';
            elseif (stripos($todo['title'], 'read') !== false) $emoji = '📕';
            elseif (stripos($todo['title'], 'swim') !== false) $emoji = '🍣';
            elseif (stripos($todo['title'], 'healthy') !== false) $emoji = '🥦';
            echo $emoji;
            ?>
           </span>
           <div>
            <div class="font-semibold text-sm">
             <?= htmlspecialchars($todo['title']) ?>
            </div>
            <div class="flex items-center gap-2 text-xs text-gray-500 select-text">
             <i class="far fa-clock"></i>
             <?= $todo['due_date'] ? date('h:i a', strtotime($todo['due_date'])) : '' ?>
             <?php if (!empty($todo['location'])): ?>
              <i class="fas fa-map-marker-alt"></i>
              <?= htmlspecialchars($todo['location']) ?>
             <?php endif; ?>
            </div>
           </div>
          </div>
          <input aria-label="Mark <?= htmlspecialchars($todo['title']) ?> as done" class="w-5 h-5 rounded border-gray-300 todo-checkbox" type="checkbox" data-todo-id="<?= $todo['id'] ?>"<?= $todo['completed'] ? ' checked disabled' : '' ?> />
         </div>
        <?php endforeach; ?>
       <?php endif; ?>
      </div>
     </div>
     <!-- Spotify and More Integrations (dynamic) -->
     <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
      <div class="bg-white rounded-xl p-6 text-center select-none ">
        <div id="spotifyCard">
          <img alt="Green Spotify logo circle" class="mx-auto mb-3" height="40" loading="lazy" src="https://storage.googleapis.com/a1aa/image/8858dbfc-990d-40ca-ee14-6e1dbee0b345.jpg" width="40"/>
          <div id="spotifyContent">
            <button id="spotifyConnectBtn" class="bg-black text-white rounded-full py-2 px-6 text-sm font-semibold hover:bg-gray-800 transition"><i class="fas fa-link mr-2"></i>Coming Soon!</button>
          </div>
        </div>
      </div>
      <div class="bg-gradient-to-r from-[#f75a5a] to-[#f77f7f] rounded-xl p-6 text-center text-white select-none flex flex-col justify-center">
        <p class="font-semibold text-lg mb-1">More Integrations</p>
        <p class="text-xs b">Coming Soon!</p>
      </div>
     </div>
     <!-- Analytics -->
     <div>
      <div class="flex justify-between items-center mb-3">
       <h3 class="font-semibold text-base">Analytics</h3>
       <button class="text-xs text-gray-500 hover:underline" type="button">View Details</button>
      </div>
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
       <div class="bg-[#7a8a5a] rounded-xl p-5 text-white select-none flex flex-col items-center justify-center gap-1">
        <div class="text-2xl">😎</div>
        <div class="text-xs font-semibold">Positive Habits</div>
        <div class="text-2xl font-extrabold leading-none">+<?= $progress ?>%</div>
       </div>
       <div class="bg-gradient-to-b from-[#1a1a1a] to-[#2a2a2a] rounded-xl p-5 text-white select-none flex flex-col items-center justify-center gap-3">
        <div class="text-3xl">🎁</div>
        <div class="text-xs font-semibold">Habits Wrapped</div>
        <div class="text-4xl font-extrabold leading-none"><?= date('Y') ?></div>
        <button class="bg-white text-black rounded-full py-1.5 px-8 text-sm font-semibold hover:bg-gray-200 transition" type="button">View</button>
       </div>
      </div>
     </div>
     <!-- Favorite Habits -->
     <div>
      <div class="flex justify-between items-center mb-3">
       <h3 class="font-semibold text-base">Favorite Habits</h3>
       <div class="flex items-center gap-3">
        <div class="relative">
          <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search todos..." class="form-input w-full pl-10 pr-4">
          <span class="absolute left-3 top-1/2 -translate-y-1/2 transform text-gray-400 flex items-center justify-center pointer-events-none"><i class="fa fa-search"></i></span>
        </div>
        <select aria-label="Select month" class="border border-gray-300 rounded-full py-1.5 px-3 text-xs focus:outline-none focus:ring-1 focus:ring-red-500">
         <option><?= htmlspecialchars($month) ?></option>
        </select>
       </div>
      </div>
      <div class="bg-white rounded-xl shadow-[0_0_0_1px_rgba(0,0,0,0.1)] p-4 overflow-x-auto scrollbar-hide">
       <div class="min-w-[600px] flex items-end gap-6 text-center text-xs text-gray-600 select-none">
        <?php foreach ($fav_habits as $habit): ?>
         <div class="flex flex-col gap-1 items-center w-12">
          <div><?= htmlspecialchars($habit['name']) ?></div>
          <div class="h-<?= 12 + $habit['count'] * 2 ?> w-6 bg-gray-100 rounded-full"></div>
         </div>
        <?php endforeach; ?>
       </div>
       <div class="flex justify-between text-[10px] text-gray-400 mt-2 select-none">
        <?php for ($i = 0; $i < 5; $i++): ?>
         <div><?= date('D d', strtotime("-$i days")) ?></div>
        <?php endfor; ?>
       </div>
      </div>
     </div>
    </div>
   </div>
  </div>
  <!-- Add Habit Modal -->
<div id="addHabitModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
  <div class="flex items-center justify-center min-h-screen p-4">
    <div class="bg-white rounded-xl p-8 max-w-md w-full relative">
      <button onclick="closeAddHabitModal()" class="absolute top-3 right-3 text-gray-400 hover:text-red-500"><i class="fas fa-times text-xl"></i></button>
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
          <button type="button" onclick="closeAddHabitModal()" class="border border-gray-300 rounded-full py-2 px-6 font-semibold text-sm hover:bg-gray-50">Cancel</button>
        </div>
      </form>
      <div id="addHabitMsg" class="mt-4 text-center text-sm"></div>
    </div>
  </div>
</div>
<script>
function openAddHabitModal() {
  document.getElementById('addHabitModal').classList.remove('hidden');
}
function closeAddHabitModal() {
  document.getElementById('addHabitModal').classList.add('hidden');
  document.getElementById('addHabitForm').reset();
  document.getElementById('addHabitMsg').textContent = '';
}
document.querySelectorAll('a[href$="habits_simple.php"]').forEach(btn => {
  btn.addEventListener('click', function(e) {
    e.preventDefault();
    openAddHabitModal();
  });
});
document.getElementById('addHabitForm').addEventListener('submit', function(e) {
  e.preventDefault();
  var form = this;
  var msg = document.getElementById('addHabitMsg');
  msg.textContent = '';
  var formData = new FormData(form);
  fetch('pages/habits_simple.php', {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body: formData
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      msg.textContent = 'Habit added successfully!';
      msg.className = 'mt-4 text-center text-green-600';
      setTimeout(() => { closeAddHabitModal(); location.reload(); }, 1000);
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
document.getElementById('addHabitModal').addEventListener('click', function(e) {
  if (e.target === this) closeAddHabitModal();
});
</script>
  <script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.todo-checkbox').forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            var todoId = this.getAttribute('data-todo-id');
            var completed = this.checked ? 1 : 0;
            var checkbox = this;
            checkbox.disabled = true;
            fetch('pages/todo_manager.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                body: 'action=toggle_todo&todo_id=' + encodeURIComponent(todoId) + '&completed=' + completed
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    var parent = checkbox.closest('.flex.items-center.justify-between');
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
});
</script>

 </body>
 
</html>