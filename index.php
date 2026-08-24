<?php
require_once 'config/app.php';
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

// Restore from remember-me token if the session lapsed, then route.
if (tryRememberLogin()) {
    redirect(APP_BASE . '/pages/dashboard.php');
} else {
    redirect(APP_BASE . '/pages/auth.php');
}
