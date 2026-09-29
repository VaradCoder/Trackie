<?php
/**
 * Google OAuth callback — Calendar + Tasks share one OAuth app/callback.
 * Tokens are stored ENCRYPTED via the Google provider (previously only in
 * the PHP session, so the connection vanished on logout).
 * Register this exact callback URL as an "Authorized redirect URI" in the
 * Google Cloud Console OAuth client (add the localhost one too for dev):
 *   https://trackie.free.nf/pages/google_callback.php
 *   http://localhost/Trackie/pages/google_callback.php
 * Also enable the Google Calendar API and Google Tasks API for the project.
 */
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/providers.php';
require_once '../includes/oauth.php';

requireAuth();

$back        = APP_BASE . '/pages/settings.php';
$clientId    = env('GOOGLE_CLIENT_ID');
$secret      = env('GOOGLE_CLIENT_SECRET');
$redirectUri = oauthRedirectUri('google_callback.php');
$gp          = provider('google');

if (!$clientId || !$secret || !$gp) {
    flash('error', 'Google integration is not configured on this server.');
    redirect($back);
}

// Step 1: redirect to Google's consent screen
if (!isset($_GET['code']) && !isset($_GET['error'])) {
    $state = generateToken(8);
    $_SESSION['google_state'] = $state;
    redirect('https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id'     => $clientId,
        'redirect_uri'  => $redirectUri,
        'response_type' => 'code',
        'access_type'   => 'offline',  // needed to receive a refresh_token
        'prompt'        => 'consent',  // force refresh_token on repeat connects
        'scope'         => implode(' ', $gp->scopes()),
        'state'         => $state,
    ]));
}

if (isset($_GET['error'])) {
    flash('error', oauthErrorMessage('Google', (string)$_GET['error'], (string)($_GET['error_description'] ?? ''), $redirectUri));
    redirect($back);
}

if (($_GET['state'] ?? '') === '' || ($_GET['state'] ?? '') !== ($_SESSION['google_state'] ?? '')) {
    flash('error', 'Google sign-in expired or was opened in another tab. Please connect again.');
    redirect($back);
}
unset($_SESSION['google_state']);

// Step 2: exchange the authorization code for tokens
[$code, $data] = oauthPost('https://oauth2.googleapis.com/token', [
    'client_id' => $clientId, 'client_secret' => $secret, 'code' => (string)$_GET['code'],
    'redirect_uri' => $redirectUri, 'grant_type' => 'authorization_code',
]);

if (empty($data['access_token'])) {
    flash('error', oauthErrorMessage('Google', (string)($data['error'] ?? "http_{$code}"), (string)($data['error_description'] ?? ''), $redirectUri));
    redirect($back);
}

// Google only sends a refresh_token on consent; keep the stored one otherwise.
$uid     = currentUserId();
$refresh = $data['refresh_token'] ?? ($gp->isConnected($uid) ? (string)$gp->refreshTokenValue($uid) : '');
try {
    $gp->storeTokens($uid, $data['access_token'], $refresh, (int)($data['expires_in'] ?? 3600), '',
                     array_filter(explode(' ', $data['scope'] ?? '')));
} catch (Throwable $e) {
    flash('error', 'Could not securely store the Google token. Check the server encryption key.');
    redirect($back);
}
unset($_SESSION['google_access_token'], $_SESSION['google_refresh_token'], $_SESSION['google_token_expires']);

$res = runSync($gp, $uid);
flash($res['ok'] ? 'success' : 'error', $res['ok']
    ? 'Google connected — synced ' . (int)($res['records'] ?? 0) . ' upcoming events and tasks.'
    : 'Google connected, but syncing failed: ' . $res['error']);
redirect($back);
