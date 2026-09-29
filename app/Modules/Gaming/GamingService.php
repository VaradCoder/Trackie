<?php
/**
 * Gaming features built on the user's own data + their Steam link:
 *   sync()          library, last played, 2-week playtime, daily snapshot
 *   refreshAchievements()  a few games per call (one Steam request each)
 *   nextUp()        backlog ranking with the reasons shown
 *   wrapped()       year in review
 *   friends()/coop()  shared multiplayer games with Steam friends
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

    /* ── Next Up ─────────────────────────────────────────────────── */

    public function nextUp(int $limit = 6): array
    {
        $games = fetchAll(
            "SELECT id, title, platform, steam_appid, cover_url, status, hours_played, last_played,
                    playtime_2weeks, ach_done, ach_total, rating
               FROM games WHERE user_id=?", [$this->uid]
        );
        $candidates = array_filter($games, static fn($g) => in_array($g['status'], ['backlog', 'playing'], true));
        if (!$candidates) return ['items' => [], 'finished' => [], 'meta_pending' => 0, 'top_genres' => []];

        // Metadata for candidates and the games that define taste.
        $played = $games;
        usort($played, static fn($a, $b) => $b['hours_played'] <=> $a['hours_played']);
        $wanted = array_merge(
            array_column(array_slice($played, 0, 15), 'steam_appid'),
            array_column($candidates, 'steam_appid')
        );
        $meta = SteamClient::appMeta($wanted, 10);
        $gh   = $this->genreHours($games, $meta);
        $share = [];
        foreach ($gh['genres'] as $genre => $h) $share[$genre] = $gh['covered'] > 0 ? $h / $gh['covered'] : 0;

        $now = time();
        $items = $finished = [];
        foreach ($candidates as $g) {
            $score = 0.0;
            $why   = [];
            $hours = (float)$g['hours_played'];
            $p2w   = (int)$g['playtime_2weeks'];
            $days  = $g['last_played'] ? (int)floor(($now - strtotime($g['last_played'])) / 86400) : null;
            $achT  = (int)$g['ach_total'];
            $achD  = (int)$g['ach_done'];
            $pct   = $achT > 0 ? (int)round($achD / $achT * 100) : null;

            // All achievements unlocked: suggest closing it out instead.
            if ($pct === 100) { $finished[] = self::card($g) + ['reason' => "All {$achT} achievements unlocked"]; continue; }

            if ($p2w > 0) {
                $score += 30 + min(20, $p2w / 60 * 2);
                $why[] = 'Played ' . self::fmtMins($p2w) . ' in the last 2 weeks';
            } elseif ($days !== null && $days <= 30) {
                $score += 15;
                $why[] = 'Last played ' . self::fmtDays($days);
            }
            if ($pct !== null && $pct > 0) {
                $score += 20 * $pct / 100 + ($pct >= 50 ? 10 : 0);
                $why[] = "{$achD}/{$achT} achievements ({$pct}%)";
            }
            if ($hours >= 2 && $days !== null && $days > 180) {
                $score += 10;
                $why[] = self::fmtHours($hours) . ' invested, untouched for ' . self::fmtDays($days, false);
            }
            if ((int)$g['rating'] >= 4) {
                $score += 10;
                $why[] = "You rated it {$g['rating']}★";
            }
            $m = $meta[(int)$g['steam_appid']] ?? null;
            if ($m) {
                $best = null;
                foreach (self::genreList($m['genres']) as $genre) {
                    if (($share[$genre] ?? 0) > ($best[1] ?? 0)) $best = [$genre, $share[$genre]];
                }
                if ($best && $best[1] >= 0.1) {
                    $score += 40 * $best[1];
                    $why[] = "{$best[0]} games are " . round($best[1] * 100) . '% of your playtime';
                }
            }
            if ($g['status'] === 'playing') $score += 5;
            if ($hours == 0.0) $why[] = $g['status'] === 'backlog' ? 'On your backlog, not started' : 'Not started yet';
            if (!$why) continue;   // no real signal → don't pretend to recommend it

            $items[] = self::card($g) + [
                'score' => round($score, 1), 'reasons' => $why,
                'genres' => self::genreLabel($m),
                'ach_pct' => $pct,
            ];
        }
        usort($items, static fn($a, $b) => $b['score'] <=> $a['score']);

        return [
            'items'        => array_slice($items, 0, $limit),
            'finished'     => array_slice($finished, 0, 4),
            'meta_pending' => SteamClient::metaPending(array_filter(array_column($candidates, 'steam_appid'))),
            'top_genres'   => array_slice(array_map(
                static fn($genre, $h) => ['genre' => $genre, 'pct' => $gh['covered'] > 0 ? (int)round($h / $gh['covered'] * 100) : 0],
                array_keys($gh['genres']), $gh['genres']
            ), 0, 3),
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
             UNION SELECT DISTINCT YEAR(completed_at) FROM games WHERE user_id=? AND completed_at IS NOT NULL",
            [$this->uid, $this->uid]
        ), 'y'));
        $years[] = (int)date('Y');
        $years = array_values(array_unique(array_filter($years)));
        rsort($years);
        return $years;
    }

    public function wrapped(int $year): array
    {
        $games = fetchAll(
            "SELECT id, title, steam_appid, cover_url, status, hours_played, last_played, completed_at
               FROM games WHERE user_id=?", [$this->uid]
        );
        $totalHours = array_sum(array_map(static fn($g) => (float)$g['hours_played'], $games));
        $playedGames = array_values(array_filter($games, static fn($g) => (float)$g['hours_played'] > 0));
        usort($playedGames, static fn($a, $b) => $b['hours_played'] <=> $a['hours_played']);

        $top = array_map(static fn($g) => self::card($g) + [
            'share' => $totalHours > 0 ? (int)round($g['hours_played'] / $totalHours * 100) : 0,
        ], array_slice($playedGames, 0, 5));

        $lastInYear = array_values(array_filter($games, static fn($g) => $g['last_played'] && (int)substr($g['last_played'], 0, 4) === $year));
        usort($lastInYear, static fn($a, $b) => strcmp($b['last_played'], $a['last_played']));
        $completed = array_values(array_filter($games, static fn($g) => $g['completed_at'] && (int)substr($g['completed_at'], 0, 4) === $year));

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
        $tracked = $this->trackedPlaytime($year);
        $personality = $this->personality($library, $top, $genres, count($completed), $tracked);

        return [
            'year'          => $year,
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

    /** Playtime measured from Trackie's own snapshots, for one calendar year. */
    private function trackedPlaytime(int $year): array
    {
        $empty = ['since' => null, 'sync_days' => 0, 'minutes' => 0, 'days_played' => 0,
                  'weekday' => array_fill(0, 7, 0), 'weekday_days' => 0, 'months' => array_fill(1, 12, 0),
                  'best_day' => null, 'streak' => 0, 'top' => [], 'gap_minutes' => 0];
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
        $out['sync_days'] = count(array_filter($markers, static fn($d) => (int)substr($d, 0, 4) === $year));

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
            if ($delta <= 0 || (int)substr($d, 0, 4) !== $year) continue;

            $gap = $pm ? (int)round((strtotime($d) - strtotime($pm)) / 86400) : 0;
            $out['minutes'] += $delta;
            $out['months'][(int)substr($d, 5, 2)] += $delta;
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
        foreach (array_slice($perGame, 0, 5, true) as $app => $m) {
            $out['top'][] = ['title' => $titles[$app] ?? "App {$app}", 'minutes' => $m];
        }
        return $out;
    }

    /** Rule-based label; every trait carries the numbers that triggered it. */
    private function personality(array $lib, array $top, array $genres, int $completed, array $tracked): array
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
                         'why' => "You marked {$completed} games completed this year."];
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

    /* ── Co-op ───────────────────────────────────────────────────── */

    public function friends(string $steamId): ?array
    {
        $f = $this->steam?->friendIds($steamId);
        if ($f === null) return null;
        if ($f['private']) return ['private' => true, 'friends' => []];
        $sum = $this->steam->summaries($f['ids']);
        $list = [];
        foreach ($f['ids'] as $id) {
            $s = $sum[$id] ?? null;
            $list[] = ['steamid' => $id, 'name' => $s['name'] ?? 'Steam user',
                       'avatar' => $s['avatar'] ?? '', 'public' => $s['public'] ?? false];
        }
        // Public profiles first (they're the ones that can be compared), then A–Z.
        usort($list, static fn($a, $b) => [!$a['public'], strtolower($a['name'])] <=> [!$b['public'], strtolower($b['name'])]);
        return ['private' => false, 'friends' => $list, 'total' => count($f['ids'])];
    }

    /** Games everyone in the group owns, tagged with Store multiplayer categories. */
    public function coop(string $steamId, array $friendIds): array
    {
        // Only real friends of this account can be compared.
        $known = $this->steam?->friendIds($steamId)['ids'] ?? [];
        $friendIds = array_slice(array_values(array_intersect(array_unique($friendIds), $known)), 0, 5);

        $mine = [];
        foreach (fetchAll("SELECT steam_appid, title, cover_url, hours_played FROM games
                            WHERE user_id=? AND steam_appid IS NOT NULL", [$this->uid]) as $g) {
            $mine[(int)$g['steam_appid']] = $g;
        }
        $shared = array_keys($mine);
        $sum = $this->steam->summaries($friendIds);
        $members = $unavailable = [];
        $libs = [];
        foreach ($friendIds as $fid) {
            $lib = $this->steam->friendLibrary($fid);
            $name = $sum[$fid]['name'] ?? 'Steam user';
            if ($lib === null || $lib['private']) {
                $unavailable[] = ['steamid' => $fid, 'name' => $name,
                                  'reason' => $lib === null ? "Couldn't reach Steam" : 'Game list is private'];
                continue;
            }
            $libs[$fid] = $lib['games'];
            $members[] = ['steamid' => $fid, 'name' => $name, 'avatar' => $sum[$fid]['avatar'] ?? '', 'owned' => count($lib['games'])];
            $shared = array_values(array_intersect($shared, array_keys($lib['games'])));
        }
        if (!$members) return ['members' => [], 'unavailable' => $unavailable, 'games' => [], 'meta_pending' => 0];

        // Most-played shared games first so their metadata arrives first.
        usort($shared, static function ($a, $b) use ($mine, $libs) {
            $ha = (float)$mine[$a]['hours_played']; $hb = (float)$mine[$b]['hours_played'];
            foreach ($libs as $l) { $ha += ($l[$a] ?? 0) / 60; $hb += ($l[$b] ?? 0) / 60; }
            return $hb <=> $ha;
        });
        $meta = SteamClient::appMeta($shared, 15);

        $games = [];
        foreach ($shared as $app) {
            $m = $meta[$app] ?? null;
            // A list, not a name-keyed map: two friends can share a display name.
            $hours = [['name' => 'You', 'hours' => (float)$mine[$app]['hours_played']]];
            foreach ($members as $mem) {
                $hours[] = ['name' => $mem['name'], 'hours' => round(($libs[$mem['steamid']][$app] ?? 0) / 60, 1)];
            }
            $games[] = [
                'appid' => $app, 'title' => $mine[$app]['title'], 'cover_url' => $mine[$app]['cover_url'],
                'known' => (bool)$m,
                'multiplayer' => (bool)($m['multiplayer'] ?? false), 'coop' => (bool)($m['coop'] ?? false),
                'online_coop' => (bool)($m['online_coop'] ?? false), 'crossplay' => (bool)($m['crossplay'] ?? false),
                'genres' => self::genreLabel($m),
                'hours' => $hours,
            ];
        }
        usort($games, static fn($a, $b) =>
            [$b['online_coop'], $b['coop'], $b['multiplayer'], array_sum(array_column($b['hours'], 'hours'))]
            <=> [$a['online_coop'], $a['coop'], $a['multiplayer'], array_sum(array_column($a['hours'], 'hours'))]);

        return ['members' => $members, 'unavailable' => $unavailable, 'games' => $games,
                'meta_pending' => SteamClient::metaPending($shared)];
    }

    /* ── Formatting ──────────────────────────────────────────────── */

    private static function fmtMins(int $m): string
    {
        return $m >= 60 ? round($m / 60, 1) . 'h' : "{$m}m";
    }

    private static function fmtHours(float $h): string
    {
        return rtrim(rtrim(number_format($h, 1), '0'), '.') . 'h';
    }

    private static function fmtDays(int $d, bool $ago = true): string
    {
        $s = $d === 0 ? 'today' : ($d < 60 ? "{$d} day" . ($d === 1 ? '' : 's') : round($d / 30) . ' months');
        return $ago && $d > 0 ? "{$s} ago" : $s;
    }
}
