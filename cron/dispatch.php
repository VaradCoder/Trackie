<?php
/**
 * Trackie — reminder dispatcher (server-side push delivery).
 *
 * Finds due reminders and delivers them via Web Push, so notifications
 * fire even when no browser tab is open. Also writes an in-app
 * notification and advances each reminder's schedule.
 *
 * Run every minute. Two ways to invoke:
 *
 *   1. Real cron (own server / hosting with cron):
 *        * * * * * php /path/to/Trackie/cron/dispatch.php
 *
 *   2. External HTTP scheduler (e.g. cron-job.org) when the host has no cron
 *      (InfinityFree): hit
 *        https://trackie.free.nf/cron/dispatch.php?token=YOUR_TOKEN
 *      The token is auto-generated on the first CLI run and stored in
 *      keys/cron_token.txt (web-blocked, git-ignored).
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/webpush.php';

$isCli = (PHP_SAPI === 'cli');

// ── Auth for HTTP invocation ─────────────────────────────────────
// CRON_TOKEN in config/env.php works on hosts without a command line
// (InfinityFree); the keys/ file is the fallback for CLI-bootstrapped setups.
$tokenFile = ROOT_PATH . '/keys/cron_token.txt';
$token = (string)env('CRON_TOKEN');
if (strlen($token) < 24) $token = is_file($tokenFile) ? trim(file_get_contents($tokenFile)) : '';

if ($isCli) {
    // First CLI run bootstraps the token for external HTTP schedulers.
    if ($token === '') {
        $token = bin2hex(random_bytes(24));
        if (!is_dir(ROOT_PATH . '/keys')) mkdir(ROOT_PATH . '/keys', 0700, true);
        // Never web-readable, even on hosts that ignore file permissions.
        @file_put_contents(ROOT_PATH . '/keys/.htaccess', "Require all denied
");
        file_put_contents($tokenFile, $token);
        fwrite(STDERR, "Generated cron token → keys/cron_token.txt\n");
    }
} else {
    $given = $_GET['token'] ?? '';
    if ($token === '' || !hash_equals($token, $given)) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain');
}

if (!tableExists('reminders')) { echo "reminders table missing\n"; exit; }

// One engine for every delivery path (includes/notify.php): claims each fire
// atomically, so a cron run and an open tab can never both send the same one;
// judges "due" in each user's own time zone; respects quiet hours and
// notification preferences; writes the bell entry and sends Web Push.
require_once __DIR__ . '/../includes/notify.php';
$fired  = fireDueReminders();
$pushed = array_sum(array_column($fired, 'pushed'));

echo 'dispatched: ' . count($fired) . " reminder(s), $pushed push(es) sent\n";
