<?php
/**
 * Strava OAuth callback — receives code from Strava, exchanges for a token.
 * Requires STRAVA_CLIENT_ID and STRAVA_CLIENT_SECRET (config/env.php).
 * Register this exact callback domain in the Strava API app settings
 * (Strava only needs the domain under "Authorization Callback Domain", e.g.
 * trackie.free.nf) — full path used here: /pages/strava_callback.php
 */
require_once '../config/app.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();

$clientId     = env('STRAVA_CLIENT_ID');
$clientSecret = env('STRAVA_CLIENT_SECRET');
$redirectUri  = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
              . '://' . $_SERVER['HTTP_HOST']
              . APP_BASE . '/pages/strava_callback.php';

if (!$clientId || !$clientSecret) {
    flash('error', 'Strava integration is not configured on this server.');
    redirect(APP_BASE . '/pages/settings.php');
}

// Step 1: redirect to Strava's authorization screen
if (!isset($_GET['code']) && !isset($_GET['error'])) {
    $state = generateToken(8);
    $_SESSION['strava_state'] = $state;

    $authUrl = 'https://www.strava.com/oauth/authorize?' . http_build_query([
        'client_id'     => $clientId,
        'redirect_uri'  => $redirectUri,
        'response_type' => 'code',
        'approval_prompt' => 'auto',
        'scope'         => 'read,activity:read_all',
        'state'         => $state,
    ]);
    redirect($authUrl);
}

// Strava returned an error (user denied, etc.)
if (isset($_GET['error'])) {
    flash('error', 'Strava authorization was denied.');
    redirect(APP_BASE . '/pages/settings.php');
}

// State validation (CSRF protection for the OAuth flow itself)
if (($_GET['state'] ?? '') !== ($_SESSION['strava_state'] ?? '')) {
    flash('error', 'Invalid Strava state. Please try again.');
    redirect(APP_BASE . '/pages/settings.php');
}
unset($_SESSION['strava_state']);

// Step 2: exchange the authorization code for tokens
$ch = curl_init('https://www.strava.com/oauth/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'code'          => $_GET['code'],
        'grant_type'    => 'authorization_code',
    ]),
    CURLOPT_TIMEOUT => 10,
]);
$resp = curl_exec($ch);
curl_close($ch);

$data = json_decode($resp, true);

if (empty($data['access_token'])) {
    flash('error', 'Failed to connect to Strava. Please try again.');
    redirect(APP_BASE . '/pages/settings.php');
}

$_SESSION['strava_access_token']  = $data['access_token'];
$_SESSION['strava_refresh_token'] = $data['refresh_token'] ?? '';
$_SESSION['strava_token_expires'] = (int)($data['expires_at'] ?? (time() + 21600));

flash('success', 'Strava connected!');
redirect(APP_BASE . '/pages/settings.php');
