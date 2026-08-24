<?php
/**
 * Trackie — encryption for secrets at rest (OAuth access/refresh tokens).
 *
 * AES-256-GCM: authenticated encryption, so a tampered ciphertext fails to
 * decrypt rather than silently yielding garbage. Each encryption uses a fresh
 * random 96-bit IV; the stored blob is base64( iv || tag || ciphertext ).
 *
 * The key lives in config/env.php as TRACKIE_ENCRYPTION_KEY (base64 of 32
 * random bytes). That file is .htaccess-blocked and gitignored.
 *
 * DESIGN DECISION — fail closed. If the key is missing or malformed we throw
 * rather than fall back to storing plaintext. A missing key must break the
 * connect flow loudly, never degrade into writing bare tokens into the
 * database where nobody would notice.
 *
 * KEY ROTATION: changing the key invalidates every stored token. Users simply
 * reconnect the affected providers — no data is lost, since synced data lives
 * in its own tables and tokens are re-obtainable.
 */

const TK_CIPHER = 'aes-256-gcm';

/** @throws RuntimeException when the key is absent or the wrong length. */
function tk_encryption_key(): string {
    static $key = null;
    if ($key !== null) return $key;

    $b64 = env('TRACKIE_ENCRYPTION_KEY');
    if ($b64 === '') {
        throw new RuntimeException(
            'TRACKIE_ENCRYPTION_KEY is not set in config/env.php — refusing to ' .
            'store secrets unencrypted. Generate one with: ' .
            'php -r "echo base64_encode(random_bytes(32));"'
        );
    }

    $raw = base64_decode($b64, true);
    if ($raw === false || strlen($raw) !== 32) {
        throw new RuntimeException('TRACKIE_ENCRYPTION_KEY must be base64 of exactly 32 random bytes.');
    }

    return $key = $raw;
}

/** True when secrets can be stored — lets callers degrade gracefully in UI. */
function tk_encryption_available(): bool {
    try { tk_encryption_key(); return extension_loaded('openssl'); }
    catch (Throwable $e) { return false; }
}

/**
 * Encrypts a secret for storage. Returns an opaque base64 string.
 * Empty input returns '' so "no refresh token" round-trips cleanly.
 */
function tk_encrypt(string $plaintext): string {
    if ($plaintext === '') return '';
    $key = tk_encryption_key();
    $iv  = random_bytes(12);                 // 96-bit IV, recommended for GCM
    $tag = '';

    $cipher = openssl_encrypt($plaintext, TK_CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) throw new RuntimeException('Encryption failed.');

    return base64_encode($iv . $tag . $cipher);
}

/**
 * Decrypts a value produced by tk_encrypt().
 * Returns null when the blob is malformed or authentication fails — callers
 * should treat that as "token unusable, ask the user to reconnect".
 */
function tk_decrypt(string $blob): ?string {
    if ($blob === '') return '';
    $raw = base64_decode($blob, true);
    if ($raw === false || strlen($raw) < 29) return null;   // 12 iv + 16 tag + >=1

    $iv     = substr($raw, 0, 12);
    $tag    = substr($raw, 12, 16);
    $cipher = substr($raw, 28);

    try {
        $plain = openssl_decrypt($cipher, TK_CIPHER, tk_encryption_key(), OPENSSL_RAW_DATA, $iv, $tag);
    } catch (Throwable $e) {
        return null;
    }
    return $plain === false ? null : $plain;
}
