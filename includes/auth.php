<?php
/**
 * Trackie v1.0 — Auth middleware
 * Include after config/app.php + config/database.php + includes/functions.php.
 */

/** Is the current user an admin? Lazily caches the flag in the session. */
function isAdmin(): bool {
    if (!isLoggedIn()) return false;
    if (!isset($_SESSION['is_admin'])) {
        $row = fetchOne("SELECT is_admin FROM users WHERE id=?", [currentUserId()]);
        $_SESSION['is_admin'] = (int)($row['is_admin'] ?? 0);
    }
    return (bool)$_SESSION['is_admin'];
}

/** Gate a page to admins only. */
function requireAdmin(): void {
    requireAuth();
    if (!isAdmin()) {
        http_response_code(403);
        flash('error', 'Admins only.');
        redirect(APP_BASE . '/pages/dashboard.php');
    }
}

/**
 * Restore a session from a valid remember-me cookie (with token rotation).
 * Returns true if a session was (re)established. Safe to call on any entry
 * point so the login screen never appears when a valid token exists.
 */
function tryRememberLogin(): bool {
    if (isLoggedIn()) return true;

    $cookie = $_COOKIE['remember_token'] ?? '';
    if (!$cookie) return false;

    $hash = hash('sha256', $cookie);
    $row  = fetchOne(
        "SELECT user_id FROM remember_tokens
         WHERE token_hash = ? AND expires_at > NOW()
         LIMIT 1",
        [$hash]
    );
    if (!$row) return false;

    $user = fetchOne("SELECT id, name, email, profile_pic, is_admin FROM users WHERE id = ?", [$row['user_id']]);
    if (!$user) return false;

    $_SESSION['user_id']     = $user['id'];
    $_SESSION['user_name']   = $user['name'];
    $_SESSION['user_email']  = $user['email'];
    $_SESSION['profile_pic'] = $user['profile_pic'];
    $_SESSION['is_admin']    = (int)($user['is_admin'] ?? 0);
    // Rotate token so a stolen cookie can't be reused.
    delete("DELETE FROM remember_tokens WHERE token_hash = ?", [$hash]);
    _issueRememberCookie((int)$user['id']);
    return true;
}

function requireAuth(): void {
    if (tryRememberLogin()) {
        // Settings → time zone: "today", streaks and reminder times follow the
        // user's own midnight (PHP + MySQL session). No-op for the default zone.
        require_once __DIR__ . '/settings.php';
        try { applyUserTimezone(currentUserId()); } catch (Throwable $e) { error_log('timezone: ' . $e->getMessage()); }
        return;
    }

    // fetch()/XHR callers get a JSON 401 instead of a redirect to the login
    // HTML (which fetch follows silently, leaving the UI parsing a web page).
    if (strcasecmp($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '', 'XMLHttpRequest') === 0) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'auth' => false, 'error' => 'Your session has expired. Please sign in again.']);
        exit;
    }

    flash('error', 'Please log in to continue.');
    redirect(APP_BASE . '/pages/auth.php');
}

function _issueRememberCookie(int $userId, int $days = 30): void {
    $token   = generateToken();
    $hash    = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', time() + $days * 86400);
    insert(
        "INSERT INTO remember_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)",
        [$userId, $hash, $expires]
    );
    setcookie('remember_token', $token, [
        'expires'  => time() + $days * 86400,
        'path'     => APP_BASE . '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function loginUser(array $user, bool $remember = false): void {
    session_regenerate_id(true);
    $_SESSION['user_id']     = $user['id'];
    $_SESSION['user_name']   = $user['name'];
    $_SESSION['user_email']  = $user['email'];
    $_SESSION['profile_pic'] = $user['profile_pic'] ?? '';
    $_SESSION['is_admin']    = (int)($user['is_admin'] ?? 0);
    if ($remember) {
        _issueRememberCookie((int)$user['id']);
    }
}

function logoutUser(): void {
    // Clear remember token from DB + cookie
    $cookie = $_COOKIE['remember_token'] ?? '';
    if ($cookie) {
        delete("DELETE FROM remember_tokens WHERE token_hash = ?", [hash('sha256', $cookie)]);
        setcookie('remember_token', '', ['expires' => time() - 3600, 'path' => APP_BASE . '/']);
    }
    session_destroy();
    session_start();
    session_regenerate_id(true);
}
