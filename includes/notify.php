<?php
/**
 * Trackie — reminder / notification engine. The ONE place reminders fire.
 *
 *   reminder row ──► fireDueReminders() ──► bell (notifications table)
 *                                       ├─► Web Push (all subscribed devices)
 *                                       └─► returned list (in-tab pop-ups)
 *
 * Called by cron/dispatch.php (all users, no browser needed) and by the
 * in-tab poll (api/reminders.php, one user) — whichever runs first wins.
 *
 * Guarantees
 *   - Exactly once: each fire is CLAIMED with a conditional UPDATE on the
 *     reminder's current next_fire_at. A concurrent cron + poll (or two
 *     tabs) can both see a reminder as due, but only one claim succeeds.
 *   - Time zones: next_fire_at is the user's local wall-clock time (it is
 *     entered in their zone), so "due" is judged in each user's zone.
 *   - Preferences: notify_reminders off → bell only. Quiet hours → bell only.
 *   - Smart reminders stay silent when the linked habit is already done today.
 */

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/webpush.php';

/** Next fire time strictly after now, for recurring/smart reminders. */
function reminderNextFire(array $r, ?int $now = null): string {
    $now  = $now ?? time();
    $fire = strtotime($r['next_fire_at']);
    $step = $r['type'] === 'smart' ? '+1 day' : '+' . max(1, (int)$r['repeat_every']) . ' ' . $r['repeat_unit'];
    for ($i = 0; $fire <= $now && $i < 100000; $i++) $fire = strtotime($step, $fire);
    return date('Y-m-d H:i:s', $fire);
}

/**
 * Fire every due reminder — for one user (in-tab poll) or everyone (cron).
 * Returns what fired: [['id','user_id','title','message','popup'=>bool,'pushed'=>int], …]
 */
function fireDueReminders(?int $onlyUid = null): array {
    if (!tableExists('reminders')) return [];
    $users = $onlyUid !== null
        ? [$onlyUid]
        : array_map('intval', array_column(fetchAll("SELECT DISTINCT user_id FROM reminders WHERE active = 1"), 'user_id'));

    $origZone = date_default_timezone_get();
    $fired = [];
    foreach ($users as $uid) {
        // Judge "due" and "today" in this user's zone. The poll runs inside the
        // user's own request (zone already applied); cron switches per user.
        if ($onlyUid === null) applyUserTimezone($uid, false);
        $now = date('Y-m-d H:i:s');

        foreach (fetchAll("SELECT * FROM reminders WHERE user_id = ? AND active = 1 AND next_fire_at <= ? ORDER BY next_fire_at", [$uid, $now]) as $r) {
            // Claim this fire. Losing the race means another run already handled it.
            $claimed = $r['type'] === 'once'
                ? update("UPDATE reminders SET active = 0, last_fired_at = NOW() WHERE id = ? AND active = 1 AND next_fire_at = ?", [$r['id'], $r['next_fire_at']])
                : update("UPDATE reminders SET next_fire_at = ?, last_fired_at = NOW() WHERE id = ? AND active = 1 AND next_fire_at = ?", [reminderNextFire($r), $r['id'], $r['next_fire_at']]);
            if ($claimed !== 1) continue;

            $message = (string)($r['notes'] ?? '');
            if ($r['type'] === 'smart' && !empty($r['habit_id'])) {
                if (fetchOne("SELECT 1 FROM logs WHERE habit_id = ? AND date_completed = CURDATE() LIMIT 1", [(int)$r['habit_id']])) {
                    continue;   // habit already done today — stay quiet
                }
                $habit = fetchOne("SELECT name FROM habits WHERE id = ? AND user_id = ?", [(int)$r['habit_id'], $uid]);
                $message = $message ?: ('"' . ($habit['name'] ?? 'Habit') . '" isn\'t logged yet today.');
            }
            $title = '⏰ ' . $r['title'];
            $link  = APP_BASE . '/pages/reminders.php';

            // Bell — always. Direct insert: createNotification() dedupes per
            // day, which would swallow repeats of an every-N-hours reminder.
            insert("INSERT INTO notifications (user_id, type, title, message, link) VALUES (?,?,?,?,?)",
                   [$uid, 'reminder', $title, $message, $link]);

            $loud   = userSetting($uid, 'notify_reminders') && !inQuietHours($uid);
            $pushed = 0;
            if ($loud) {
                try {
                    $pushed = sendPushToUser($uid, [
                        'title' => $title,
                        'body'  => $message !== '' ? $message : 'Trackie reminder',
                        'url'   => $link,
                        'tag'   => 'reminder-' . $r['id'],
                    ]);
                } catch (Throwable $e) {
                    error_log('push failed for user ' . $uid . ': ' . $e->getMessage());   // bell entry still stands
                }
            }
            $fired[] = ['id' => (int)$r['id'], 'user_id' => $uid, 'title' => $r['title'], 'message' => $message,
                        'popup' => $loud, 'pushed' => $pushed];
        }
    }
    if ($onlyUid === null && date_default_timezone_get() !== $origZone) {
        date_default_timezone_set($origZone);
        try { db()->exec("SET time_zone = '" . date('P') . "'"); } catch (Throwable $e) {}
    }
    return $fired;
}
