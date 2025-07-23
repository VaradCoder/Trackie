<?php
// Determine the base path for assets
$current_path = $_SERVER['PHP_SELF'];
$is_in_pages = strpos($current_path, '/pages/') !== false;
$assets_base = $is_in_pages ? '../' : '';
// Fetch user profile picture
$profile_pic = $_SESSION['profile_pic'] ?? '';
$profile_pic_path = $profile_pic && file_exists($profile_pic) ? $profile_pic : $assets_base . 'assets/images/default-user.png';
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Trackie.in - Track Your Life</title>
  <link rel="icon" type="image/x-icon" href="<?= $assets_base ?>assets/images/Logo.png">
  <!-- Tailwind CSS CDN -->
  <script src="https://cdn.tailwindcss.com"></script>
  <!-- Google Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Bangers&family=Comic+Neue:wght@400;700&display=swap" rel="stylesheet" />
  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
  <link rel="stylesheet" href="<?= $assets_base ?>assets/css/style.css" />
  <script defer src="<?= $assets_base ?>assets/js/app.js"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>
  <script src="https://cdn.jsdelivr.net/npm/animejs@3.2.1/lib/anime.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<header class="flex items-center space-x-3 text-[13px] text-gray-500 mb-6 select-none py-4 px-2 sm:px-0">
    <div class="flex items-center space-x-1 font-semibold text-gray-800 text-base" style="margin-left:100px;">
      <span><?= isset($page_emoji) ? $page_emoji : '🏡' ?> <?= isset($page_title) ? $page_title : 'Dashboard' ?></span>
    </div>
    <div class="flex-1"></div>
    <button id="themeToggle" class="p-2 rounded-full border border-gray-300 bg-white hover:bg-gray-100 transition" title="Toggle theme" aria-label="Toggle theme">
        <i id="themeIconSun" class="fa fa-sun text-xl" style="display:none;"></i>
        <i id="themeIconMoon" class="fa fa-moon text-xl" style="display:inline;"></i>
    </button>
    <button aria-label="Search" class="text-gray-400 hover:text-gray-600">
      <i class="fas fa-search"></i>
    </button>
    <nav class="bg-glass shadow-luxury">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8" style="margin-right:100px;">        
          <!-- User Menu -->
          <div class="relative">
              <button id="userMenuButton" class="flex items-center space-x-2 text-silver hover:text-gold transition-colors">
                  <img class="h-8 w-8 rounded-full border-2 border-gold" src="<?= $profile_pic_path ?>" alt="User">
                  <span class="text-sm font-medium"><?= $_SESSION['user_name'] ?? 'User' ?></span>
                  <!-- <i class="fas fa-chevron-down text-xs"></i> -->
              </button> 
            </div>
        </div>
    </nav>
  </header>
<script>
// Theme toggle logic
function setTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem('theme', theme);
    document.getElementById('themeIconSun').style.display = theme === 'dark' ? 'inline' : 'none';
    document.getElementById('themeIconMoon').style.display = theme === 'light' ? 'inline' : 'none';
}
document.addEventListener('DOMContentLoaded', function() {
    var theme = localStorage.getItem('theme') || 'light';
    setTheme(theme);
    document.getElementById('themeToggle').addEventListener('click', function() {
        var current = document.documentElement.getAttribute('data-theme') || 'light';
        setTheme(current === 'light' ? 'dark' : 'light');
    });
});
</script>
