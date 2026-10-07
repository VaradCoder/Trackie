<?php
/**
 * Google OAuth callback — one registered redirect URI for every Google flow:
 *
 *   ?mode=connect (default)  Calendar + Tasks for a signed-in user. Tokens
 *                            stored ENCRYPTED via the Google provider.
 *   ?mode=login              "Continue with Google" on the sign-in page.
 *                            OpenID Connect (openid email profile), no tokens
 *                            kept; linked to the existing users table —
 *                            see includes/identity.php for the linking rules.
 *   ?mode=link               Settings → Sign-in methods → Link Google.
 *   &app=1                   Started from the Android app in the system
 *                            browser (Google blocks WebView sign-in): the
 *                            result goes back to the app as a one-time code
 *                            (com.varad.trackie://auth?code=…), and the app
 *                            redeems it here with ?handoff=<code>.
 *
 * Register this exact callback URL as an "Authorized redirect URI" in the
 * Google Cloud Console OAuth client (add the localhost one too for dev):
 *   https://trackie.free.nf/pages/google_callback.php
 *   http://localhost/Trackie/pages/google_callback.php
 * Calendar/Tasks also need the Google Calendar API and Google Tasks API enabled.
 */
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/providers.php';
require_once '../includes/oauth.php';
require_once '../includes/identity.php';

$settings    = APP_BASE . '/pages/settings.php';
$signIn      = APP_BASE . '/pages/auth.php';
$clientId    = env('GOOGLE_CLIENT_ID');
$secret      = env('GOOGLE_CLIENT_SECRET');
$redirectUri = oauthRedirectUri('google_callback.php');
$gp          = provider('google');

/** Where a flow ends up when something goes wrong. */
$backFor = static fn(string $mode) => $mode === 'login' ? $signIn : $settings;
$fail = static function (string $mode, string $msg) use ($backFor) { flash('error', $msg); redirect($backFor($mode)); };

if (!$clientId || !$secret || !$gp) {
    flash('error', 'Google sign-in is not configured on this server.');
    redirect(isLoggedIn() ? $settings : $signIn);
}

/* ── App handoff: the app redeems a one-time code from the browser flow ── */
if (isset($_GET['handoff'])) {
    if (!identitiesReady()) $fail('login', 'Google sign-in needs the latest database update.');
    if (!rateLimit('google_handoff', $_SERVER['REMOTE_ADDR'] ?? 'cli', 20, 600)) $fail('login', 'Too many attempts. Please wait a few minutes.');
    $uid = handoffConsume((string)$_GET['handoff']);
    if (!$uid) $fail('login', 'That sign-in link expired or was already used. Please try "Continue with Google" again.');
    $user = fetchOne("SELECT id, name, email, profile_pic, is_admin, onboarding_completed_at FROM users WHERE id=?", [$uid]);
    if (!$user) $fail('login', 'Account not found.');
    loginUser($user, true);
    redirect(APP_BASE . ($user['onboarding_completed_at'] ? '/pages/dashboard.php' : '/pages/onboarding.php'));
}

/* ── Step 1: send the person to Google ─────────────────────────────── */
if (!isset($_GET['code']) && !isset($_GET['error'])) {
    $mode = in_array($_GET['mode'] ?? '', ['login', 'link'], true) ? $_GET['mode'] : 'connect';
    $app  = !empty($_GET['app']) && $mode === 'login';
    if ($mode !== 'login') requireAuth();
    if ($mode !== 'connect' && !identitiesReady()) $fail($mode, 'Google sign-in needs the latest database update.');
    if ($mode === 'login' && !$app && tryRememberLogin()) redirect(APP_BASE . '/pages/dashboard.php');

    $state = generateToken(16);
    $_SESSION['google_oauth'] = ['state' => $state, 'mode' => $mode, 'app' => $app, 'at' => time()];
    $params = [
        'client_id'     => $clientId,
        'redirect_uri'  => $redirectUri,
        'response_type' => 'code',
        'state'         => $state,
    ];
    if ($mode === 'connect') {
        $params += ['scope' => implode(' ', $gp->scopes()), 'access_type' => 'offline', 'prompt' => 'consent'];
    } else {
        // Sign-in only needs who you are — no Calendar/Tasks access, no refresh token.
        $params += ['scope' => 'openid email profile', 'prompt' => 'select_account'];
    }
    redirect('https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params));
}

/* ── Step 2: Google sent the person back ──────────────────────────── */
$flow = $_SESSION['google_oauth'] ?? null;
unset($_SESSION['google_oauth']);
$mode = $flow['mode'] ?? 'connect';

if (isset($_GET['error'])) {
    $fail($mode, oauthErrorMessage('Google', (string)$_GET['error'], (string)($_GET['error_description'] ?? ''), $redirectUri));
}
if (!$flow || ($_GET['state'] ?? '') === '' || !hash_equals((string)$flow['state'], (string)$_GET['state']) || time() - (int)$flow['at'] > 900) {
    $fail($mode, 'Google sign-in expired or was opened in another tab. Please try again.');
}

// GOOGLE_TOKEN_URL: test hook (a local mock); unset in production.
[$code, $data] = oauthPost(env('GOOGLE_TOKEN_URL', 'https://oauth2.googleapis.com/token'), [
    'client_id' => $clientId, 'client_secret' => $secret, 'code' => (string)$_GET['code'],
    'redirect_uri' => $redirectUri, 'grant_type' => 'authorization_code',
]);
if (empty($data['access_token'])) {
    $fail($mode, oauthErrorMessage('Google', (string)($data['error'] ?? "http_{$code}"), (string)($data['error_description'] ?? ''), $redirectUri));
}

/* Sign in / link */
if ($mode !== 'connect') {
    $claims = googleIdClaims((string)($data['id_token'] ?? ''));
    if (!$claims) $fail($mode, 'Google\'s answer could not be verified. Please try again.');

    if ($mode === 'link') {
        requireAuth();
        $res = googleSignIn($claims, currentUserId());
        if (isset($res['error'])) $fail('link', $res['error']);
        flash('success', 'Google linked — you can now sign in with ' . ($claims['email'] ?? 'your Google account') . '.');
        redirect($settings . '#signin-methods');
    }

    // mode = login
    if (!rateLimit('google_login', $_SERVER['REMOTE_ADDR'] ?? 'cli', 20, 900)) $fail('login', 'Too many sign-in attempts. Please wait 15 minutes.');
    $res = googleSignIn($claims, null);
    if (isset($res['error'])) $fail('login', $res['error']);
    $user = $res['user'];

    if (!empty($flow['app'])) {
        // Finished in the phone's browser: hand the sign-in to the app.
        $handoff = handoffCreate((int)$user['id']);
        $appUrl  = 'com.varad.trackie://auth?code=' . $handoff;
        header('Cache-Control: no-store');
        ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Back to Trackie</title>
<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0f172a;color:#e2e8f0;font:16px/1.5 system-ui,sans-serif;text-align:center;padding:1.5rem}
a{display:inline-block;margin-top:1rem;padding:.875rem 1.5rem;border-radius:12px;background:#ef4444;color:#fff;font-weight:700;text-decoration:none}</style>
</head><body><div>
  <p>Signed in as <b><?= h($user['email']) ?></b>.</p>
  <a href="<?= h($appUrl) ?>" id="go">Open Trackie</a>
  <p style="font-size:.8125rem;color:#94a3b8;margin-top:1rem">This link works once and expires in 2 minutes.</p>
</div><script>location.href = <?= json_encode($appUrl) ?>;</script></body></html><?php
        exit;
    }

    loginUser($user, true);
    if (!empty($res['created'])) redirect(APP_BASE . '/pages/onboarding.php');
    if (!empty($res['linked'])) flash('success', 'Google is now linked to your Trackie account.');
    redirect(APP_BASE . '/pages/dashboard.php');
}

/* Calendar + Tasks connection (existing flow) */
requireAuth();
// Google only sends a refresh_token on consent; keep the stored one otherwise.
$uid     = currentUserId();
$refresh = $data['refresh_token'] ?? ($gp->isConnected($uid) ? (string)$gp->refreshTokenValue($uid) : '');
try {
    $gp->storeTokens($uid, $data['access_token'], $refresh, (int)($data['expires_in'] ?? 3600), '',
                     array_filter(explode(' ', $data['scope'] ?? '')));
} catch (Throwable $e) {
    flash('error', 'Could not securely store the Google token. Check the server encryption key.');
    redirect($settings);
}
unset($_SESSION['google_access_token'], $_SESSION['google_refresh_token'], $_SESSION['google_token_expires']);

$res = runSync($gp, $uid);
flash($res['ok'] ? 'success' : 'error', $res['ok']
    ? 'Google connected — synced ' . (int)($res['records'] ?? 0) . ' upcoming events and tasks.'
    : 'Google connected, but syncing failed: ' . $res['error']);
redirect($settings);
