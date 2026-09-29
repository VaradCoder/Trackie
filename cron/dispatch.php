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
$tokenFile = ROOT_PATH . '/keys/cron_token.txt';
$token = is_file($tokenFile) ? trim(file_get_contents($tokenFile)) : '';

if ($isCli) {
    // First CLI run bootstraps the token for external HTTP schedulers.
    if ($token === '') {
        $token = bin2hex(random_bytes(24));
        if (!is_dir(ROOT_PATH . '/keys')) mkdir(ROOT_PATH . '/keys', 0700, true);
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

// ── Advance a fired recurring/smart reminder past now ───────────
function nextFire(array $r): string
{
    $now  = time();
    $fire = strtotime($r['next_fire_at']);
    $step = $r['type'] === 'smart'
        ? '+1 day'
        : '+' . max(1, (int) $r['repeat_every']) . ' ' . $r['repeat_unit'];
    while ($fire <= $now) {
        $fire = strtotime($step, $fire);
    }
    return date('Y-m-d H:i:s', $fire);
}

// ── Find every due reminder across all users ────────────────────
$due = fetchAll(
    "SELECT * FROM reminders WHERE active = 1 AND next_fire_at <= NOW() ORDER BY user_id"
);

$pushed = 0;
$fired  = 0;

foreach ($due as $r) {
    $uid = (int) $r['user_id'];

    // Smart reminders only fire if the linked habit isn't logged today.
    if ($r['type'] === 'smart' && !empty($r['habit_id'])) {
        $logged = fetchOne(
            "SELECT 1 FROM logs WHERE habit_id = ? AND date_completed = CURDATE() LIMIT 1",
            [(int) $r['habit_id']]
        );
        if ($logged) {
            // Skip this fire; just advance the schedule.
            update("UPDATE reminders SET next_fire_at = ? WHERE id = ?", [nextFire($r), $r['id']]);
            continue;
        }
    }

    $title = '⏰ ' . $r['title'];
    $body  = $r['notes'] !== null && $r['notes'] !== '' ? $r['notes'] : 'Trackie reminder';

    // In-app notification (bell) — deduped per day by createNotification().
    createNotification($uid, 'reminder', $title, $body, APP_BASE . '/pages/reminders.php');

    // Web Push to the user's devices — unless they turned reminder notifications off.
    require_once __DIR__ . '/../includes/settings.php';
    if (userSetting($uid, 'notify_reminders')) $pushed += sendPushToUser($uid, [
        'title' => $title,
        'body'  => $body,
        'url'   => APP_BASE . '/pages/reminders.php',
        'tag'   => 'reminder-' . $r['id'],
    ]);
    $fired++;

    // Advance the schedule: once → deactivate; recurring/smart → step forward.
    if ($r['type'] === 'once') {
        update("UPDATE reminders SET active = 0, last_fired_at = NOW() WHERE id = ?", [$r['id']]);
    } else {
        update(
            "UPDATE reminders SET next_fire_at = ?, last_fired_at = NOW() WHERE id = ?",
            [nextFire($r), $r['id']]
        );
    }
}

echo "dispatched: $fired reminder(s), $pushed push(es) sent\n";
