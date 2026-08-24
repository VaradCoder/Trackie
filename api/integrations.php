<?php
/**
 * Integrations API — manual sync and disconnect.
 *
 * Sync is exposed here (user-triggered) rather than running on page render:
 * modules always read from the local cache, and a third-party outage can only
 * ever affect this endpoint, never a page load.
 */
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/providers.php';

requireAuth();
verify_csrf();

$uid    = currentUserId();
$action = sanitizeInput($_POST['action'] ?? '');
$key    = sanitizeInput($_POST['provider'] ?? '');
$p      = provider($key);

if (!$p) json_out(['success' => false, 'error' => 'Unknown or unavailable provider.'], 404);

switch ($action) {

    case 'sync':
        if (!$p->isConnected($uid)) {
            json_out(['success' => false, 'error' => 'Not connected.'], 409);
        }
        // runSync() never throws — a provider failure is reported, not fatal.
        $res = runSync($p, $uid);
        json_out([
            'success' => $res['ok'],
            'records' => $res['records'] ?? 0,
            'error'   => $res['error']   ?? null,
            'status'  => $p->status($uid),
        ]);

    case 'disconnect':
        $p->disconnect($uid);
        json_out(['success' => true]);

    default:
        json_out(['success' => false, 'error' => 'Unknown action.'], 400);
}
