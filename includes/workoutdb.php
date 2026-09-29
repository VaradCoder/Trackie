<?php
/**
 * WorkoutDB (work-out-db.com) — exercise demo media and instructions.
 * Contract: https://work-out-db.com/openapi.json (Exercise schema).
 *
 * Catalogue data, not user data, so results are cached globally in
 * workoutdb_cache: the API quota is spent once per exercise name, not once
 * per workout. Same defensive rules as the other providers: short timeouts,
 * never throw, never invent data — any failure simply yields null.
 */

const WORKOUTDB_DEFAULT_URL = 'https://api.work-out-db.com';
const WORKOUTDB_TTL_HIT     = 30 * 86400;  // a matched exercise
const WORKOUTDB_TTL_MISS    = 7 * 86400;   // API answered, no confident match
const WORKOUTDB_TTL_ERROR   = 3600;        // outage / bad key — retry within the hour

function workoutdbConfigured(): bool {
    return env('WORKOUTDB_API_KEY') !== '';
}

/** Multibyte-safe truncation, without assuming the host enabled mbstring. */
function workoutdbCut(string $s, int $start, int $len): string {
    return function_exists('mb_substr') ? mb_substr($s, $start, $len) : substr($s, $start, $len);
}

function workoutdbNormalize(string $s): string {
    return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($s)));
}

/** External payloads are untrusted: only absolute https URLs reach the browser. */
function workoutdbSafeUrl($u): ?string {
    return is_string($u) && str_starts_with($u, 'https://') && filter_var($u, FILTER_VALIDATE_URL) ? $u : null;
}

/**
 * A fuzzy search always returns *something*; accept a hit only when it is
 * plausibly the same exercise. Exact match, or every word of the shorter
 * name (at least two words) appears in the longer one — so "chest press"
 * accepts "Machine Chest Press" but "chest" alone accepts nothing.
 */
function workoutdbNameMatches(string $want, string $have): bool {
    if ($want === '' || $have === '') return false;
    if ($want === $have) return true;
    $a = explode(' ', $want);
    $b = explode(' ', $have);
    [$short, $long] = count($a) <= count($b) ? [$a, $b] : [$b, $a];
    if (count($short) < 2) return false;
    return !array_diff($short, $long);
}

/** GET a /v1 path. Returns [httpCode, decodedJson|null]; code 0 = transport failure. */
function workoutdbGet(string $path, array $query): array {
    $base = rtrim(env('WORKOUTDB_API_URL', WORKOUTDB_DEFAULT_URL) ?: WORKOUTDB_DEFAULT_URL, '/');
    $ch = curl_init($base . $path . '?' . http_build_query($query));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'X-API-Key: ' . env('WORKOUTDB_API_KEY'),
            'User-Agent: Trackie',
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = is_string($body) ? json_decode($body, true) : null;
    return [$code, is_array($json) ? $json : null];
}

/** Reduce an API Exercise to what the workout screen shows; null if it has nothing useful. */
function workoutdbExtract(array $ex): ?array {
    $video = $ex['media']['video'] ?? null;
    $mp4   = is_array($video) ? ($video['mp4'] ?? []) : [];
    $steps = [];
    foreach ((array)($ex['instructions'] ?? []) as $s) {
        if (is_string($s) && trim($s) !== '') $steps[] = workoutdbCut(trim($s), 0, 400);
    }
    $demo = [
        'name'         => workoutdbCut((string)($ex['name'] ?? ''), 0, 120),
        'video'        => workoutdbSafeUrl($mp4['720p'] ?? null) ?? workoutdbSafeUrl($mp4['480p'] ?? null),
        'poster'       => is_array($video) ? workoutdbSafeUrl($video['poster'] ?? null) : null,
        'gif'          => workoutdbSafeUrl($ex['media']['gif'] ?? null) ?? workoutdbSafeUrl($ex['gifUrl'] ?? null),
        'instructions' => array_slice($steps, 0, 8),
    ];
    return ($demo['video'] || $demo['gif'] || $demo['instructions']) ? $demo : null;
}

/**
 * Demo data for an exercise name — ['name','video','poster','gif','instructions']
 * — or null when unconfigured, unavailable, or no confident match.
 */
function workoutdbDemo(string $exerciseName): ?array {
    if (!workoutdbConfigured() || !tableExists('workoutdb_cache')) return null;
    $key = workoutdbCut(workoutdbNormalize($exerciseName), 0, 191);
    if ($key === '') return null;

    $row = fetchOne(
        "SELECT status, payload, UNIX_TIMESTAMP(fetched_at) AS t FROM workoutdb_cache WHERE query_key=?",
        [$key]
    );
    if ($row) {
        $ttl = ['hit' => WORKOUTDB_TTL_HIT, 'miss' => WORKOUTDB_TTL_MISS][$row['status']] ?? WORKOUTDB_TTL_ERROR;
        if (time() - (int)$row['t'] < $ttl) {
            return $row['status'] === 'hit' ? json_decode((string)$row['payload'], true) : null;
        }
    }

    [$code, $json] = workoutdbGet('/v1/exercises/search', ['q' => $exerciseName, 'limit' => 5, 'lang' => 'en']);
    $status = 'error';
    $demo   = null;
    if ($code === 200 && isset($json['data']) && is_array($json['data'])) {
        $status = 'miss';
        foreach ($json['data'] as $ex) {
            if (!is_array($ex) || !workoutdbNameMatches($key, workoutdbNormalize((string)($ex['name'] ?? '')))) continue;
            $demo = workoutdbExtract($ex);
            if ($demo) { $status = 'hit'; break; }
        }
    } else {
        // Never log the key — code + stable error code is enough to diagnose.
        error_log('WorkoutDB: search failed (HTTP ' . $code . ', ' . ($json['error']['code'] ?? 'no error code') . ')');
    }

    update(
        "INSERT INTO workoutdb_cache (query_key, status, payload) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE status=VALUES(status), payload=VALUES(payload), fetched_at=CURRENT_TIMESTAMP",
        [$key, $status, $demo ? json_encode($demo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null]
    );
    return $demo;
}

/**
 * Raw cached request helper for catalogue calls (search / by id). Same TTL
 * rules as workoutdbDemo(); the cache key is namespaced so it never collides
 * with the per-name demo rows. Returns the decoded `data` payload or null.
 */
function workoutdbCachedGet(string $cacheKey, string $path, array $query, callable $trim): ?array {
    if (!workoutdbConfigured() || !tableExists('workoutdb_cache')) return null;
    $key = workoutdbCut($cacheKey, 0, 191);
    $row = fetchOne(
        "SELECT status, payload, UNIX_TIMESTAMP(fetched_at) AS t FROM workoutdb_cache WHERE query_key=?",
        [$key]
    );
    if ($row) {
        $ttl = ['hit' => WORKOUTDB_TTL_HIT, 'miss' => WORKOUTDB_TTL_MISS][$row['status']] ?? WORKOUTDB_TTL_ERROR;
        if (time() - (int)$row['t'] < $ttl) {
            return $row['status'] === 'hit' ? json_decode((string)$row['payload'], true) : null;
        }
    }
    [$code, $json] = workoutdbGet($path, $query);
    $data = null;
    if ($code === 200 && isset($json['data']) && is_array($json['data'])) {
        $data   = $trim($json['data']);
        $status = $data ? 'hit' : 'miss';
    } elseif ($code === 404 && ($json['error']['code'] ?? '') === 'not_found') {
        $status = 'miss';
    } else {
        $status = 'error';
        error_log('WorkoutDB: ' . $path . ' failed (HTTP ' . $code . ', ' . ($json['error']['code'] ?? 'no error code') . ')');
    }
    update(
        "INSERT INTO workoutdb_cache (query_key, status, payload) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE status=VALUES(status), payload=VALUES(payload), fetched_at=CURRENT_TIMESTAMP",
        [$key, $status, $data ? json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null]
    );
    return $data;
}

/** Keep only the fields Trackie uses, so cached payloads stay small. */
function workoutdbTrimExercise(array $ex): array {
    $keep = ['id', 'slug', 'name', 'bodyPart', 'target', 'secondaryMuscles', 'equipment',
             'category', 'difficulty', 'instructions', 'gifUrl', 'media'];
    $out = array_intersect_key($ex, array_flip($keep));
    if (isset($out['instructions']) && is_array($out['instructions'])) {
        $out['instructions'] = array_slice($out['instructions'], 0, 8);
    }
    unset($out['media']['photos']);
    return $out;
}

/** Search the catalogue (cached). Returns raw Exercise arrays or null when unavailable. */
function workoutdbSearchExercises(string $q, int $limit = 12): ?array {
    $q = trim($q);
    if ($q === '') return null;
    $limit = max(1, min(12, $limit));
    return workoutdbCachedGet(
        'q:' . workoutdbNormalize($q) . ':' . $limit,
        '/v1/exercises/search',
        ['q' => $q, 'limit' => $limit, 'lang' => 'en'],
        fn(array $list) => array_values(array_map('workoutdbTrimExercise', array_filter($list, 'is_array')))
    );
}

/** One exercise by id/slug (cached). */
function workoutdbGetExercise(string $idOrSlug): ?array {
    if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/', $idOrSlug)) return null; // path-safe ids only
    return workoutdbCachedGet(
        'id:' . $idOrSlug,
        '/v1/exercises/' . $idOrSlug,
        ['lang' => 'en'],
        fn(array $ex) => workoutdbTrimExercise($ex)
    );
}
