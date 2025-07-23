<?php
session_start();

// Spotify credentials
$client_id = 'd305a7f4f4e54b44aaf49581427e0e73';
$client_secret = '52064c52b46e4b12b92760f4ad8ab8b1';
$redirect_uri = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/spotify_auth.php';

// Step 1: Redirect to Spotify authorization
if (!isset($_GET['code'])) {
    $state = bin2hex(random_bytes(8));
    $_SESSION['spotify_auth_state'] = $state;
    $scope = 'user-read-private user-read-email user-top-read';
    $auth_url = 'https://accounts.spotify.com/authorize?'.http_build_query([
        'response_type' => 'code',
        'client_id' => $client_id,
        'scope' => $scope,
        'redirect_uri' => $redirect_uri,
        'state' => $state
    ]);
    header('Location: ' . $auth_url);
    exit();
}

// Step 2: Handle callback
if (isset($_GET['code']) && isset($_GET['state']) && $_GET['state'] === ($_SESSION['spotify_auth_state'] ?? '')) {
    $code = $_GET['code'];
    // Exchange code for access token
    $ch = curl_init('https://accounts.spotify.com/api/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => $redirect_uri,
        'client_id' => $client_id,
        'client_secret' => $client_secret
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    $response = curl_exec($ch);
    $data = json_decode($response, true);
    curl_close($ch);
    if (isset($data['access_token'])) {
        $_SESSION['spotify_access_token'] = $data['access_token'];
        $_SESSION['spotify_refresh_token'] = $data['refresh_token'] ?? null;
        $_SESSION['spotify_token_expires'] = time() + $data['expires_in'];
        // Optionally fetch user profile here
        header('Location: ../dashboard.php?spotify=connected');
        exit();
    } else {
        echo 'Spotify authentication failed.';
        exit();
    }
} else {
    echo 'Invalid state or missing code.';
    exit();
} 