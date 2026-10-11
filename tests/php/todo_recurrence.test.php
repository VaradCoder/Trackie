<?php
/**
 * Which period a todo completion belongs to.  php tests/php/todo_recurrence.test.php
 * Must agree with resetRecurringTodos(): day / week from Monday / month from the 1st.
 */
date_default_timezone_set('UTC');
require __DIR__ . '/../../includes/functions.php';

$pass = $fail = 0;
function ok(bool $c, string $n): void { global $pass, $fail; if ($c) { $pass++; echo "PASS  $n\n"; } else { $fail++; echo "FAIL  $n\n"; } }
$k = 'todoOccurrenceKey';

ok($k('none', '2026-10-11') === 'todo' && $k('', '2026-10-11') === 'todo', 'one-off todos keep the original key');
ok($k('daily', '2026-10-11') === 'todo:2026-10-11', 'daily → that day');
ok($k('daily', '2026-12-31') === 'todo:2026-12-31' && $k('daily', '2027-01-01') === 'todo:2027-01-01', 'daily: year boundary gives two keys');
ok($k('weekly', '2026-10-05') === 'todo:2026-10-05', 'weekly: Monday → itself');
ok($k('weekly', '2026-10-11') === 'todo:2026-10-05', 'weekly: Sunday → the Monday before (Mon-start, as the reset)');
ok($k('weekly', '2026-10-12') === 'todo:2026-10-12', 'weekly: next Monday → new period');
ok($k('weekly', '2027-01-01') === 'todo:2026-12-28', 'weekly: a week spanning New Year keeps one key');
ok($k('monthly', '2026-10-31') === 'todo:2026-10-01' && $k('monthly', '2026-11-01') === 'todo:2026-11-01', 'monthly: last day vs 1st');
ok($k('monthly', '2028-02-29') === 'todo:2028-02-01', 'monthly: leap day');
ok(strlen($k('weekly', '2026-10-11')) <= 50, 'fits activity_log.ref_type VARCHAR(50)');
foreach (['America/New_York' => '2026-11-01', 'Europe/London' => '2026-10-25', 'America/Santiago' => '2026-09-06'] as $tz => $dst) {
    date_default_timezone_set($tz);   // the day of that zone's DST change
    ok($k('daily', $dst) === "todo:$dst" && $k('weekly', $dst) === 'todo:' . date('Y-m-d', strtotime('monday this week', strtotime("$dst 12:00"))), "$tz: DST-change day keys correctly");
}
date_default_timezone_set('UTC');

// The reset SQL's week (WEEKDAY → Monday) and month boundaries, mirrored in PHP:
foreach (['2026-10-05', '2026-10-08', '2026-10-11', '2026-10-12', '2026-12-31'] as $d) {
    $mon = date('Y-m-d', strtotime($d . ' 12:00 -' . ((int)date('N', strtotime($d)) - 1) . ' day'));
    ok($k('weekly', $d) === "todo:$mon", "weekly $d matches the reset's week start $mon");
}

echo "== $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
