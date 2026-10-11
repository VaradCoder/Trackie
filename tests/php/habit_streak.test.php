<?php
/**
 * Overall habit streak (Dashboard, Habits header, Analytics, Today, sidebar,
 * /api/me, streak milestones, achievements) — pure rules, no database.
 *   php tests/php/habit_streak.test.php
 *
 * Regression for: days on which no habit was scheduled broke the overall
 * habit streak, because every page ran calculateStreaks(all completion dates),
 * which treats every calendar day as required.
 */
date_default_timezone_set('UTC');
require __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/habit_schedule.php';

$pass = $fail = 0;
function ok(bool $cond, string $name): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  $name\n"; } else { $fail++; echo "FAIL  $name\n"; }
}
function eq(array $got, int $cur, int $best, string $name): void {
    ok($got['current'] === $cur && $got['best'] === $best, $name . "  (got {$got['current']}/{$got['best']}, want $cur/$best)");
}
function days(string $from, string $to, ?array $weekdays = null): array {
    $out = [];
    for ($t = strtotime("$from 12:00"); date('Y-m-d', $t) <= $to; $t = strtotime('+1 day', $t))
        if ($weekdays === null || in_array((int)date('w', $t), $weekdays, true)) $out[] = date('Y-m-d', $t);
    return $out;
}
$H = static fn(int $id, string $freq = 'daily', ?string $sched = null, string $created = '2026-01-01') =>
    ['id' => $id, 'frequency' => $freq, 'schedule_days' => $sched, 'created_on' => $created];

// Week of 2026-10-05: Mon 05 … Sun 11. Today in these tests = Sun 2026-10-11 unless stated.
$T = '2026-10-11';
$mwf = days('2026-09-21', '2026-10-09', [1, 3, 5]);   // 9 Mon/Wed/Fri dates

// ── Scheduled habit: off days are neutral
eq(overallHabitStreak([$H(1, 'daily', '1,3,5')], [1 => $mwf], [], 0, $T), 9, 9, 'Mon/Wed/Fri habit done every time → 9 (was 0)');
eq(calculateStreaks($mwf, [], $T), 0, 1, '(the bug) old aggregate on the same data → 0 / best 1');
eq(overallHabitStreak([$H(1, 'daily', '1,3,5')], [1 => array_values(array_diff($mwf, ['2026-09-30']))], [], 0, $T), 4, 4, 'missing one scheduled Wednesday breaks it; best = the run before or after');
eq(overallHabitStreak([$H(1, 'daily', '1,3,5')], [1 => array_values(array_diff($mwf, ['2026-09-30']))], ['2026-09-30'], 0, $T), 8, 8, '…unless that Wednesday was explicitly skipped: bridged, not counted (existing rule)');
eq(overallHabitStreak([$H(1, 'daily', '1,3,5')], [1 => array_merge($mwf, ['2026-10-10'])], [], 0, $T), 10, 10, 'a completion on an off day still counts');

// ── Daily habit: no exemption, behaviour identical to before
$daily = days('2026-10-01', '2026-10-10');
eq(overallHabitStreak([$H(1)], [1 => $daily], [], 0, $T), 10, 10, 'daily habit, 10 days done, today pending → 10');
eq(overallHabitStreak([$H(1)], [1 => array_values(array_diff($daily, ['2026-10-06']))], [], 0, $T), 4, 5, 'daily habit: a missed day breaks it');
mt_srand(7); $same = true;
for ($i = 0; $i < 200; $i++) {   // only-daily users: new == old for random histories
    $d = array_values(array_filter(days('2026-08-01', $T), static fn() => mt_rand(0, 3) > 0));
    $sk = array_values(array_filter(days('2026-08-01', $T), static fn() => mt_rand(0, 9) === 0));
    if (overallHabitStreak([$H(1, 'daily', null, '2026-08-01')], [1 => $d], $sk, 0, $T) !== calculateStreaks($d, $sk, $T)) { $same = false; break; }
}
ok($same, 'daily-only users: identical to the old calculation on 200 random histories');

// ── Mixed: any required habit makes the day required
eq(overallHabitStreak([$H(1), $H(2, 'daily', '1,3,5')], [2 => $mwf], [], 0, $T), 0, 1, 'a daily habit makes every day required — off days for one habit are not off for all');
eq(overallHabitStreak([$H(1, 'daily', '1,3,5'), $H(2, 'daily', '2,4')], [1 => days('2026-10-05', '2026-10-09', [1, 3, 5]), 2 => ['2026-10-06', '2026-10-08']], [], 0, $T), 5, 5, 'Mon/Wed/Fri + Tue/Thu habits: weekend neutral → 5');

// ── Weekly habits: missable only at week end
$wk = $H(1, 'weekly');
eq(overallHabitStreak([$wk], [1 => ['2026-09-23', '2026-09-30', '2026-10-08']], [], 1, $T), 3, 3, 'weekly habit done each week (Mon-start) → 3, mid-week gaps neutral');
eq(overallHabitStreak([$wk], [1 => ['2026-09-16', '2026-09-30', '2026-10-08']], [], 1, $T), 2, 2, 'a week with no completion breaks it (at that week\'s last day)');
eq(overallHabitStreak([$wk], [1 => ['2026-09-30']], [], 1, '2026-10-11'), 1, 1, 'this week ends today (Sun) and is not done → not missed yet');
eq(overallHabitStreak([$wk], [1 => ['2026-09-30']], [], 1, '2026-10-12'), 0, 1, '…Monday: that week is now missed');
eq(overallHabitStreak([$wk], [1 => ['2026-09-30']], [], 1, '2026-10-10'), 1, 1, 'mid-week: last week\'s completion still current until this week ends');

// ── Creation date and schedule history
eq(overallHabitStreak([$H(1), $H(2, 'daily', null, '2026-10-08')], [1 => ['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10']], [], 0, $T), 6, 6, 'a habit added on Thu does not turn earlier days into misses');
eq(overallHabitStreak([$H(1, 'daily', '1,3,5', '2026-10-06')], [1 => ['2026-10-05', '2026-10-07']], [], 0, '2026-10-08'), 2, 2, 'completions before creation (back-dated) still count');
eq(overallHabitStreak([$H(1, 'daily', '1')], [1 => ['2026-09-28', '2026-10-05']], [], 0, $T), 2, 2, 'Monday-only: six neutral days between');
eq(overallHabitStreak([$H(1)], [1 => ['2026-10-12']], [], 0, $T), 0, 0, 'future-dated log ignored');
eq(overallHabitStreak([], [], [], 0, $T), 0, 0, 'no habits → 0');

// ── Midnight / time zone: "today" is the user's local date (applyUserTimezone)
eq(overallHabitStreak([$H(1, 'daily', '1,3,5')], [1 => ['2026-10-05']], [], 0, '2026-10-07'), 1, 1, 'Wed 23:59 local, Wed not done yet → still 1');
eq(overallHabitStreak([$H(1, 'daily', '1,3,5')], [1 => ['2026-10-05']], [], 0, '2026-10-08'), 0, 1, 'Thu 00:00 local → Wednesday missed');
date_default_timezone_set('America/New_York');   // crosses the 1 Nov 2026 DST change
$ny = days('2026-10-26', '2026-11-06', [1, 3, 5]);
eq(overallHabitStreak([$H(1, 'daily', '1,3,5')], [1 => $ny], [], 0, '2026-11-08'), count($ny), count($ny), 'DST week: no day dropped or doubled');
date_default_timezone_set('UTC');

// ── habitRequiredDays directly
$req = habitRequiredDays([$H(1, 'daily', '1,3,5')], [], '2026-10-05', '2026-10-11');
ok(array_keys($req) === ['2026-10-05', '2026-10-07', '2026-10-09'], 'required days for Mon/Wed/Fri = exactly those dates');
$req = habitRequiredDays([$H(1, 'weekly')], [1 => ['2026-10-07']], '2026-09-28', '2026-10-11', 1);
ok(array_keys($req) === ['2026-10-04'], 'weekly: only the end of the missed week is required');

echo "== $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
