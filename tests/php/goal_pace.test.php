<?php
/**
 * goalPace() — is a goal on track for its deadline?  php tests/php/goal_pace.test.php
 */
date_default_timezone_set('UTC');
require __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/goal_sources.php';

$pass = $fail = 0;
function ok(bool $c, string $n): void { global $pass, $fail; if ($c) { $pass++; echo "PASS  $n\n"; } else { $fail++; echo "FAIL  $n\n"; } }
$G = static fn(int $p, int $t, ?string $dl, string $created = '2026-10-01 09:00:00', ?string $since = null) =>
    ['progress' => $p, 'target_value' => $t, 'deadline' => $dl, 'created_at' => $created, 'source_since' => $since];

// 1–31 Oct, 100 to do: on the 16th, halfway is expected.
$p = goalPace($G(50, 100, '2026-10-31'), '2026-10-16');
ok($p['state'] === 'on' && $p['expected'] === 50 && $p['daysLeft'] === 15, 'halfway at the halfway date → on track');
$p = goalPace($G(65, 100, '2026-10-31'), '2026-10-16');
ok($p['state'] === 'ahead' && $p['diff'] === 15, 'ahead by 15');
$p = goalPace($G(30, 100, '2026-10-31'), '2026-10-16');
ok($p['state'] === 'behind' && $p['diff'] === -20 && $p['perDay'] === 4.4, 'behind by 20 → 70 left over 16 days (today counts) = 4.4/day');
ok(goalPace($G(52, 100, '2026-10-31'), '2026-10-16')['state'] === 'on', '±3% band counts as on track');
ok(goalPace($G(100, 100, '2026-10-31'), '2026-10-16')['state'] === 'done', 'reached target → done');
ok(goalPace($G(120, 100, null), '2026-10-16')['state'] === 'done', 'over target, no deadline → done');
ok(goalPace($G(10, 100, null), '2026-10-16')['state'] === 'none', 'no deadline → no pace');
$p = goalPace($G(70, 100, '2026-10-20'), '2026-10-25');
ok($p['state'] === 'overdue' && $p['diff'] === -30 && $p['daysLeft'] === 5, 'past the deadline → overdue, 30 short, 5 days ago');
$p = goalPace($G(0, 100, '2026-10-31'), '2026-10-01');
ok($p['state'] === 'on' && $p['expected'] === 0, 'day one: nothing expected yet');
$p = goalPace($G(90, 100, '2026-10-31'), '2026-10-31');
ok($p['state'] === 'behind' && $p['daysLeft'] === 0 && $p['perDay'] === 10.0, 'deadline day: the rest is due today');
$p = goalPace($G(5, 10, '2026-10-31', '2026-10-01 09:00:00', '2026-10-21'), '2026-10-26');
ok($p['expected'] === 5, 'a linked goal counts from source_since, not creation');
$p = goalPace($G(0, 10, '2026-10-01', '2026-10-05 09:00:00'), '2026-09-30');
ok(in_array($p['state'], ['on', 'behind'], true) && $p['expected'] >= 0, 'created after its deadline: no division by zero');
date_default_timezone_set('America/New_York');   // 1 Nov 2026 DST change inside the range
$p = goalPace($G(50, 100, '2026-11-20', '2026-10-21 09:00:00'), '2026-11-05');
ok($p['expected'] === 50 && $p['daysLeft'] === 15, 'DST change inside the range does not shift days');

echo "== $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
