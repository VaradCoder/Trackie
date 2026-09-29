<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();
verify_csrf();

$uid    = currentUserId();
$action = sanitizeInput($_POST['action'] ?? '');

/* Steam calls live in app/Modules/Gaming (server-side; the key never
   reaches the browser). Every action below is scoped to $uid. */
require_once '../app/Modules/Gaming/GamingService.php';

function gamingService(int $uid): GamingService {
    return new GamingService($uid, SteamClient::fromEnv());
}

/** Linked SteamID for the user, or a JSON error response. */
function requireSteamLink(int $uid): string {
    $steamId = SteamClient::fromEnv() ? gamingService($uid)->steamId() : null;
    if (!$steamId) json_out(['success' => false, 'error' => 'Steam isn\'t connected yet.'], 400);
    return $steamId;
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
        // completed_at records WHEN it was finished (Wrapped "completed this year").
        update(
            "UPDATE games SET status=?,
                    completed_at = CASE WHEN ?='completed' THEN COALESCE(completed_at, NOW()) ELSE NULL END
              WHERE id=? AND user_id=?",
            [$status, $status, $id, $uid]
        );
        $newAch = [];
        $xp = null;
        if ($status === 'completed' && fetchOne("SELECT id FROM games WHERE id=? AND user_id=?", [$id, $uid])) {
            require_once '../includes/activity.php';
            // Once per game, however often it's toggled back and forth.
            $xp = recordActivity($uid, 'game_completed', 'game', $id);
            $newAch = function_exists('checkAchievements') ? checkAchievements($uid) : [];
        }
        if ($status !== 'completed') {
            require_once '../includes/activity.php';
            undoActivity($uid, 'game_completed', 'game', $id);
        }
        json_out(['success' => true, 'xp' => $xp, 'newAchievements' => $newAch]);

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
        $steam = SteamClient::fromEnv();
        if (!$steam) json_out(['success' => false, 'error' => 'Steam isn\'t configured yet.'], 400);

        $raw = trim(sanitizeInput($_POST['steam_id'] ?? ''));
        if (!$raw) json_out(['success' => false, 'error' => 'Enter your SteamID64 or profile URL.'], 422);

        // Accept a bare SteamID64, a /profiles/<id> URL, or a /id/<vanity> URL.
        $raw = preg_replace('#^https?://(www\.)?steamcommunity\.com/#i', '', $raw);
        $raw = trim($raw, '/');
        if (preg_match('#^profiles/(\d{17})#', $raw, $m)) {
            $steamId = $m[1];
        } elseif (preg_match('#^id/([^/]+)#', $raw, $m)) {
            $steamId = $steam->resolveVanity($m[1]);
        } elseif (preg_match('/^\d{17}$/', $raw)) {
            $steamId = $raw;
        } else {
            $steamId = $steam->resolveVanity($raw);
        }
        if (!$steamId) json_out(['success' => false, 'error' => 'Could not find that Steam profile. Check the URL/ID and that your profile is public.'], 422);

        $summary = $steam->api('ISteamUser/GetPlayerSummaries/v2', ['steamids' => $steamId]);
        $player  = $summary['response']['players'][0] ?? null;
        if (!$player) json_out(['success' => false, 'error' => 'That Steam profile could not be verified — it may be private.'], 422);

        insert(
            "INSERT INTO user_integrations (user_id,provider,external_id,sync_status,last_sync)
             VALUES (?,'steam',?,'ok',NOW())
             ON DUPLICATE KEY UPDATE external_id=VALUES(external_id), sync_status='ok', last_sync=NOW(), last_error=NULL",
            [$uid, $steamId]
        );

        $sync = (new GamingService($uid, $steam))->sync($steamId);
        if ($sync !== null && $sync['private']) {
            update("UPDATE user_integrations SET last_error='Games list is private' WHERE user_id=? AND provider='steam'", [$uid]);
        }
        json_out([
            'success' => true, 'persona' => $player['personaname'] ?? null,
            'synced'  => $sync['count'] ?? 0, 'private' => $sync['private'] ?? false,
        ]);

    case 'steam_sync':
        $steamId = requireSteamLink($uid);
        $sync = gamingService($uid)->sync($steamId);
        if ($sync === null) {
            update("UPDATE user_integrations SET sync_status='error', last_error='Steam API request failed' WHERE user_id=? AND provider='steam'", [$uid]);
            json_out(['success' => false, 'error' => 'Could not reach Steam right now. Try again shortly.'], 502);
        }
        update(
            "UPDATE user_integrations SET sync_status='ok', last_sync=NOW(), last_error=? WHERE user_id=? AND provider='steam'",
            [$sync['private'] ? 'Games list is private' : null, $uid]
        );
        json_out(['success' => true, 'synced' => $sync['count'], 'private' => $sync['private']]);

    /* ── Gaming V2: achievements, Next Up, Wrapped, Co-op ─────────── */

    case 'steam_achievements':
        // A few games per call (one Steam request each); the page calls again
        // while `remaining` > 0 instead of blocking on one long request.
        $steamId = requireSteamLink($uid);
        json_out(['success' => true] + gamingService($uid)->refreshAchievements($steamId, 5));

    case 'next_up':
        json_out(['success' => true] + gamingService($uid)->nextUp(6));

    case 'wrapped':
        $svc   = gamingService($uid);
        $years = $svc->years();
        $year  = (int)($_POST['year'] ?? 0);
        if (!in_array($year, $years, true)) $year = $years[0];
        json_out(['success' => true, 'years' => $years, 'wrapped' => $svc->wrapped($year)]);

    case 'coop_friends':
        $steamId = requireSteamLink($uid);
        $res = gamingService($uid)->friends($steamId);
        if ($res === null) json_out(['success' => false, 'error' => 'Could not reach Steam right now. Try again shortly.'], 502);
        json_out(['success' => true] + $res);

    case 'coop_match':
        $steamId = requireSteamLink($uid);
        $ids = array_values(array_filter(
            array_map('trim', explode(',', (string)($_POST['friends'] ?? ''))),
            static fn($id) => (bool)preg_match('/^\d{17}$/', $id)
        ));
        if (!$ids) json_out(['success' => false, 'error' => 'Pick at least one friend.'], 422);
        if (count($ids) > 5) json_out(['success' => false, 'error' => 'Compare up to 5 friends at a time.'], 422);
        json_out(['success' => true] + gamingService($uid)->coop($steamId, $ids));

    case 'steam_disconnect':
        delete("DELETE FROM user_integrations WHERE user_id=? AND provider='steam'", [$uid]);
        json_out(['success' => true]);

    default:
        json_out(['success' => false, 'error' => 'Unknown action.'], 400);
}
