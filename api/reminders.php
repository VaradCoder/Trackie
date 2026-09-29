<?php
/**
 * Reminders API — CRUD + poll.
 *
 * Poll model (no cron on shared PHP hosting): the frontend polls
 * `action=poll` every minute. Due reminders are returned for browser
 * notification, written to the notifications table, and advanced:
 *   once      → deactivated after firing
 *   recurring → next_fire_at stepped forward by its interval
 *   smart     → fires only if the linked habit isn't logged today,
 *               then advances to tomorrow at the same time
 */
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();
verify_csrf();

$uid    = currentUserId();
$action = sanitizeInput($_POST['action'] ?? '');

// Reminders table may be missing on an un-migrated database. Don't 500 —
// the global poller calls this every minute on every page.
if (!tableExists('reminders')) {
    if ($action === 'poll') json_out(['success' => true, 'fired' => [], 'unread' => 0]);
    json_out(['success' => false, 'error' => 'Reminders are not set up yet.'], 503);
}

/** Compute the first fire datetime for a reminder row (on create/update). */
function initialFire(string $type, ?string $date, string $time, int $every, string $unit): string {
    $now = time();
    if ($type === 'once') {
        return date('Y-m-d H:i:s', strtotime("$date $time"));
    }
    // recurring + smart: today at remind_time, stepped forward until future
    $fire = strtotime(date('Y-m-d') . " $time");
    $step = $type === 'smart' ? '+1 day' : "+{$every} {$unit}";
    while ($fire <= $now) {
        $fire = strtotime($step, $fire);
    }
    return date('Y-m-d H:i:s', $fire);
}

/** Advance a fired recurring/smart reminder past now. */
function advanceFire(array $r): string {
    $now  = time();
    $fire = strtotime($r['next_fire_at']);
    $step = $r['type'] === 'smart'
        ? '+1 day'
        : '+' . max(1, (int)$r['repeat_every']) . ' ' . $r['repeat_unit'];
    while ($fire <= $now) {
        $fire = strtotime($step, $fire);
    }
    return date('Y-m-d H:i:s', $fire);
}

/** Validate + collect fields shared by add/edit. Exits with 422 on bad input. */
function reminderInput(int $uid): array {
    $title = sanitizeInput($_POST['title'] ?? '');
    $notes = sanitizeInput($_POST['notes'] ?? '');
    $type  = $_POST['type'] ?? 'once';
    $date  = sanitizeInput($_POST['remind_date'] ?? '');
    $time  = sanitizeInput($_POST['remind_time'] ?? '');
    $every = max(1, (int)($_POST['repeat_every'] ?? 1));
    $unit  = $_POST['repeat_unit'] ?? 'day';
    $habit = (int)($_POST['habit_id'] ?? 0) ?: null;

    if (!$title) json_out(['success' => false, 'error' => 'Title is required.'], 422);
    if (!in_array($type, ['once', 'recurring', 'smart'], true)) $type = 'once';
    if (!in_array($unit, ['hour', 'day', 'week'], true)) $unit = 'day';
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
        json_out(['success' => false, 'error' => 'A valid time is required.'], 422);
    }

    if ($type === 'once') {
        if (!$date || strtotime("$date $time") === false) {
            json_out(['success' => false, 'error' => 'A valid date is required for one-time reminders.'], 422);
        }
        if (strtotime("$date $time") <= time()) {
            json_out(['success' => false, 'error' => 'That date/time is already in the past.'], 422);
        }
    } else {
        $date = null;
    }

    if ($type === 'smart') {
        if (!$habit || !fetchOne("SELECT id FROM habits WHERE id=? AND user_id=?", [$habit, $uid])) {
            json_out(['success' => false, 'error' => 'Pick a habit for the smart reminder.'], 422);
        }
    } else {
        $habit = null;
    }

    return [
        'title' => $title, 'notes' => $notes, 'type' => $type,
        'date'  => $date,  'time'  => $time,  'every' => $every,
        'unit'  => $unit,  'habit' => $habit,
        'next'  => initialFire($type, $date, $time, $every, $unit),
    ];
}

switch ($action) {

    case 'add':
        $in = reminderInput($uid);
        $id = insert(
            "INSERT INTO reminders
             (user_id,title,notes,type,remind_date,remind_time,repeat_every,repeat_unit,habit_id,next_fire_at)
             VALUES (?,?,?,?,?,?,?,?,?,?)",
            [$uid, $in['title'], $in['notes'], $in['type'], $in['date'], $in['time'],
             $in['every'], $in['unit'], $in['habit'], $in['next']]
        );
        json_out(['success' => true, 'id' => $id]);

    case 'edit':
        $id = (int)($_POST['reminder_id'] ?? 0);
        if (!$id || !fetchOne("SELECT id FROM reminders WHERE id=? AND user_id=?", [$id, $uid])) {
            json_out(['success' => false, 'error' => 'Reminder not found.'], 404);
        }
        $in = reminderInput($uid);
        update(
            "UPDATE reminders SET title=?,notes=?,type=?,remind_date=?,remind_time=?,
             repeat_every=?,repeat_unit=?,habit_id=?,next_fire_at=?,active=1
             WHERE id=? AND user_id=?",
            [$in['title'], $in['notes'], $in['type'], $in['date'], $in['time'],
             $in['every'], $in['unit'], $in['habit'], $in['next'], $id, $uid]
        );
        json_out(['success' => true]);

    case 'toggle':
        $id = (int)($_POST['reminder_id'] ?? 0);
        $r  = fetchOne("SELECT * FROM reminders WHERE id=? AND user_id=?", [$id, $uid]);
        if (!$r) json_out(['success' => false, 'error' => 'Reminder not found.'], 404);

        if ($r['active']) {
            update("UPDATE reminders SET active=0 WHERE id=?", [$id]);
            json_out(['success' => true, 'active' => 0]);
        }
        // Re-activating: recompute next fire so it doesn't fire instantly
        if ($r['type'] === 'once' && strtotime($r['next_fire_at']) <= time()) {
            json_out(['success' => false, 'error' => 'This one-time reminder is in the past — edit it to set a new date.'], 422);
        }
        $next = $r['type'] === 'once' ? $r['next_fire_at'] : advanceFire($r);
        update("UPDATE reminders SET active=1, next_fire_at=? WHERE id=?", [$next, $id]);
        json_out(['success' => true, 'active' => 1, 'next_fire_at' => $next]);

    case 'delete':
        $id = (int)($_POST['reminder_id'] ?? 0);
        delete("DELETE FROM reminders WHERE id=? AND user_id=?", [$id, $uid]);
        json_out(['success' => true]);

    case 'get':
        $id = (int)($_POST['reminder_id'] ?? 0);
        $r  = fetchOne("SELECT * FROM reminders WHERE id=? AND user_id=?", [$id, $uid]);
        if (!$r) json_out(['success' => false, 'error' => 'Reminder not found.'], 404);
        json_out(['success' => true, 'reminder' => $r]);

    case 'poll':
        $due   = fetchAll(
            "SELECT * FROM reminders WHERE user_id=? AND active=1 AND next_fire_at <= NOW()",
            [$uid]
        );
        $fired = [];

        foreach ($due as $r) {
            $notify  = true;
            $message = $r['notes'] ?: '';

            if ($r['type'] === 'smart' && $r['habit_id']) {
                $logged = fetchOne(
                    "SELECT id FROM logs WHERE habit_id=? AND date_completed=CURDATE()",
                    [$r['habit_id']]
                );
                if ($logged) {
                    $notify = false;     // habit already done today — stay quiet
                } else {
                    $habit   = fetchOne("SELECT name FROM habits WHERE id=?", [$r['habit_id']]);
                    $message = $message ?: ('"' . ($habit['name'] ?? 'Habit') . '" isn\'t logged yet today.');
                }
            }

            if ($r['type'] === 'once') {
                update("UPDATE reminders SET active=0, last_fired_at=NOW() WHERE id=?", [$r['id']]);
            } else {
                update("UPDATE reminders SET next_fire_at=?, last_fired_at=NOW() WHERE id=?",
                       [advanceFire($r), $r['id']]);
            }

            if ($notify) {
                // Direct insert — createNotification() dedupes per day, which
                // would swallow repeats of an every-N-hours reminder.
                insert(
                    "INSERT INTO notifications (user_id,type,title,message,link) VALUES (?,?,?,?,?)",
                    [$uid, 'reminder', '⏰ ' . $r['title'], $message, APP_BASE . '/pages/reminders.php']
                );
                // Settings → Preferences: pop-ups can be switched off; the bell entry above stays.
                require_once '../includes/settings.php';
                if (userSetting($uid, 'notify_reminders')) {
                    $fired[] = ['id' => (int)$r['id'], 'title' => $r['title'], 'message' => $message];
                }
            }
        }

        json_out([
            'success' => true,
            'fired'   => $fired,
            'unread'  => unreadNotificationCount($uid),
        ]);

    case 'upcoming':
        // Every occurrence in the next 7 days, for the native app to schedule
        // as OS-level local notifications that fire even when it's closed.
        // Smart reminders are left out: they depend on whether the habit is
        // logged by then, which can't be known in advance (the in-app check
        // still fires them). Capped at 60 — iOS keeps at most 64 pending.
        require_once '../includes/settings.php';
        if (!userSetting($uid, 'notify_reminders')) json_out(['success' => true, 'occurrences' => []]);
        $until = time() + 7 * 86400;
        $occ = [];
        foreach (fetchAll("SELECT id, title, notes, type, repeat_every, repeat_unit, next_fire_at FROM reminders
                           WHERE user_id=? AND active=1 AND type<>'smart' AND next_fire_at <= DATE_ADD(NOW(), INTERVAL 7 DAY)", [$uid]) as $r) {
            $t = strtotime($r['next_fire_at']);
            $step = '+' . max(1, (int)$r['repeat_every']) . ' ' . $r['repeat_unit'];
            for ($n = 0; $t <= $until && $n < 200; $n++) {
                if ($t > time()) $occ[] = ['reminder_id' => (int)$r['id'], 'title' => $r['title'], 'body' => $r['notes'] ?: 'Trackie reminder', 'at' => date('c', $t)];
                if ($r['type'] === 'once') break;
                $t = strtotime($step, $t);
            }
        }
        usort($occ, static fn($a, $b) => strcmp($a['at'], $b['at']));
        json_out(['success' => true, 'occurrences' => array_slice($occ, 0, 60)]);

    default:
        json_out(['success' => false, 'error' => 'Unknown action.'], 400);
}
