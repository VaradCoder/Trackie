<?php
/**
 * Auth API — JSON login & register for the unified AJAX auth page.
 * Registration auto-logs-in (no "log in again" step). CSRF-protected.
 */
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

verify_csrf();

$action  = sanitizeInput($_POST['action'] ?? '');
$dashUrl = APP_BASE . '/pages/dashboard.php';

// Already authenticated? Just send them in.
if (isLoggedIn()) json_out(['success' => true, 'redirect' => $dashUrl]);

switch ($action) {

    case 'login':
        $email    = sanitizeInput($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        // The phone app is a personal device that can't show a login prompt in
        // the background — it always stays signed in (until Sign out).
        $remember = !empty($_POST['remember']) || str_contains($_SERVER['HTTP_USER_AGENT'] ?? '', 'TrackieApp/');

        if (!$email || !$password)      json_out(['success' => false, 'error' => 'Please fill in all fields.'], 422);
        if (!validateEmail($email))     json_out(['success' => false, 'error' => 'Enter a valid email address.'], 422);

        // Two independent throttles. IP alone lets a botnet spread attempts
        // across addresses to brute-force ONE account; per-email alone lets a
        // single IP spray many accounts. Both are needed.
        if (!rateLimit('login', $_SERVER['REMOTE_ADDR'] ?? 'cli', 10, 900)) {
            json_out(['success' => false, 'error' => 'Too many attempts. Please wait 15 minutes.'], 429);
        }
        if (!rateLimit('login_acct', strtolower($email), 8, 900)) {
            json_out(['success' => false, 'error' => 'Too many attempts for this account. Please wait 15 minutes.'], 429);
        }

        $user = fetchOne("SELECT * FROM users WHERE email = ?", [$email]);

        // Always run a hash verification, even when the email is unknown.
        // Otherwise "no such user" returns in ~1ms while a real user costs the
        // full bcrypt work factor, and that timing difference alone tells an
        // attacker which addresses are registered.
        $hash = $user['password'] ?? '$2y$10$usesomesillystringfNoBcRyPtHashHereToBurnTimeAAAAAAAAAAAAA';
        $ok   = verifyPassword($password, $hash);

        if (!$user || !$ok) {
            json_out(['success' => false, 'error' => 'Incorrect email or password.'], 401);
        }
        loginUser($user, $remember);
        json_out(['success' => true, 'redirect' => $dashUrl]);

    case 'register':
        $name     = sanitizeInput($_POST['name']  ?? '');
        $email    = sanitizeInput($_POST['email'] ?? '');
        $phone    = sanitizeInput($_POST['phone'] ?? '');
        $password = $_POST['password']         ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if (!$name || !$email || !$password)  json_out(['success' => false, 'error' => 'Please fill in all required fields.'], 422);
        if (!validateEmail($email))           json_out(['success' => false, 'error' => 'Enter a valid email address.'], 422);
        if (mb_strlen($name) > 80)            json_out(['success' => false, 'error' => 'That name is too long.'], 422);

        // Registration was completely unthrottled — one script could create
        // unlimited accounts, and the "email already exists" reply below turns
        // that into an email-harvesting oracle. Throttle before either.
        if (!rateLimit('register', $_SERVER['REMOTE_ADDR'] ?? 'cli', 5, 3600)) {
            json_out(['success' => false, 'error' => 'Too many sign-ups from this connection. Please try again later.'], 429);
        }

        // Shared rule set with the client-side meter — see passwordStrength().
        $pwCheck = passwordStrength($password);
        if (!$pwCheck['ok']) {
            json_out([
                'success'  => false,
                'error'    => 'Password needs ' . implode(', ', $pwCheck['issues']) . '.',
                'strength' => $pwCheck,
            ], 422);
        }
        if ($password !== $confirm)           json_out(['success' => false, 'error' => 'Passwords do not match.'], 422);
        if (fetchOne("SELECT id FROM users WHERE email = ?", [$email])) {
            json_out(['success' => false, 'error' => 'An account with that email already exists.'], 409);
        }

        $id = insert(
            "INSERT INTO users (name, email, phone, password) VALUES (?, ?, ?, ?)",
            [$name, $email, $phone, hashPassword($password)]
        );
        if (!$id) json_out(['success' => false, 'error' => 'Registration failed. Please try again.'], 500);

        // Auto-login: create session + persistent cookie, then the one-time
        // onboarding flow (Phase 2 Week 2) instead of straight to an empty
        // dashboard — onboarding.php itself redirects to dashboard.php once
        // done, and is a no-op redirect for anyone who's already onboarded.
        $user = fetchOne("SELECT id, name, email, profile_pic, is_admin FROM users WHERE id = ?", [$id]);
        loginUser($user, true);
        json_out(['success' => true, 'redirect' => APP_BASE . '/pages/onboarding.php', 'welcome' => true]);

    default:
        json_out(['success' => false, 'error' => 'Unknown action.'], 400);
}
