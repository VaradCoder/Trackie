<?php
/**
 * Google OAuth callback — Calendar + Tasks share one OAuth app/callback.
 * Requires GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET (config/env.php).
 * Register this exact callback URL as an "Authorized redirect URI" in the
 * Google Cloud Console OAuth client:
 *   https://trackie.free.nf/pages/google_callback.php
 * Also enable the Calendar API and Tasks API for the project.
 */
require_once '../config/app.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();

$clientId     = env('GOOGLE_CLIENT_ID');
$clientSecret = env('GOOGLE_CLIENT_SECRET');
$redirectUri  = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
              . '://' . $_SERVER['HTTP_HOST']
              . APP_BASE . '/pages/google_callback.php';

if (!$clientId || !$clientSecret) {
    flash('error', 'Google integration is not configured on this server.');
    redirect(APP_BASE . '/pages/settings.php');
}

// Step 1: redirect to Google's consent screen
if (!isset($_GET['code']) && !isset($_GET['error'])) {
    $state = generateToken(8);
    $_SESSION['google_state'] = $state;

    $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id'     => $clientId,
        'redirect_uri'  => $redirectUri,
        'response_type' => 'code',
        'access_type'   => 'offline',  // needed to receive a refresh_token
        'prompt'        => 'consent',  // force refresh_token on repeat connects
        'scope'         => implode(' ', [
            'https://www.googleapis.com/auth/calendar.readonly',
            'https://www.googleapis.com/auth/tasks',
        ]),
        'state' => $state,
    ]);
    redirect($authUrl);
}

// Google returned an error (user denied, etc.)
if (isset($_GET['error'])) {
    flash('error', 'Google authorization was denied.');
    redirect(APP_BASE . '/pages/settings.php');
}

// State validation (CSRF protection for the OAuth flow itself)
if (($_GET['state'] ?? '') !== ($_SESSION['google_state'] ?? '')) {
    flash('error', 'Invalid Google state. Please try again.');
    redirect(APP_BASE . '/pages/settings.php');
}
unset($_SESSION['google_state']);

// Step 2: exchange the authorization code for tokens
$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'code'          => $_GET['code'],
        'redirect_uri'  => $redirectUri,
        'grant_type'    => 'authorization_code',
    ]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_TIMEOUT => 10,
]);
$resp = curl_exec($ch);
curl_close($ch);

$data = json_decode($resp, true);

if (empty($data['access_token'])) {
    flash('error', 'Failed to connect to Google. Please try again.');
    redirect(APP_BASE . '/pages/settings.php');
}

$_SESSION['google_access_token']  = $data['access_token'];
$_SESSION['google_refresh_token'] = $data['refresh_token'] ?? ($_SESSION['google_refresh_token'] ?? '');
$_SESSION['google_token_expires'] = time() + (int)($data['expires_in'] ?? 3600);

flash('success', 'Google connected! Calendar and Tasks sync will appear once enabled.');
redirect(APP_BASE . '/pages/settings.php');
