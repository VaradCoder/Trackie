<?php
session_start();

// Check if user is logged in
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit();
} else {
    header('Location: pages/login.php');
    exit();
}
?> 