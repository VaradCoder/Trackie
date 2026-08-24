<?php
/**
 * Web Push API — subscription management + self-test.
 *
 * Actions (POST, CSRF-protected):
 *   publicKey   → { key }              VAPID public key for the browser
 *   subscribe   → store a PushSubscription for this user
 *   unsubscribe → remove a subscription by endpoint
 *   test        → send a push to all of this user's devices right now
 */
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/webpush.php';

requireAuth();
verify_csrf();

$uid    = currentUserId();
$action = sanitizeInput($_POST['action'] ?? '');

// Degrade gracefully before migration / on hosts without the crypto stack.
if (!tableExists('push_subscriptions')) {
    json_out(['success' => false, 'error' => 'Push is not set up yet. Run database setup.'], 503);
}

switch ($action) {

    case 'publicKey': {
        $cfg = vapidConfig();
        if (!$cfg) json_out(['success' => false, 'error' => 'Push keys not configured on the server.'], 503);
        json_out(['success' => true, 'key' => $cfg['publicKey']]);
    }

    case 'subscribe': {
        $endpoint = sanitizeInput($_POST['endpoint'] ?? '');
        $p256dh   = sanitizeInput($_POST['p256dh']   ?? '');
        $auth     = sanitizeInput($_POST['auth']     ?? '');
        $ua       = substr(sanitizeInput($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

        if (!$endpoint || !$p256dh || !$auth || !filter_var($endpoint, FILTER_VALIDATE_URL)) {
            json_out(['success' => false, 'error' => 'Invalid subscription.'], 422);
        }

        // Upsert on endpoint: same device re-subscribing updates its keys/owner.
        insert(
            "INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, ua, last_used_at)
             VALUES (?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), p256dh=VALUES(p256dh),
                                     auth=VALUES(auth), ua=VALUES(ua), last_used_at=NOW()",
            [$uid, $endpoint, $p256dh, $auth, $ua]
        );
        json_out(['success' => true]);
    }

    case 'unsubscribe': {
        $endpoint = sanitizeInput($_POST['endpoint'] ?? '');
        if ($endpoint) {
            delete("DELETE FROM push_subscriptions WHERE user_id=? AND endpoint=?", [$uid, $endpoint]);
        }
        json_out(['success' => true]);
    }

    case 'test': {
        if (!pushEnabled()) {
            json_out(['success' => false, 'error' => 'Push is not available on this server.'], 503);
        }
        $sent = sendPushToUser($uid, [
            'title' => 'Trackie',
            'body'  => 'Push notifications are working. 🎉',
            'url'   => APP_BASE . '/pages/dashboard.php',
        ]);
        json_out(['success' => true, 'sent' => $sent]);
    }

    default:
        json_out(['success' => false, 'error' => 'Unknown action.'], 400);
}
