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
 */
function integrationsRegistry(): array {
    $reg = [
        // ── Live / near-live (credential-gated, real code exists) ──
        'spotify' => [
            'name' => 'Spotify', 'icon' => 'fa-brands fa-spotify', 'color' => '#1db954',
            'category' => 'Music',
            'desc' => 'Now Playing, playlists, and focus-session auto-play.',
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
            'desc' => 'Commits, repos, languages, and contribution streaks.',
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
            'auth' => 'oauth', 'connect' => '/pages/google_callback.php',
            'docs' => 'https://console.cloud.google.com/apis/credentials',
            'setup' => 'config/env.php → add GOOGLE_CLIENT_ID + GOOGLE_CLIENT_SECRET',
        ],
        'google_tasks' => [
            'name' => 'Google Tasks', 'icon' => 'fa-list-check', 'color' => '#4285f4',
            'category' => 'Productivity',
            'desc' => 'Two-way sync with your Google Tasks lists.',
            'creds' => ['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET'],
            'auth' => 'oauth', 'connect' => '/pages/google_callback.php',
            'docs' => 'https://console.cloud.google.com/apis/credentials',
            'setup' => 'config/env.php → shares GOOGLE_CLIENT_ID + GOOGLE_CLIENT_SECRET',
        ],
        'google_books' => [
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
            'desc' => 'Owned games, playtime, and achievements.',
            'creds' => ['STEAM_API_KEY'],
            'auth' => 'apikey', 'connect' => '',
            'docs' => 'https://steamcommunity.com/dev/apikey',
            'setup' => 'config/env.php → add STEAM_API_KEY',
        ],
        'rawg' => [
            'name' => 'RAWG', 'icon' => 'fa-gamepad', 'color' => '#e11d48',
            'category' => 'Gaming',
            'desc' => 'Game database: covers, genres, and ratings.',
            'creds' => ['RAWG_API_KEY'],
            'auth' => 'apikey', 'connect' => '',
            'docs' => 'https://rawg.io/apidocs',
            'setup' => 'config/env.php → add RAWG_API_KEY',
        ],
        'strava' => [
            'name' => 'Strava', 'icon' => 'fa-brands fa-strava', 'color' => '#fc4c02',
            'category' => 'Sports',
            'desc' => 'Runs, rides, and training distance.',
            'creds' => ['STRAVA_CLIENT_ID', 'STRAVA_CLIENT_SECRET'],
            'auth' => 'oauth', 'connect' => '/pages/strava_callback.php',
            'docs' => 'https://www.strava.com/settings/api',
            'setup' => 'config/env.php → add STRAVA_CLIENT_ID + STRAVA_CLIENT_SECRET',
        ],
        'unsplash' => [
            'name' => 'Unsplash', 'icon' => 'fa-camera', 'color' => '#111827',
            'category' => 'Photography',
            'desc' => 'Reference photos and inspiration.',
            'creds' => ['UNSPLASH_ACCESS_KEY'],
            'auth' => 'apikey', 'connect' => '',
            'docs' => 'https://unsplash.com/developers',
            'setup' => 'config/env.php → add UNSPLASH_ACCESS_KEY',
        ],
        'chesscom' => [
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
        $configured = !empty($p['creds']);
        foreach ($p['creds'] as $c) {
            if (env($c) === '') { $configured = false; break; }
        }
        $p['configured'] = $configured;

        // Defaults for providers with no implementation yet.
        $p['connected']  = false;
        $p['lastSync']   = null;
        $p['syncStatus'] = null;
        $p['lastError']  = null;
        $p['hasImpl']    = isset($liveProviders[$key]);

        if ($p['hasImpl'] && $uid) {
            $st = $liveProviders[$key]->status($uid);
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

    return $reg;
}

/** Small helper: counts of configured vs total, for the Settings header. */
function integrationsSummary(): array {
    $reg = integrationsRegistry();
    $active = 0;
    foreach ($reg as $p) if ($p['configured']) $active++;
    return ['active' => $active, 'total' => count($reg)];
}
