<?php
/**
 * Streak rules — pure functions, no database.  php tests/php/streak.test.php
 *
 * Regression for: a planned rest day (no workout scheduled) reset the Fitness
 * streak, because every workout streak ran calculateStreaks(), which treats
 * every calendar day as required.
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

// Week of 2026-10-05: Mon 05, Tue 06, Wed 07, Thu 08, Fri 09, Sat 10, Sun 11.
$MWF   = [1 => '2026-01-01', 3 => '2026-01-01', 5 => '2026-01-01'];   // Mon/Wed/Fri planned all year
$weeks = ['2026-09-21', '2026-09-23', '2026-09-25', '2026-09-28', '2026-09-30', '2026-10-02', '2026-10-05', '2026-10-07', '2026-10-09'];

// 1. Scheduled workouts completed
eq(scheduledStreak($weeks, $MWF, '2026-10-09'), 9, 9, '1. every planned workout done → streak counts each one');
eq(scheduledStreak($weeks, $MWF, '2026-10-11'), 9, 9, '1. …and survives the weekend rest days after it');
eq(calculateStreaks(array_slice($weeks, 6)), 0, 1, '1. (the bug) calculateStreaks on the same dates gave 0 / best 1');

// 2. Planned rest day between completed days
eq(scheduledStreak(['2026-10-05', '2026-10-07'], $MWF, '2026-10-07'), 2, 2, '2. Mon done, Tue planned rest, Wed done → 2');
eq(scheduledStreak(['2026-10-05', '2026-10-07'], $MWF, '2026-10-08'), 2, 2, '2. Thursday (rest) does not break it');
eq(scheduledStreak(['2026-10-05', '2026-10-06', '2026-10-07'], $MWF, '2026-10-07'), 3, 3, '2. a bonus session on a rest day still counts');

// 3. Missed required workout day
eq(scheduledStreak(['2026-10-02', '2026-10-05', '2026-10-09'], $MWF, '2026-10-09'), 1, 2, '3. Wed missed → run restarts at Fri, best keeps Fri+Mon');
eq(scheduledStreak(['2026-10-05'], $MWF, '2026-10-08'), 0, 1, '3. a missed Wednesday shows by Thursday');
eq(scheduledStreak(['2026-10-05'], $MWF, '2026-10-07'), 1, 1, '3. today (Wed) not done yet does NOT break it — the day is not over');

// 4. Daily habit, no rest-day exemption — unchanged behaviour
eq(calculateStreaks(['2026-10-05', '2026-10-06', '2026-10-08']), 0, 2, '4. daily: a gap day breaks the streak (no exemption)');
$y = date('Y-m-d', strtotime('-1 day')); $y3 = date('Y-m-d', strtotime('-3 day'));
eq(calculateStreaks([$y3, $y]), 1, 1, '4. daily: done yesterday, not yet today → 1 (grace until midnight), the earlier gap still broke it');
eq(calculateStreaks(['2026-10-05', '2026-10-07'], ['2026-10-06']), 0, 2, '4. daily: only an explicit "skip" bridges a gap (existing rule)');
eq(habitStreak(['frequency' => 'daily', 'schedule_days' => null], ['2026-10-05', '2026-10-07']), 0, 1, '4. per-habit daily habit: a missed day breaks it');
ok(scheduledStreak(['2026-10-05', '2026-10-07'], [0 => '2026-01-01', 1 => '2026-01-01', 2 => '2026-01-01', 3 => '2026-01-01', 4 => '2026-01-01', 5 => '2026-01-01', 6 => '2026-01-01'], '2026-10-07')['current'] === 1,
   '4. a plan for all 7 days has no rest days — a gap breaks it');

// 5. Time zones and midnight boundaries
//    Dates are the user's local calendar dates (applyUserTimezone() sets PHP and
//    MySQL to the user's zone per request), so "today" is the local date.
$at = strtotime('2026-10-07 20:00:00 UTC');   // Wed 20:00 UTC = Thu 01:30 in Kolkata
foreach (['UTC' => ['2026-10-07', 1], 'Asia/Kolkata' => ['2026-10-08', 1], 'America/Los_Angeles' => ['2026-10-07', 1]] as $tz => [$want, $cur]) {
    date_default_timezone_set($tz);
    $today = date('Y-m-d', $at);
    ok($today === $want, "5. $tz: same instant is local date $want");
    eq(scheduledStreak(['2026-10-05'], $MWF, $today), $tz === 'Asia/Kolkata' ? 0 : 1, 1,
       "5. $tz: Mon done, Wed " . ($tz === 'Asia/Kolkata' ? 'is over locally → missed' : 'still today locally → pending'));
}
date_default_timezone_set('UTC');
eq(scheduledStreak(['2026-10-05'], $MWF, '2026-10-07'), 1, 1, '5. 23:59 Wed: pending');
eq(scheduledStreak(['2026-10-05'], $MWF, '2026-10-08'), 0, 1, '5. 00:00 Thu: Wednesday is now a miss');
foreach (['America/New_York' => ['2026-10-26', '2026-11-09'], 'America/Santiago' => ['2026-08-31', '2026-09-14'], 'Europe/London' => ['2026-10-19', '2026-11-02']] as $tz => [$from, $to]) {
    date_default_timezone_set($tz);   // each range crosses that zone's DST change
    $d = []; for ($t = strtotime("$from 12:00"); date('Y-m-d', $t) <= $to; $t = strtotime('+1 day', $t)) if (in_array((int)date('w', $t), [1, 3, 5], true)) $d[] = date('Y-m-d', $t);
    eq(scheduledStreak($d, $MWF, $to), count($d), count($d), "5. $tz: a DST change does not drop or double a day");
}
date_default_timezone_set('UTC');

// 6. Schedule changes and historical completions
$addedTue = $MWF + [2 => '2026-10-06'];   // Tuesday planned from Tue 6 Oct onward
eq(scheduledStreak($weeks, $addedTue, '2026-10-05'), 7, 7, '6. adding a Tuesday plan does not turn past Tuesdays into misses');
eq(scheduledStreak($weeks, $addedTue, '2026-10-09'), 2, 7, '6. …but a missed Tuesday after it was planned does break it');
eq(scheduledStreak($weeks, [1 => '2026-01-01', 5 => '2026-01-01'], '2026-10-09'), 9, 9, '6. dropping Wednesday: past Wednesday sessions still count');
eq(scheduledStreak(['2026-09-28', '2026-10-05'], [1 => '2026-01-01'], '2026-10-11'), 2, 2, '6. Monday-only plan: six rest days between sessions');
eq(scheduledStreak(['2026-10-12'], $MWF, '2026-10-11'), 0, 0, '6. a future-dated log is ignored, not counted');
eq(scheduledStreak([], $MWF, '2026-10-11'), 0, 0, '6. no history → 0');

echo "== $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
