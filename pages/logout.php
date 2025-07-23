<?php
session_start();

// Include configuration and functions
require_once '../config/database.php';
require_once '../includes/functions.php';

// Log the logout activity if user was logged in
if (isLoggedIn()) {
    logActivity('logout', [
        'user_id' => getCurrentUserId(),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
}

// Clear all session data
$_SESSION = array();

// Destroy the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Clear remember me cookie if it exists
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/', '', true, true);
}

// Set flash message for login page
session_start(); // Start new session for flash message
setFlashMessage('success', 'You have been successfully logged out.');

// Redirect to login page
redirect('login.php');
?> 