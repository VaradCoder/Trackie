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
    require_once __DIR__ . '/../includes/notify.php';
    return reminderNextFire($r);
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
        // Same engine as cron/dispatch.php (includes/notify.php): atomic
        // claim per fire, so an open tab and the cron can't both deliver it.
        // Only fires allowed to be loud (preferences + quiet hours) pop up.
        require_once '../includes/notify.php';
        $fired = [];
        foreach (fireDueReminders($uid) as $f) {
            if ($f['popup']) $fired[] = ['id' => $f['id'], 'title' => $f['title'], 'message' => $f['message'], 'pushed' => $f['pushed'] > 0];
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
        // still fires them). Quiet hours are skipped. Capped at 60 — iOS keeps at
        // most 64 pending.
        require_once '../includes/notify.php';
        json_out(['success' => true, 'occurrences' => upcomingReminderOccurrences($uid, 168, 60)]);

    default:
        json_out(['success' => false, 'error' => 'Unknown action.'], 400);
}
