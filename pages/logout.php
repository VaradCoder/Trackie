<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

logoutUser();
flash('success', 'You have been logged out.');
redirect(APP_BASE . '/pages/auth.php');
