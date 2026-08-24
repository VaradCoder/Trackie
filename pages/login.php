<?php
// Legacy entry point — the unified, tabbed auth page replaced separate login/register.
require_once '../config/app.php';
require_once '../includes/functions.php';
redirect(APP_BASE . '/pages/auth.php?tab=login');
