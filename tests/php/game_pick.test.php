<?php
/**
 * gameCandidates() — "What should I play?".  php tests/php/game_pick.test.php
 */
require __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/hobby_ui.php';

$pass = $fail = 0;
function ok(bool $c, string $n): void { global $pass, $fail; if ($c) { $pass++; echo "PASS  $n\n"; } else { $fail++; echo "FAIL  $n\n"; } }
$G = static fn(int $id, string $status, ?string $last = null, ?int $rating = null, ?string $cover = null) =>
    ['id' => $id, 'title' => "Game $id", 'status' => $status, 'rating' => $rating, 'last_played' => $last, 'cover_url' => $cover, 'hours_played' => 2.25];

$c = gameCandidates([
    $G(1, 'completed'), $G(2, 'wishlist'), $G(3, 'dropped'),
    $G(4, 'backlog'), $G(5, 'backlog', null, 5),
    $G(6, 'playing', '2026-10-09 20:00:00'), $G(7, 'playing', '2026-10-20'), $G(8, 'playing'),
], '2026-10-21');
$by = array_column($c, null, 'id');

ok(array_column($c, 'id') === [6, 8, 5, 7, 4], 'only playing/backlog, by weight (ties keep order): idle in-progress first');
ok(!isset($by[1]) && !isset($by[2]) && !isset($by[3]), 'completed, wishlist and dropped never suggested');
ok($by[6]['weight'] === 6 && str_contains($by[6]['why'], "haven't played it in 12 days"), 'in progress but idle 12 days → strongest, says so');
ok($by[7]['weight'] === 3 && str_contains($by[7]['why'], 'momentum'), 'played yesterday → keep the momentum');
ok($by[8]['weight'] === 4, 'started, never logged a session → keep the run going');
ok($by[5]['weight'] === 3 && str_contains($by[5]['why'], 'highest-rated'), 'backlog rated 5 → favoured');
ok($by[4]['weight'] === 1, 'plain backlog → base weight');
ok($by[4]['hours'] === 2.3 && $by[4]['cover'] === null, 'hours rounded; no cover → null');
ok(gameCandidates([$G(9, 'backlog', null, null, 'http://x/c.jpg')], '2026-10-21')[0]['cover'] === null
   && gameCandidates([$G(9, 'backlog', null, null, 'https://x/c.jpg')], '2026-10-21')[0]['cover'] === 'https://x/c.jpg', 'only https covers pass through');
ok(gameCandidates([], '2026-10-21') === [], 'no games → no candidates');

echo "== $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
