<?php
/**
 * Trackie — native app (Capacitor) support.
 *
 *   Device tokens  The Android app's background job (WorkManager) syncs
 *                  reminders without a browser session. On first launch the
 *                  signed-in app registers and receives a random device token
 *                  (shown once; only its SHA-256 is stored). Background calls
 *                  send it as `Authorization: Bearer …`. Revocable per device
 *                  (Settings) and on sign-out from the app.
 *
 *   Firebase push  sendFcmToUser() sends through FCM HTTP v1 using the
 *                  service-account key in keys/fcm-service-account.json
 *                  (web-blocked, git-ignored). Without that file, push is
 *                  simply off — nothing else depends on it.
 */

const DEVICE_TOKEN_BYTES = 32;

function nativeReady(): bool {
    static $ok = null;
    return $ok ??= tableExists('native_devices');
}

/** Create a device for this user; returns the raw token (only time it exists in plain text). */
function nativeRegisterDevice(int $uid, string $platform, ?string $name, ?string $version, ?string $fcm): string {
    $token = bin2hex(random_bytes(DEVICE_TOKEN_BYTES));
    insert("INSERT INTO native_devices (user_id, token_hash, platform, device_name, app_version, fcm_token, last_seen_at) VALUES (?,?,?,?,?,?,NOW())",
           [$uid, hash('sha256', $token), $platform, $name, $version, $fcm]);
    if ($fcm) nativeClaimFcmToken($fcm, $uid, hash('sha256', $token));
    return $token;
}

/** A Firebase token belongs to one device — drop it from any other row (reinstall, account switch). */
function nativeClaimFcmToken(string $fcm, int $uid, string $keepHash): void {
    update("UPDATE native_devices SET fcm_token = NULL WHERE fcm_token = ? AND token_hash <> ?", [$fcm, $keepHash]);
}

/** Device row for an `Authorization: Bearer <token>` header, or null. */
function nativeDeviceFromRequest(): ?array {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) if (strcasecmp($k, 'Authorization') === 0) { $h = $v; break; }
    }
    // Some shared hosts strip Authorization; the app also sends X-Trackie-Device.
    $raw = preg_match('/^Bearer\s+([a-f0-9]{64})$/i', trim($h), $m) ? $m[1] : (string)($_SERVER['HTTP_X_TRACKIE_DEVICE'] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/i', $raw) || !nativeReady()) return null;
    $row = fetchOne("SELECT * FROM native_devices WHERE token_hash = ? AND revoked_at IS NULL", [hash('sha256', strtolower($raw))]);
    return $row ?: null;
}

function nativeRevokeDevice(int $uid, int $deviceId): bool {
    return update("UPDATE native_devices SET revoked_at = NOW(), fcm_token = NULL WHERE id = ? AND user_id = ? AND revoked_at IS NULL", [$deviceId, $uid]) === 1;
}

/* ── Firebase Cloud Messaging (HTTP v1) ─────────────────────────────── */

function fcmServiceAccount(): ?array {
    static $sa = false;
    if ($sa !== false) return $sa;
    $file = ROOT_PATH . '/keys/fcm-service-account.json';
    if (!is_file($file)) return $sa = null;
    $j = json_decode((string)file_get_contents($file), true);
    if (!is_array($j) || empty($j['client_email']) || empty($j['private_key']) || empty($j['project_id'])) return $sa = null;
    return $sa = $j;
}

function fcmEnabled(): bool {
    return fcmServiceAccount() !== null && function_exists('openssl_sign') && function_exists('curl_init');
}

/** OAuth2 access token for FCM, from a signed service-account JWT. Cached until shortly before expiry. */
function fcmAccessToken(): ?string {
    $sa = fcmServiceAccount();
    if (!$sa) return null;
    $cache = ROOT_PATH . '/keys/fcm-token-cache.json';
    $c = is_file($cache) ? json_decode((string)@file_get_contents($cache), true) : null;
    if (is_array($c) && ($c['exp'] ?? 0) > time() + 120 && ($c['sub'] ?? '') === $sa['client_email']) return $c['token'];

    $b64 = static fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $now = time();
    $head = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claims = $b64(json_encode([
        'iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600,
    ]));
    $key = openssl_pkey_get_private($sa['private_key']);
    if (!$key || !openssl_sign("$head.$claims", $sig, $key, OPENSSL_ALGO_SHA256)) { error_log('FCM: could not sign JWT'); return null; }

    $ch = curl_init($sa['token_uri'] ?? 'https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => "$head.$claims." . $b64($sig)])]);
    $res = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    if (empty($res['access_token'])) { error_log('FCM: token request failed: ' . json_encode($res)); return null; }
    @file_put_contents($cache, json_encode(['token' => $res['access_token'], 'exp' => $now + (int)($res['expires_in'] ?? 3600), 'sub' => $sa['client_email']]));
    return $res['access_token'];
}

/**
 * Push to every active app install of a user. $msg: title, body, url, tag,
 * channel ('trackie_general' | 'trackie_reminders'). Returns devices reached.
 * Dead tokens (uninstalled app) are cleared automatically.
 */
function sendFcmToUser(int $uid, array $msg): int {
    if (!nativeReady() || !fcmEnabled()) return 0;
    $devices = fetchAll("SELECT id, fcm_token FROM native_devices WHERE user_id = ? AND revoked_at IS NULL AND fcm_token IS NOT NULL", [$uid]);
    if (!$devices) return 0;
    $token = fcmAccessToken();
    if (!$token) return 0;
    $sa = fcmServiceAccount();
    $sent = 0;
    foreach ($devices as $d) {
        $payload = ['message' => [
            'token' => $d['fcm_token'],
            'notification' => ['title' => (string)$msg['title'], 'body' => (string)($msg['body'] ?? '')],
            'data' => ['url' => (string)($msg['url'] ?? APP_BASE . '/pages/today.php')],
            'android' => ['priority' => 'high', 'notification' => array_filter([
                'channel_id' => $msg['channel'] ?? 'trackie_general',
                'tag'        => $msg['tag'] ?? null,          // same tag replaces instead of stacking
                'icon'       => 'ic_stat_trackie',
                'color'      => '#ef4444',
            ])],
        ]];
        $ch = curl_init('https://fcm.googleapis.com/v1/projects/' . rawurlencode($sa['project_id']) . '/messages:send');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload)]);
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200) { $sent++; continue; }
        // Uninstalled / token rotated → stop sending to it.
        if ($code === 404 || str_contains($body, 'UNREGISTERED') || str_contains($body, 'INVALID_ARGUMENT')) {
            update("UPDATE native_devices SET fcm_token = NULL WHERE id = ?", [$d['id']]);
        } else {
            error_log("FCM send failed ($code): " . substr($body, 0, 300));
        }
    }
    return $sent;
}
