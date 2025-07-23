<?php
// Determine the base path for navigation
$current_path = $_SERVER['PHP_SELF'];
$is_in_pages = strpos($current_path, '/pages/') !== false;

// Define the correct paths based on current location
$dashboard_path = $is_in_pages ? '../dashboard.php' : 'dashboard.php';
$pages_base = $is_in_pages ? '' : 'pages/';
?>

<script>
// User menu dropdown functionality
document.getElementById('userMenuButton').addEventListener('click', function() {
    const dropdown = document.getElementById('userMenuDropdown');
    dropdown.classList.toggle('hidden');
});

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    const dropdown = document.getElementById('userMenuDropdown');
    const button = document.getElementById('userMenuButton');
    
    if (!button.contains(e.target) && !dropdown.contains(e.target)) {
        dropdown.classList.add('hidden');
    }
});
</script> 