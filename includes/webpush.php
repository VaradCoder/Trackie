<?php
/**
 * Trackie — dependency-free Web Push sender.
 *
 * Implements VAPID (RFC 8292) + aes128gcm payload encryption
 * (RFC 8291 / RFC 8188) using only PHP's OpenSSL + hash_hkdf.
 * No Composer, no minishlink/web-push — works on shared hosts
 * (InfinityFree) as long as the OpenSSL EC functions are available.
 *
 * Usage:
 *   $wp = new WebPush(vapidConfig());
 *   $res = $wp->send($subscription, ['title'=>'Hi','body'=>'...','url'=>'/']);
 *   // $res = ['ok'=>bool, 'status'=>int, 'expired'=>bool]
 *
 * $subscription = ['endpoint'=>..., 'p256dh'=>b64url, 'auth'=>b64url]
 */

final class WebPush
{
    private string $publicKey;      // raw 65-byte server (VAPID) public key
    private string $publicKeyB64;   // base64url of the above
    private $privatePem;            // PEM EC private key (VAPID)
    private string $subject;        // mailto: or https: contact

    public function __construct(array $vapid)
    {
        $this->publicKeyB64 = $vapid['publicKey'];
        $this->publicKey    = self::b64uDecode($vapid['publicKey']);
        $this->privatePem   = $vapid['privateKeyPem'];
        $this->subject      = $vapid['subject'] ?? 'mailto:admin@example.com';
    }

    // ── base64url ─────────────────────────────────────────────────
    public static function b64uEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $s): string
    {
        $s = strtr($s, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad) $s .= str_repeat('=', 4 - $pad);
        return base64_decode($s);
    }

    /** Left-pad a raw big-endian integer to a fixed byte length. */
    private static function pad(string $bin, int $len): string
    {
        return str_pad($bin, $len, "\x00", STR_PAD_LEFT);
    }

    /**
     * Create a P-256 EC keypair. On some Windows/XAMPP SAPIs OpenSSL can't
     * locate openssl.cnf, so retry with common config paths before giving up.
     */
    public static function newEcKey()
    {
        $base = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
        $key  = @openssl_pkey_new($base);
        if ($key !== false) return $key;

        foreach (self::opensslConfigCandidates() as $cnf) {
            $key = @openssl_pkey_new($base + ['config' => $cnf]);
            if ($key !== false) return $key;
        }
        throw new RuntimeException('EC keygen failed: ' . openssl_error_string());
    }

    private static function opensslConfigCandidates(): array
    {
        $paths = [
            getenv('OPENSSL_CONF') ?: null,
            'C:/xampp/apache/conf/openssl.cnf',
            'C:/xampp/php/extras/openssl/openssl.cnf',
            '/etc/ssl/openssl.cnf',
        ];
        return array_filter($paths, fn($p) => $p && is_file($p));
    }

    // ── VAPID JWT (ES256) ─────────────────────────────────────────
    private function vapidHeader(string $endpoint): string
    {
        $u   = parse_url($endpoint);
        $aud = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');

        $header  = self::b64uEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $payload = self::b64uEncode(json_encode([
            'aud' => $aud,
            'exp' => time() + 12 * 3600,
            'sub' => $this->subject,
        ]));
        $input = $header . '.' . $payload;

        $der = '';
        if (!openssl_sign($input, $der, $this->privatePem, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('VAPID signing failed: ' . openssl_error_string());
        }
        $sig = self::b64uEncode(self::derToRaw($der));
        return $input . '.' . $sig;
    }

    /** Convert a DER-encoded ECDSA signature to raw R||S (64 bytes). */
    private static function derToRaw(string $der): string
    {
        $off = 0;
        if (ord($der[$off++]) !== 0x30) throw new RuntimeException('bad DER');
        $off++; // sequence length
        $read = function () use ($der, &$off): string {
            if (ord($der[$off++]) !== 0x02) throw new RuntimeException('bad DER int');
            $len = ord($der[$off++]);
            $val = substr($der, $off, $len);
            $off += $len;
            return ltrim($val, "\x00");
        };
        $r = self::pad($read(), 32);
        $s = self::pad($read(), 32);
        return $r . $s;
    }

    // ── Build an OpenSSL public-key resource from a raw P-256 point ─
    private static function rawPublicToPem(string $raw65): string
    {
        // DER SubjectPublicKeyInfo prefix for prime256v1 uncompressed points.
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw65;
        return "-----BEGIN PUBLIC KEY-----\n"
             . chunk_split(base64_encode($der), 64, "\n")
             . "-----END PUBLIC KEY-----\n";
    }

    /** Raw 65-byte public point from an OpenSSL key details array. */
    private static function rawPublicFromKey($key): string
    {
        $d = openssl_pkey_get_details($key);
        return "\x04" . self::pad($d['ec']['x'], 32) . self::pad($d['ec']['y'], 32);
    }

    // ── Encrypt a payload for one subscription (aes128gcm) ─────────
    private function encrypt(string $payload, string $uaPublicRaw, string $authSecret): string
    {
        // Ephemeral (application server) keypair for this message.
        $as = self::newEcKey();
        $asPublicRaw = self::rawPublicFromKey($as);

        // ECDH shared secret between our ephemeral key and the UA public key.
        $uaPem  = self::rawPublicToPem($uaPublicRaw);
        $secret = openssl_pkey_derive($uaPem, $as, 32);
        if ($secret === false) throw new RuntimeException('ECDH derive failed: ' . openssl_error_string());

        $salt = random_bytes(16);

        // RFC 8291: IKM = HKDF(auth_secret, ecdh_secret, "WebPush: info\0"||ua||as, 32)
        $keyInfo = "WebPush: info\x00" . $uaPublicRaw . $asPublicRaw;
        $ikm     = hash_hkdf('sha256', $secret, 32, $keyInfo, $authSecret);

        // RFC 8188: derive CEK + NONCE from the salt.
        $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00",     $salt);

        // Single record: plaintext + 0x02 padding delimiter, then AES-128-GCM.
        $tag = '';
        $cipher = openssl_encrypt(
            $payload . "\x02",
            'aes-128-gcm',
            $cek,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );
        if ($cipher === false) throw new RuntimeException('AES-GCM encrypt failed');

        // aes128gcm content-coding header: salt(16) | rs(uint32=4096) | idlen(1=65) | as_public(65)
        $header = $salt . pack('N', 4096) . chr(65) . $asPublicRaw;
        return $header . $cipher . $tag;
    }

    /**
     * Send one push. Returns ['ok','status','expired','error'].
     * expired=true means the subscription is gone (404/410) → delete it.
     */
    public function send(array $sub, array $data): array
    {
        $payload = json_encode($data);
        $uaPub   = self::b64uDecode($sub['p256dh']);
        $auth    = self::b64uDecode($sub['auth']);

        try {
            $body = $this->encrypt($payload, $uaPub, $auth);
            $jwt  = $this->vapidHeader($sub['endpoint']);
        } catch (Throwable $e) {
            error_log('WebPush encrypt/sign error: ' . $e->getMessage());
            return ['ok' => false, 'status' => 0, 'expired' => false, 'error' => $e->getMessage()];
        }

        $headers = [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: 86400',
            'Authorization: vapid t=' . $jwt . ', k=' . $this->publicKeyB64,
        ];

        $ch = curl_init($sub['endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($status === 0) {
            error_log('WebPush curl error: ' . $err);
            return ['ok' => false, 'status' => 0, 'expired' => false, 'error' => $err];
        }

        $expired = ($status === 404 || $status === 410);
        $ok      = ($status >= 200 && $status < 300);
        if (!$ok && !$expired) {
            error_log("WebPush push service returned $status: " . $resp);
        }
        return ['ok' => $ok, 'status' => $status, 'expired' => $expired, 'error' => $ok ? null : $resp];
    }
}

/**
 * Load VAPID keys from keys/vapid.php (generated once via cron/generate_vapid.php).
 * Returns null if not configured yet — callers should degrade gracefully.
 */
function vapidConfig(): ?array
{
    static $cfg = false;
    if ($cfg !== false) return $cfg;

    $file = ROOT_PATH . '/keys/vapid.php';
    if (!is_file($file)) return $cfg = null;

    $c = require $file;
    if (empty($c['publicKey']) || empty($c['privateKeyPem'])) return $cfg = null;
    return $cfg = $c;
}

/** Convenience: is push configured and usable on this host? */
function pushEnabled(): bool
{
    return vapidConfig() !== null
        && function_exists('openssl_pkey_derive')
        && function_exists('hash_hkdf');
}

/**
 * Send a push notification to every device registered for a user.
 * Prunes subscriptions the push service reports as gone (404/410).
 * Returns the number of successful deliveries.
 *
 * $data = ['title'=>..., 'body'=>..., 'url'=>..., optional 'tag'=>...]
 */
function sendPushToUser(int $userId, array $data): int
{
    if (!pushEnabled() || !function_exists('fetchAll')) return 0;

    $subs = fetchAll(
        "SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id=?",
        [$userId]
    );
    if (!$subs) return 0;

    $wp   = new WebPush(vapidConfig());
    $sent = 0;
    foreach ($subs as $s) {
        $res = $wp->send($s, $data);
        if ($res['ok']) {
            $sent++;
            update("UPDATE push_subscriptions SET last_used_at=NOW() WHERE id=?", [$s['id']]);
        } elseif ($res['expired']) {
            delete("DELETE FROM push_subscriptions WHERE id=?", [$s['id']]);
        }
    }
    return $sent;
}
