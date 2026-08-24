<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();
verify_csrf();

$uid    = currentUserId();
$action = sanitizeInput($_POST['action'] ?? '');

/* ── Steam Web API helpers ────────────────────────────────────────
   Same defensive style as config/weather.php: @file_get_contents with a
   short timeout, never throw, never fabricate data on failure. */
function steamApiGet(string $apiKey, string $path, array $params): ?array {
    $params['key']    = $apiKey;
    $params['format'] = 'json';
    $url = "https://api.steampowered.com/{$path}/?" . http_build_query($params);
    $resp = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 6]]));
    if (!$resp) return null;
    $data = json_decode($resp, true);
    return is_array($data) ? $data : null;
}

function steamResolveVanity(string $apiKey, string $vanity): ?string {
    $res = steamApiGet($apiKey, 'ISteamUser/ResolveVanityURL/v1', ['vanityurl' => $vanity]);
    $r = $res['response'] ?? null;
    return ($r && (int)($r['success'] ?? 0) === 1) ? $r['steamid'] : null;
}

/**
 * Pull owned games + playtime from Steam and upsert into `games`.
 * Never touches status/rating/notes on an existing row — those are the
 * user's own shelf categorization, Steam only knows playtime. Returns null
 * if the Steam API call itself failed (network/key/etc — a real error);
 * otherwise ['count' => N, 'private' => bool].
 *
 * Steam quietly returns {"response":{}} (no "games" key, but no error either)
 * when the profile's GAME DETAILS privacy setting is private. That's a
 * distinct, common, valid state — not a sync failure — so it's surfaced to
 * the user as "this profile's games list is private", not "sync failed".
 */
function steamSyncGames(int $uid, string $apiKey, string $steamId): ?array {
    $res = steamApiGet($apiKey, 'IPlayerService/GetOwnedGames/v1', [
        'steamid' => $steamId, 'include_appinfo' => 1, 'include_played_free_games' => 1,
    ]);
    if ($res === null) return null; // the HTTP/API call itself failed
    $private = !array_key_exists('games', $res['response'] ?? []);
    $owned = $res['response']['games'] ?? [];

    foreach ($owned as $g) {
        $appid   = (int)$g['appid'];
        $hours   = round(((int)($g['playtime_forever'] ?? 0)) / 60, 1);
        $cover   = "https://cdn.akamai.steamstatic.com/steam/apps/{$appid}/header.jpg";
        $existing = fetchOne("SELECT id, status FROM games WHERE user_id=? AND steam_appid=?", [$uid, $appid]);

        if ($existing) {
            update(
                "UPDATE games SET title=?, hours_played=?, cover_url=? WHERE id=?",
                [sanitizeInput($g['name'] ?? 'Unknown'), $hours, $cover, $existing['id']]
            );
        } else {
            // First time we've seen this game: default shelf reflects whether
            // they've actually put time into it, not an arbitrary guess.
            $status = $hours > 0 ? 'playing' : 'backlog';
            insert(
                "INSERT INTO games (user_id,title,platform,steam_appid,cover_url,status,hours_played)
                 VALUES (?,?,?,?,?,?,?)",
                [$uid, sanitizeInput($g['name'] ?? 'Unknown'), 'Steam', $appid, $cover, $status, $hours]
            );
        }
    }
    return ['count' => count($owned), 'private' => $private];
}

switch ($action) {

    case 'add':
        $title = sanitizeInput($_POST['title'] ?? '');
        if (!$title) json_out(['success' => false, 'error' => 'Title is required.'], 422);
        $id = insert(
            "INSERT INTO games (user_id,title,platform,status) VALUES (?,?,?,?)",
            [$uid, $title, sanitizeInput($_POST['platform'] ?? '') ?: null, sanitizeInput($_POST['status'] ?? 'backlog')]
        );
        json_out(['success' => true, 'id' => $id]);

    case 'update_status':
        $id = (int)($_POST['item_id'] ?? 0);
        $status = sanitizeInput($_POST['status'] ?? '');
        if (!in_array($status, ['wishlist','backlog','playing','completed'], true)) json_out(['success' => false, 'error' => 'Invalid status.'], 422);
        update("UPDATE games SET status=? WHERE id=? AND user_id=?", [$status, $id, $uid]);
        $newAch = [];
        if ($status === 'completed') {
            require_once '../includes/gamification.php';
            $newAch = function_exists('checkAchievements') ? checkAchievements($uid) : [];
        }
        json_out(['success' => true, 'newAchievements' => $newAch]);

    case 'rate':
        $id = (int)($_POST['item_id'] ?? 0);
        $rating = (int)($_POST['rating'] ?? 0);
        if ($rating < 1 || $rating > 5) json_out(['success' => false, 'error' => 'Rating must be 1-5.'], 422);
        update("UPDATE games SET rating=? WHERE id=? AND user_id=?", [$rating, $id, $uid]);
        json_out(['success' => true]);

    case 'delete':
        $id = (int)($_POST['item_id'] ?? 0);
        delete("DELETE FROM games WHERE id=? AND user_id=?", [$id, $uid]);
        json_out(['success' => true]);

    /* ── Steam sync (real owned-games/playtime via the Web API) ─────────
       auth:apikey in the integrations registry — one app-level key, each
       user just supplies their own SteamID64/vanity URL (no OAuth; Steam's
       Web API doesn't need per-user tokens for public-profile game data). */

    case 'steam_connect':
        $apiKey = env('STEAM_API_KEY');
        if (!$apiKey) json_out(['success' => false, 'error' => 'Steam isn\'t configured yet.'], 400);

        $raw = trim(sanitizeInput($_POST['steam_id'] ?? ''));
        if (!$raw) json_out(['success' => false, 'error' => 'Enter your SteamID64 or profile URL.'], 422);

        // Accept a bare SteamID64, a /profiles/<id> URL, or a /id/<vanity> URL.
        $raw = preg_replace('#^https?://(www\.)?steamcommunity\.com/#i', '', $raw);
        $raw = trim($raw, '/');
        if (preg_match('#^profiles/(\d{17})#', $raw, $m)) {
            $steamId = $m[1];
        } elseif (preg_match('#^id/([^/]+)#', $raw, $m)) {
            $steamId = steamResolveVanity($apiKey, $m[1]);
        } elseif (preg_match('/^\d{17}$/', $raw)) {
            $steamId = $raw;
        } else {
            $steamId = steamResolveVanity($apiKey, $raw);
        }
        if (!$steamId) json_out(['success' => false, 'error' => 'Could not find that Steam profile. Check the URL/ID and that your profile is public.'], 422);

        $summary = steamApiGet($apiKey, 'ISteamUser/GetPlayerSummaries/v2', ['steamids' => $steamId]);
        $player  = $summary['response']['players'][0] ?? null;
        if (!$player) json_out(['success' => false, 'error' => 'That Steam profile could not be verified — it may be private.'], 422);

        insert(
            "INSERT INTO user_integrations (user_id,provider,external_id,sync_status,last_sync)
             VALUES (?,'steam',?,'ok',NOW())
             ON DUPLICATE KEY UPDATE external_id=VALUES(external_id), sync_status='ok', last_sync=NOW(), last_error=NULL",
            [$uid, $steamId]
        );

        $sync = steamSyncGames($uid, $apiKey, $steamId);
        if ($sync !== null && $sync['private']) {
            update("UPDATE user_integrations SET last_error='Games list is private' WHERE user_id=? AND provider='steam'", [$uid]);
        }
        json_out([
            'success' => true, 'persona' => $player['personaname'] ?? null,
            'synced'  => $sync['count'] ?? 0, 'private' => $sync['private'] ?? false,
        ]);

    case 'steam_sync':
        $apiKey = env('STEAM_API_KEY');
        $link = fetchOne("SELECT external_id FROM user_integrations WHERE user_id=? AND provider='steam'", [$uid]);
        if (!$apiKey || !$link) json_out(['success' => false, 'error' => 'Steam isn\'t connected yet.'], 400);

        $sync = steamSyncGames($uid, $apiKey, $link['external_id']);
        if ($sync === null) {
            update("UPDATE user_integrations SET sync_status='error', last_error='Steam API request failed' WHERE user_id=? AND provider='steam'", [$uid]);
            json_out(['success' => false, 'error' => 'Could not reach Steam right now. Try again shortly.'], 502);
        }
        update(
            "UPDATE user_integrations SET sync_status='ok', last_sync=NOW(), last_error=? WHERE user_id=? AND provider='steam'",
            [$sync['private'] ? 'Games list is private' : null, $uid]
        );
        json_out(['success' => true, 'synced' => $sync['count'], 'private' => $sync['private']]);

    case 'steam_disconnect':
        delete("DELETE FROM user_integrations WHERE user_id=? AND provider='steam'", [$uid]);
        json_out(['success' => true]);

    default:
        json_out(['success' => false, 'error' => 'Unknown action.'], 400);
}
