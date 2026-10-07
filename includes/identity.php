<?php
/**
 * External sign-in identities ("Continue with Google") on top of Trackie's
 * own users table — one account system, several ways in.
 *
 *   user_identities  (provider, subject) → user. `subject` is Google's stable
 *                    `sub`, never the email (an address can change owners).
 *   auth_handoffs    one-time codes that carry a sign-in finished in the
 *                    phone's browser into the Android app (Google refuses
 *                    OAuth inside app WebViews). SHA-256 stored, 2-minute life.
 *
 * Linking rules (duplicate accounts / takeover protection):
 *   1. Known Google identity            → sign in as its user.
 *   2. Signed in already (Settings)     → link to the current user.
 *   3. Email matches an existing user   → link automatically ONLY when Google
 *      vouches for that address (email_verified AND a Gmail address or a
 *      Google Workspace domain — `hd`). Otherwise ask the person to sign in
 *      with their password and link from Settings.
 *   4. Otherwise                         → create a new user (no password yet;
 *      "Forgot password" can set one).
 *
 * Accounts created through Google store a non-bcrypt sentinel as `password`,
 * so password sign-in can never match it and Settings knows unlinking Google
 * would lock the person out.
 */

const NO_PASSWORD_SENTINEL = '!google-signin';

function identitiesReady(): bool {
    static $ok = null;
    return $ok ??= tableExists('user_identities') && tableExists('auth_handoffs');
}

function userHasPassword(array $user): bool {
    return ($user['password'] ?? '') !== '' && $user['password'][0] !== '!';
}

function identityUser(string $provider, string $subject): ?array {
    $row = fetchOne(
        "SELECT u.id, u.name, u.email, u.profile_pic, u.is_admin, u.onboarding_completed_at
           FROM user_identities i JOIN users u ON u.id = i.user_id
          WHERE i.provider = ? AND i.subject = ?",
        [$provider, $subject]
    );
    return $row ?: null;
}

function identityForUser(int $uid, string $provider): ?array {
    return fetchOne("SELECT subject, email, created_at, last_login_at FROM user_identities WHERE user_id=? AND provider=?", [$uid, $provider]) ?: null;
}

function identityLink(int $uid, string $provider, string $subject, ?string $email): void {
    insert(
        "INSERT INTO user_identities (user_id, provider, subject, email, last_login_at) VALUES (?,?,?,?,NOW())
         ON DUPLICATE KEY UPDATE email = VALUES(email), last_login_at = NOW()",
        [$uid, $provider, $subject, $email]
    );
}

function identityUnlink(int $uid, string $provider): bool {
    return delete("DELETE FROM user_identities WHERE user_id=? AND provider=?", [$uid, $provider]) > 0;
}

/**
 * Claims from a Google ID token received DIRECTLY from Google's token
 * endpoint over TLS (OpenID Connect §3.1.3.7 allows skipping the signature
 * check in that case). Still validates issuer, audience and expiry.
 */
function googleIdClaims(string $idToken): ?array {
    $parts = explode('.', $idToken);
    if (count($parts) !== 3) return null;
    $c = json_decode((string)base64_decode(strtr($parts[1], '-_', '+/')), true);
    if (!is_array($c) || empty($c['sub'])) return null;
    if (!in_array($c['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)) return null;
    $aud = (array)($c['aud'] ?? []);
    if (!in_array(env('GOOGLE_CLIENT_ID'), $aud, true)) return null;
    if ((int)($c['exp'] ?? 0) < time() - 60) return null;
    return $c;
}

/** True when Google is the authority for this address (safe to auto-link). */
function googleVouchesFor(array $c): bool {
    if (empty($c['email_verified']) || empty($c['email'])) return false;
    return !empty($c['hd']) || (bool)preg_match('/@(gmail|googlemail)\.com$/i', $c['email']);
}

/**
 * Resolve a verified Google sign-in to a Trackie user, applying the linking
 * rules above. Returns ['user' => row, 'created' => bool] or ['error' => msg].
 */
function googleSignIn(array $c, ?int $currentUid): array {
    $sub   = (string)$c['sub'];
    $email = isset($c['email']) ? strtolower(trim((string)$c['email'])) : null;

    $known = identityUser('google', $sub);
    if ($currentUid) {
        if ($known && (int)$known['id'] !== $currentUid) {
            return ['error' => 'That Google account is already linked to a different Trackie account.'];
        }
        $mine = identityForUser($currentUid, 'google');
        if ($mine && $mine['subject'] !== $sub) {
            return ['error' => 'A different Google account is already linked. Unlink it in Settings first.'];
        }
        identityLink($currentUid, 'google', $sub, $email);
        return ['user' => fetchOne("SELECT id, name, email, profile_pic, is_admin FROM users WHERE id=?", [$currentUid]), 'created' => false, 'linked' => true];
    }
    if ($known) {
        identityLink((int)$known['id'], 'google', $sub, $email);   // refresh email + last login
        return ['user' => $known, 'created' => false];
    }

    if (!$email || empty($c['email_verified'])) {
        return ['error' => 'Google did not confirm an email address for this account, so Trackie can\'t sign you in with it.'];
    }
    $existing = fetchOne("SELECT id, name, email, profile_pic, is_admin FROM users WHERE LOWER(email) = ?", [$email]);
    if ($existing) {
        if (!googleVouchesFor($c)) {
            return ['error' => 'A Trackie account already uses ' . $email . '. Sign in with your password, then link Google under Settings → Sign-in methods.'];
        }
        if (identityForUser((int)$existing['id'], 'google')) {
            return ['error' => 'This Trackie account is linked to a different Google account.'];
        }
        identityLink((int)$existing['id'], 'google', $sub, $email);
        return ['user' => $existing, 'created' => false, 'linked' => true];
    }

    $name = trim((string)($c['name'] ?? '')) ?: strstr($email, '@', true);
    $id = insert("INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
                 [mb_substr(sanitizeInput($name), 0, 80), $email, NO_PASSWORD_SENTINEL]);
    if (!$id) return ['error' => 'Could not create your account. Please try again.'];
    identityLink((int)$id, 'google', $sub, $email);
    return ['user' => fetchOne("SELECT id, name, email, profile_pic, is_admin FROM users WHERE id=?", [$id]), 'created' => true];
}

/* ── App handoff (browser → Android app) ─────────────────────────── */

function handoffCreate(int $uid): string {
    delete("DELETE FROM auth_handoffs WHERE expires_at < NOW() - INTERVAL 1 DAY");
    $code = bin2hex(random_bytes(32));
    insert("INSERT INTO auth_handoffs (code_hash, user_id, expires_at) VALUES (?, ?, NOW() + INTERVAL 2 MINUTE)",
           [hash('sha256', $code), $uid]);
    return $code;
}

/** One use, unexpired. Returns the user id or null. */
function handoffConsume(string $code): ?int {
    if (!preg_match('/^[a-f0-9]{64}$/', $code)) return null;
    $hash = hash('sha256', $code);
    // The UPDATE is the lock: only one request can flip used_at.
    $n = update("UPDATE auth_handoffs SET used_at = NOW() WHERE code_hash = ? AND used_at IS NULL AND expires_at > NOW()", [$hash]);
    if ($n !== 1) return null;
    return (int)(fetchOne("SELECT user_id FROM auth_handoffs WHERE code_hash = ?", [$hash])['user_id'] ?? 0) ?: null;
}
