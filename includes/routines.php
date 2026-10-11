<?php
/**
 * Routine day states — the one reader for routine_logs + routine_rest_days.
 *
 *   routines.schedule_days   the weekly template (NULL = every day)
 *   routine_logs.status      what happened to ONE routine on ONE day:
 *                            'done' or 'skipped' (a one-day exception)
 *   routine_rest_days        a planned rest day for the whole plan
 *
 * Everything that shows or counts routines goes through routineDayState(), so
 * the Routines page, Today, the Calendar and the activity log agree. Works on a
 * database without the 2026-10-11 migration: every log is then 'done' and
 * there are no rest days — exactly the old behaviour.
 */
require_once __DIR__ . '/habit_schedule.php';

/** routine_logs.status exists (migration 2026-10-11 applied). */
function routineSkipsReady(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { db()->query("SELECT status FROM routine_logs LIMIT 1"); return $ok = true; }
    catch (Throwable $e) { return $ok = false; }
}

function routineRestReady(): bool {
    return tableExists('routine_rest_days');
}

/** "routine_id|Y-m-d" => 'done' | 'skipped' for days in [$from, $to]. */
function routineLogStates(int $uid, string $from, string $to): array {
    if (!tableExists('routine_logs')) return [];
    $col = routineSkipsReady() ? 'status' : "'done'";
    $out = [];
    foreach (fetchAll("SELECT routine_id, log_date, $col AS status FROM routine_logs WHERE user_id=? AND log_date BETWEEN ? AND ?", [$uid, $from, $to]) as $r) {
        $out[$r['routine_id'] . '|' . $r['log_date']] = $r['status'];
    }
    return $out;
}

/** Y-m-d => true for planned rest days in [$from, $to]. */
function routineRestDays(int $uid, string $from, string $to): array {
    if (!routineRestReady()) return [];
    return array_fill_keys(array_column(fetchAll("SELECT rest_date FROM routine_rest_days WHERE user_id=? AND rest_date BETWEEN ? AND ?", [$uid, $from, $to]), 'rest_date'), true);
}

/** Does the weekly template schedule this routine on $date? */
function routineScheduledOn(array $r, string $date): bool {
    $days = habitDays(['frequency' => 'daily', 'schedule_days' => $r['schedule_days'] ?? null]);
    return !$days || in_array((int)date('w', strtotime($date . ' 12:00:00')), $days, true);
}

/**
 * One routine on one day:
 *   'done'    recorded as done      } what the user recorded always wins, so
 *   'skipped' recorded as skipped   } history survives later schedule changes
 *   'rest'    scheduled, but the day is a planned rest day — not expected
 *   'due'     scheduled, nothing recorded (on a past day: the only real miss)
 *   'off'     not scheduled that day
 */
function routineDayState(array $r, string $date, array $logs, array $restDays): string {
    $logged = $logs[$r['id'] . '|' . $date] ?? null;
    if ($logged === 'done' || $logged === 'skipped') return $logged;
    if (!routineScheduledOn($r, $date)) return 'off';
    return isset($restDays[$date]) ? 'rest' : 'due';
}

/**
 * Progress for one day's states: done out of expected. Only 'due' and 'done'
 * are expected — a skip, a rest day or an unscheduled routine never counts
 * against the day.
 */
function routineProgress(array $states): array {
    $c = array_count_values($states);
    $done = $c['done'] ?? 0;
    $expected = $done + ($c['due'] ?? 0);
    return ['done' => $done, 'expected' => $expected, 'pct' => $expected ? (int)round($done / $expected * 100) : 0];
}

/**
 * The user's routines with their state on $date, ordered by time.
 * @return array<int, array> each routine row plus 'state'
 */
function routinesOnDay(int $uid, string $date): array {
    if (!tableExists('routines')) return [];
    $logs = routineLogStates($uid, $date, $date);
    $rest = routineRestDays($uid, $date, $date);
    $rows = fetchAll("SELECT * FROM routines WHERE user_id=? ORDER BY time_slot ASC", [$uid]);
    foreach ($rows as &$r) $r['state'] = routineDayState($r, $date, $logs, $rest);
    return $rows;
}
