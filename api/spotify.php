<?php
/**
 * Spotify API proxy — returns current track and refreshes tokens.
 * Called via AJAX from the dashboard.
 */
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();

$clientId     = env('SPOTIFY_CLIENT_ID');
$clientSecret = env('SPOTIFY_CLIENT_SECRET');

if (!$clientId || !$clientSecret) {
    json_out(['success' => false, 'connected' => false, 'reason' => 'not_configured', 'error' => 'Spotify is not set up on this server.'], 503);
}

// Tokens live encrypted in user_integrations (provider 'spotify') and are
// refreshed automatically when the 1-hour access token expires.
require_once '../includes/providers.php';
$sp = provider('spotify');
$uid = currentUserId();
if (!$sp || !$sp->isConnected($uid)) {
    json_out(['connected' => false, 'reason' => 'not_connected']);
}
$accessToken = $sp->validAccessToken($uid);
if (!$accessToken) {
    json_out(['connected' => false, 'reason' => 'refresh_failed',
              'error' => 'Spotify access expired — reconnect it in Settings.']);
}

/** Thin wrapper around the Spotify Web API using the session's access token. */
function spotifyGet(string $path, string $token): array {
    $ch = curl_init('https://api.spotify.com/v1' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
        CURLOPT_TIMEOUT        => 8,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 401 || $code === 403) {
        // 403 on these endpoints = a scope from before the scope fix is missing,
        // or the account isn't on the app's allow-list (Development mode).
        json_out(['success' => false, 'connected' => true, 'needsReconnect' => true,
                  'error' => $code === 401 ? 'Spotify rejected the token — reconnect it in Settings.'
                                           : 'Spotify denied this request. Reconnect Spotify in Settings to grant the new permissions.'], 200);
    }
    return ['code' => $code, 'body' => $raw ? json_decode($raw, true) : null];
}
function spotifyPut(string $path, string $token, array $body = []): int {
    $ch = curl_init('https://api.spotify.com/v1' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}", 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => $body ? json_encode($body) : '',
        CURLOPT_TIMEOUT        => 8,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

$action = sanitizeInput($_POST['action'] ?? $_GET['action'] ?? '');

switch ($action) {

    case 'recently_played':
        $r = spotifyGet('/me/player/recently-played?limit=15', $accessToken);
        $items = array_map(fn($it) => [
            'name'    => $it['track']['name'] ?? '',
            'artist'  => implode(', ', array_column($it['track']['artists'] ?? [], 'name')),
            'art'     => $it['track']['album']['images'][2]['url'] ?? ($it['track']['album']['images'][0]['url'] ?? ''),
            'url'     => $it['track']['external_urls']['spotify'] ?? '',
            'played_at' => $it['played_at'] ?? '',
        ], $r['body']['items'] ?? []);
        json_out(['success' => true, 'items' => $items]);

    case 'top_artists':
        $r = spotifyGet('/me/top/artists?limit=10&time_range=short_term', $accessToken);
        $items = array_map(fn($a) => [
            'name'  => $a['name'] ?? '',
            'image' => $a['images'][1]['url'] ?? ($a['images'][0]['url'] ?? ''),
            'url'   => $a['external_urls']['spotify'] ?? '',
            'genres'=> array_slice($a['genres'] ?? [], 0, 2),
        ], $r['body']['items'] ?? []);
        json_out(['success' => true, 'items' => $items]);

    case 'top_tracks':
        $r = spotifyGet('/me/top/tracks?limit=10&time_range=short_term', $accessToken);
        $items = array_map(fn($t) => [
            'name'   => $t['name'] ?? '',
            'artist' => implode(', ', array_column($t['artists'] ?? [], 'name')),
            'art'    => $t['album']['images'][2]['url'] ?? ($t['album']['images'][0]['url'] ?? ''),
            'url'    => $t['external_urls']['spotify'] ?? '',
            'uri'    => $t['uri'] ?? '',
        ], $r['body']['items'] ?? []);
        json_out(['success' => true, 'items' => $items]);

    case 'playlists':
        $r = spotifyGet('/me/playlists?limit=20', $accessToken);
        $items = array_map(fn($p) => [
            'id'    => $p['id'] ?? '',
            'uri'   => $p['uri'] ?? '',
            'name'  => $p['name'] ?? '',
            'image' => $p['images'][0]['url'] ?? '',
            'tracks'=> $p['tracks']['total'] ?? 0,
            'url'   => $p['external_urls']['spotify'] ?? '',
        ], $r['body']['items'] ?? []);
        json_out(['success' => true, 'items' => $items]);

    case 'play':
        verify_csrf();
        $uri      = sanitizeInput($_POST['playlist_uri'] ?? '');
        $trackUri = sanitizeInput($_POST['track_uri'] ?? '');
        $deviceId = sanitizeInput($_POST['device_id'] ?? '');
        $path = '/me/player/play' . ($deviceId ? '?device_id=' . urlencode($deviceId) : '');
        $body = [];
        if ($trackUri) $body['uris'] = [$trackUri];
        elseif ($uri)  $body['context_uri'] = $uri;
        $code = spotifyPut($path, $accessToken, $body);
        // 404 = no active device (Spotify must be open somewhere) — surfaced to the UI, not a hard error
        json_out(['success' => in_array($code, [200, 204], true), 'noActiveDevice' => $code === 404]);

    case 'pause':
        verify_csrf();
        $deviceId = sanitizeInput($_POST['device_id'] ?? '');
        $path = '/me/player/pause' . ($deviceId ? '?device_id=' . urlencode($deviceId) : '');
        $code = spotifyPut($path, $accessToken);
        json_out(['success' => in_array($code, [200, 204], true), 'noActiveDevice' => $code === 404]);

    case 'get_token':
        // Hands the (already server-refreshed) access token to the browser so the
        // Web Playback SDK can initialize. Short-lived (~1hr) and scoped to this
        // logged-in user's own session — this is Spotify's documented pattern for
        // the SDK, which must run client-side.
        json_out(['success' => true, 'access_token' => $accessToken]);

    default:
        // Backward-compatible default: currently-playing (used by the dashboard widget poller)
        $r = spotifyGet('/me/player/currently-playing', $accessToken);
        if ($r['code'] === 204 || empty($r['body']) || empty($r['body']['item'])) {
            json_out(['connected' => true, 'playing' => false]);
        }
        $item = $r['body']['item'];
        json_out([
            'connected'  => true,
            'playing'    => $r['body']['is_playing'] ?? false,
            'track'      => [
                'name'    => $item['name'] ?? '',
                'artist'  => implode(', ', array_column($item['artists'] ?? [], 'name')),
                'album'   => $item['album']['name'] ?? '',
                'art'     => $item['album']['images'][1]['url'] ?? ($item['album']['images'][0]['url'] ?? ''),
                'url'     => $item['external_urls']['spotify'] ?? '',
                'progress_ms' => $r['body']['progress_ms'] ?? 0,
                'duration_ms' => $item['duration_ms'] ?? 0,
            ],
        ]);
}
