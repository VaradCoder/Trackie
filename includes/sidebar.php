<?php
// Determine the base path for navigation
$current_path = $_SERVER['PHP_SELF'];
$is_in_pages = strpos($current_path, '/pages/') !== false;

// Define the correct paths based on current location
$dashboard_path = $is_in_pages ? '../dashboard.php' : 'dashboard.php';
$pages_base = $is_in_pages ? '' : 'pages/';
?>

<button id="sidebarToggle" aria-label="Toggle Sidebar" class="fixed top-4 left-4 z-50 btn-primary p-3 rounded-lg focus:outline-none focus:ring-2 focus:ring-gold">
  <i class="fa-solid fa-bars"></i>
</button>
<div id="sidebar" class="fixed top-0 left-0 w-72 h-full sidebar flex flex-col p-6 space-y-6 z-40 transform -translate-x-full transition-transform duration-300 font-comic overflow-y-auto" style="max-height: 100vh; background: var(--color-bg);">
  <div class="flex items-center justify-between mb-4">
    <span class="text-2xl font-bold text-gold">Trackie.in</span>
    <button id="sidebarClose" aria-label="Close Sidebar" class="text-gold hover:text-silver focus:outline-none focus:ring-2 focus:ring-gold">
      <i class="fas fa-times text-2xl"></i>
    </button>
  </div>
  <nav class="flex flex-col space-y-3">
    <a href="<?= $dashboard_path ?>" class="nav-link">
      <i class="fa-solid fa-tachometer-alt mr-2"></i>Dashboard
    </a>
    <a href="<?= $pages_base ?>todo_manager.php" class="nav-link">
      <i class="fa-solid fa-tasks mr-2"></i>Todos
    </a>
    <a href="<?= $pages_base ?>study_plan.php" class="nav-link">
      <i class="fa-solid fa-book-open mr-2"></i>Study Plan
    </a>
    <a href="<?= $pages_base ?>routine_simple.php" class="nav-link">
      <i class="fa-solid fa-calendar-alt mr-2"></i>Routines
    </a>
    <a href="<?= $pages_base ?>habits_simple.php" class="nav-link">
      <i class="fa-solid fa-check mr-2"></i>Habits
    </a>
    <a href="<?= $pages_base ?>goals_simple.php" class="nav-link">
      <i class="fa-solid fa-bullseye mr-2"></i>Goals
    </a>
    <a href="<?= $pages_base ?>analytics_simple.php" class="nav-link">
      <i class="fa-solid fa-chart-bar mr-2"></i>Analytics
    </a>
    <a href="<?= $pages_base ?>calendar.php" class="nav-link">
      <i class="fa-solid fa-calendar-days mr-2"></i>Calendar
    </a>
    <a href="<?= $pages_base ?>profile.php" class="nav-link">
      <i class="fa-solid fa-user mr-2"></i>Profile
    </a>
    <div class="border-t border-silver pt-3 mt-3">
      <a href="<?= $pages_base ?>logout.php" class="nav-link">
        <i class="fa-solid fa-sign-out-alt mr-2"></i>Log out
      </a>
    </div>
  </nav>
</div>

<script>
  // Sidebar toggle functionality
  const sidebarToggle = document.getElementById('sidebarToggle');
  const sidebar = document.getElementById('sidebar');
  const sidebarClose = document.getElementById('sidebarClose');
  
  sidebarToggle.addEventListener('click', function() {
    if (sidebar.classList.contains('-translate-x-full')) {
      sidebar.classList.remove('-translate-x-full');
    } else {
      sidebar.classList.add('-translate-x-full');
    }
  });

  sidebarClose.addEventListener('click', function() {
    sidebar.classList.add('-translate-x-full');
  });
</script>
