<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['spotify_access_token'])) {
    echo json_encode(['error' => 'not_authenticated']);
    exit();
}
$access_token = $_SESSION['spotify_access_token'];

function spotify_api_get($url, $access_token) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $access_token
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

$user = spotify_api_get('https://api.spotify.com/v1/me', $access_token);
$top_tracks = spotify_api_get('https://api.spotify.com/v1/me/top/tracks?limit=5', $access_token);

echo json_encode([
    'user' => $user,
    'top_tracks' => $top_tracks['items'] ?? []
]); 