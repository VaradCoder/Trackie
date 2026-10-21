<?php
/**
 * Rain-aware watering — plantIsOutdoor() and rainAdvice().  php tests/php/watering.test.php
 * Offline: rainAdvice() takes the forecast as an argument.
 */
require_once __DIR__ . '/../../includes/gardening.php';

$pass = $fail = 0;
function ok(bool $c, string $n): void { global $pass, $fail; if ($c) { $pass++; echo "PASS  $n\n"; } else { $fail++; echo "FAIL  $n\n"; } }

foreach (['Balcony', 'back garden', 'Patio pots', 'Outdoors', 'front yard', 'raised bed 2', 'Roof terrace'] as $l) ok(plantIsOutdoor($l), "outdoor: $l");
foreach (['Kitchen window', 'Living room', 'Office desk', '', null, 'Bathroom shelf', 'Gardenia corner'] as $l) ok(!plantIsOutdoor($l), 'indoor: ' . var_export($l, true));

$P = static fn(string $loc = 'Balcony', bool $due = true, string $status = 'healthy') => ['location' => $loc, 'due' => $due, 'status' => $status];
$R = static fn(float $y = 0, float $t = 0, float $tm = 0, int $pt = 0) => ['yesterday' => $y, 'today' => $t, 'tomorrow' => $tm, 'prob_today' => $pt, 'prob_tomorrow' => 0, 'city' => ''];

ok(rainAdvice($P(), null) === null, 'no forecast → no advice');
ok(rainAdvice($P(), $R()) === null, 'dry forecast → no advice');
ok(rainAdvice($P('Kitchen'), $R(12, 8)) === null, 'indoor plant never told to skip');
ok(rainAdvice($P('Balcony', false), $R(12)) === null, 'not due → no advice');
ok(rainAdvice($P('Balcony', true, 'dormant'), $R(12)) === null, 'dormant → no advice');
ok(str_contains((string)rainAdvice($P(), $R(6.5)), 'rained 6.5 mm yesterday'), 'soaked yesterday → check the soil');
ok(rainAdvice($P(), $R(4.9)) === null, 'light rain yesterday (under 5 mm) doesn\'t count');
ok(str_contains((string)rainAdvice($P(), $R(0, 3)), 'skip watering') && str_contains((string)rainAdvice($P(), $R(0, 3)), '(3 mm)'), 'rain today ≥3 mm → skip, number formatted');
ok(str_contains((string)rainAdvice($P(), $R(0, 1, 0, 80)), '80% chance'), 'high chance of rain → hold off');
ok(str_contains((string)rainAdvice($P(), $R(0, 0, 9)), 'light watering'), 'heavy rain tomorrow → water lightly');
ok(str_contains((string)rainAdvice($P(), $R(7, 9)), 'yesterday'), 'yesterday\'s soaking wins over today\'s forecast');

echo "== $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
