<?php
/**
 * Spotify OAuth callback — receives code from Spotify, exchanges for tokens.
 * Requires SPOTIFY_CLIENT_ID and SPOTIFY_CLIENT_SECRET env vars.
 */
require_once '../config/app.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();

$clientId     = env('SPOTIFY_CLIENT_ID');
$clientSecret = env('SPOTIFY_CLIENT_SECRET');
$redirectUri  = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
              . '://' . $_SERVER['HTTP_HOST']
              . APP_BASE . '/pages/spotify_callback.php';

if (!$clientId || !$clientSecret) {
    flash('error', 'Spotify integration is not configured on this server.');
    redirect(APP_BASE . '/pages/dashboard.php');
}

// Step 1: redirect to Spotify authorization
if (!isset($_GET['code']) && !isset($_GET['error'])) {
    $state = generateToken(8);
    $_SESSION['spotify_state'] = $state;

    $authUrl = 'https://accounts.spotify.com/authorize?' . http_build_query([
        'response_type' => 'code',
        'client_id'     => $clientId,
        'scope'         => 'user-read-private user-read-email user-read-currently-playing user-top-read',
        'redirect_uri'  => $redirectUri,
        'state'         => $state,
    ]);
    redirect($authUrl);
}

// Spotify returned an error
if (isset($_GET['error'])) {
    flash('error', 'Spotify authorization was denied.');
    redirect(APP_BASE . '/pages/dashboard.php');
}

// State validation
if (($_GET['state'] ?? '') !== ($_SESSION['spotify_state'] ?? '')) {
    flash('error', 'Invalid Spotify state. Please try again.');
    redirect(APP_BASE . '/pages/dashboard.php');
}
unset($_SESSION['spotify_state']);

// Step 2: exchange authorization code for tokens
$ch = curl_init('https://accounts.spotify.com/api/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'grant_type'   => 'authorization_code',
        'code'         => $_GET['code'],
        'redirect_uri' => $redirectUri,
    ]),
    CURLOPT_HTTPHEADER => [
        'Authorization: Basic ' . base64_encode("{$clientId}:{$clientSecret}"),
        'Content-Type: application/x-www-form-urlencoded',
    ],
    CURLOPT_TIMEOUT => 10,
]);
$resp = curl_exec($ch);
curl_close($ch);

$data = json_decode($resp, true);

if (empty($data['access_token'])) {
    flash('error', 'Failed to connect to Spotify. Please try again.');
    redirect(APP_BASE . '/pages/dashboard.php');
}

$_SESSION['spotify_access_token']  = $data['access_token'];
$_SESSION['spotify_refresh_token'] = $data['refresh_token'] ?? '';
$_SESSION['spotify_token_expires'] = time() + (int)($data['expires_in'] ?? 3600);

flash('success', 'Spotify connected! Your currently playing track will appear on the dashboard.');
redirect(APP_BASE . '/pages/dashboard.php');
