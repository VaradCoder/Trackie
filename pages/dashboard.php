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
require_once __DIR__ . '/../includes/dashboard_sections.php';
try { maybeNotifyWeeklyReview($uid); } catch (Throwable $e) { error_log('weekly review notify: ' . $e->getMessage()); }
resetRecurringTodos($uid);
$pageTitle   = 'Dashboard';
$currentPage = 'dashboard';
$today       = date('Y-m-d');
$tbl         = static fn(string $t) => tableExists($t);

/* ════════════════════════════════════════════════════════════════
   Dashboard — the first page after sign-in. Bento layout:
     1  greeting · new habit · month calendar · get the app
     2  weather · "Should do!" (habits still due) · goal in focus
     3  today's todos           4  Spotify · more integrations
     3–4  analytics: positive habits · year wrapped · favourite habits
   Everything is the user's recorded data. A card with nothing to show
   explains what fills it instead of showing invented numbers.
   ════════════════════════════════════════════════════════════════ */

// ── Today (same builder as the Today page) ────────────────────────
$items = todayBoard($uid, $today)['items'];
$shouldDo = array_values(array_filter($items, static fn($i) => $i['kind'] === 'habit'));
usort($shouldDo, static fn($a, $b) => [$a['done'], $a['sort']] <=> [$b['done'], $b['sort']]);
$todos = array_values(array_filter($items, static fn($i) => $i['kind'] !== 'habit'));

// ── Your space: Plan / Life / Hobbies, from what the user picked ──
$space = dashboardSections($uid, $items, $today);
$focusName = ['discipline' => 'discipline', 'fitness' => 'fitness', 'wellbeing' => 'wellbeing', 'study' => 'study', 'money' => 'money', 'creativity' => 'creativity'][$space['focus'] ?? ''] ?? null;
usort($todos, static fn($a, $b) => [$a['done'], $a['section'] !== 'overdue', $a['sort']] <=> [$b['done'], $b['section'] !== 'overdue', $b['sort']]);
$todosOpen = count(array_filter($todos, static fn($i) => !$i['done']));

$habitTotals = $tbl('logs') ? array_column(fetchAll(
    "SELECT l.habit_id, COUNT(*) n FROM logs l JOIN habits h ON h.id=l.habit_id WHERE h.user_id=? GROUP BY l.habit_id", [$uid]), 'n', 'habit_id') : [];

// ── Month calendar: days you were active circled, today filled ────
$calYm = preg_match('/^\d{4}-\d{2}$/', $_GET['cal'] ?? '') ? $_GET['cal'] : date('Y-m');
$calFirst = strtotime($calYm . '-01 12:00');
$calFrom  = date('Y-m-01', $calFirst); $calTo = date('Y-m-t', $calFirst);
$weekStartDay = (int)userSetting($uid, 'week_start');
$calLead  = ((int)date('w', $calFirst) - $weekStartDay + 7) % 7;
$calDow   = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
if ($weekStartDay === 1) $calDow = array_merge(array_slice($calDow, 1), ['S']);
$activeDays = activityReady() ? array_flip(array_column(fetchAll(
    "SELECT DISTINCT occurred_on d FROM activity_log WHERE user_id=? AND occurred_on BETWEEN ? AND ?", [$uid, $calFrom, $calTo]), 'd')) : [];
$dueDays = $tbl('todos') ? array_flip(array_column(fetchAll(
    "SELECT DISTINCT due_date d FROM todos WHERE user_id=? AND deleted_at IS NULL AND completed=0 AND due_date BETWEEN ? AND ?", [$uid, $calFrom, $calTo]), 'd')) : [];
// "+3.2% from last month": active days so far this month vs the same span last month.
$dayN = (int)date('j');
$thisSpan = activityReady() ? (int)(fetchOne("SELECT COUNT(DISTINCT occurred_on) n FROM activity_log WHERE user_id=? AND occurred_on BETWEEN ? AND ?",
    [$uid, date('Y-m-01'), $today])['n'] ?? 0) : 0;
$lastFrom = date('Y-m-01', strtotime('first day of last month'));
$lastTo   = date('Y-m-d', strtotime($lastFrom . ' +' . min($dayN, (int)date('t', strtotime($lastFrom))) - 1 . ' day'));
$lastSpan = activityReady() ? (int)(fetchOne("SELECT COUNT(DISTINCT occurred_on) n FROM activity_log WHERE user_id=? AND occurred_on BETWEEN ? AND ?",
    [$uid, $lastFrom, $lastTo])['n'] ?? 0) : 0;
$monthDelta = $lastSpan > 0 ? round(($thisSpan - $lastSpan) / $lastSpan * 100, 1) : null;

// ── Weather ───────────────────────────────────────────────────────
$weather = fetchWeatherData();

// ── Goal in focus: nearest deadline, not reached ──────────────────
$goal = $tbl('goals') ? fetchOne("SELECT * FROM goals WHERE user_id=? AND progress < target_value ORDER BY deadline IS NULL, deadline, created_at DESC LIMIT 1", [$uid]) : null;
$goalPace = $goal ? goalPace($goal) : null;

// ── Analytics ─────────────────────────────────────────────────────
// Positive habits: check-ins this month so far vs the same span last month.
$hThis = $tbl('logs') ? (int)(fetchOne("SELECT COUNT(*) n FROM logs l JOIN habits h ON h.id=l.habit_id WHERE h.user_id=? AND l.date_completed BETWEEN ? AND ?", [$uid, date('Y-m-01'), $today])['n'] ?? 0) : 0;
$hLast = $tbl('logs') ? (int)(fetchOne("SELECT COUNT(*) n FROM logs l JOIN habits h ON h.id=l.habit_id WHERE h.user_id=? AND l.date_completed BETWEEN ? AND ?", [$uid, $lastFrom, $lastTo])['n'] ?? 0) : 0;
$habitDelta = $hLast > 0 ? round(($hThis - $hLast) / $hLast * 100, 1) : null;
// Year wrapped.
$year = (int)date('Y');
$yearActs = activityReady() ? fetchOne("SELECT COUNT(*) n, COUNT(DISTINCT occurred_on) d FROM activity_log WHERE user_id=? AND YEAR(occurred_on)=?", [$uid, $year]) : ['n' => 0, 'd' => 0];
// Favourite habits: check-ins per habit per month, last 6 months.
$months = [];
for ($i = 5; $i >= 0; $i--) $months[] = date('Y-m', strtotime(date('Y-m-01') . " -$i month"));
$favHabits = $tbl('habits') ? fetchAll("SELECT id, name, color FROM habits WHERE user_id=? ORDER BY name", [$uid]) : [];
$favCounts = array_fill_keys($months, []);
if ($favHabits) {
    foreach (fetchAll("SELECT l.habit_id, DATE_FORMAT(l.date_completed, '%Y-%m') ym, COUNT(*) n FROM logs l JOIN habits h ON h.id=l.habit_id
                        WHERE h.user_id=? AND l.date_completed >= ? GROUP BY l.habit_id, ym", [$uid, $months[0] . '-01']) as $r) {
        if (isset($favCounts[$r['ym']])) $favCounts[$r['ym']][(int)$r['habit_id']] = (int)$r['n'];
    }
}

// ── Spotify ───────────────────────────────────────────────────────
require_once '../includes/providers.php';
$spConfigured = (bool)env('SPOTIFY_CLIENT_ID');
$spConnected  = $spConfigured && (provider('spotify')?->isConnected($uid) ?? false);

$hour = (int)date('G');
$wave = $hour < 12 ? '👋' : ($hour < 18 ? '☀️' : '🌙');
$kindTile = ['todo' => ['fa-check', 'var(--accent)'], 'study' => ['fa-graduation-cap', '#8b5cf6'], 'routine' => ['fa-clock', 'var(--info)'], 'workout' => ['fa-dumbbell', '#f97316']];

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<?php if (($_GET['onboarded'] ?? '') === '1'): ?>
  <?= renderInsight("You're set up! Log a habit under “Should do!” or tick a todo to earn your first XP.", 'fa-champagne-glasses') ?>
<?php endif; ?>

<div class="bd-wrap">
<div class="bd" id="bdRoot" data-today="<?= h($today) ?>">

  <!-- ═══ Column 1 ═══ -->
  <div class="bd-col bd-c1">
    <section class="bd-hello" style="--o:1">
      <h1>Happy <br><?= h(date('l')) ?> <span aria-hidden="true"><?= $wave ?></span></h1>
      <p class="bd-when"><?= h(date('j M Y')) ?>, <span id="bdClock"><?= h(date('g:i a')) ?></span></p>
      <button type="button" class="bd-btn bd-btn-primary" onclick="Trackie.QuickAdd.open('habit')"><i class="fas fa-plus" aria-hidden="true"></i> New Habit</button>
      <a class="bd-btn bd-btn-ghost" href="<?= APP_BASE ?>/pages/habits.php">Browse Popular Habits</a>
    </section>

    <section class="bd-card bd-cal" aria-labelledby="bdCalTitle" style="--o:7">
      <header class="bd-cal-head">
        <h2 id="bdCalTitle"><?= h(date('F, Y', $calFirst)) ?></h2>
        <div class="bd-cal-nav">
          <a class="bd-round" href="?cal=<?= date('Y-m', strtotime('-1 month', $calFirst)) ?>" aria-label="Previous month"><i class="fas fa-chevron-left" aria-hidden="true"></i></a>
          <a class="bd-round is-accent" href="?cal=<?= date('Y-m', strtotime('+1 month', $calFirst)) ?>" aria-label="Next month"><i class="fas fa-chevron-right" aria-hidden="true"></i></a>
        </div>
      </header>
      <div class="bd-cal-grid" role="grid" aria-label="<?= h(date('F Y', $calFirst)) ?>: circled days you were active">
        <?php foreach ($calDow as $d): ?><span class="bd-dow" role="columnheader"><?= $d ?></span><?php endforeach; ?>
        <?php for ($i = 0; $i < $calLead; $i++): ?><span></span><?php endfor; ?>
        <?php for ($d = 1; $d <= (int)date('t', $calFirst); $d++): $ds = date('Y-m-d', strtotime($calYm . '-' . str_pad((string)$d, 2, '0', STR_PAD_LEFT))); ?>
          <a class="bd-day<?= isset($activeDays[$ds]) ? ' is-active' : '' ?><?= $ds === $today ? ' is-today' : '' ?><?= isset($dueDays[$ds]) ? ' has-due' : '' ?>"
             href="<?= APP_BASE ?>/pages/calendar.php?month=<?= (int)date('n', $calFirst) ?>&year=<?= (int)date('Y', $calFirst) ?>&day=<?= $ds ?>"
             role="gridcell" aria-label="<?= h(date('j F', strtotime($ds))) ?><?= isset($activeDays[$ds]) ? ', active' : '' ?><?= isset($dueDays[$ds]) ? ', tasks due' : '' ?>"><?= $d ?></a>
        <?php endfor; ?>
      </div>
      <p class="bd-cal-foot">
        <?php if ($monthDelta !== null): ?>
          <b class="<?= $monthDelta >= 0 ? 'is-up' : 'is-down' ?>"><?= $monthDelta >= 0 ? '+' : '' ?><?= rtrim(rtrim(number_format($monthDelta, 1), '0'), '.') ?>%</b> active days vs last month
        <?php else: ?>
          <b><?= $thisSpan ?></b> active day<?= $thisSpan === 1 ? '' : 's' ?> this month
        <?php endif; ?>
      </p>
    </section>

    <section class="bd-card bd-app" id="bdApp" style="--o:12">
      <div class="bd-app-art" aria-hidden="true"><i class="fas fa-mobile-screen-button"></i><i class="fas fa-bell bd-app-bell"></i></div>
      <h2>Sync anywhere with the Trackie app</h2>
      <p>Reminders that ring, a focus timer that survives the lock screen.</p>
      <a class="bd-btn bd-btn-soft" href="<?= APP_BASE ?>/pages/download.php">Download app</a>
    </section>
  </div>

  <!-- ═══ Column 2 ═══ -->
  <div class="bd-col bd-c2">
    <section class="bd-card bd-weather<?= $weather ? '' : ' is-empty' ?>" aria-labelledby="bdWxTitle" style="--o:5">
      <header class="bd-card-head"><h2 id="bdWxTitle">Weather</h2><?php if ($weather): ?><span class="bd-muted"><?= h($weather['city'] ?? '') ?></span><?php endif; ?></header>
      <?php if ($weather): ?>
        <div class="bd-wx-top">
          <span class="bd-wx-icon" aria-hidden="true"><i class="fas <?= h(str_contains(strtolower((string)$weather['desc']), 'rain') ? 'fa-cloud-rain' : (str_contains(strtolower((string)$weather['desc']), 'cloud') ? 'fa-cloud-sun' : 'fa-sun')) ?>"></i></span>
          <span class="bd-wx-temp"><?= (int)round((float)$weather['temp']) ?>°C</span>
        </div>
        <p class="bd-wx-desc"><?= h(ucfirst((string)$weather['desc'])) ?></p>
        <dl class="bd-wx-stats">
          <div><dt>Wind</dt><dd><?= h((string)$weather['wind']) ?> km/h</dd></div>
          <div><dt>Pressure</dt><dd><?= h((string)$weather['pressure']) ?> mb</dd></div>
          <div><dt>Humidity</dt><dd><?= h((string)$weather['humidity']) ?>%</dd></div>
        </dl>
        <i class="fas fa-umbrella bd-wx-art" aria-hidden="true"></i>
      <?php else: ?>
        <div class="bd-wx-top"><span class="bd-wx-icon" aria-hidden="true"><i class="fas fa-cloud-sun"></i></span></div>
        <p class="bd-wx-desc">Add your city to see today's weather here.</p>
        <a class="bd-btn bd-btn-soft" href="<?= APP_BASE ?>/pages/settings.php">Set location</a>
        <i class="fas fa-umbrella bd-wx-art" aria-hidden="true"></i>
      <?php endif; ?>
    </section>

    <section class="bd-card bd-should" id="bdShould" aria-labelledby="bdShouldTitle" style="--o:3">
      <header class="bd-card-head"><h2 id="bdShouldTitle">Should Do!</h2><a class="bd-link" href="<?= APP_BASE ?>/pages/habits.php">View Details</a></header>
      <?php if (!$shouldDo): ?>
        <p class="bd-empty">No habits due today. <a href="<?= APP_BASE ?>/pages/habits.php">Add one</a> you want to do most days.</p>
      <?php else: ?>
        <ul class="bd-should-list">
          <?php foreach ($shouldDo as $hb): ?>
            <li class="bd-should-item<?= $hb['done'] ? ' is-done' : '' ?>" style="--hc:<?= h($hb['color']) ?>" data-id="<?= (int)$hb['id'] ?>">
              <span class="bd-tile" aria-hidden="true"><?= h(mb_strtoupper(mb_substr($hb['title'], 0, 1))) ?></span>
              <span class="bd-item-text"><strong><?= h($hb['title']) ?></strong><small><?= (int)($habitTotals[$hb['id']] ?? 0) ?> logs total</small></span>
              <button type="button" class="bd-log" data-done="<?= $hb['done'] ? 1 : 0 ?>" aria-pressed="<?= $hb['done'] ? 'true' : 'false' ?>"
                      aria-label="<?= $hb['done'] ? 'Logged today — undo' : 'Log ' . h($hb['title']) . ' for today' ?>">
                <?= $hb['done'] ? '<i class="fas fa-check" aria-hidden="true"></i> Done' : 'Log it' ?>
              </button>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="bd-card bd-goal" aria-labelledby="bdGoalTitle" style="--o:9">
      <header class="bd-card-head"><h2 id="bdGoalTitle"><?= $goal ? h($goal['goal_name']) : 'Goal in focus' ?></h2><?php if ($goal): ?><a class="bd-round" href="<?= APP_BASE ?>/pages/goals.php" aria-label="Open goals"><i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i></a><?php endif; ?></header>
      <?php if ($goal):
        $t = max(1, (int)$goal['target_value']); $p = (int)$goal['progress']; $pct = min(100, (int)round($p / $t * 100));
        $daysLeft = $goal['deadline'] ? max(0, (int)round((strtotime($goal['deadline']) - strtotime($today)) / 86400)) : null;
      ?>
        <div class="bd-goal-stats">
          <span><i class="fas fa-flag" aria-hidden="true"></i> <?= $p ?>/<?= $t ?></span>
          <?php if ($daysLeft !== null): ?><span><i class="fas fa-hourglass-half" aria-hidden="true"></i> <?= $daysLeft ?> day<?= $daysLeft === 1 ? '' : 's' ?></span><?php endif; ?>
          <?php if ($goalPace['perDay'] ?? null): ?><span><i class="fas fa-gauge" aria-hidden="true"></i> <?= rtrim(rtrim(number_format($goalPace['perDay'], 1), '0'), '.') ?>/day</span><?php endif; ?>
        </div>
        <div class="bd-goal-track pace-<?= h($goalPace['state']) ?>" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Goal progress">
          <span class="bd-goal-fill" style="--w:<?= $pct / 100 ?>"></span>
          <span class="bd-goal-pin" style="--x:<?= $pct ?>%"><b><?= $pct ?>%</b></span>
        </div>
        <p class="bd-goal-pace pace-<?= h($goalPace['state']) ?>">
          <?= h(['ahead' => 'Ahead of pace by ' . $goalPace['diff'], 'on' => 'Right on pace', 'behind' => 'Behind pace by ' . -$goalPace['diff'], 'overdue' => 'The deadline has passed', 'none' => 'No deadline — steady wins'][$goalPace['state']] ?? '') ?>
        </p>
      <?php else: ?>
        <p class="bd-empty">A number and a date — “read 12 books by December”. <a href="<?= APP_BASE ?>/pages/goals.php">Set a goal</a></p>
      <?php endif; ?>
    </section>
  </div>

  <!-- ═══ Columns 3–4 ═══ -->
  <div class="bd-col bd-c34">
    <div class="bd-c34-top">
      <section class="bd-card bd-todos" id="bdTodos" aria-labelledby="bdTodosTitle" style="--o:2">
        <header class="bd-card-head"><h2 id="bdTodosTitle">Today's Todos</h2><a class="bd-link" href="<?= APP_BASE ?>/pages/todos.php">View Details</a></header>
        <?php if (!$todos): ?>
          <p class="bd-empty">Nothing due today. <a href="<?= APP_BASE ?>/pages/todos.php">Plan something</a> or enjoy the space.</p>
        <?php else: ?>
          <ul class="bd-todo-list">
            <?php foreach (array_slice($todos, 0, 7) as $it): [$ic, $col] = $kindTile[$it['kind']] ?? ['fa-check', 'var(--accent)']; ?>
              <li class="bd-todo<?= $it['done'] ? ' is-done' : '' ?><?= $it['section'] === 'overdue' ? ' is-late' : '' ?>"
                  data-kind="<?= h($it['kind']) ?>" data-id="<?= (int)$it['id'] ?>" data-key="<?= h($it['key']) ?>">
                <span class="bd-tile" style="--hc:<?= h($it['kind'] === 'todo' ? $it['color'] : $col) ?>" aria-hidden="true"><i class="fas <?= h($ic) ?>"></i></span>
                <span class="bd-item-text">
                  <strong><?= h($it['title']) ?></strong>
                  <small>
                    <span><i class="fas fa-clock" aria-hidden="true"></i> <?= h($it['kind'] === 'routine' ? $it['label'] : ($it['section'] === 'overdue' ? 'Overdue' : 'Today')) ?></span>
                    <span><i class="fas fa-tag" aria-hidden="true"></i> <?= h($it['kind'] === 'routine' ? 'Routine' : ucfirst($it['kind'])) ?></span>
                  </small>
                </span>
                <?php if ($it['kind'] === 'workout'): ?>
                  <a class="bd-box<?= $it['done'] ? ' is-on' : '' ?>" href="<?= h($it['link'] ?? (APP_BASE . '/pages/gym.php')) ?>" aria-label="<?= $it['done'] ? 'Workout done' : 'Start ' . h($it['title']) ?>"><i class="fas <?= $it['done'] ? 'fa-check' : 'fa-play' ?>" aria-hidden="true"></i></a>
                <?php else: ?>
                  <button type="button" class="bd-box<?= $it['done'] ? ' is-on' : '' ?>" role="checkbox" aria-checked="<?= $it['done'] ? 'true' : 'false' ?>" aria-label="<?= h($it['title']) ?>"><i class="fas fa-check" aria-hidden="true"></i></button>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
          <?php if (count($todos) > 7): ?><a class="bd-link bd-more" href="<?= APP_BASE ?>/pages/today.php">+<?= count($todos) - 7 ?> more on Today</a><?php endif; ?>
        <?php endif; ?>
      </section>

      <div class="bd-col bd-c4">
        <section class="bd-card bd-spotify" style="--o:10" aria-label="Spotify">
          <span class="bd-sp-logo" aria-hidden="true"><i class="fab fa-spotify"></i></span>
          <?php if ($spConnected): ?>
            <h2>Now playing</h2>
            <div id="ovSpotify" class="ov-sp-body"><span class="bd-muted">Checking Spotify…</span></div>
            <a class="bd-btn bd-btn-dark" href="<?= APP_BASE ?>/pages/music.php"><i class="fab fa-spotify" aria-hidden="true"></i> Open Music</a>
          <?php else: ?>
            <h2>Connect your Spotify account</h2>
            <p>See what's playing and start a focus playlist with every session.</p>
            <?php if ($spConfigured): ?>
              <a class="bd-btn bd-btn-dark" href="<?= APP_BASE ?>/pages/spotify_callback.php"><i class="fas fa-link" aria-hidden="true"></i> Link Account</a>
            <?php else: ?>
              <a class="bd-btn bd-btn-dark" href="<?= APP_BASE ?>/pages/music.php">Open Music</a>
            <?php endif; ?>
          <?php endif; ?>
        </section>
        <a class="bd-card bd-integr" href="<?= APP_BASE ?>/pages/settings.php" style="--o:11">
          <span>More Integrations</span><small>GitHub, Google, Steam &amp; more</small>
          <i class="fas fa-plug bd-integr-art" aria-hidden="true"></i>
        </a>
      </div>
    </div>

    <section class="bd-analytics" aria-labelledby="bdAnTitle" style="--o:8">
      <header class="bd-card-head bd-an-head"><h2 id="bdAnTitle">Analytics</h2><a class="bd-link" href="<?= APP_BASE ?>/pages/analytics.php">View Details</a></header>
      <div class="bd-an-grid">
        <div class="bd-an-side">
          <div class="bd-card bd-pos">
            <span class="bd-pos-ic" aria-hidden="true"><i class="fas fa-face-smile"></i></span>
            <span class="bd-pos-label">Positive Habits</span>
            <strong><?php if ($habitDelta !== null): ?><?= $habitDelta >= 0 ? '+' : '' ?><?= rtrim(rtrim(number_format($habitDelta, 1), '0'), '.') ?>%<?php else: ?><?= $hThis ?><?php endif; ?></strong>
            <small><?= $habitDelta !== null ? 'check-ins vs last month' : 'check-ins this month' ?></small>
          </div>
          <a class="bd-card bd-wrapped" href="<?= APP_BASE ?>/pages/progress.php">
            <span class="bd-wrapped-ic" aria-hidden="true"><i class="fas fa-gift"></i></span>
            <span class="bd-wrapped-label">Habits Wrapped</span>
            <strong><?= $year ?></strong>
            <small><?= (int)$yearActs['n'] ?> things on <?= (int)$yearActs['d'] ?> days</small>
            <span class="bd-btn bd-btn-light">View</span>
          </a>
        </div>
        <div class="bd-card bd-fav" id="bdFav">
          <header class="bd-fav-head">
            <h3>Favorite Habits</h3>
            <label class="bd-select"><span class="sr-only">Month</span>
              <select id="bdFavMonth">
                <?php foreach (array_reverse($months) as $m): ?><option value="<?= $m ?>"><?= h(date('F', strtotime($m . '-01'))) ?></option><?php endforeach; ?>
              </select>
            </label>
          </header>
          <div class="bd-fav-bars" id="bdFavBars" role="img" aria-label="Check-ins per habit this month"></div>
          <?php if (!$favHabits): ?><p class="bd-empty">Your habits' check-ins show up here.</p><?php endif; ?>
        </div>
      </div>
    </section>
  </div>
</div>

<!-- ═══ Your space: one tile per module the user has ═══ -->
<section class="ys" aria-labelledby="ysTitle">
  <header class="ys-head">
    <div>
      <h2 id="ysTitle">Your space</h2>
      <p class="bd-muted"><?= $focusName ? 'Ordered for your focus on ' . h($focusName) . '.' : 'Everything you track, grouped like your sidebar.' ?>
        <a class="bd-link" href="<?= APP_BASE ?>/pages/profile.php">Change focus</a></p>
    </div>
  </header>
  <?php $n = 0; foreach ($space['order'] as $key): $sec = $space['sections'][$key]; ?>
  <section class="ys-sec" data-sec="<?= $key ?>" aria-labelledby="ys-<?= $key ?>">
    <header class="ys-sec-head">
      <h3 id="ys-<?= $key ?>"><?= h($sec['title']) ?></h3>
      <span class="bd-muted"><?= h($sec['sub']) ?></span>
      <?php if ($sec['manage']): ?><a class="bd-link" href="<?= APP_BASE ?>/pages/<?= h($sec['manage'][1]) ?>"><?= h($sec['manage'][0]) ?> <i class="fas fa-arrow-right" aria-hidden="true"></i></a><?php endif; ?>
    </header>
    <div class="ys-grid">
      <?php foreach ($sec['tiles'] as $tl): $n++; $max = $tl['spark'] ? max(1, max($tl['spark'])) : 1; ?>
      <a class="ys-tile" data-page="<?= h($tl['page']) ?>" href="<?= APP_BASE ?>/pages/<?= h($tl['href']) ?>" style="--c:<?= h($tl['color']) ?>;--i:<?= min($n, 24) ?>">
        <span class="ys-top">
          <span class="ys-ic" aria-hidden="true"><i class="fas <?= h($tl['icon']) ?>"></i></span>
          <span class="ys-label"><?= h($tl['label']) ?></span>
          <?php if ($tl['streak'] > 1): ?><span class="ys-streak" title="<?= $tl['streak'] ?>-day streak"><i class="fas fa-fire" aria-hidden="true"></i><?= $tl['streak'] ?></span><?php endif; ?>
        </span>
        <span class="ys-value"><strong><?= h((string)$tl['value']) ?></strong> <small><?= h($tl['unit']) ?></small></span>
        <span class="ys-sub"><?= h($tl['sub'] ?: ($tl['last'] ? 'Last active ' . $tl['last'] : 'Nothing logged yet')) ?></span>
        <?php if ($tl['spark'] !== null): ?>
        <span class="ys-spark" role="img" aria-label="<?= array_sum($tl['spark']) ?> entries in the last 7 days">
          <?php foreach ($tl['spark'] as $v): ?><i style="--h:<?= $v ? max(14, (int)round($v / $max * 100)) : 0 ?>%"></i><?php endforeach; ?>
        </span>
        <?php endif; ?>
      </a>
      <?php endforeach; ?>
      <?php if ($key === 'hobbies' && !$space['picked']): ?>
      <a class="ys-tile ys-pick" href="<?= APP_BASE ?>/pages/hobbies.php" style="--c:var(--accent)">
        <span class="ys-top"><span class="ys-ic" aria-hidden="true"><i class="fas fa-star"></i></span><span class="ys-label">Pick your hobbies</span></span>
        <span class="ys-sub">Reading, coding, gaming, cooking… each one you pick gets its own tile here.</span>
      </a>
      <?php endif; ?>
    </div>
  </section>
  <?php endforeach; ?>
</section>
</div><!-- /.bd-wrap -->

</div>
<?php include '../includes/footer.php'; ?>

<script>
(() => {
  const root = document.getElementById('bdRoot');
  const main = document.getElementById('page-main');
  if (!root || !main) return;
  const base = document.querySelector('meta[name="app-base"]')?.content || '';
  const TODAY = root.dataset.today;
  const reduce = () => matchMedia('(prefers-reduced-motion: reduce)').matches;
  const refresh = (...ids) => Trackie.refreshFragments(ids);

  // The app card is for the browser; inside the Android app it's pointless.
  if (window.TrackieNative || window.Capacitor?.isNativePlatform?.()) document.getElementById('bdApp')?.remove();

  // Live clock in the greeting.
  const clock = document.getElementById('bdClock');
  const tickClock = () => { if (clock?.isConnected) clock.textContent = new Date().toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }).toLowerCase(); };
  setInterval(tickClock, 15000);

  // ── Should do: log a habit for today (tap again to undo) ────────────
  main.addEventListener('click', async e => {
    const log = e.target.closest('#bdShould .bd-log');
    if (log) {
      if (log.getAttribute('aria-busy') === 'true') return;
      const li = log.closest('.bd-should-item'), id = +li.dataset.id, was = log.dataset.done === '1';
      log.setAttribute('aria-busy', 'true');
      li.classList.toggle('is-done', !was);
      if (!was) Trackie.Delight?.tap();
      try {
        const res = await Trackie.API.post(`${base}/api/habits.php`, was ? { action: 'unlog', habit_id: id, date: TODAY } : { action: 'log', habit_id: id, date: TODAY, status: 'done' });
        if (!res?.success) throw res;
        if (res.achievements?.length) Trackie.showAchievementToasts?.(res.achievements);
        if (!was && res.xp?.gained) Trackie.Toast.success(`+${res.xp.gained} XP`, 1600);
        await new Promise(r => setTimeout(r, was || reduce() ? 0 : 350));
        await refresh('bdShould');
        if (!was && !document.querySelector('#bdShould .bd-should-item:not(.is-done)')) {
          Trackie.Delight?.burst(document.getElementById('bdShould'));
          Trackie.Toast.success('Every habit done for today 🎉', 3000);
        }
      } catch (err) { li.classList.toggle('is-done', was); Trackie.UI.toastError(err, "Couldn't log that habit."); }
      finally { log.removeAttribute('aria-busy'); }
      return;
    }

    // ── Today's todos: tick off in place ──────────────────────────────
    const box = e.target.closest('#bdTodos button.bd-box');
    if (!box || box.getAttribute('aria-busy') === 'true') return;
    const li = box.closest('.bd-todo'), kind = li.dataset.kind, id = +li.dataset.id;
    const done = box.getAttribute('aria-checked') !== 'true';
    box.setAttribute('aria-checked', String(done)); box.setAttribute('aria-busy', 'true');
    box.classList.toggle('is-on', done); li.classList.toggle('is-done', done);
    if (done) Trackie.Delight?.tap();
    const post = (file, body) => Trackie.API.post(`${base}/api/${file}`, body);
    try {
      const res = await (kind === 'study' ? post('study_plan.php', { action: 'toggle', task_id: id, completed: done ? 1 : 0 })
                       : kind === 'routine' ? post('routines.php', { action: done ? 'complete' : 'uncomplete', routine_id: id })
                       : post('todos.php', { action: 'toggle', todo_id: id, completed: done ? 1 : 0 }));
      if (!res?.success) throw res;
      if (res.achievements?.length) Trackie.showAchievementToasts?.(res.achievements);
      if (done && res.xp?.gained) Trackie.Toast.success(`+${res.xp.gained} XP`, 1600);
      await new Promise(r => setTimeout(r, done && !reduce() ? 400 : 0));
      await Trackie.UI.transition(() => {
        const ul = li.parentElement;
        if (done) ul.append(li); else ul.prepend(li);          // done sinks, reopened rises
      }, [...li.parentElement.children]);
      if (done && !document.querySelector('#bdTodos .bd-box[aria-checked="false"]')) {
        Trackie.Delight?.burst(document.getElementById('bdTodos'));
        Trackie.Toast.success('All of today\'s todos are done 🎉', 3000);
      }
    } catch (err) {
      box.setAttribute('aria-checked', String(!done)); box.classList.toggle('is-on', !done); li.classList.toggle('is-done', !done);
      Trackie.UI.toastError(err, "Couldn't update that.");
    } finally { box.removeAttribute('aria-busy'); }
  });

  // ── Favourite habits: bars per habit for the chosen month ───────────
  const FAV = { habits: <?= json_encode(array_map(static fn($hb) => ['id' => (int)$hb['id'], 'name' => $hb['name'], 'color' => $hb['color'] ?: '#ef4444'], $favHabits), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                counts: <?= json_encode($favCounts ?: new stdClass(), JSON_FORCE_OBJECT) ?> };
  function paintFav(ym) {
    const el = document.getElementById('bdFavBars');
    if (!el || !FAV.habits.length) return;
    const counts = FAV.counts[ym] || {};
    const rows = FAV.habits.map(h => ({ ...h, n: +(counts[h.id] || 0) })).slice(0, 7);
    const max = Math.max(1, ...rows.map(r => r.n));
    const top = rows.reduce((a, b) => (b.n > a.n ? b : a), rows[0]);
    el.innerHTML = rows.map((r, i) => {
      const hgt = r.n ? Math.max(8, Math.round(r.n / max * 100)) : 4;
      const isTop = r === top && r.n > 0;
      return `<div class="bd-fav-col${isTop ? ' is-top' : ''}" style="--i:${i}" title="${escHtml(r.name)}: ${r.n} check-in${r.n === 1 ? '' : 's'}">
        <span class="bd-fav-bar" style="--h:${hgt}%">${isTop ? `<span class="bd-fav-tip">${escHtml(r.name)}<b>${r.n} times</b></span>` : ''}</span>
        <span class="bd-fav-name">${escHtml(r.name)}</span></div>`;
    }).join('');
    el.setAttribute('aria-label', rows.map(r => `${r.name}: ${r.n}`).join(', '));
  }
  const sel = document.getElementById('bdFavMonth');
  sel?.addEventListener('change', () => paintFav(sel.value));
  if (sel) paintFav(sel.value);

  // ── Spotify now playing (connected only) ────────────────────────────
  if (document.getElementById('ovSpotify')) {
    const poll = async () => {
      const el = document.getElementById('ovSpotify'); if (!el) return;
      try {
        const r = await fetch(`${base}/api/spotify.php`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (!r.ok) return;
        const d = await r.json();
        if (!d.connected) { el.innerHTML = '<span class="bd-muted">Reconnect Spotify in Music.</span>'; return; }
        if (!d.playing || !d.track) { el.innerHTML = '<span class="bd-muted">Nothing playing right now.</span>'; return; }
        const t = d.track;
        const art = typeof t.art === 'string' && t.art.startsWith('https://') ? `<img src="${escHtml(t.art)}" alt="" width="44" height="44">` : '';
        el.innerHTML = `${art}<div class="ov-sp-text"><strong>${escHtml(t.name)}</strong><span>${escHtml(t.artist)}</span></div><span class="ov-sp-eq" aria-label="Playing"><i></i><i></i><i></i></span>`;
      } catch { /* non-critical */ }
    };
    poll(); setInterval(poll, 30000);
  }
})();
</script>
