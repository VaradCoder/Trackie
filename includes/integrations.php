<?php
/**
 * Trackie — Integration provider registry (single source of truth).
 *
 * This is the "provider interface" expressed as data: adding a new
 * integration = adding one entry here (+ its service code + credentials in
 * config/env.php). The Settings → Integrations page, dashboard cards, and any
 * future connector code all read from this one function, so nothing else needs
 * to change when a provider is added or goes live.
 *
 * Status is DERIVED, never hand-set:
 *   - 'active'      → app-level API key present, works now (e.g. Weather).
 *   - 'connect'     → OAuth app configured; each user connects their account.
 *   - 'coming_soon' → credentials not set yet. UI shows a disabled card and
 *                     tells the user exactly which config/env.php constant to
 *                     fill in to enable it.
 *
 * `creds`  — env() constant names that must ALL be non-empty to enable it.
 * `auth`   — 'apikey' (app-level key) | 'oauth' (per-user connect).
 * `connect`— URL that starts the connect flow (oauth only).
 * `setup`  — human-readable "where do I put the key" note (shown at the bottom
 *            of Settings and in MD/INTEGRATIONS.md).
 * `built`  — false = no code uses it yet. Those entries are kept as a
 *            roadmap but hidden from Settings: a key in env.php must never
 *            make an unbuilt integration look "Active".
 */
function integrationsRegistry(): array {
    $reg = [
        // ── Live / near-live (credential-gated, real code exists) ──
        'spotify' => [
            'name' => 'Spotify', 'icon' => 'fa-brands fa-spotify', 'color' => '#1db954',
            'category' => 'Music',
            'desc' => 'Remote control, Liked Songs, recent listening and focus-session auto-play.',
            'creds' => ['SPOTIFY_CLIENT_ID', 'SPOTIFY_CLIENT_SECRET'],
            'auth' => 'oauth', 'connect' => '/pages/music.php',
            'docs' => 'https://developer.spotify.com/dashboard',
            'setup' => 'config/env.php → SPOTIFY_CLIENT_ID + SPOTIFY_CLIENT_SECRET',
        ],
        'weather' => [
            'name' => 'Weather', 'icon' => 'fa-cloud-sun', 'color' => '#0ea5e9',
            'category' => 'Dashboard',
            'desc' => 'Current conditions on your dashboard (Open-Meteo — free, no key needed).',
            'creds' => [], // Open-Meteo requires no API key — always active
            'auth' => 'apikey', 'connect' => '',
            'docs' => 'https://open-meteo.com/',
            'setup' => 'No key needed — works out of the box via IP geolocation.',
        ],
        'ai_coach' => [
            'name' => 'AI Coach', 'icon' => 'fa-wand-magic-sparkles', 'color' => '#a855f7',
            'category' => 'Intelligence',
            'desc' => 'Natural-language coaching on top of your own data.',
            'creds' => ['GEMINI_API_KEY'], // OpenAI is an alt fallback in api/ai.php
            'auth' => 'apikey', 'connect' => '',
            'docs' => 'https://aistudio.google.com/app/apikey',
            'setup' => 'config/env.php → GEMINI_API_KEY (or OPENAI_API_KEY)',
        ],

        // ── Connector stubs (OAuth/API, no credentials yet → coming soon) ──
        'github' => [
            'name' => 'GitHub', 'icon' => 'fa-brands fa-github', 'color' => '#8b949e',
            'category' => 'Coding',
            'desc' => 'Every repository you can access, activity and languages — on the Coding page.',
            'creds' => ['GITHUB_CLIENT_ID', 'GITHUB_CLIENT_SECRET'],
            'auth' => 'oauth', 'connect' => '/pages/github_callback.php',
            'docs' => 'https://github.com/settings/developers',
            'setup' => 'config/env.php → add GITHUB_CLIENT_ID + GITHUB_CLIENT_SECRET',
        ],
        'google_calendar' => [
            'name' => 'Google Calendar', 'icon' => 'fa-calendar-days', 'color' => '#4285f4',
            'category' => 'Productivity',
            'desc' => 'Sync events and see today’s schedule in Trackie.',
            'creds' => ['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET'],
            'auth' => 'oauth', 'connect' => '/pages/google_callback.php', 'impl' => 'google',
            'docs' => 'https://console.cloud.google.com/apis/credentials',
            'setup' => 'config/env.php → add GOOGLE_CLIENT_ID + GOOGLE_CLIENT_SECRET',
        ],
        'google_tasks' => [
            'name' => 'Google Tasks', 'icon' => 'fa-list-check', 'color' => '#4285f4',
            'category' => 'Productivity',
            'desc' => 'Two-way sync with your Google Tasks lists.',
            'creds' => ['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET'],
            'auth' => 'oauth', 'connect' => '/pages/google_callback.php', 'impl' => 'google',
            'docs' => 'https://console.cloud.google.com/apis/credentials',
            'setup' => 'config/env.php → shares GOOGLE_CLIENT_ID + GOOGLE_CLIENT_SECRET',
        ],
        'google_books' => [
            'built' => false,
            'name' => 'Google Books', 'icon' => 'fa-book', 'color' => '#4285f4',
            'category' => 'Reading',
            'desc' => 'Search books, covers, and metadata for your library.',
            'creds' => ['GOOGLE_BOOKS_API_KEY'],
            'auth' => 'apikey', 'connect' => '',
            'docs' => 'https://developers.google.com/books',
            'setup' => 'config/env.php → add GOOGLE_BOOKS_API_KEY',
        ],
        'steam' => [
            'name' => 'Steam', 'icon' => 'fa-brands fa-steam', 'color' => '#66c0f4',
            'category' => 'Gaming',
            'desc' => 'Owned games, playtime, achievements and your Gaming Wrap.',
            'creds' => ['STEAM_API_KEY'],
            // App-level key + each user links their SteamID on the Gaming page.
            'auth' => 'oauth', 'connect' => '/pages/gaming.php',
            'docs' => 'https://steamcommunity.com/dev/apikey',
            'setup' => 'config/env.php → add STEAM_API_KEY',
        ],
        'rawg' => [
            'built' => false,
            'name' => 'RAWG', 'icon' => 'fa-gamepad', 'color' => '#e11d48',
            'category' => 'Gaming',
            'desc' => 'Game database: covers, genres, and ratings.',
            'creds' => ['RAWG_API_KEY'],
            'auth' => 'apikey', 'connect' => '',
            'docs' => 'https://rawg.io/apidocs',
            'setup' => 'config/env.php → add RAWG_API_KEY',
        ],
        'workoutdb' => [
            'name' => 'WorkoutDB', 'icon' => 'fa-dumbbell', 'color' => '#ff4545',
            'category' => 'Fitness',
            'desc' => 'Exercise demo videos and how-to steps in your workouts.',
            'creds' => ['WORKOUTDB_API_KEY'],
            'auth' => 'apikey', 'connect' => '',
            'docs' => 'https://work-out-db.com/docs',
            'setup' => 'config/env.php → add WORKOUTDB_API_KEY',
        ],
        'strava' => [
            'built' => false,
            'name' => 'Strava', 'icon' => 'fa-brands fa-strava', 'color' => '#fc4c02',
            'category' => 'Sports',
            'desc' => 'Runs, rides, and training distance.',
            'creds' => ['STRAVA_CLIENT_ID', 'STRAVA_CLIENT_SECRET'],
            'auth' => 'oauth', 'connect' => '',
            'docs' => 'https://www.strava.com/settings/api',
            'setup' => 'config/env.php → add STRAVA_CLIENT_ID + STRAVA_CLIENT_SECRET',
        ],
        'unsplash' => [
            'built' => false,
            'name' => 'Unsplash', 'icon' => 'fa-camera', 'color' => '#111827',
            'category' => 'Photography',
            'desc' => 'Reference photos and inspiration.',
            'creds' => ['UNSPLASH_ACCESS_KEY'],
            'auth' => 'apikey', 'connect' => '',
            'docs' => 'https://unsplash.com/developers',
            'setup' => 'config/env.php → add UNSPLASH_ACCESS_KEY',
        ],
        'chesscom' => [
            'built' => false,
            'name' => 'Chess.com', 'icon' => 'fa-chess-knight', 'color' => '#7fa650',
            'category' => 'Gaming',
            'desc' => 'Ratings, games, and puzzle stats (public API, no key).',
            'creds' => [], // public API — enabled by building the connector
            'auth' => 'apikey', 'connect' => '',
            'docs' => 'https://www.chess.com/news/view/published-data-api',
            'setup' => 'No key needed — connector implementation pending.',
        ],
    ];

    // Per-user connection state, when a real provider implementation exists.
    // Without this the UI could only say whether the SERVER has credentials —
    // not whether THIS user has actually connected their account.
    $liveProviders = function_exists('providerRegistry') ? providerRegistry() : [];
    $uid = (function_exists('isLoggedIn') && isLoggedIn()) ? currentUserId() : 0;

    foreach ($reg as $key => &$p) {
        $p['built'] = $p['built'] ?? true;
        // No credentials needed (Open-Meteo weather) = configured by definition.
        $configured = true;
        foreach ($p['creds'] as $c) {
            if (env($c) === '') { $configured = false; break; }
        }
        $p['configured'] = $configured;

        // Defaults for providers with no implementation yet.
        $p['connected']  = false;
        $p['lastSync']   = null;
        $p['syncStatus'] = null;
        $p['lastError']  = null;
        // Several cards can share one provider (Calendar + Tasks → 'google').
        $p['impl']       = $p['impl'] ?? $key;
        $p['hasImpl']    = isset($liveProviders[$p['impl']]);

        if ($key === 'steam' && $uid && $configured) {
            // Steam has no OAuth provider class: the link is a user_integrations row.
            $row = fetchOne("SELECT last_sync, sync_status, last_error FROM user_integrations WHERE user_id=? AND provider='steam'", [$uid]);
            if ($row) {
                $p['connected'] = true; $p['lastSync'] = $row['last_sync'];
                $p['syncStatus'] = $row['sync_status']; $p['lastError'] = $row['last_error'];
            }
        } elseif ($p['hasImpl'] && $uid) {
            $st = $liveProviders[$p['impl']]->status($uid);
            $p['connected']  = $st['connected'];
            $p['lastSync']   = $st['lastSync'];
            $p['syncStatus'] = $st['syncStatus'];
            $p['lastError']  = $st['lastError'];
        }

        if (!$configured)            $p['status'] = 'coming_soon';
        elseif ($p['connected'])     $p['status'] = 'connected';
        elseif ($p['auth'] === 'oauth') $p['status'] = 'connect';
        else                         $p['status'] = 'active';
    }
    unset($p);

    // WorkoutDB has a key but no per-user connection, so "active" would only
    // mean "a key is set". The honest health signal is how the most recent
    // lookup went — if it failed, say so instead of showing a green badge.
    if (!empty($reg['workoutdb']['configured']) && function_exists('tableExists') && tableExists('workoutdb_cache')) {
        // Ties (several lookups in one second) resolve toward the error.
        $last = fetchOne("SELECT status FROM workoutdb_cache ORDER BY fetched_at DESC, status = 'error' DESC LIMIT 1");
        if ($last && $last['status'] === 'error') {
            $reg['workoutdb']['status']    = 'unavailable';
            $reg['workoutdb']['lastError'] = "WorkoutDB didn't respond on the last lookup, so demo videos from it are paused. Trackie retries automatically within the hour.";
        }
    }

    return $reg;
}

/** Small helper: counts of configured vs total, for the Settings header. */
function integrationsSummary(): array {
    $reg = array_filter(integrationsRegistry(), static fn($p) => $p['built']);
    $active = $connected = 0;
    foreach ($reg as $p) {
        if ($p['configured']) $active++;
        if ($p['status'] === 'connected' || $p['status'] === 'active') $connected++;
    }
    return ['active' => $active, 'connected' => $connected, 'total' => count($reg)];
}
