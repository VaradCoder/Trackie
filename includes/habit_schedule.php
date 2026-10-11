<?php
/**
 * Habit scheduling rules — the single definition of "due" and "streak".
 *
 *   daily, no schedule_days   → due every day
 *   daily, schedule_days=1,3,5 → due Mon/Wed/Fri only; other days never break a streak
 *   weekly                     → due once per week (the user's week); streak counts weeks
 */

require_once __DIR__ . '/settings.php';

const WEEKDAY_SHORT = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

/** Parsed weekday list, or [] for "every day". */
function habitDays(array $h): array {
    // Not empty(): "0" (Sunday only) is a real schedule, but empty('0') is true in PHP.
    $raw = (string)($h['schedule_days'] ?? '');
    if (($h['frequency'] ?? 'daily') !== 'daily' || $raw === '') return [];
    $d = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$h['schedule_days'])), static fn($x) => $x >= 0 && $x <= 6)));
    sort($d);
    return count($d) === 7 ? [] : $d;
}

/** Clean a user-supplied list for storage ("1,3,5"), or null for every day. */
function normalizeScheduleDays($raw): ?string {
    $list = is_array($raw) ? $raw : explode(',', (string)$raw);
    $d = array_values(array_unique(array_filter(array_map('intval', array_filter($list, 'is_numeric')), static fn($x) => $x >= 0 && $x <= 6)));
    sort($d);
    return ($d && count($d) < 7) ? implode(',', $d) : null;
}

function habitScheduleLabel(array $h): string {
    if (($h['frequency'] ?? 'daily') === 'weekly') return 'Weekly';
    $d = habitDays($h);
    if (!$d) return 'Daily';
    if ($d === [1, 2, 3, 4, 5]) return 'Weekdays';
    if ($d === [0, 6]) return 'Weekends';
    return implode(' · ', array_map(static fn($x) => WEEKDAY_SHORT[$x], $d));
}

/** Start (Y-m-d) of the week containing $date, per the user's week start. */
function weekStartOf(string $date, int $weekStart): string {
    $w = (int)date('w', strtotime($date));
    return date('Y-m-d', strtotime("-" . (($w - $weekStart + 7) % 7) . " day", strtotime($date)));
}

/**
 * Due on $date? $logDates lets weekly habits drop off once done this week.
 */
function habitDueOn(array $h, string $date, array $logDates = [], int $weekStart = 0): bool {
    if (($h['frequency'] ?? 'daily') === 'weekly') {
        $ws = weekStartOf($date, $weekStart);
        foreach ($logDates as $d) if ($d >= $ws && $d < $date) return false;   // already done earlier this week
        return true;
    }
    $days = habitDays($h);
    return !$days || in_array((int)date('w', strtotime($date)), $days, true);
}

/** How many times the habit is expected in the week containing $date. */
function habitWeekTarget(array $h): int {
    if (($h['frequency'] ?? 'daily') === 'weekly') return 1;
    $d = habitDays($h);
    return $d ? count($d) : 7;
}

/**
 * Streak over a weekly schedule (used by workouts; see workoutStreak()).
 *
 * A day only breaks the run when it was DUE and nothing was done. Days that
 * aren't due are planned rest: they never break it, and a completion on one
 * still counts — an extra session is still a session. Today never breaks it,
 * because it isn't over yet.
 *
 * @param string[]           $dates    Y-m-d completion dates (any order, dupes ok).
 * @param array<int, string> $dueSince weekday (0 = Sun … 6 = Sat) => Y-m-d it became
 *        due. Before that date the weekday counts as rest, so adding a new plan
 *        day doesn't retroactively turn old weeks into misses. Must not be empty —
 *        with no schedule every day is due; callers use calculateStreaks().
 * @param string|null        $today    Y-m-d "today" in the user's zone (tests pass one).
 */
function scheduledStreak(array $dates, array $dueSince, ?string $today = null): array {
    $today ??= date('Y-m-d');
    $set = array_flip(array_filter($dates, static fn($d) => is_string($d) && $d <= $today));
    if (!$set) return ['current' => 0, 'best' => 0];
    ksort($set);

    $run = $best = 0;
    // Noon, not midnight: in zones whose DST switch happens at midnight, 00:00
    // can be skipped and "+1 day" would then drift. Calendar days are what count.
    $day = new DateTimeImmutable(array_key_first($set) . ' 12:00:00');
    for ($d = $day->format('Y-m-d'); $d <= $today; $day = $day->modify('+1 day'), $d = $day->format('Y-m-d')) {
        if (isset($set[$d])) { $run++; $best = max($best, $run); continue; }
        $since = $dueSince[(int)$day->format('w')] ?? null;
        if ($since !== null && $d >= $since && $d !== $today) $run = 0;   // missed a planned day
    }
    return ['current' => $run, 'best' => $best];
}

/**
 * Current/best streak for ONE habit, honouring its schedule:
 * unscheduled days are skipped; weekly habits count consecutive weeks.
 * Today (or this week) not done yet doesn't break the current streak.
 */
function habitStreak(array $h, array $logDates, int $weekStart = 0): array {
    $set = array_flip($logDates);
    if (!$set) return ['current' => 0, 'best' => 0];
    $first = min($logDates);
    $today = date('Y-m-d');

    if (($h['frequency'] ?? 'daily') === 'weekly') {
        $weeks = [];
        foreach ($logDates as $d) $weeks[weekStartOf($d, $weekStart)] = true;
        $w = weekStartOf($first, $weekStart); $end = weekStartOf($today, $weekStart);
        $run = $best = 0; $cur = 0;
        for (; $w <= $end; $w = date('Y-m-d', strtotime('+7 day', strtotime($w)))) {
            if (isset($weeks[$w])) { $run++; $best = max($best, $run); }
            elseif ($w !== $end) { $run = 0; }
        }
        return ['current' => $run, 'best' => $best];
    }

    $run = $best = 0;
    for ($d = $first; $d <= $today; $d = date('Y-m-d', strtotime('+1 day', strtotime($d)))) {
        if (!habitDueOn($h, $d)) continue;                 // not scheduled: neutral
        if (isset($set[$d])) { $run++; $best = max($best, $run); }
        elseif ($d !== $today) { $run = 0; }               // missed a scheduled day
    }
    return ['current' => $run, 'best' => $best];
}
