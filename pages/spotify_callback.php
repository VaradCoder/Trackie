<?php
/**
 * Spotify OAuth callback — receives code from Spotify, exchanges it for
 * tokens and stores them ENCRYPTED via the Spotify provider (so the
 * connection survives logout; it used to live only in the PHP session).
 * Register this exact redirect URI in the Spotify developer dashboard:
 *   https://trackie.free.nf/pages/spotify_callback.php
 * (Spotify rejects "localhost" — for local testing register
 *  http://127.0.0.1/Trackie/pages/spotify_callback.php and open Trackie
 *  via 127.0.0.1.)
 */
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/providers.php';
require_once '../includes/oauth.php';

requireAuth();

$back        = APP_BASE . '/pages/settings.php';
$clientId    = env('SPOTIFY_CLIENT_ID');
$secret      = env('SPOTIFY_CLIENT_SECRET');
$redirectUri = oauthRedirectUri('spotify_callback.php');
$sp          = provider('spotify');

if (!$clientId || !$secret || !$sp) {
    flash('error', 'Spotify integration is not configured on this server.');
    redirect($back);
}

// Step 1: redirect to Spotify authorization
if (!isset($_GET['code']) && !isset($_GET['error'])) {
    $state = generateToken(8);
    $_SESSION['spotify_state'] = $state;
    redirect('https://accounts.spotify.com/authorize?' . http_build_query([
        'response_type' => 'code',
        'client_id'     => $clientId,
        'scope'         => implode(' ', $sp->scopes()),
        'redirect_uri'  => $redirectUri,
        'state'         => $state,
        'show_dialog'   => 'false',
    ]));
}

if (isset($_GET['error'])) {
    flash('error', oauthErrorMessage('Spotify', (string)$_GET['error'], (string)($_GET['error_description'] ?? ''), $redirectUri));
    redirect($back);
}

if (($_GET['state'] ?? '') === '' || ($_GET['state'] ?? '') !== ($_SESSION['spotify_state'] ?? '')) {
    flash('error', 'Spotify sign-in expired or was opened in another tab. Please connect again.');
    redirect($back);
}
unset($_SESSION['spotify_state']);

// Step 2: exchange the authorization code for tokens
[$code, $data] = oauthPost('https://accounts.spotify.com/api/token', [
    'grant_type' => 'authorization_code', 'code' => (string)$_GET['code'], 'redirect_uri' => $redirectUri,
], ['Authorization: Basic ' . base64_encode("{$clientId}:{$secret}")]);

if (empty($data['access_token'])) {
    flash('error', oauthErrorMessage('Spotify', (string)($data['error'] ?? "http_{$code}"), (string)($data['error_description'] ?? ''), $redirectUri));
    redirect($back);
}

try {
    $sp->storeTokens(currentUserId(), $data['access_token'], $data['refresh_token'] ?? '',
                     (int)($data['expires_in'] ?? 3600), '', array_filter(explode(' ', $data['scope'] ?? '')));
} catch (Throwable $e) {
    flash('error', 'Could not securely store the Spotify token. Check the server encryption key.');
    redirect($back);
}
// Drop any tokens left over from the old session-only storage.
unset($_SESSION['spotify_access_token'], $_SESSION['spotify_refresh_token'], $_SESSION['spotify_token_expires']);

$res = runSync($sp, currentUserId());
flash($res['ok'] ? 'success' : 'error', $res['ok']
    ? 'Spotify connected — it stays connected across logins now.'
    : 'Spotify connected, but a test request failed: ' . $res['error']);
redirect($back);
