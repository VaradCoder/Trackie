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

$uid    = currentUserId();
$action = sanitizeInput($_POST['action'] ?? '');

// The service worker re-registers a rotated subscription on its own
// (pushsubscriptionchange) and has no CSRF token. Instead it must prove
// ownership: the OLD endpoint has to be registered to this signed-in user.
// Every other action is CSRF-protected as usual.
if ($action !== 'resubscribe') verify_csrf();

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

        if (!$endpoint || !$p256dh || !$auth || !filter_var($endpoint, FILTER_VALIDATE_URL) || !str_starts_with($endpoint, 'https://')) {
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

    case 'resubscribe': {
        $old      = (string)($_POST['old_endpoint'] ?? '');
        $endpoint = (string)($_POST['endpoint'] ?? '');
        $p256dh   = sanitizeInput($_POST['p256dh'] ?? '');
        $auth     = sanitizeInput($_POST['auth'] ?? '');
        if ($old === '' || !fetchOne("SELECT id FROM push_subscriptions WHERE user_id=? AND endpoint=?", [$uid, $old])) {
            json_out(['success' => false, 'error' => 'Unknown subscription.'], 403);
        }
        if (!filter_var($endpoint, FILTER_VALIDATE_URL) || !str_starts_with($endpoint, 'https://') || !$p256dh || !$auth) {
            json_out(['success' => false, 'error' => 'Invalid subscription.'], 422);
        }
        update("UPDATE push_subscriptions SET endpoint=?, p256dh=?, auth=?, last_used_at=NOW() WHERE user_id=? AND endpoint=?",
               [$endpoint, $p256dh, $auth, $uid, $old]);
        json_out(['success' => true]);
    }

    case 'status': {
        // Is push usable on the server, and is THIS device subscribed?
        $endpoint = (string)($_POST['endpoint'] ?? '');
        json_out(['success' => true, 'configured' => (bool)vapidConfig(),
                  'subscribed' => $endpoint !== '' && (bool)fetchOne("SELECT id FROM push_subscriptions WHERE user_id=? AND endpoint=?", [$uid, $endpoint]),
                  'devices' => (int)(fetchOne("SELECT COUNT(*) n FROM push_subscriptions WHERE user_id=?", [$uid])['n'] ?? 0)]);
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
