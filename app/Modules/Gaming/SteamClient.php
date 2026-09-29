<?php
/**
 * Server-side Steam client. The Web API key (STEAM_API_KEY) never leaves the
 * server: every Steam call from the browser goes through api/gaming.php.
 *
 * Two sources:
 *   - Steam Web API (api.steampowered.com, key required). Library, friends,
 *     achievements. Only public data — private profiles return empty.
 *   - Steam Store appdetails (store.steampowered.com, no key, unofficial
 *     ~200 requests / 5 min). Genres + categories only, cached for 30 days
 *     in steam_app_meta and fetched a few at a time.
 *
 * Nothing here invents data: a failed call returns null, never a guess.
 */

final class SteamClient
{
    /** Steam Store category ids Trackie cares about (stable across locales). */
    private const CAT_MULTIPLAYER = [1, 36, 47, 49];        // Multi-player, Online PvP, LAN PvP, PvP
    private const CAT_COOP        = [9, 38, 48, 39];        // Co-op, Online Co-op, LAN Co-op, Split Screen Co-op
    private const CAT_ONLINE_COOP = [38];
    private const CAT_CROSSPLAY   = [27];                   // Cross-Platform Multiplayer

    private const META_TTL_HIT   = 30 * 86400;
    private const META_TTL_MISS  = 7 * 86400;
    private const META_TTL_ERROR = 3600;

    /** HTTP status of the most recent Web API call (0 = no response). */
    private int $lastCode = 0;

    public function __construct(private string $apiKey) {}

    public static function fromEnv(): ?self
    {
        $key = (string)env('STEAM_API_KEY');
        return $key !== '' ? new self($key) : null;
    }

    /* ── HTTP ─────────────────────────────────────────────────────── */

    /** @return array{0:int,1:?array} [http status, decoded JSON or null] */
    private static function httpJson(string $url, int $timeout = 6): array
    {
        $ctx = stream_context_create(['http' => [
            'timeout' => $timeout, 'ignore_errors' => true,
            'header'  => "Accept: application/json\r\nUser-Agent: Trackie/1.0\r\n",
        ]]);
        $resp = @file_get_contents($url, false, $ctx);
        $code = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int)$m[1];
        }
        if ($resp === false || $resp === '') return [$code, null];
        $data = json_decode($resp, true);
        return [$code, is_array($data) ? $data : null];
    }

    /** Raw Web API GET. Returns null on network/HTTP failure. */
    public function api(string $path, array $params): ?array
    {
        $params['key']    = $this->apiKey;
        $params['format'] = 'json';
        [$code, $data] = self::httpJson("https://api.steampowered.com/{$path}/?" . http_build_query($params));
        $this->lastCode = $code;
        if ($data === null && $code !== 401) {
            // Never log the URL — it contains the key.
            error_log("Steam: {$path} failed (HTTP {$code})");
        }
        return $data;
    }

    /**
     * Cached Web API GET via provider_cache. $trim reduces the response to what
     * Trackie keeps; errors are cached briefly so a Steam outage isn't hammered.
     */
    private function cached(string $key, int $ttl, string $path, array $params, callable $trim): ?array
    {
        $useCache = tableExists('provider_cache');
        if ($useCache) {
            $row = fetchOne("SELECT status, payload, UNIX_TIMESTAMP(fetched_at) t FROM provider_cache WHERE cache_key=?", [$key]);
            if ($row) {
                $age = time() - (int)$row['t'];
                if ($row['status'] === 'ok' && $age < $ttl)   return json_decode((string)$row['payload'], true);
                if ($row['status'] === 'error' && $age < 300) return null;
            }
        }
        $raw = $this->api($path, $params);
        // 401 = the target profile hides this data: a real "private" answer.
        if ($raw === null && $this->lastCode === 401) $raw = [];
        $data = $raw === null ? null : $trim($raw);
        if ($useCache) {
            update(
                "INSERT INTO provider_cache (cache_key, status, payload) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE status=VALUES(status), payload=VALUES(payload), fetched_at=CURRENT_TIMESTAMP",
                [$key, $data === null ? 'error' : 'ok', $data === null ? null : json_encode($data, JSON_UNESCAPED_UNICODE)]
            );
        }
        return $data;
    }

    /* ── Web API ──────────────────────────────────────────────────── */

    public function resolveVanity(string $vanity): ?string
    {
        $r = $this->api('ISteamUser/ResolveVanityURL/v1', ['vanityurl' => $vanity])['response'] ?? null;
        return ($r && (int)($r['success'] ?? 0) === 1) ? (string)$r['steamid'] : null;
    }

    /**
     * Owned games, uncached (used by sync).
     * @return ?array{private:bool, games:array} null = request failed.
     * Steam returns {"response":{}} when "Game details" privacy is not public.
     */
    public function ownedGames(string $steamId): ?array
    {
        $res = $this->api('IPlayerService/GetOwnedGames/v1', [
            'steamid' => $steamId, 'include_appinfo' => 1, 'include_played_free_games' => 1,
        ]);
        if ($res === null) return null;
        $resp = $res['response'] ?? [];
        return ['private' => !array_key_exists('games', $resp), 'games' => $resp['games'] ?? []];
    }

    /**
     * A friend's library as appid => minutes, cached 6h.
     * @return ?array{private:bool, games:array<int,int>}
     */
    public function friendLibrary(string $steamId): ?array
    {
        return $this->cached("steam:owned:{$steamId}", 6 * 3600, 'IPlayerService/GetOwnedGames/v1',
            ['steamid' => $steamId, 'include_played_free_games' => 1],
            static function (array $res): array {
                $resp = $res['response'] ?? [];
                $games = [];
                foreach ($resp['games'] ?? [] as $g) $games[(int)$g['appid']] = (int)($g['playtime_forever'] ?? 0);
                return ['private' => !array_key_exists('games', $resp), 'games' => $games];
            });
    }

    /**
     * Friend SteamIDs, cached 6h. ['private' => true] when the friends list
     * is hidden (Steam answers 401 with an empty body in that case).
     */
    public function friendIds(string $steamId): ?array
    {
        return $this->cached("steam:friends:{$steamId}", 6 * 3600, 'ISteamUser/GetFriendList/v1',
            ['steamid' => $steamId, 'relationship' => 'friend'],
            static fn(array $res): array => [
                'private' => !isset($res['friendslist']),
                'ids'     => array_values(array_map(static fn($f) => (string)$f['steamid'], $res['friendslist']['friends'] ?? [])),
            ]);
    }

    /** Player summaries (name, avatar, visibility), cached 6h, max 100 ids. */
    public function summaries(array $steamIds): array
    {
        $steamIds = array_slice(array_values(array_unique($steamIds)), 0, 100);
        if (!$steamIds) return [];
        sort($steamIds);
        $data = $this->cached('steam:summ:' . md5(implode(',', $steamIds)), 6 * 3600, 'ISteamUser/GetPlayerSummaries/v2',
            ['steamids' => implode(',', $steamIds)],
            static function (array $res): array {
                $out = [];
                foreach ($res['response']['players'] ?? [] as $p) {
                    $out[(string)$p['steamid']] = [
                        'name'   => (string)($p['personaname'] ?? 'Steam user'),
                        'avatar' => (string)($p['avatarmedium'] ?? ''),
                        // 3 = public profile; anything else hides the library.
                        'public' => (int)($p['communityvisibilitystate'] ?? 1) === 3,
                    ];
                }
                return $out;
            });
        return $data ?? [];
    }

    /**
     * Achievement progress for one game: ['done'=>n,'total'=>m], or
     * ['done'=>0,'total'=>0] when the game has no achievements, or null
     * when Steam wouldn't say (private stats / request failed).
     */
    public function achievements(string $steamId, int $appid): ?array
    {
        [$code, $res] = self::httpJson('https://api.steampowered.com/ISteamUserStats/GetPlayerAchievements/v1/?'
            . http_build_query(['key' => $this->apiKey, 'steamid' => $steamId, 'appid' => $appid]), 5);
        $ps = $res['playerstats'] ?? null;
        if (!$ps) return null;
        if (!($ps['success'] ?? false)) {
            // "Requested app has no stats" is a real answer, not a failure.
            return stripos((string)($ps['error'] ?? ''), 'no stats') !== false ? ['done' => 0, 'total' => 0] : null;
        }
        $list = $ps['achievements'] ?? [];
        $done = count(array_filter($list, static fn($a) => (int)($a['achieved'] ?? 0) === 1));
        return ['done' => $done, 'total' => count($list)];
    }

    /* ── Store metadata ──────────────────────────────────────────── */

    /**
     * Genres/categories for the given appids from steam_app_meta, fetching at
     * most $maxFetch missing/stale ones from the Store this call (rate limit).
     * @return array<int,array> appid => meta row (only apps with known data)
     */
    public static function appMeta(array $appids, int $maxFetch = 12): array
    {
        $appids = array_values(array_unique(array_filter(array_map('intval', $appids))));
        if (!$appids || !tableExists('steam_app_meta')) return [];

        $in   = implode(',', array_fill(0, count($appids), '?'));
        $rows = fetchAll("SELECT *, UNIX_TIMESTAMP(fetched_at) t FROM steam_app_meta WHERE appid IN ($in)", $appids);
        $have = array_column($rows, null, 'appid');

        $fetched = 0;
        foreach ($appids as $id) {
            $row = $have[$id] ?? null;
            if ($row) {
                $ttl = ['hit' => self::META_TTL_HIT, 'miss' => self::META_TTL_MISS][$row['status']] ?? self::META_TTL_ERROR;
                if (time() - (int)$row['t'] < $ttl) continue;
            }
            if ($fetched >= $maxFetch) continue;
            $fetched++;
            $have[$id] = self::fetchStoreMeta($id);
        }
        return array_filter($have, static fn($r) => ($r['status'] ?? '') === 'hit');
    }

    /** Count of the given appids with no fresh metadata yet (for "still loading" notes). */
    public static function metaPending(array $appids): int
    {
        $appids = array_values(array_unique(array_filter(array_map('intval', $appids))));
        if (!$appids || !tableExists('steam_app_meta')) return count($appids);
        $in = implode(',', array_fill(0, count($appids), '?'));
        $known = (int)(fetchOne("SELECT COUNT(*) n FROM steam_app_meta WHERE appid IN ($in) AND status IN ('hit','miss')", $appids)['n'] ?? 0);
        return max(0, count($appids) - $known);
    }

    private static function fetchStoreMeta(int $appid): array
    {
        [$code, $res] = self::httpJson('https://store.steampowered.com/api/appdetails?' . http_build_query([
            'appids' => $appid, 'filters' => 'genres,categories', 'l' => 'english',
        ]), 5);
        $entry = $res[(string)$appid] ?? null;

        $row = ['appid' => $appid, 'status' => 'error', 'genres' => null, 'categories' => null,
                'multiplayer' => 0, 'coop' => 0, 'online_coop' => 0, 'crossplay' => 0];
        if ($entry !== null) {
            if (!($entry['success'] ?? false)) {
                $row['status'] = 'miss';      // delisted / region-locked / not a store app
            } else {
                $d      = $entry['data'] ?? [];
                $genres = array_map(static fn($g) => (string)$g['description'], $d['genres'] ?? []);
                $cats   = $d['categories'] ?? [];
                $catIds = array_map(static fn($c) => (int)$c['id'], $cats);
                $any    = static fn(array $want) => (int)(bool)array_intersect($want, $catIds);
                $row = [
                    'appid'       => $appid, 'status' => 'hit',
                    'genres'      => mb_substr(implode(', ', $genres), 0, 255),
                    'categories'  => mb_substr(implode(', ', array_map(static fn($c) => (string)$c['description'], $cats)), 0, 600),
                    'multiplayer' => $any(self::CAT_MULTIPLAYER) ?: $any(self::CAT_COOP),
                    'coop'        => $any(self::CAT_COOP),
                    'online_coop' => $any(self::CAT_ONLINE_COOP),
                    'crossplay'   => $any(self::CAT_CROSSPLAY),
                ];
            }
        } elseif ($code === 429) {
            error_log('Steam Store: rate limited');
        }
        update(
            "INSERT INTO steam_app_meta (appid,status,genres,categories,multiplayer,coop,online_coop,crossplay)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE status=VALUES(status), genres=VALUES(genres), categories=VALUES(categories),
               multiplayer=VALUES(multiplayer), coop=VALUES(coop), online_coop=VALUES(online_coop),
               crossplay=VALUES(crossplay), fetched_at=CURRENT_TIMESTAMP",
            [$appid, $row['status'], $row['genres'], $row['categories'],
             $row['multiplayer'], $row['coop'], $row['online_coop'], $row['crossplay']]
        );
        return $row;
    }
}
