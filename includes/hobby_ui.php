<?php
/**
 * The shared hero at the top of every hobby page: the hobby's colour and
 * icon, its streak (with a nudge when today isn't logged yet), this week
 * against last week, and a 14-day activity strip — all from activity_log.
 * It also sets --hx on the page, which the hobby-page stat cards pick up.
 *
 *   <?php ob_start(); ?> …action buttons… <?= hobbyHero('Reading', ob_get_clean()) ?>
 */
require_once __DIR__ . '/activity.php';

const HOBBY_MODULE = ['Fitness' => 'fitness', 'Reading' => 'reading', 'Gaming' => 'gaming', 'Photography' => 'photography',
                      'Sports' => 'sports', 'Meditation' => 'meditation', 'Coding' => 'coding', 'Cooking' => 'cooking',
                      'Writing' => 'writing', 'Art' => 'art', 'Gardening' => 'gardening'];

const HOBBY_TAGLINE = [
    'Reading' => 'Pages add up. Log a session and watch the shelf grow.', 'Coding' => 'Ship small, ship often.',
    'Writing' => 'A few hundred words a day is a book a year.', 'Art' => 'Every sketch counts — practice beats perfect.',
    'Photography' => 'Go out, look closer, keep shooting.', 'Sports' => 'Train, play, recover, repeat.',
    'Meditation' => 'A few quiet minutes, every day.', 'Gardening' => 'Little and often keeps them thriving.',
    'Cooking' => 'Cook it once, love it forever.', 'Gaming' => 'Your backlog, your pace.', 'Fitness' => 'Show up. Lift. Log it.',
];

/** Activity numbers for one hobby module: 14-day strip, streak, this week vs last. */
function hobbyPulse(int $uid, string $module): array {
    $days = [];
    for ($i = 13; $i >= 0; $i--) $days[] = date('Y-m-d', strtotime("-$i day"));
    $by = []; $s = ['current' => 0, 'best' => 0, 'last' => null];
    if (activityReady()) {
        $s = moduleStreaks($uid)[$module] ?? $s;
        $by = array_column(fetchAll("SELECT occurred_on d, COUNT(*) n FROM activity_log WHERE user_id=? AND module=? AND occurred_on >= ? GROUP BY occurred_on",
            [$uid, $module, date('Y-m-d', strtotime('-13 day'))]), 'n', 'd');
    }
    $strip = array_map(static fn($d) => ['d' => $d, 'n' => (int)($by[$d] ?? 0)], $days);
    $week = array_sum(array_column(array_slice($strip, 7), 'n'));
    $prev = array_sum(array_column(array_slice($strip, 0, 7), 'n'));
    return ['strip' => $strip, 'week' => $week, 'prev' => $prev, 'current' => (int)$s['current'], 'best' => (int)$s['best'],
            'today' => (int)($by[date('Y-m-d')] ?? 0) > 0, 'last' => $s['last'] ?? null];
}

/** The streak line: protect a live streak, celebrate a fresh one, or invite a start. */
function hobbyNudge(array $p): string {
    if ($p['current'] > 0 && !$p['today']) return "Log today to keep your {$p['current']}-day streak alive.";
    if ($p['today'] && $p['current'] >= $p['best'] && $p['current'] > 1) return "Personal best — {$p['current']} days in a row!";
    if ($p['today']) return $p['current'] > 1 ? "Done for today · {$p['current']}-day streak." : 'Logged today — come back tomorrow to start a streak.';
    return $p['best'] > 0 ? "Best streak so far: {$p['best']} days. Start a new one today." : 'Log your first session to start a streak.';
}

/**
 * "What should I play?" — the games worth suggesting, each with a weight and
 * the reason it's suggested. Games in progress that went quiet come first
 * (finish what you started), then rated/recent backlog, then the rest.
 * @param array<array{id:int|string,title:string,status:string,rating:?int,last_played:?string,cover_url:?string,hours_played:?float}> $games
 */
function gameCandidates(array $games, string $today): array {
    $out = [];
    foreach ($games as $g) {
        if (!in_array($g['status'], ['playing', 'backlog'], true)) continue;
        $idle = $g['last_played'] ? (int)floor((strtotime($today) - strtotime(substr($g['last_played'], 0, 10))) / 86400) : null;
        if ($g['status'] === 'playing') {
            if ($idle !== null && $idle >= 3) [$w, $why] = [6, "You haven't played it in $idle days — pick it back up."];
            elseif ($idle === null) [$w, $why] = [4, 'You started it — keep the run going.'];
            else [$w, $why] = [3, 'You were just playing it — keep the momentum.'];
        } elseif ((int)($g['rating'] ?? 0) >= 4) [$w, $why] = [3, 'One of the highest-rated games in your backlog.'];
        else [$w, $why] = [1, 'A fresh pick from your backlog.'];
        $out[] = ['id' => (int)$g['id'], 'title' => (string)$g['title'], 'cover' => str_starts_with((string)($g['cover_url'] ?? ''), 'https://') ? $g['cover_url'] : null,
                  'hours' => round((float)($g['hours_played'] ?? 0), 1), 'status' => $g['status'], 'weight' => $w, 'why' => $why];
    }
    usort($out, static fn($a, $b) => $b['weight'] <=> $a['weight']);
    return $out;
}

function hobbyHero(string $hobby, string $actionsHtml = '', ?string $tagline = null): string {
    $m = allHobbiesMeta()[$hobby] ?? ['icon' => 'fa-star', 'color' => '#6366f1', 'moduleLabel' => $hobby];
    $p = hobbyPulse(currentUserId(), HOBBY_MODULE[$hobby] ?? strtolower($hobby));
    $max = max(1, max(array_column($p['strip'], 'n')));
    $delta = $p['week'] - $p['prev'];
    $title = $m['moduleLabel'] === $hobby ? $hobby : $m['moduleLabel'];
    ob_start(); ?>
<style>#page-main { --hx: <?= h($m['color']) ?>; }</style>
<header class="hx-hero" style="--c:<?= h($m['color']) ?>">
  <div class="hx-main">
    <span class="hx-ic" aria-hidden="true"><i class="fas <?= h($m['icon']) ?>"></i></span>
    <div class="hx-text">
      <h1><?= h($title) ?></h1>
      <p><?= h($tagline ?? HOBBY_TAGLINE[$hobby] ?? '') ?></p>
    </div>
    <?php if (trim($actionsHtml) !== ''): ?><div class="hx-actions"><?= $actionsHtml ?></div><?php endif; ?>
  </div>
  <div class="hx-pulse">
    <div class="hx-streak<?= $p['current'] > 0 ? ' is-on' : '' ?><?= $p['current'] > 0 && !$p['today'] ? ' is-at-risk' : '' ?>">
      <span class="hx-flame" aria-hidden="true"><i class="fas fa-fire"></i></span>
      <span><strong><?= $p['current'] ?></strong> day streak<small><?= h(hobbyNudge($p)) ?></small></span>
    </div>
    <div class="hx-week">
      <strong><?= $p['week'] ?></strong> this week
      <?php if ($p['week'] || $p['prev']): ?>
        <span class="hx-delta <?= $delta > 0 ? 'is-up' : ($delta < 0 ? 'is-down' : '') ?>"><?= $delta > 0 ? '▲ ' . $delta : ($delta < 0 ? '▼ ' . abs($delta) : '＝') ?> vs last week</span>
      <?php endif; ?>
    </div>
    <ol class="hx-strip" aria-label="Activity, last 14 days">
      <?php foreach ($p['strip'] as $i => $d): ?>
        <li style="--a:<?= $d['n'] ? round(.3 + .7 * $d['n'] / $max, 2) : 0 ?>;--i:<?= $i ?>"<?= $d['d'] === date('Y-m-d') ? ' class="is-today"' : '' ?> title="<?= h(date('D j M', strtotime($d['d']))) ?>: <?= $d['n'] ?>"><span class="sr-only"><?= h(date('D j M', strtotime($d['d']))) ?>: <?= $d['n'] ?></span></li>
      <?php endforeach; ?>
    </ol>
  </div>
</header>
<?php return (string)ob_get_clean();
}
