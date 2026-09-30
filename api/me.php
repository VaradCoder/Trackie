<?php
/**
 * Live "chrome" state for the app shell: level/XP, Trackie streak, unread
 * notifications. Read-only; the client calls it after mutations and SPA
 * navigations so the sidebar and bell never need a full page reload.
 */
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/activity.php';

requireAuth();
$uid = currentUserId();
header('Cache-Control: no-store');

$xp = function_exists('xpSummary') ? xpSummary($uid) : null;
try {
    $streak = activityReady() ? (int)activityStreak($uid)['current'] : (int)(habitStreaks($uid)['current'] ?? 0);
} catch (Throwable $e) {
    $streak = null;
}

json_out([
    'success' => true,
    'data' => [
        'level'    => $xp ? (int)$xp['level'] : null,
        'total_xp' => $xp ? (int)$xp['total'] : null,
        'streak'   => $streak,
        'unread'   => unreadNotificationCount($uid),
    ],
]);
