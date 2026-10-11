<?php
/**
 * Routine day states — pure rules, no database.  php tests/php/routine_states.test.php
 * done / skipped / rest / due / off, and what counts toward a day's progress.
 */
date_default_timezone_set('UTC');
require __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/routines.php';

$pass = $fail = 0;
function ok(bool $c, string $n): void { global $pass, $fail; if ($c) { $pass++; echo "PASS  $n\n"; } else { $fail++; echo "FAIL  $n\n"; } }

// 2026-10-05 Mon … 2026-10-11 Sun
$daily = ['id' => 1, 'schedule_days' => null];
$mwf   = ['id' => 2, 'schedule_days' => '1,3,5'];
$S = 'routineDayState';

ok($S($daily, '2026-10-06', [], []) === 'due', 'daily routine, nothing recorded → due');
ok($S($mwf, '2026-10-06', [], []) === 'off' && $S($mwf, '2026-10-07', [], []) === 'due', 'Mon/Wed/Fri routine: Tue off, Wed due');
ok($S($daily, '2026-10-06', ['1|2026-10-06' => 'done'], []) === 'done', 'recorded done → done');
ok($S($daily, '2026-10-06', ['1|2026-10-06' => 'skipped'], []) === 'skipped', 'recorded skip → skipped');
ok($S($daily, '2026-10-06', [], ['2026-10-06' => true]) === 'rest', 'planned rest day → rest, not due');
ok($S($mwf, '2026-10-06', [], ['2026-10-06' => true]) === 'off', 'rest day on an unscheduled day stays off');
ok($S($daily, '2026-10-06', ['1|2026-10-06' => 'done'], ['2026-10-06' => true]) === 'done', 'done on a rest day still counts as done');

// One-day skip never touches other days or the weekly template
$logs = ['2|2026-10-07' => 'skipped'];
ok($S($mwf, '2026-10-07', $logs, []) === 'skipped' && $S($mwf, '2026-10-14', $logs, []) === 'due' && $S($mwf, '2026-10-09', $logs, []) === 'due',
   'skipping Wed 7 Oct: Fri 9 and next Wed 14 are still due');
ok($mwf['schedule_days'] === '1,3,5', 'the weekly template is data the skip never writes');

// History survives schedule changes: a recorded day shows even if no longer scheduled
$movedToTueThu = ['id' => 2, 'schedule_days' => '2,4'];
ok($S($movedToTueThu, '2026-10-07', ['2|2026-10-07' => 'done'], []) === 'done', 'done last Wed, routine moved to Tue/Thu → still done on that Wed');
ok($S($movedToTueThu, '2026-10-05', [], []) === 'off', '…and un-recorded old Mondays become off, not missed');
ok($S($mwf, '2026-10-06', ['2|2026-10-06' => 'done'], []) === 'done', 'done on an unscheduled day (an extra) → done');

// Progress: only due + done are expected
$p = routineProgress(['done', 'due', 'skipped', 'rest', 'off']);
ok($p === ['done' => 1, 'expected' => 2, 'pct' => 50], 'progress: 1 done of 2 expected (skip, rest, off not counted)');
ok(routineProgress(['rest', 'rest']) === ['done' => 0, 'expected' => 0, 'pct' => 0], 'rest day: nothing expected, nothing missed');
ok(routineProgress(['skipped', 'done']) === ['done' => 1, 'expected' => 1, 'pct' => 100], 'skip + done → 100%');
ok(routineProgress(['done', 'rest']) === ['done' => 1, 'expected' => 1, 'pct' => 100], 'done on a rest day → counts');
ok(routineProgress([]) === ['done' => 0, 'expected' => 0, 'pct' => 0], 'no routines → 0, no division by zero');

// Schedule edge cases shared with habits
ok(routineScheduledOn(['schedule_days' => '0'], '2026-10-11') && !routineScheduledOn(['schedule_days' => '0'], '2026-10-12'), "Sunday-only ('0') is a real schedule");
date_default_timezone_set('America/Santiago');   // DST starts at local midnight on 6 Sep 2026
ok(routineScheduledOn($mwf, '2026-09-07') && !routineScheduledOn($mwf, '2026-09-06'), 'DST-at-midnight day keeps the right weekday');
date_default_timezone_set('UTC');

echo "== $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
