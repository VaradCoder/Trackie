<?php
/**
 * Native app devices (Android/iOS Capacitor shell).
 *
 * Session actions (signed-in page, CSRF-protected):
 *   register   → create this install's device; returns its token ONCE
 *   list       → the user's app installs (Settings)
 *   revoke     → sign a device out of background sync + push
 *
 * Device actions (Authorization: Bearer <device token> — no session/cookie,
 * so CSRF does not apply; the token is the credential):
 *   sync       → reminder occurrences to schedule as alarms (background job)
 *   set_fcm    → store/rotate this install's Firebase token
 *   unregister → this install signs itself out (app sign-out)
 */
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/native.php';
require_once '../includes/notify.php';

$action = (string)($_POST['action'] ?? '');
header('Cache-Control: no-store');

if (!nativeReady()) json_out(['success' => false, 'error' => 'App support is not set up on the server yet (run the database migration).'], 503);

$clean = static fn($v, int $max) => ($v = trim((string)$v)) === '' ? null : mb_substr($v, 0, $max);
$fcmIn = static function () {
    $t = trim((string)($_POST['fcm_token'] ?? ''));
    return preg_match('/^[A-Za-z0-9_:\-\.]{20,255}$/', $t) ? $t : null;
};

/* ── Device-token actions ─────────────────────────────────────────── */
if (in_array($action, ['sync', 'set_fcm', 'unregister'], true)) {
    $dev = nativeDeviceFromRequest();
    if (!$dev) json_out(['success' => false, 'auth' => false, 'error' => 'This device is signed out. Open Trackie to sign in again.'], 401);
    $uid = (int)$dev['user_id'];
    update("UPDATE native_devices SET last_seen_at = NOW(), app_version = COALESCE(?, app_version) WHERE id = ?",
           [$clean($_POST['app_version'] ?? '', 32), $dev['id']]);

    switch ($action) {
        case 'sync':
            // Judge times in the user's own zone, as the app would.
            applyUserTimezone($uid, false);
            $hours = max(1, min(168, (int)($_POST['hours'] ?? 48)));
            json_out(['success' => true, 'occurrences' => upcomingReminderOccurrences($uid, $hours, 60),
                      'unread' => unreadNotificationCount($uid), 'server_time' => date('c')]);

        case 'set_fcm':
            $fcm = $fcmIn();
            update("UPDATE native_devices SET fcm_token = ? WHERE id = ?", [$fcm, $dev['id']]);
            if ($fcm) nativeClaimFcmToken($fcm, $uid, $dev['token_hash']);
            json_out(['success' => true, 'push' => $fcm !== null && fcmEnabled()]);

        case 'unregister':
            nativeRevokeDevice($uid, (int)$dev['id']);
            json_out(['success' => true]);
    }
}

/* ── Session actions ──────────────────────────────────────────────── */
requireAuth();
verify_csrf();
$uid = currentUserId();

switch ($action) {
    case 'register': {
        $platform = in_array($_POST['platform'] ?? '', ['android', 'ios'], true) ? $_POST['platform'] : 'android';
        // Re-registering (new sign-in on the same phone): retire the old row first.
        $prev = nativeDeviceFromRequest();
        if ($prev && (int)$prev['user_id'] === $uid) nativeRevokeDevice($uid, (int)$prev['id']);
        $token = nativeRegisterDevice($uid, $platform, $clean($_POST['device_name'] ?? '', 80), $clean($_POST['app_version'] ?? '', 32), $fcmIn());
        $id = (int)(fetchOne("SELECT id FROM native_devices WHERE token_hash = ?", [hash('sha256', $token)])['id'] ?? 0);
        json_out(['success' => true, 'device_id' => $id, 'device_token' => $token, 'push' => fcmEnabled()]);
    }

    case 'list':
        json_out(['success' => true, 'push' => fcmEnabled(), 'devices' => array_map(static fn($d) => [
            'id' => (int)$d['id'], 'platform' => $d['platform'], 'name' => $d['device_name'], 'version' => $d['app_version'],
            'push' => $d['fcm_token'] !== null, 'last_seen' => $d['last_seen_at'], 'created' => $d['created_at'],
        ], fetchAll("SELECT * FROM native_devices WHERE user_id = ? AND revoked_at IS NULL ORDER BY last_seen_at DESC", [$uid]))]);

    case 'revoke':
        $ok = nativeRevokeDevice($uid, (int)($_POST['device_id'] ?? 0));
        $ok ? json_out(['success' => true]) : json_out(['success' => false, 'error' => 'Device not found.'], 404);

    default:
        json_out(['success' => false, 'error' => 'Unknown action.'], 400);
}
