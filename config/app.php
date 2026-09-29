<?php
/**
 * Trackie v1.0 — Application Bootstrap
 * Include this first in every entry point.
 */

// ── Error reporting (disable output in production) ────────────
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// ── Path constants ────────────────────────────────────────────
define('ROOT_PATH', dirname(__DIR__));   // C:/.../Trackie

// ── Load local environment credentials ────────────────────────
$_envFile = __DIR__ . '/env.php';
if (file_exists($_envFile)) require_once $_envFile;
unset($_envFile);

// ── Application settings ──────────────────────────────────────
// Defaults reproduce the pre-existing behavior when env.php omits them.
if (!defined('APP_ENV'))   define('APP_ENV',   getenv('APP_ENV') ?: 'production');
if (!defined('APP_DEBUG')) define('APP_DEBUG', filter_var(getenv('APP_DEBUG'), FILTER_VALIDATE_BOOLEAN));
if (!defined('APP_URL'))   define('APP_URL',   (string)getenv('APP_URL'));

// Derive APP_BASE from the request URL (not the filesystem), so links work
// whether the app is in the docroot, a subfolder, or served via an Apache
// Alias / VirtualHost — and still resolve to '' at a domain root (InfinityFree).
// Entry points are /index.php, /pages/*.php and /api/*.php.
$_script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$_base   = preg_replace('#/(pages|api)/[^/]*$#', '', $_script); // strip /pages/x.php or /api/x.php
$_base   = preg_replace('#/index\.php$#', '', $_base);          // strip /index.php
define('APP_BASE', rtrim($_base, '/'));                         // e.g. /Trackie  (or '' at root)
unset($_script, $_base);

// ── Security headers ──────────────────────────────────────────
// Sent from PHP rather than only .htaccess: mod_headers is not guaranteed on
// shared hosting (InfinityFree), and a header block that silently does not
// apply is worse than none because it looks covered in review.
// Content-Security-Policy: the app still has inline <script> blocks and style
// attributes, so script/style need 'unsafe-inline' (tracked debt: move to
// nonces). The policy still earns its keep — scripts can only load from the
// origins below (an injected <script src=//evil> is blocked), plugins are
// off, <base> can't be hijacked, forms can't post off-site and no other site
// can frame Trackie. Images are https-anywhere because covers/avatars come
// from many CDNs (Open Library, Spotify, RAWG, GitHub, Google…).
// Spotify's Web Playback SDK (Music page) needs sdk.scdn.co + *.spotify.com.
const TRACKIE_CSP = "default-src 'self'; "
    . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://sdk.scdn.co; "
    . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; "
    . "font-src 'self' data: https://fonts.gstatic.com https://cdnjs.cloudflare.com; "
    . "img-src 'self' data: blob: https:; "
    . "media-src 'self' blob: https:; "
    . "connect-src 'self' https://cdn.jsdelivr.net https://*.spotify.com wss://*.spotify.com https://*.scdn.co; "
    . "frame-src 'self' https://sdk.scdn.co https://*.spotify.com; "
    . "worker-src 'self'; manifest-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'; "
    . "form-action 'self' https://github.com https://accounts.google.com https://accounts.spotify.com https://www.strava.com";
if (!headers_sent()) {
    header('Content-Security-Policy: ' . TRACKIE_CSP);
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    // No feature in Trackie uses these; deny them so a compromised third-party
    // script cannot silently reach for the camera, mic or location.
    header('Permissions-Policy: geolocation=(), camera=(), microphone=(), payment=(), usb=()');
    // HSTS only over HTTPS — sending it on plain HTTP is ignored at best, and
    // pinning a local http:// dev host would be actively unhelpful.
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// ── Session ───────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => APP_BASE . '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ── Timezone ──────────────────────────────────────────────────
// Must match the MySQL server's timezone — date('Y-m-d') in PHP and
// CURDATE()/NOW() in SQL are compared all over the app (todos due
// today, habit logs, reminder scheduling). Override via APP_TIMEZONE,
// as a constant in env.php (preferred) or an environment variable.
date_default_timezone_set(
    defined('APP_TIMEZONE') ? APP_TIMEZONE : (getenv('APP_TIMEZONE') ?: 'Asia/Kolkata')
);
