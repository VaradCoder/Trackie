<?php
/**
 * Gaming features built on the user's own data + their Steam link:
 *   sync()          library, last played, 2-week playtime, daily snapshot
 *   refreshAchievements()  a few games per call (one Steam request each)
 *   shelves()       Most Played / Currently Playing / Completed / Dropped
 *   wrapped()       Gaming Wrap for a year or a single month
 *
 * Honesty rules:
 *   - Steam keeps NO per-day playtime history. Day/weekday/month patterns come
 *     only from snapshots Trackie took at each sync, and are labelled with the
 *     date tracking started. A "day" is playtime recorded since the previous
 *     day's sync; multi-day gaps count toward totals but not weekday stats.
 *   - Steam only reports the LAST time a game was played, so "played in YEAR"
 *     means "last played in YEAR".
 *   - No game-length estimates: Trackie has no licensed source for them.
 */

require_once __DIR__ . '/SteamClient.php';

final class GamingService
{
    public function __construct(private int $uid, private ?SteamClient $steam = null) {}

    public function steamId(): ?string
    {
        $row = fetchOne("SELECT external_id FROM user_integrations WHERE user_id=? AND provider='steam'", [$this->uid]);
        return $row['external_id'] ?? null;
    }

    /* ── Sync ─────────────────────────────────────────────────────── */

    /** @return ?array{count:int, private:bool} null when the Steam request failed. */
    public function sync(string $steamId): ?array
    {
        $owned = $this->steam?->ownedGames($steamId);
        if ($owned === null) return null;

        $existing = array_column(fetchAll(
            "SELECT id, steam_appid FROM games WHERE user_id=? AND steam_appid IS NOT NULL", [$this->uid]
        ), 'id', 'steam_appid');

        foreach ($owned['games'] as $g) {
            $appid = (int)$g['appid'];
            $mins  = (int)($g['playtime_forever'] ?? 0);
            $hours = round($mins / 60, 1);
            $title = sanitizeInput($g['name'] ?? 'Unknown');
            $cover = "https://cdn.akamai.steamstatic.com/steam/apps/{$appid}/header.jpg";
            $last  = !empty($g['rtime_last_played']) ? date('Y-m-d H:i:s', (int)$g['rtime_last_played']) : null;
            // Steam omits playtime_2weeks when it is zero.
            $twoWk = (int)($g['playtime_2weeks'] ?? 0);

            if (isset($existing[$appid])) {
                // Never touch status/rating/notes: that's the user's own shelf.
                update(
                    "UPDATE games SET title=?, hours_played=?, cover_url=?, last_played=?, playtime_2weeks=? WHERE id=?",
                    [$title, $hours, $cover, $last, $twoWk, $existing[$appid]]
                );
            } else {
                insert(
                    "INSERT INTO games (user_id,title,platform,steam_appid,cover_url,status,hours_played,last_played,playtime_2weeks)
                     VALUES (?,?,?,?,?,?,?,?,?)",
                    [$this->uid, $title, 'Steam', $appid, $cover, $hours > 0 ? 'playing' : 'backlog', $hours, $last, $twoWk]
                );
            }
        }
        if (!$owned['private'] && $owned['games']) $this->snapshot($owned['games']);
        return ['count' => count($owned['games']), 'private' => $owned['private']];
    }

    /**
     * Today's snapshot: a row per game whose cumulative playtime changed since
     * its last snapshot, plus an appid-0 marker meaning "a sync happened today".
     */
    private function snapshot(array $games): void
    {
        if (!tableExists('steam_playtime_snapshots')) return;
        $today = date('Y-m-d');
        $prev = array_column(fetchAll(
            "SELECT s.steam_appid, s.playtime_min FROM steam_playtime_snapshots s
               JOIN (SELECT steam_appid, MAX(snap_date) d FROM steam_playtime_snapshots
                      WHERE user_id=? AND steam_appid>0 AND snap_date<? GROUP BY steam_appid) m
                 ON m.steam_appid=s.steam_appid AND m.d=s.snap_date
              WHERE s.user_id=?",
            [$this->uid, $today, $this->uid]
        ), 'playtime_min', 'steam_appid');

        $lastSync = fetchOne(
            "SELECT MAX(snap_date) d FROM steam_playtime_snapshots WHERE user_id=? AND steam_appid=0 AND snap_date<?",
            [$this->uid, $today]
        )['d'] ?? null;

        $total = 0;
        foreach ($games as $g) {
            $mins = (int)($g['playtime_forever'] ?? 0);
            $total += $mins;
            $appid = (int)$g['appid'];
            if ($mins <= 0 || (isset($prev[$appid]) && (int)$prev[$appid] === $mins)) continue;
            if (!isset($prev[$appid]) && $lastSync) {
                // First time this game has playtime since tracking began (new
                // purchase, or it was unplayed at earlier syncs). Give it a
                // baseline at the previous sync: 0 only when Steam says it was
                // played after that sync and the time fits in the gap —
                // otherwise its existing time is history, not new play.
                $since  = strtotime($lastSync);
                $played = (int)($g['rtime_last_played'] ?? 0) >= $since && $mins <= (time() - $since) / 60;
                $this->putSnapshot($appid, $lastSync, $played ? 0 : $mins);
            }
            $this->putSnapshot($appid, $today, $mins);
        }
        $this->putSnapshot(0, $today, $total);
    }

    private function putSnapshot(int $appid, string $date, int $mins): void
    {
        update(
            "INSERT INTO steam_playtime_snapshots (user_id, steam_appid, snap_date, playtime_min) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE playtime_min=VALUES(playtime_min)",
            [$this->uid, $appid, $date, $mins]
        );
    }

    /**
     * Refresh achievement counts for up to $limit played games whose counts
     * are missing or older than their last play (or 7 days). One Steam call each.
     */
    public function refreshAchievements(string $steamId, int $limit = 5): array
    {
        $due = fetchAll(
            "SELECT id, steam_appid FROM games
              WHERE user_id=? AND steam_appid IS NOT NULL AND hours_played > 0
                AND (ach_synced_at IS NULL OR ach_synced_at < last_played OR ach_synced_at < NOW() - INTERVAL 7 DAY)
              ORDER BY playtime_2weeks DESC, hours_played DESC LIMIT " . max(1, min(10, $limit)),
            [$this->uid]
        );
        $updated = 0;
        foreach ($due as $g) {
            $a = $this->steam?->achievements($steamId, (int)$g['steam_appid']);
            // null = Steam wouldn't say (private stats / failure): mark checked, keep counts unknown.
            update(
                "UPDATE games SET ach_done=?, ach_total=?, ach_synced_at=NOW() WHERE id=? AND user_id=?",
                [$a['done'] ?? null, $a['total'] ?? null, $g['id'], $this->uid]
            );
            if ($a !== null) $updated++;
        }
        $remaining = (int)(fetchOne(
            "SELECT COUNT(*) n FROM games WHERE user_id=? AND steam_appid IS NOT NULL AND hours_played > 0
                AND (ach_synced_at IS NULL OR ach_synced_at < last_played OR ach_synced_at < NOW() - INTERVAL 7 DAY)",
            [$this->uid]
        )['n'] ?? 0);
        return ['checked' => count($due), 'updated' => $updated, 'remaining' => $remaining];
    }

    /* ── Genre affinity (shared by Next Up + Wrapped) ─────────────── */

    /** @return array{genres: array<string,float>, total: float, covered: float} hours by genre */
    private function genreHours(array $games, array $meta): array
    {
        $byGenre = [];
        $total = $covered = 0.0;
        foreach ($games as $g) {
            $h = (float)$g['hours_played'];
            if ($h <= 0) continue;
            $total += $h;
            $m = $meta[(int)$g['steam_appid']] ?? null;
            if (!$m || !$m['genres']) continue;
            $covered += $h;
            foreach (self::genreList($m['genres']) as $genre) $byGenre[$genre] = ($byGenre[$genre] ?? 0) + $h;
        }
        arsort($byGenre);
        return ['genres' => $byGenre, 'total' => $total, 'covered' => $covered];
    }

    /** Steam lists "Free To Play"/"Early Access" as genres; they say nothing about taste. */
    private static function genreList(?string $csv): array
    {
        $skip = ['Free To Play', 'Free to Play', 'Early Access', 'Indie', 'Massively Multiplayer'];
        return array_values(array_diff(array_filter(array_map('trim', explode(',', (string)$csv))), $skip));
    }

    private static function genreLabel(?array $meta): ?string
    {
        $g = $meta ? array_slice(self::genreList($meta['genres']), 0, 3) : [];
        return $g ? implode(', ', $g) : null;
    }

    /* ── Shelves ─────────────────────────────────────────────────── */

    /**
     * The page's four lists, organised from what Steam reports plus the
     * user's own status choices:
     *   most_played — every game with playtime, by hours
     *   playing     — not completed/dropped, AND played in the last 2 weeks or
     *                 30 days (Steam), or marked Playing by hand (non-Steam games)
     *   completed / dropped — the user's call; Steam can't know
     */
    public function shelves(): array
    {
        $games = fetchAll(
            "SELECT id, title, platform, steam_appid, cover_url, status, hours_played, last_played, playtime_2weeks,
                    ach_done, ach_total, rating, completed_at, dropped_at
               FROM games WHERE user_id=?", [$this->uid]
        );
        $now = time();
        // Genres from the cached Store metadata only — never a live call on render.
        $meta = SteamClient::appMeta(array_column($games, 'steam_appid'), 0);
        $playing = $most = $completed = $dropped = [];
        foreach ($games as $g) {
            $card = self::card($g) + [
                'genres' => self::genreLabel($meta[(int)$g['steam_appid']] ?? null),
                'platform' => $g['platform'], 'rating' => $g['rating'] !== null ? (int)$g['rating'] : null,
                'last_played' => $g['last_played'] ? substr($g['last_played'], 0, 10) : null,
                'mins_2weeks' => (int)$g['playtime_2weeks'],
                'ach_done' => $g['ach_total'] !== null ? (int)$g['ach_done'] : null,
                'ach_total' => $g['ach_total'] !== null ? (int)$g['ach_total'] : null,
                'completed_at' => $g['completed_at'] ? substr($g['completed_at'], 0, 10) : null,
                'dropped_at' => $g['dropped_at'] ? substr($g['dropped_at'], 0, 10) : null,
            ];
            if ((float)$g['hours_played'] > 0) $most[] = $card;
            if ($g['status'] === 'completed') { $completed[] = $card; continue; }
            if ($g['status'] === 'dropped')   { $dropped[] = $card; continue; }
            $recent = $g['last_played'] && strtotime($g['last_played']) >= $now - 30 * 86400;
            $manualPlaying = !$g['steam_appid'] && $g['status'] === 'playing';
            if ((int)$g['playtime_2weeks'] > 0 || $recent || $manualPlaying) $playing[] = $card;
        }
        usort($most, static fn($a, $b) => $b['hours'] <=> $a['hours']);
        usort($playing, static fn($a, $b) => [$b['mins_2weeks'], (string)$b['last_played']] <=> [$a['mins_2weeks'], (string)$a['last_played']]);
        usort($completed, static fn($a, $b) => strcmp((string)$b['completed_at'], (string)$a['completed_at']));
        usort($dropped, static fn($a, $b) => strcmp((string)$b['dropped_at'], (string)$a['dropped_at']));
        $total = array_sum(array_column($most, 'hours'));
        foreach ($most as &$m) $m['share'] = $total > 0 ? round($m['hours'] / $total * 100, 1) : 0;
        unset($m);
        return [
            'most_played' => array_slice($most, 0, 60),
            'playing'     => $playing,
            'completed'   => $completed,
            'dropped'     => $dropped,
            'counts'      => ['games' => count($games), 'played' => count($most), 'playing' => count($playing),
                              'completed' => count($completed), 'dropped' => count($dropped), 'hours' => round($total, 1)],
        ];
    }

    private static function card(array $g): array
    {
        return [
            'id' => (int)$g['id'], 'title' => $g['title'], 'cover_url' => $g['cover_url'],
            'status' => $g['status'], 'hours' => (float)$g['hours_played'],
            'steam_appid' => $g['steam_appid'] ? (int)$g['steam_appid'] : null,
        ];
    }

    /* ── Wrapped ─────────────────────────────────────────────────── */

    public function years(): array
    {
        $years = array_map('intval', array_column(fetchAll(
            "SELECT DISTINCT YEAR(last_played) y FROM games WHERE user_id=? AND last_played IS NOT NULL
             UNION SELECT DISTINCT YEAR(completed_at) FROM games WHERE user_id=? AND completed_at IS NOT NULL
             UNION SELECT DISTINCT YEAR(dropped_at) FROM games WHERE user_id=? AND dropped_at IS NOT NULL",
            [$this->uid, $this->uid, $this->uid]
        ), 'y'));
        $years[] = (int)date('Y');
        $years = array_values(array_unique(array_filter($years)));
        rsort($years);
        return $years;
    }

    /**
     * Gaming Wrap for a year, or one month of it ($month 1–12). Period stats
     * use dates Trackie really has: Steam's last-played date, when you marked
     * a game completed/dropped, and playtime from Trackie's own daily
     * snapshots. Lifetime numbers are labelled as such.
     */
    public function wrapped(int $year, int $month = 0): array
    {
        $month = $month >= 1 && $month <= 12 ? $month : 0;
        $inPeriod = static fn(?string $d) => $d && (int)substr($d, 0, 4) === $year && (!$month || (int)substr($d, 5, 2) === $month);
        $games = fetchAll(
            "SELECT id, title, steam_appid, cover_url, status, hours_played, last_played, completed_at, dropped_at, ach_done, ach_total
               FROM games WHERE user_id=?", [$this->uid]
        );
        $totalHours = array_sum(array_map(static fn($g) => (float)$g['hours_played'], $games));
        $playedGames = array_values(array_filter($games, static fn($g) => (float)$g['hours_played'] > 0));
        usort($playedGames, static fn($a, $b) => $b['hours_played'] <=> $a['hours_played']);

        $top = array_map(static fn($g) => self::card($g) + [
            'share' => $totalHours > 0 ? (int)round($g['hours_played'] / $totalHours * 100) : 0,
        ], array_slice($playedGames, 0, 5));

        $lastInYear = array_values(array_filter($games, static fn($g) => $inPeriod($g['last_played'])));
        usort($lastInYear, static fn($a, $b) => strcmp($b['last_played'], $a['last_played']));
        $completed = array_values(array_filter($games, static fn($g) => $inPeriod($g['completed_at'])));
        $dropped   = array_values(array_filter($games, static fn($g) => $inPeriod($g['dropped_at'])));

        $meta = SteamClient::appMeta(array_column(array_slice($playedGames, 0, 25), 'steam_appid'), 12);
        $gh   = $this->genreHours($games, $meta);
        $genres = [];
        foreach (array_slice($gh['genres'], 0, 6, true) as $genre => $h) {
            $genres[] = ['genre' => $genre, 'hours' => round($h, 1), 'pct' => $gh['covered'] > 0 ? (int)round($h / $gh['covered'] * 100) : 0];
        }

        $library = [
            'owned'        => count($games),
            'hours'        => round($totalHours, 1),
            'played'       => count($playedGames),
            'never_played' => count($games) - count($playedGames),
        ];
        $tracked = $this->trackedPlaytime($year, $month);
        $personality = $this->personality($library, $top, $genres, count($completed), $tracked, $month ? 'this month' : 'this year');

        // Games played in the period: tracked playtime in it, or last played in it.
        $playedIds = array_flip(array_map('intval', array_column($tracked['top_all'], 'appid')));
        $playedInPeriod = count(array_filter($games, static fn($g) =>
            $inPeriod($g['last_played']) || ($g['steam_appid'] && isset($playedIds[(int)$g['steam_appid']]))));
        $achDone = $achTotal = $perfect = 0;
        foreach ($games as $g) {
            if ($g['ach_total'] === null) continue;
            $achDone += (int)$g['ach_done']; $achTotal += (int)$g['ach_total'];
            if ((int)$g['ach_total'] > 0 && (int)$g['ach_done'] === (int)$g['ach_total']) $perfect++;
        }
        unset($tracked['top_all']);

        return [
            'year'          => $year,
            'month'         => $month,
            'played_in_period' => $playedInPeriod,
            'dropped'       => array_map(static fn($g) => self::card($g) + ['dropped_at' => substr($g['dropped_at'], 0, 10)], $dropped),
            'achievements'  => ['unlocked' => $achDone, 'total' => $achTotal, 'perfect' => $perfect],
            'library'       => $library,
            'top'           => $top,
            'last_played_in_year' => array_map(static fn($g) => self::card($g) + ['last_played' => substr($g['last_played'], 0, 10)], array_slice($lastInYear, 0, 8)),
            'last_played_in_year_count' => count($lastInYear),
            'completed'     => array_map(static fn($g) => self::card($g) + ['completed_at' => substr($g['completed_at'], 0, 10)], $completed),
            'genres'        => $genres,
            'genre_coverage'=> $gh['total'] > 0 ? (int)round($gh['covered'] / $gh['total'] * 100) : 0,
            'tracked'       => $tracked,
            'personality'   => $personality,
        ];
    }

    /** Playtime measured from Trackie's own snapshots, for a year or one month of it. */
    private function trackedPlaytime(int $year, int $month = 0): array
    {
        $daysInMonth = $month ? (int)date('t', mktime(0, 0, 0, $month, 1, $year)) : 0;
        $empty = ['since' => null, 'sync_days' => 0, 'minutes' => 0, 'days_played' => 0,
                  'weekday' => array_fill(0, 7, 0), 'weekday_days' => 0, 'months' => array_fill(1, 12, 0),
                  'days' => $month ? array_fill(1, $daysInMonth, 0) : [],
                  'best_day' => null, 'streak' => 0, 'top' => [], 'top_all' => [], 'gap_minutes' => 0];
        $inPeriod = static fn(string $d) => (int)substr($d, 0, 4) === $year && (!$month || (int)substr($d, 5, 2) === $month);
        if (!tableExists('steam_playtime_snapshots')) return $empty;

        $first = fetchOne("SELECT MIN(snap_date) d FROM steam_playtime_snapshots WHERE user_id=? AND steam_appid=0", [$this->uid])['d'] ?? null;
        if (!$first) return $empty;
        $markers = array_column(fetchAll(
            "SELECT snap_date FROM steam_playtime_snapshots WHERE user_id=? AND steam_appid=0 ORDER BY snap_date", [$this->uid]
        ), 'snap_date');
        $prevMarker = [];
        foreach ($markers as $i => $d) $prevMarker[$d] = $markers[$i - 1] ?? null;

        $rows = fetchAll(
            "SELECT steam_appid, snap_date, playtime_min FROM steam_playtime_snapshots
              WHERE user_id=? AND steam_appid>0 ORDER BY steam_appid, snap_date", [$this->uid]
        );
        $out = $empty;
        $out['since'] = $first;
        $out['sync_days'] = count(array_filter($markers, $inPeriod));

        $perDay = $perGame = [];
        $lastVal = [];
        foreach ($rows as $r) {
            $app = (int)$r['steam_appid'];
            $d   = $r['snap_date'];
            $v   = (int)$r['playtime_min'];
            $pm  = $prevMarker[$d] ?? null;
            // A game's first row is always its baseline (see snapshot()).
            $delta = isset($lastVal[$app]) ? $v - $lastVal[$app] : 0;
            $lastVal[$app] = $v;
            if ($delta <= 0 || !$inPeriod($d)) continue;

            $gap = $pm ? (int)round((strtotime($d) - strtotime($pm)) / 86400) : 0;
            $out['minutes'] += $delta;
            $out['months'][(int)substr($d, 5, 2)] += $delta;
            if ($month) $out['days'][(int)substr($d, 8, 2)] += $delta;
            $perGame[$app] = ($perGame[$app] ?? 0) + $delta;
            if ($gap === 1) $perDay[$d] = ($perDay[$d] ?? 0) + $delta;
            else $out['gap_minutes'] += $delta;
        }

        foreach ($perDay as $d => $m) $out['weekday'][(int)date('w', strtotime($d))] += $m;
        $out['weekday_days'] = count($perDay);
        $out['days_played']  = count($perDay);
        if ($perDay) {
            arsort($perDay);
            $bd = array_key_first($perDay);
            $out['best_day'] = ['date' => $bd, 'minutes' => $perDay[$bd]];
            $out['streak'] = calculateStreaks(array_keys($perDay))['best'] ?? 0;
        }
        arsort($perGame);
        $titles = $perGame ? array_column(fetchAll(
            "SELECT steam_appid, title FROM games WHERE user_id=? AND steam_appid IN (" . implode(',', array_map('intval', array_keys($perGame))) . ")",
            [$this->uid]
        ), 'title', 'steam_appid') : [];
        foreach ($perGame as $app => $m) {
            $row = ['appid' => $app, 'title' => $titles[$app] ?? "App {$app}", 'minutes' => $m];
            $out['top_all'][] = $row;
            if (count($out['top']) < 5) $out['top'][] = $row;
        }
        return $out;
    }

    /** Rule-based label; every trait carries the numbers that triggered it. */
    private function personality(array $lib, array $top, array $genres, int $completed, array $tracked, string $periodLabel = 'this year'): array
    {
        $traits = [];
        $t1 = $top[0] ?? null;
        if ($t1 && $t1['share'] >= 50) {
            $traits[] = ['name' => 'The Devotee', 'icon' => 'fa-heart',
                         'why' => "{$t1['share']}% of all your playtime is in {$t1['title']}."];
        }
        if ($lib['played'] >= 10 && $t1 && $t1['share'] < 25) {
            $traits[] = ['name' => 'The Explorer', 'icon' => 'fa-compass',
                         'why' => "You've put time into {$lib['played']} games and no single one dominates."];
        }
        if ($lib['owned'] >= 10 && $lib['never_played'] / max(1, $lib['owned']) >= 0.4) {
            $traits[] = ['name' => 'The Collector', 'icon' => 'fa-box-archive',
                         'why' => "{$lib['never_played']} of {$lib['owned']} games haven't been launched yet."];
        }
        if ($completed >= 3) {
            $traits[] = ['name' => 'The Finisher', 'icon' => 'fa-flag-checkered',
                         'why' => "You marked {$completed} games completed {$periodLabel}."];
        }
        if (($genres[0]['pct'] ?? 0) >= 40) {
            $traits[] = ['name' => $genres[0]['genre'] . ' Specialist', 'icon' => 'fa-bullseye',
                         'why' => "{$genres[0]['genre']} games make up {$genres[0]['pct']}% of your categorised playtime."];
        }
        if ($tracked['weekday_days'] >= 14) {
            $wk = $tracked['weekday'][0] + $tracked['weekday'][6];
            $all = array_sum($tracked['weekday']);
            if ($all > 0 && $wk / $all >= 0.6) {
                $traits[] = ['name' => 'Weekend Warrior', 'icon' => 'fa-calendar-week',
                             'why' => round($wk / $all * 100) . '% of your tracked playtime falls on weekends.'];
            }
        }
        if (!$traits) {
            $traits[] = ['name' => 'Balanced Player', 'icon' => 'fa-scale-balanced',
                         'why' => 'No single pattern stands out in your library yet.'];
        }
        return ['primary' => $traits[0], 'traits' => $traits];
    }
}
