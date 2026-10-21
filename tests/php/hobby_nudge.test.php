<?php
/**
 * hobbyNudge() — the streak line in the hobby hero.  php tests/php/hobby_nudge.test.php
 */
require __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/hobby_ui.php';

$pass = $fail = 0;
function ok(bool $c, string $n): void { global $pass, $fail; if ($c) { $pass++; echo "PASS  $n\n"; } else { $fail++; echo "FAIL  $n\n"; } }
$P = static fn(int $cur, int $best, bool $today) => ['current' => $cur, 'best' => $best, 'today' => $today];

ok(hobbyNudge($P(0, 0, false)) === 'Log your first session to start a streak.', 'never logged → first-session invite');
ok(str_contains(hobbyNudge($P(4, 9, false)), 'keep your 4-day streak'), 'live streak, nothing today → protect it');
ok(str_contains(hobbyNudge($P(5, 5, true)), 'Personal best — 5 days'), 'today extends the best streak → celebrate');
ok(str_contains(hobbyNudge($P(3, 8, true)), 'Done for today · 3-day streak'), 'logged today, below best → done for today');
ok(str_contains(hobbyNudge($P(1, 1, true)), 'come back tomorrow'), 'first day → come back tomorrow');
ok(str_contains(hobbyNudge($P(0, 6, false)), 'Best streak so far: 6 days'), 'streak broken → show best, start again');

echo "== $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
