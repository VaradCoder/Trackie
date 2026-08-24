<?php
// Legacy entry point — registration now lives on the unified auth page and auto-logs-in.
require_once '../config/app.php';
require_once '../includes/functions.php';
redirect(APP_BASE . '/pages/auth.php?tab=register');
