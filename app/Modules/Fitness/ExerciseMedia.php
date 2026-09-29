<?php
/**
 * Demo media for an exercise, looked up by NAME, server-side and cached.
 *
 * Chain (first that answers wins):
 *   1. ExerciseDB (RapidAPI) — an animated GIF. The GIF endpoint needs the
 *      secret key, so the file is fetched here once, saved under
 *      assets/cache/exercise-gifs/ and served from Trackie's own origin: the
 *      key never reaches the browser and the free quota is spent once per
 *      exercise, not per view.
 *   2. free-exercise-db (The Unlicense / public domain) — start + end photos,
 *      bundled as data/free-exercise-db.json so it works with no network.
 *      The browser alternates the two frames.
 *
 * Results live in workoutdb_cache (the shared external-catalogue cache) under
 * "media:<name>". Never throws; a total miss returns null and the workout
 * screen simply shows no demo.
 */

require_once __DIR__ . '/../../../includes/workoutdb.php'; // cache helpers + URL safety

final class ExerciseMedia
{
    private const TTL_HIT     = 30 * 86400; // resolved from the best source
    private const TTL_PARTIAL = 3600;       // ExerciseDB errored → fallback used, retry soon
    private const TTL_MISS    = 7 * 86400;  // no source knows this exercise
    private const GIF_DIR     = 'assets/cache/exercise-gifs';
    private const FEDB_IMG    = 'https://raw.githubusercontent.com/yuhonas/free-exercise-db/main/exercises/';

    private static ?array $fedb = null;

    /**
     * @return array{type:string, gif_url?:string, frames?:string[], source:string,
     *               credit:string, name:string, instructions:string[]}|null
     */
    public static function forName(string $exercise): ?array
    {
        $norm = self::norm($exercise);
        if ($norm === '' || !tableExists('workoutdb_cache')) return null;
        $key = workoutdbCut('media:' . $norm, 0, 191);

        $row = fetchOne("SELECT status, payload, UNIX_TIMESTAMP(fetched_at) t FROM workoutdb_cache WHERE query_key=?", [$key]);
        if ($row) {
            $ttl = ['hit' => self::TTL_HIT, 'partial' => self::TTL_PARTIAL, 'miss' => self::TTL_MISS][$row['status']] ?? 0;
            $cached = $row['payload'] ? json_decode($row['payload'], true) : null;
            // A cached GIF whose file has gone (redeploy, cleanup) is re-fetched.
            $fileOk = !$cached || $cached['type'] !== 'gif' || is_file(ROOT_PATH . '/' . $cached['gif_path']);
            if (time() - (int)$row['t'] < $ttl && $fileOk) return $cached ? self::present($cached) : null;
        }

        // The better name match wins, whichever source it comes from — a GIF of
        // the WRONG exercise is worse than photos of the right one. The local
        // dataset is checked first (free, offline) so ExerciseDB's GIF is only
        // downloaded, and quota only spent, when it would actually be shown.
        $fedb = self::fromFreeExerciseDb($exercise);
        $fedbScore = $fedb['score'] ?? 0.0;
        unset($fedb['score']);
        [$gif, $edbFailed] = self::fromExerciseDb($exercise, $fedbScore);

        $media  = $gif ?: ($fedb ?: null);
        $status = $media ? ($edbFailed && !$gif ? 'partial' : 'hit') : ($edbFailed ? 'partial' : 'miss');

        update(
            "INSERT INTO workoutdb_cache (query_key, status, payload) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE status=VALUES(status), payload=VALUES(payload), fetched_at=CURRENT_TIMESTAMP",
            [$key, $status, $media ? json_encode($media, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null]
        );
        return $media ? self::present($media) : null;
    }

    /** Stored shape → response shape (paths become URLs for this install). */
    private static function present(array $m): array
    {
        if ($m['type'] === 'gif') {
            $m['gif_url'] = APP_BASE . '/' . implode('/', array_map('rawurlencode', explode('/', $m['gif_path'])));
            unset($m['gif_path']);
        }
        return $m;
    }

    /* ── Matching ──────────────────────────────────────────────────── */

    public static function norm(string $s): string
    {
        $s = strtolower(str_replace(['-', '/'], ' ', $s));
        $s = trim(preg_replace('/[^a-z0-9]+/', ' ', $s));
        // "push up" / "push-up" / "pushup" are one exercise in every source.
        return preg_replace('/\b(push|pull|chin|sit) ups?\b/', '$1up', $s);
    }

    private static function stem(string $w): string
    {
        if (strlen($w) > 4 && preg_match('/(ch|sh|x)es$/', $w)) return substr($w, 0, -2);
        if (strlen($w) > 3 && substr($w, -1) === 's' && substr($w, -2) !== 'ss') return substr($w, 0, -1);
        return $w;
    }

    /**
     * How well a candidate name matches what the user typed (0 = reject).
     * Exact > every typed word present (2+ words) > a single typed word that is
     * the last word of a short name ("plank" → "front plank"). A lone word is
     * never matched loosely ("chest" → nothing).
     */
    public static function score(string $want, string $have): float
    {
        $w = array_map([self::class, 'stem'], explode(' ', self::norm($want)));
        $h = array_map([self::class, 'stem'], explode(' ', self::norm($have)));
        if ($w === [''] || $h === ['']) return 0;
        if ($w === $h) return 100;
        if (count($w) >= 2 && !array_diff($w, $h)) return 80 - self::extraPenalty(array_diff($h, $w));
        if (count($w) === 1 && end($h) === $w[0] && count($h) <= 3) return 60 - self::extraPenalty(array_slice($h, 0, -1));
        return 0;
    }

    /**
     * Extra words that only name equipment ("barbell bench press") barely
     * change the exercise; others ("reverse grip", "power point") do, so they
     * cost more. Among equipment, the standard barbell/dumbbell version wins.
     */
    private static function extraPenalty(array $extra): float
    {
        $cost = [
            // equipment: same movement, different tool
            'barbell' => 0.6, 'dumbbell' => 0.7, 'cable' => 0.8, 'lever' => 0.8, 'machine' => 0.8,
            'smith' => 0.9, 'body' => 0.8, 'weight' => 0.8, 'band' => 0.9, 'kettlebell' => 0.9,
            // grip width: a mild variation
            'grip' => 1.5, 'wide' => 1.5, 'close' => 1.5, 'medium' => 1.5, 'neutral' => 1.5,
            // clearly a different variation
            'one' => 4.0, 'single' => 4.0, 'arm' => 4.0, 'alternate' => 4.0, 'alternating' => 4.0,
            'reverse' => 4.0, 'side' => 4.0, 'twist' => 4.0, 'kneeling' => 4.0,
        ];
        $p = 0.0;
        foreach ($extra as $x) $p += $cost[$x] ?? 3.0;
        return $p;
    }

    /* ── ExerciseDB (GIF) ──────────────────────────────────────────── */

    /**
     * @param float $mustBeat score of the best local match; the GIF is used only
     *                        if its match is at least as good (+ a small tie bonus for animation)
     * @return array{0: ?array, 1: bool} [media, failed] — failed = transport/API error, not "no match".
     */
    private static function fromExerciseDb(string $exercise, float $mustBeat = 0.0): array
    {
        $key = env('EXERCISEDB_API_KEY');
        if ($key === '' || !function_exists('curl_init')) return [null, false];
        $host = env('EXERCISEDB_API_HOST', 'exercisedb.p.rapidapi.com') ?: 'exercisedb.p.rapidapi.com';
        if (!preg_match('/^[a-z0-9.-]+$/i', $host)) return [null, false];

        [$code, $body] = self::edbGet($host, $key, '/exercises/name/' . rawurlencode(implode(' ', array_map([self::class, 'stem'], explode(' ', self::norm($exercise))))) . '?limit=30');
        if ($code !== 200) {
            error_log("ExerciseDB: search failed (HTTP {$code})");
            return [null, true];
        }
        $list = json_decode((string)$body, true);
        if (!is_array($list)) return [null, true];

        $best = null; $bestScore = 0.0;
        foreach ($list as $ex) {
            if (!is_array($ex) || !isset($ex['id'], $ex['name'])) continue;
            $s = self::score($exercise, (string)$ex['name']);
            if ($s > $bestScore || ($s === $bestScore && $s > 0 && strlen($ex['name']) < strlen($best['name']))) {
                $best = $ex; $bestScore = $s;
            }
        }
        if (!$best || $bestScore + 0.5 < $mustBeat) return [null, false];

        $id = preg_replace('/[^0-9A-Za-z_-]/', '', (string)$best['id']);
        if ($id === '') return [null, false];
        $rel = self::GIF_DIR . '/' . $id . '.gif';
        $abs = ROOT_PATH . '/' . $rel;
        if (!is_file($abs)) {
            [$gcode, $gif, $ctype] = self::edbGet($host, $key, '/image?exerciseId=' . rawurlencode($id) . '&resolution=180', true);
            // Only a real, reasonably sized GIF is ever written to disk.
            if ($gcode !== 200 || stripos((string)$ctype, 'image/gif') !== 0 || strlen((string)$gif) < 100
                || strlen((string)$gif) > 3 * 1024 * 1024 || strncmp((string)$gif, 'GIF8', 4) !== 0) {
                error_log("ExerciseDB: image failed (HTTP {$gcode})");
                return [null, true];
            }
            if (!is_dir(dirname($abs)) && !@mkdir(dirname($abs), 0755, true)) return [null, true];
            if (@file_put_contents($abs, $gif, LOCK_EX) === false) return [null, true];
        }

        return [[
            'type'         => 'gif',
            'gif_path'     => $rel,
            'source'       => 'exercisedb',
            'credit'       => 'ExerciseDB',
            'name'         => workoutdbCut((string)$best['name'], 0, 120),
            'instructions' => self::cleanSteps($best['instructions'] ?? []),
        ], false];
    }

    /** @return array{0:int,1:string|false,2:?string} [http code, body, content-type] */
    private static function edbGet(string $host, string $key, string $path, bool $binary = false): array
    {
        $ch = curl_init('https://' . $host . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => $binary ? 10 : 6,
            CURLOPT_HTTPHEADER     => ['X-RapidAPI-Key: ' . $key, 'X-RapidAPI-Host: ' . $host, 'User-Agent: Trackie'],
        ]);
        $body  = curl_exec($ch);
        $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: null;
        curl_close($ch);
        return [$code, $body, $ctype];
    }

    /* ── free-exercise-db (bundled, public domain) ─────────────────── */

    private static function fromFreeExerciseDb(string $exercise): ?array
    {
        if (self::$fedb === null) {
            $raw = @file_get_contents(__DIR__ . '/data/free-exercise-db.json');
            self::$fedb = $raw ? (json_decode($raw, true) ?: []) : [];
        }
        $best = null; $bestScore = 0.0;
        foreach (self::$fedb as $ex) {
            $s = self::score($exercise, (string)$ex['n']);
            if ($s > $bestScore || ($s === $bestScore && $s > 0 && strlen($ex['n']) < strlen($best['n']))) {
                $best = $ex; $bestScore = $s;
            }
        }
        if (!$best || empty($best['i'])) return null;
        $frames = [];
        foreach ($best['i'] as $p) {
            if (!preg_match('#^[A-Za-z0-9_().,\'-]+/[0-9]+\.(jpg|jpeg|png|gif)$#i', (string)$p)) continue;
            $frames[] = self::FEDB_IMG . implode('/', array_map('rawurlencode', explode('/', $p)));
        }
        if (!$frames) return null;
        return [
            'type'         => 'frames',
            'frames'       => $frames,
            'source'       => 'free-exercise-db',
            'credit'       => 'Free Exercise DB',
            'name'         => workoutdbCut((string)$best['n'], 0, 120),
            'instructions' => self::cleanSteps($best['t'] ?? []),
            'score'        => $bestScore, // used by forName() to pick the better source; not stored
        ];
    }

    private static function cleanSteps($steps): array
    {
        $out = [];
        foreach ((array)$steps as $s) {
            if (is_string($s) && trim($s) !== '') $out[] = workoutdbCut(trim($s), 0, 400);
        }
        return array_slice($out, 0, 8);
    }
}
