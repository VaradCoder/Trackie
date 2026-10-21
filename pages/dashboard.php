<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../config/weather.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/intelligence.php';
// Gamification is optional — degrade gracefully if the file isn't deployed yet.
if (is_file(__DIR__ . '/../includes/gamification.php')) {
    require_once __DIR__ . '/../includes/gamification.php';
}

requireAuth();

$uid = currentUserId();
require_once __DIR__ . '/../includes/insights.php';
require_once __DIR__ . '/../includes/today_board.php';
require_once __DIR__ . '/../includes/goal_sources.php';
try { maybeNotifyWeeklyReview($uid); } catch (Throwable $e) { error_log('weekly review notify: ' . $e->getMessage()); }
resetRecurringTodos($uid);
$pageTitle   = 'Overview';
$currentPage = 'dashboard';
$today       = date('Y-m-d');

/* ════════════════════════════════════════════════════════════════
   Overview: how you're doing, at a glance. Today is where you act; this
   page answers "am I on track?" — score, level, streak, the week so far,
   activity, goals' pace and what the data says. Every number is recorded
   data (activity_log, xp_events, goals), never an estimate.
   ════════════════════════════════════════════════════════════════ */

// Today, summarised from the same builder Today renders.
$board     = todayBoard($uid, $today)['items'];
$boardDone = count(array_filter($board, static fn($i) => $i['done']));
$boardOpen = array_values(array_filter($board, static fn($i) => !$i['done']));
usort($boardOpen, static fn($a, $b) => [$a['section'] !== 'today', $a['sort']] <=> [$b['section'] !== 'today', $b['sort']]);
$boardPct  = $board ? (int)round($boardDone / count($board) * 100) : 0;

$xp     = function_exists('xpSummary') ? xpSummary($uid) : ['total' => 0, 'level' => 1, 'title' => 'Beginner', 'pct' => 0, 'to_next' => 100];
$score  = function_exists('trackieScore') ? trackieScore($uid) : ['score' => 0, 'band' => '', 'has_data' => false];
$trend  = function_exists('scoreTrend') ? scoreTrend($uid) : ['delta' => null];
$streak = activityReady() ? activityStreak($uid) : habitStreaks($uid);

// This week (the user's week) against the one before.
$weekStartDay = (int)userSetting($uid, 'week_start');
$weekFrom = weekStartOf($today, $weekStartDay);
$week     = weeklyReview($uid, $weekFrom);
$activeSet = activityReady() ? array_flip(array_column(fetchAll(
    "SELECT DISTINCT occurred_on d FROM activity_log WHERE user_id=? AND occurred_on BETWEEN ? AND ?", [$uid, $weekFrom, $today]), 'd')) : [];

// 14 days of activity for the bar chart.
$range   = progressOverview($uid, 14);
$maxDay  = max(1, ...array_column($range['per_day'], 'count'));

// Goals with a deadline first, nearest first.
$goals = fetchAll("SELECT * FROM goals WHERE user_id=? AND progress < target_value
                   ORDER BY deadline IS NULL, deadline, created_at DESC LIMIT 3", [$uid]);

$insights   = array_slice(crossInsights($uid), 0, 3);
$crossInsight = crossModuleInsights($uid);
$weatherData  = fetchWeatherData();

require_once '../includes/providers.php';
$spotifyOn = env('SPOTIFY_CLIENT_ID') && (provider('spotify')?->isConnected($uid) ?? false);

$fmtMin = static fn(int $m) => $m >= 60 ? floor($m / 60) . 'h ' . ($m % 60) . 'm' : $m . 'm';
$delta  = static function (int $now, int $prev): string {
    if ($now === $prev) return '<span class="ov-delta is-flat">same as last week</span>';
    $up = $now > $prev;
    return '<span class="ov-delta ' . ($up ? 'is-up' : 'is-down') . '"><i class="fas fa-arrow-' . ($up ? 'up' : 'down') . '" aria-hidden="true"></i> '
         . abs($now - $prev) . ' vs last week</span>';
};

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<?= renderPageHeader('Overview', [
  'icon'    => 'fa-gauge',
  'sub'     => 'How you\'re doing, at a glance — ' . date('l j F'),
  'actions' => '<a class="btn btn-secondary btn-sm" href="' . APP_BASE . '/pages/review.php"><i class="fas fa-calendar-week" aria-hidden="true"></i> Weekly review</a>',
]) ?>

<?php if (($_GET['onboarded'] ?? '') === '1'): ?>
  <?= renderInsight("You're set up! Head to Today and tick off your first thing to earn XP.", 'fa-champagne-glasses') ?>
<?php elseif ($crossInsight): ?>
  <?= renderInsight($crossInsight['text'], $crossInsight['icon']) ?>
<?php endif; ?>

<div class="ov-grid">

  <!-- Today, in one line -->
  <a class="card ov-today" href="<?= APP_BASE . HOME_PATH ?>">
    <div class="td-ring ov-today-ring<?= $board && $boardDone === count($board) ? ' is-complete' : '' ?><?= $boardDone ? '' : ' is-empty' ?>" style="--p:<?= $boardPct ?>" aria-hidden="true">
      <svg viewBox="0 0 100 100"><circle class="td-ring-track" cx="50" cy="50" r="42" pathLength="100"/><circle class="td-ring-fill" cx="50" cy="50" r="42" pathLength="100"/></svg>
      <div class="td-ring-label"><span class="td-ring-num"><?= $boardDone ?>/<?= count($board) ?></span></div>
    </div>
    <div class="ov-today-text">
      <span class="ov-kicker">Today</span>
      <?php if (!$board): ?>
        <strong>Nothing planned yet.</strong><span>Add the first thing you want to get done.</span>
      <?php elseif (!$boardOpen): ?>
        <strong>All done for today. 🎉</strong><span><?= count($board) ?> of <?= count($board) ?> finished.</span>
      <?php else: ?>
        <strong><?= count($boardOpen) ?> left</strong><span>Next up: <?= h($boardOpen[0]['title']) ?></span>
      <?php endif; ?>
    </div>
    <span class="ov-go">Open Today <i class="fas fa-arrow-right" aria-hidden="true"></i></span>
  </a>

  <!-- KPI tiles -->
  <section class="ov-kpis" aria-label="Key numbers">
    <article class="card ov-kpi">
      <span class="ov-kicker"><i class="fas fa-gauge-high" aria-hidden="true"></i> Trackie Score</span>
      <?php if (!empty($score['has_data'])): ?>
        <div class="ov-big"><span class="stat-val"><?= (int)$score['score'] ?></span><small>/100</small></div>
        <div class="ov-foot">
          <?php if ($trend['delta'] !== null): ?>
            <span class="ov-delta <?= $trend['delta'] > 0 ? 'is-up' : ($trend['delta'] < 0 ? 'is-down' : 'is-flat') ?>">
              <?php if ($trend['delta']): ?><i class="fas fa-arrow-<?= $trend['delta'] > 0 ? 'up' : 'down' ?>" aria-hidden="true"></i> <?= abs((int)$trend['delta']) ?> this week<?php else: ?>steady this week<?php endif; ?>
            </span>
          <?php else: ?><span><?= h($score['band'] ?? '') ?></span><?php endif; ?>
        </div>
      <?php else: ?>
        <div class="ov-big ov-big-muted">—</div>
        <div class="ov-foot">Log a few days to get a score.</div>
      <?php endif; ?>
    </article>

    <article class="card ov-kpi">
      <span class="ov-kicker"><i class="fas fa-bolt" aria-hidden="true"></i> Level</span>
      <div class="ov-big"><span class="stat-val"><?= (int)$xp['level'] ?></span><small><?= h($xp['title'] ?? '') ?></small></div>
      <div class="ov-xp" role="progressbar" aria-valuenow="<?= (int)($xp['pct'] ?? 0) ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Progress to the next level">
        <span style="--w:<?= max(0, min(100, (int)($xp['pct'] ?? 0))) / 100 ?>"></span>
      </div>
      <div class="ov-foot"><?= (int)($xp['to_next'] ?? 0) ?> XP to level <?= (int)$xp['level'] + 1 ?></div>
    </article>

    <article class="card ov-kpi">
      <span class="ov-kicker"><i class="fas fa-fire" aria-hidden="true"></i> Streak</span>
      <div class="ov-big<?= (int)$streak['current'] > 0 ? ' is-lit' : '' ?>"><span class="stat-val"><?= (int)$streak['current'] ?></span><small>day<?= (int)$streak['current'] === 1 ? '' : 's' ?></small></div>
      <div class="ov-foot">Best: <?= (int)$streak['best'] ?> day<?= (int)$streak['best'] === 1 ? '' : 's' ?></div>
    </article>

    <article class="card ov-kpi">
      <span class="ov-kicker"><i class="fas fa-calendar-check" aria-hidden="true"></i> This week</span>
      <div class="ov-big"><span class="stat-val"><?= (int)$week['active_days'] ?></span><small>active day<?= (int)$week['active_days'] === 1 ? '' : 's' ?></small></div>
      <div class="ov-week" aria-hidden="true">
        <?php for ($i = 0; $i < 7; $i++): $d = date('Y-m-d', strtotime("$weekFrom 12:00 +$i day")); ?>
          <span class="<?= isset($activeSet[$d]) ? 'is-on' : '' ?><?= $d === $today ? ' is-today' : '' ?><?= $d > $today ? ' is-future' : '' ?>" title="<?= h(date('D j M', strtotime($d))) ?>"><?= h(mb_substr(date('D', strtotime($d)), 0, 1)) ?></span>
        <?php endfor; ?>
      </div>
      <div class="ov-foot"><?= $delta((int)$week['active_days'], (int)$week['prev_active_days']) ?></div>
    </article>
  </section>

  <!-- Activity -->
  <section class="card ov-activity" aria-labelledby="ovActTitle">
    <header class="ov-card-head">
      <h2 id="ovActTitle">Activity · last 14 days</h2>
      <span class="ov-muted"><?= (int)$range['activities'] ?> things logged on <?= (int)$range['active_days'] ?> days</span>
    </header>
    <div class="ov-bars" role="img" aria-label="Things logged per day over the last 14 days">
      <?php foreach ($range['per_day'] as $i => $d):
        $h = $d['count'] ? max(6, (int)round($d['count'] / $maxDay * 100)) : 0; ?>
        <div class="ov-bar<?= $d['date'] === $today ? ' is-today' : '' ?>" style="--i:<?= $i ?>" title="<?= h(date('D j M', strtotime($d['date']))) ?>: <?= (int)$d['count'] ?>">
          <span class="ov-bar-fill" style="--h:<?= $h ?>%"></span>
          <span class="ov-bar-lbl"><?= h(mb_substr(date('D', strtotime($d['date'])), 0, 1)) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if ($range['modules']): ?>
      <div class="ov-mods">
        <?php foreach (array_slice($range['modules'], 0, 5) as $m): ?>
          <span><b><?= (int)$m['count'] ?></b> <?= h($m['label']) ?></span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <!-- Week vs last week -->
  <section class="card ov-vs" aria-labelledby="ovVsTitle">
    <header class="ov-card-head"><h2 id="ovVsTitle">This week vs last</h2><a class="ov-link" href="<?= APP_BASE ?>/pages/review.php">Review →</a></header>
    <?php if ($week['metrics']): ?>
      <ul class="ov-metrics">
        <?php foreach ($week['metrics'] as $label => $m): ?>
          <li>
            <span class="ov-metric-label"><?= h($label) ?></span>
            <strong class="ov-metric-now"><?= str_contains($label, 'minutes') ? h($fmtMin((int)$m['now'])) : (int)$m['now'] ?></strong>
            <?= $delta((int)$m['now'], (int)$m['prev']) ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <?= renderEmptyState('fa-chart-line', 'Nothing to compare yet', 'Finish a few things this week and the comparison shows up here.', '', true) ?>
    <?php endif; ?>
  </section>

  <!-- Goals -->
  <section class="card ov-goals" aria-labelledby="ovGoalsTitle">
    <header class="ov-card-head"><h2 id="ovGoalsTitle">Goals</h2><a class="ov-link" href="<?= APP_BASE ?>/pages/goals.php">All goals →</a></header>
    <?php if ($goals): ?>
      <ul class="ov-goal-list">
        <?php foreach ($goals as $g):
          $t = max(1, (int)$g['target_value']); $p = (int)$g['progress']; $pct = min(100, (int)round($p / $t * 100));
          $pace = goalPace($g);
          $paceTxt = ['ahead' => 'Ahead', 'on' => 'On track', 'behind' => 'Behind', 'overdue' => 'Deadline passed'][$pace['state']] ?? '';
        ?>
          <li class="pace-<?= h($pace['state']) ?>">
            <div class="gl-ring ov-mini-ring" style="--p:<?= $pct ?>" aria-hidden="true">
              <svg viewBox="0 0 100 100"><circle class="td-ring-track" cx="50" cy="50" r="42" pathLength="100"/><circle class="gl-ring-fill" cx="50" cy="50" r="42" pathLength="100"/></svg>
              <span class="gl-pct"><?= $pct ?></span>
            </div>
            <div class="ov-goal-text">
              <strong><?= h($g['goal_name']) ?></strong>
              <span><?= $p ?> / <?= $t ?><?= $paceTxt ? ' · <b class="gl-pace">' . h($paceTxt) . '</b>' : '' ?><?= $g['deadline'] ? ' · ' . h(date('j M', strtotime($g['deadline']))) : '' ?></span>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <?= renderEmptyState('fa-bullseye', 'No active goals', 'A number and a date — “read 12 books by December”.', '<a class="btn btn-secondary btn-sm" href="' . APP_BASE . '/pages/goals.php">Set a goal</a>', true) ?>
    <?php endif; ?>
  </section>

  <?php if ($insights): ?>
  <section class="card ov-insights" aria-labelledby="ovInsTitle">
    <header class="ov-card-head"><h2 id="ovInsTitle">What your data says</h2></header>
    <ul>
      <?php foreach ($insights as $in): ?>
        <li><i class="fas <?= h($in['icon']) ?>" aria-hidden="true"></i> <?= h($in['text']) ?></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <?php if ($weatherData): ?>
  <section class="card ov-weather" aria-label="Weather">
    <span class="ov-kicker"><i class="fas fa-location-dot" aria-hidden="true"></i> <?= h($weatherData['city'] ?? '') ?></span>
    <div class="ov-big"><?= isset($weatherData['temp']) ? (int)round($weatherData['temp']) . '°' : '—' ?><small><?= h(ucfirst((string)($weatherData['desc'] ?? ''))) ?></small></div>
  </section>
  <?php endif; ?>

  <?php if ($spotifyOn): ?>
  <section class="card ov-spotify" aria-label="Spotify">
    <span class="ov-kicker"><i class="fab fa-spotify" style="color:#1db954" aria-hidden="true"></i> Now playing</span>
    <div id="ovSpotify" class="ov-sp-body"><span class="ov-muted">Checking Spotify…</span></div>
    <a class="ov-link" href="<?= APP_BASE ?>/pages/music.php">Open Music →</a>
  </section>
  <?php endif; ?>
</div>

</div>
<?php include '../includes/footer.php'; ?>

<?php if ($spotifyOn): ?>
<script>
(() => {
  // Now playing, every 30 s while this page is open (SpaNav clears the timer on leave).
  const box = () => document.getElementById('ovSpotify');
  const base = document.querySelector('meta[name="app-base"]')?.content || '';
  async function poll() {
    if (!box()) return;
    try {
      const res = await fetch(`${base}/api/spotify.php`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      if (!res.ok) return;
      const d = await res.json();
      const el = box(); if (!el) return;
      if (!d.connected) { el.innerHTML = '<span class="ov-muted">Reconnect Spotify in Music.</span>'; return; }
      if (!d.playing || !d.track) { el.innerHTML = '<span class="ov-muted">Nothing playing right now.</span>'; return; }
      const t = d.track;
      const art = typeof t.art === 'string' && t.art.startsWith('https://') ? `<img src="${escHtml(t.art)}" alt="" width="48" height="48">` : '';
      el.innerHTML = `${art}<div class="ov-sp-text"><strong>${escHtml(t.name)}</strong><span>${escHtml(t.artist)}</span></div><span class="ov-sp-eq" aria-label="Playing"><i></i><i></i><i></i></span>`;
    } catch { /* non-critical widget */ }
  }
  poll();
  setInterval(poll, 30000);
})();
</script>
<?php endif; ?>
