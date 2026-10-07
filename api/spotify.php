<?php
/**
 * Spotify Web API proxy for the Music page, the dashboard widget and Focus.
 * Tokens stay server-side (encrypted in user_integrations, refreshed here);
 * the only exception is `get_token` for the Web Playback SDK, which by
 * Spotify's design must run in the browser.
 *
 * Read:     state, queue, devices, liked, recently_played, top_tracks,
 *           top_artists, playlists, is_saved, (default) currently-playing
 * Control:  control (play|pause|next|previous|seek|volume|shuffle|repeat|transfer),
 *           play (context / track), pause, save, unsave        — CSRF-checked
 *
 * Errors carry `reason` so the UI can explain them: no_device (Spotify isn't
 * open anywhere), premium (playback control needs Premium), reconnect
 * (token/scopes), rate_limited (+ retry_after seconds).
 */
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/providers.php';

requireAuth();

if (!env('SPOTIFY_CLIENT_ID') || !env('SPOTIFY_CLIENT_SECRET')) {
    json_out(['success' => false, 'connected' => false, 'reason' => 'not_configured', 'error' => 'Spotify is not set up on this server.'], 503);
}

$sp  = provider('spotify');
$uid = currentUserId();
if (!$sp || !$sp->isConnected($uid)) {
    json_out(['connected' => false, 'reason' => 'not_connected']);
}
$accessToken = $sp->validAccessToken($uid);
if (!$accessToken) {
    json_out(['connected' => false, 'reason' => 'refresh_failed', 'needsReconnect' => true,
              'error' => 'Spotify access expired — reconnect Spotify.']);
}
// SPOTIFY_API_URL: test hook (a local mock); unset in production.
define('SPOTIFY_API', rtrim(env('SPOTIFY_API_URL', 'https://api.spotify.com/v1'), '/'));
$grantedScopes = preg_split('/[\s,]+/', (string)($sp->connection($uid)['scopes'] ?? ''));
$hasLibraryScope = in_array('user-library-read', $grantedScopes, true);

/** One request through the shared client; turns the common failures into UI reasons. */
function spotify(string $method, string $path, string $token, array $opts = []): array {
    try {
        $r = providerHttp('Spotify', $method, SPOTIFY_API . $path, $token, $opts + ['timeout' => 8]);
    } catch (ProviderRateLimited $e) {
        json_out(['success' => false, 'connected' => true, 'reason' => 'rate_limited', 'retry_after' => $e->retryAfter, 'error' => $e->getMessage()], 200);
    } catch (RuntimeException $e) {
        json_out(['success' => false, 'connected' => true, 'reason' => 'network', 'error' => $e->getMessage()], 200);
    }
    $msg = (string)($r['body']['error']['message'] ?? '');
    $why = (string)($r['body']['error']['reason'] ?? '');
    if ($r['code'] === 401) {
        json_out(['success' => false, 'connected' => true, 'needsReconnect' => true, 'reason' => 'reconnect',
                  'error' => 'Spotify rejected the token — reconnect Spotify.'], 200);
    }
    if ($r['code'] === 403) {
        if ($why === 'PREMIUM_REQUIRED' || stripos($msg, 'premium') !== false) {
            json_out(['success' => false, 'connected' => true, 'reason' => 'premium',
                      'error' => 'Controlling playback needs Spotify Premium (a Spotify rule).'], 200);
        }
        if (str_starts_with($path, '/me/player') && $method !== 'GET') {
            // e.g. "Restriction violated" — skipping not allowed on this content.
            json_out(['success' => false, 'connected' => true, 'reason' => 'restricted',
                      'error' => $msg !== '' ? "Spotify: {$msg}" : 'Spotify does not allow that right now.'], 200);
        }
        json_out(['success' => false, 'connected' => true, 'needsReconnect' => true, 'reason' => 'reconnect',
                  'error' => 'Spotify denied this request. Reconnect Spotify to grant the new permissions'
                           . ' (and, while the Spotify app is in Development mode, make sure your account is on its user list).'], 200);
    }
    if ($r['code'] === 404 && str_starts_with($path, '/me/player') && $method !== 'GET') {
        json_out(['success' => false, 'connected' => true, 'reason' => 'no_device', 'noActiveDevice' => true,
                  'error' => 'Open Spotify on your phone or computer first — then Trackie can control it.'], 200);
    }
    return $r;
}

/** Compact track object for the UI. */
function trackOut(?array $t): ?array {
    if (!$t || empty($t['id'])) return null;
    $imgs = $t['album']['images'] ?? [];
    return [
        'id'       => $t['id'],
        'uri'      => $t['uri'] ?? 'spotify:track:' . $t['id'],
        'name'     => $t['name'] ?? '',
        'artist'   => implode(', ', array_column($t['artists'] ?? [], 'name')),
        'album'    => $t['album']['name'] ?? '',
        'art'      => $imgs[0]['url'] ?? '',
        'thumb'    => ($imgs[2] ?? $imgs[1] ?? $imgs[0] ?? [])['url'] ?? '',
        'url'      => $t['external_urls']['spotify'] ?? '',
        'duration' => (int)($t['duration_ms'] ?? 0),
        'explicit' => (bool)($t['explicit'] ?? false),
    ];
}

/** Which of these track ids are in Liked Songs. Uses /me/library/contains (Feb 2026), falls back to the old endpoint. */
function likedMap(array $ids, string $token, bool $hasScope): array {
    $ids = array_values(array_unique(array_filter($ids)));
    if (!$ids || !$hasScope) return [];
    $out = [];
    try {
    foreach (array_chunk($ids, 40) as $chunk) {
        $uris = implode(',', array_map(static fn($id) => "spotify:track:{$id}", $chunk));
        $r = providerHttp('Spotify', 'GET', SPOTIFY_API . '/me/library/contains?uris=' . rawurlencode($uris), $token, ['timeout' => 6]);
        if ($r['code'] >= 400 || !is_array($r['body'])) {
            $r = providerHttp('Spotify', 'GET', SPOTIFY_API . '/me/tracks/contains?ids=' . implode(',', $chunk), $token, ['timeout' => 6]);
        }
        if (is_array($r['body']) && array_values($r['body']) === $r['body']) {   // a JSON list of booleans
            foreach ($chunk as $i => $id) $out[$id] = (bool)($r['body'][$i] ?? false);
        }
    }
    } catch (RuntimeException $e) { /* hearts are a nicety: unknown rather than an error */ }
    return $out;
}

$action = sanitizeInput($_POST['action'] ?? $_GET['action'] ?? '');
$isWrite = in_array($action, ['control', 'play', 'pause', 'save', 'unsave'], true);
if ($isWrite) verify_csrf();

switch ($action) {

    case 'state': {
        // Full playback state of whatever device is active (phone app, desktop, web player).
        $r = spotify('GET', '/me/player?additional_types=track', $accessToken);
        if ($r['code'] === 204 || empty($r['body'])) {
            json_out(['success' => true, 'active' => false, 'library_scope' => $hasLibraryScope]);
        }
        $b = $r['body'];
        $track = trackOut(($b['currently_playing_type'] ?? 'track') === 'track' ? ($b['item'] ?? null) : null);
        $liked = $track ? (likedMap([$track['id']], $accessToken, $hasLibraryScope)[$track['id']] ?? null) : null;
        json_out([
            'success'  => true, 'active' => true, 'library_scope' => $hasLibraryScope,
            'playing'  => (bool)($b['is_playing'] ?? false),
            'progress' => (int)($b['progress_ms'] ?? 0),
            'shuffle'  => (bool)($b['shuffle_state'] ?? false),
            'repeat'   => (string)($b['repeat_state'] ?? 'off'),
            'type'     => $b['currently_playing_type'] ?? 'track',
            'track'    => $track, 'liked' => $liked,
            'device'   => [
                'id' => $b['device']['id'] ?? null, 'name' => $b['device']['name'] ?? 'Unknown device',
                'type' => $b['device']['type'] ?? '', 'volume' => $b['device']['volume_percent'] ?? null,
                'supports_volume' => (bool)($b['device']['supports_volume'] ?? false),
            ],
            'disallows' => array_keys(array_filter($b['actions']['disallows'] ?? [])),
            'context'  => isset($b['context']['uri']) ? ['uri' => $b['context']['uri'], 'type' => $b['context']['type'] ?? ''] : null,
        ]);
    }

    case 'control': {
        $cmd = (string)($_POST['cmd'] ?? '');
        $dev = preg_match('/^[A-Za-z0-9]{10,64}$/', (string)($_POST['device_id'] ?? '')) ? '?device_id=' . $_POST['device_id'] : '';
        $amp = $dev ? '&' : '?';
        switch ($cmd) {
            case 'play':     spotify('PUT',  '/me/player/play' . $dev, $accessToken); break;
            case 'pause':    spotify('PUT',  '/me/player/pause' . $dev, $accessToken); break;
            case 'next':     spotify('POST', '/me/player/next' . $dev, $accessToken); break;
            case 'previous': spotify('POST', '/me/player/previous' . $dev, $accessToken); break;
            case 'seek':
                spotify('PUT', '/me/player/seek' . $dev . $amp . 'position_ms=' . max(0, (int)($_POST['value'] ?? 0)), $accessToken); break;
            case 'volume':
                spotify('PUT', '/me/player/volume' . $dev . $amp . 'volume_percent=' . max(0, min(100, (int)($_POST['value'] ?? 50))), $accessToken); break;
            case 'shuffle':
                spotify('PUT', '/me/player/shuffle' . $dev . $amp . 'state=' . (!empty($_POST['value']) && $_POST['value'] !== 'false' ? 'true' : 'false'), $accessToken); break;
            case 'repeat':
                $state = in_array($_POST['value'] ?? '', ['off', 'context', 'track'], true) ? $_POST['value'] : 'off';
                spotify('PUT', '/me/player/repeat' . $dev . $amp . 'state=' . $state, $accessToken); break;
            case 'transfer':
                $to = (string)($_POST['device_id'] ?? '');
                if (!preg_match('/^[A-Za-z0-9]{10,64}$/', $to)) json_out(['success' => false, 'error' => 'Pick a device.'], 422);
                spotify('PUT', '/me/player', $accessToken, ['json' => ['device_ids' => [$to], 'play' => !empty($_POST['play'])]]); break;
            default:
                json_out(['success' => false, 'error' => 'Unknown command.'], 400);
        }
        json_out(['success' => true]);
    }

    case 'queue': {
        $r = spotify('GET', '/me/player/queue', $accessToken);
        $q = array_values(array_filter(array_map('trackOut', array_slice($r['body']['queue'] ?? [], 0, 20))));
        json_out(['success' => true, 'current' => trackOut($r['body']['currently_playing'] ?? null), 'queue' => $q]);
    }

    case 'devices': {
        $r = spotify('GET', '/me/player/devices', $accessToken);
        json_out(['success' => true, 'devices' => array_map(static fn($d) => [
            'id' => $d['id'] ?? null, 'name' => $d['name'] ?? 'Device', 'type' => $d['type'] ?? '',
            'active' => (bool)($d['is_active'] ?? false), 'restricted' => (bool)($d['is_restricted'] ?? false),
            'volume' => $d['volume_percent'] ?? null,
        ], $r['body']['devices'] ?? [])]);
    }

    case 'liked': {
        // Liked Songs, newest first, 50 per page.
        if (!$hasLibraryScope) {
            json_out(['success' => false, 'connected' => true, 'needsReconnect' => true, 'reason' => 'scope',
                      'error' => 'Reconnect Spotify once to let Trackie read and save your Liked Songs.']);
        }
        $offset = max(0, (int)($_POST['offset'] ?? 0));
        $r = spotify('GET', '/me/tracks?limit=50&offset=' . $offset, $accessToken);
        $items = [];
        foreach ($r['body']['items'] ?? [] as $it) {
            if ($t = trackOut($it['track'] ?? null)) $items[] = $t + ['added_at' => $it['added_at'] ?? null];
        }
        json_out(['success' => true, 'items' => $items, 'total' => (int)($r['body']['total'] ?? count($items)),
                  'next_offset' => !empty($r['body']['next']) ? $offset + 50 : null]);
    }

    case 'is_saved': {
        $ids = array_slice(array_filter(explode(',', (string)($_POST['ids'] ?? '')), static fn($id) => (bool)preg_match('/^[A-Za-z0-9]{22}$/', $id)), 0, 50);
        json_out(['success' => true, 'saved' => likedMap($ids, $accessToken, $hasLibraryScope)]);
    }

    case 'save':
    case 'unsave': {
        $id = (string)($_POST['track_id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9]{22}$/', $id)) json_out(['success' => false, 'error' => 'Unknown track.'], 422);
        if (!in_array('user-library-modify', $grantedScopes, true)) {
            json_out(['success' => false, 'needsReconnect' => true, 'reason' => 'scope',
                      'error' => 'Reconnect Spotify once to let Trackie save songs to your Liked Songs.']);
        }
        $method = $action === 'save' ? 'PUT' : 'DELETE';
        // Feb 2026: PUT/DELETE /me/library?uris=…; older apps still answer the per-type endpoint.
        try {
            $r = providerHttp('Spotify', $method, SPOTIFY_API . '/me/library?uris=' . rawurlencode("spotify:track:{$id}"), $accessToken, ['timeout' => 8]);
        } catch (ProviderRateLimited $e) {
            json_out(['success' => false, 'reason' => 'rate_limited', 'retry_after' => $e->retryAfter, 'error' => $e->getMessage()]);
        } catch (RuntimeException $e) {
            json_out(['success' => false, 'reason' => 'network', 'error' => $e->getMessage()]);
        }
        if ($r['code'] >= 400 && $r['code'] !== 401 && $r['code'] !== 429) {
            $r = spotify($method, '/me/tracks?ids=' . $id, $accessToken);
        } elseif ($r['code'] === 401) {
            spotify('GET', '/me', $accessToken);   // reuses the 401 → reconnect message
        }
        json_out(['success' => $r['code'] < 300, 'saved' => $action === 'save']);
    }

    case 'recently_played': {
        $r = spotify('GET', '/me/player/recently-played?limit=50', $accessToken);
        $items = [];
        foreach ($r['body']['items'] ?? [] as $it) {
            if ($t = trackOut($it['track'] ?? null)) $items[] = $t + ['played_at' => $it['played_at'] ?? ''];
        }
        $liked = likedMap(array_column($items, 'id'), $accessToken, $hasLibraryScope);
        foreach ($items as &$i) $i['liked'] = $liked[$i['id']] ?? null;
        unset($i);
        json_out(['success' => true, 'items' => $items]);
    }

    case 'top_artists': {
        $range = in_array($_POST['range'] ?? '', ['short_term', 'medium_term', 'long_term'], true) ? $_POST['range'] : 'short_term';
        $r = spotify('GET', "/me/top/artists?limit=12&time_range={$range}", $accessToken);
        json_out(['success' => true, 'items' => array_map(static fn($a) => [
            'name'   => $a['name'] ?? '',
            'image'  => $a['images'][1]['url'] ?? ($a['images'][0]['url'] ?? ''),
            'url'    => $a['external_urls']['spotify'] ?? '',
            'uri'    => $a['uri'] ?? '',
            'genres' => array_slice($a['genres'] ?? [], 0, 2),
        ], $r['body']['items'] ?? [])]);
    }

    case 'top_tracks': {
        $range = in_array($_POST['range'] ?? '', ['short_term', 'medium_term', 'long_term'], true) ? $_POST['range'] : 'short_term';
        $r = spotify('GET', "/me/top/tracks?limit=20&time_range={$range}", $accessToken);
        $items = array_values(array_filter(array_map('trackOut', $r['body']['items'] ?? [])));
        $liked = likedMap(array_column($items, 'id'), $accessToken, $hasLibraryScope);
        foreach ($items as &$i) $i['liked'] = $liked[$i['id']] ?? null;
        unset($i);
        json_out(['success' => true, 'items' => $items]);
    }

    case 'playlists': {
        $r = spotify('GET', '/me/playlists?limit=30', $accessToken);
        json_out(['success' => true, 'items' => array_map(static fn($p) => [
            'id'     => $p['id'] ?? '',
            'uri'    => $p['uri'] ?? '',
            'name'   => $p['name'] ?? '',
            'image'  => $p['images'][0]['url'] ?? '',
            // Feb 2026 renamed `tracks` to `items` on playlist objects.
            'tracks' => $p['items']['total'] ?? $p['tracks']['total'] ?? 0,
            'url'    => $p['external_urls']['spotify'] ?? '',
        ], array_values(array_filter($r['body']['items'] ?? []))) ]);
    }

    case 'play': {
        $uri      = (string)($_POST['playlist_uri'] ?? '');
        $trackUri = (string)($_POST['track_uri'] ?? '');
        $deviceId = (string)($_POST['device_id'] ?? '');
        $body = [];
        if (preg_match('/^spotify:track:[A-Za-z0-9]{22}$/', $trackUri)) $body['uris'] = [$trackUri];
        elseif (preg_match('/^spotify:(playlist|album|artist):[A-Za-z0-9]{22}$/', $uri)) $body['context_uri'] = $uri;
        $path = '/me/player/play' . (preg_match('/^[A-Za-z0-9]{10,64}$/', $deviceId) ? '?device_id=' . $deviceId : '');
        spotify('PUT', $path, $accessToken, $body ? ['json' => $body] : []);
        json_out(['success' => true]);
    }

    case 'pause': {
        $deviceId = (string)($_POST['device_id'] ?? '');
        spotify('PUT', '/me/player/pause' . (preg_match('/^[A-Za-z0-9]{10,64}$/', $deviceId) ? '?device_id=' . $deviceId : ''), $accessToken);
        json_out(['success' => true]);
    }

    case 'get_token':
        // The Web Playback SDK runs in the browser by Spotify's design and needs
        // a token there. Short-lived (≤1 h), this user's own, refreshed server-side.
        json_out(['success' => true, 'access_token' => $accessToken]);

    default: {
        // Currently playing — the dashboard widget's poller.
        $r = spotify('GET', '/me/player/currently-playing', $accessToken);
        if ($r['code'] === 204 || empty($r['body']['item'])) json_out(['connected' => true, 'playing' => false]);
        $item = $r['body']['item'];
        json_out([
            'connected' => true,
            'playing'   => $r['body']['is_playing'] ?? false,
            'track'     => [
                'name'        => $item['name'] ?? '',
                'artist'      => implode(', ', array_column($item['artists'] ?? [], 'name')),
                'album'       => $item['album']['name'] ?? '',
                'art'         => $item['album']['images'][1]['url'] ?? ($item['album']['images'][0]['url'] ?? ''),
                'url'         => $item['external_urls']['spotify'] ?? '',
                'progress_ms' => $r['body']['progress_ms'] ?? 0,
                'duration_ms' => $item['duration_ms'] ?? 0,
            ],
        ]);
    }
}
