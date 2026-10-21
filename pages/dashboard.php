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
require_once __DIR__ . '/../includes/calendar.php';
try { maybeNotifyWeeklyReview($uid); } catch (Throwable $e) { error_log('weekly review notify: ' . $e->getMessage()); }
resetRecurringTodos($uid);
$pageTitle   = 'Dashboard';
$currentPage = 'dashboard';
$today       = date('Y-m-d');
$hour        = (int)date('G');
$greeting    = $hour < 5 ? 'Good night' : ($hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening'));
$firstName   = trim(explode(' ', (string)($_SESSION['user_name'] ?? ''))[0]) ?: 'there';
$tbl         = static fn(string $t) => tableExists($t);

/* ════════════════════════════════════════════════════════════════
   The dashboard — the first page after sign-in. Everything in one place:
   act on today, see what's next, and every area of Trackie in detail.
   Every number is recorded data; nothing is estimated or invented, and a
   section with nothing to say says so (or isn't shown) instead of a zero.
   ════════════════════════════════════════════════════════════════ */

// ── Today (the same builder the Today page renders) ───────────────
$board      = todayBoard($uid, $today);
$items      = $board['items'];
$boardDone  = count(array_filter($items, static fn($i) => $i['done']));
$boardOpen  = array_values(array_filter($items, static fn($i) => !$i['done']));
usort($boardOpen, static fn($a, $b) => [$a['section'] !== 'overdue', $a['sort']] <=> [$b['section'] !== 'overdue', $b['sort']]);
$boardTotal = count($items);
$boardPct   = $boardTotal ? (int)round($boardDone / $boardTotal * 100) : 0;
$SHOW_OPEN  = 8;

// ── Headline numbers ──────────────────────────────────────────────
$xp     = function_exists('xpSummary') ? xpSummary($uid) : ['total' => 0, 'level' => 1, 'title' => 'Beginner', 'pct' => 0, 'to_next' => 100];
$score  = function_exists('trackieScore') ? trackieScore($uid) : ['score' => 0, 'band' => '', 'has_data' => false];
$trend  = function_exists('scoreTrend') ? scoreTrend($uid) : ['delta' => null];
$streak = activityReady() ? activityStreak($uid) : habitStreaks($uid);
$weekStartDay = (int)userSetting($uid, 'week_start');
$weekFrom = weekStartOf($today, $weekStartDay);
$week     = weeklyReview($uid, $weekFrom);
$activeSet = activityReady() ? array_flip(array_column(fetchAll(
    "SELECT DISTINCT occurred_on d FROM activity_log WHERE user_id=? AND occurred_on BETWEEN ? AND ?", [$uid, $weekFrom, $today]), 'd')) : [];

// ── Up next: routines still to come, reminders later today, the next 7 days ──
$nowHm = date('H:i');
$routinesNext = array_values(array_filter(routinesOnDay($uid, $today), static fn($r) => $r['state'] === 'due'));
$remindersToday = $tbl('reminders') ? fetchAll(
    "SELECT title, next_fire_at FROM reminders WHERE user_id=? AND active=1
       AND next_fire_at BETWEEN NOW() AND CONCAT(CURDATE(), ' 23:59:59') ORDER BY next_fire_at LIMIT 5", [$uid]) : [];
$weekAhead = [];
foreach (calendarEvents($uid, date('Y-m-d', strtotime('+1 day')), date('Y-m-d', strtotime('+7 day'))) as $e) {
    if ($e['source'] === 'routine' || $e['done']) continue;      // routines repeat daily; done needs no attention
    $weekAhead[$e['date']][] = $e;
}
ksort($weekAhead);

// ── Habits with their last 7 days ─────────────────────────────────
$habitDays = [];
for ($i = 6; $i >= 0; $i--) $habitDays[] = date('Y-m-d', strtotime("-$i day"));
$habits = $tbl('habits') ? fetchAll("SELECT id, name, color, frequency, schedule_days, created_at FROM habits WHERE user_id=? ORDER BY name", [$uid]) : [];
$habitLogs = []; $habitMarks = [];
if ($habits) {
    foreach (fetchAll("SELECT l.habit_id, l.date_completed d FROM logs l JOIN habits h ON h.id=l.habit_id WHERE h.user_id=?", [$uid]) as $r) $habitLogs[$r['habit_id']][] = $r['d'];
    if ($tbl('habit_status_log')) foreach (fetchAll("SELECT habit_id, log_date d, status FROM habit_status_log WHERE user_id=? AND log_date >= ?", [$uid, $habitDays[0]]) as $r) $habitMarks[$r['habit_id']][$r['d']] = $r['status'];
}

// ── Goals ─────────────────────────────────────────────────────────
$goals = $tbl('goals') ? fetchAll("SELECT * FROM goals WHERE user_id=? AND progress < target_value ORDER BY deadline IS NULL, deadline, created_at DESC LIMIT 4", [$uid]) : [];

// ── Activity ──────────────────────────────────────────────────────
$range  = progressOverview($uid, 14);
$maxDay = max(1, ...array_column($range['per_day'], 'count'));

// ── Areas ─────────────────────────────────────────────────────────
$areas = [];
// Fitness
if ($tbl('workout_logs')) {
    $ws = workoutStreak($uid);
    $sessionsWeek = $tbl('workout_sessions') ? (int)(fetchOne("SELECT COUNT(*) n FROM workout_sessions WHERE user_id=? AND ended_at IS NOT NULL AND session_date >= ?", [$uid, $weekFrom])['n'] ?? 0) : 0;
    $trainedDays  = (int)(fetchOne("SELECT COUNT(DISTINCT log_date) n FROM workout_logs WHERE user_id=? AND log_date >= ?", [$uid, $weekFrom])['n'] ?? 0);
    $plan = $tbl('workout_plans') ? fetchOne("SELECT name FROM workout_plans WHERE user_id=? AND day_of_week=? LIMIT 1", [$uid, date('l')]) : null;
    $last = fetchOne("SELECT MAX(log_date) d FROM workout_logs WHERE user_id=?", [$uid])['d'] ?? null;
    if ($last || $plan) $areas['fitness'] = ['Fitness', 'fa-dumbbell', '#0ea5e9', 'gym.php', [
        ['Streak', $ws['current'] . ' ' . $ws['unit'] . ($ws['current'] === 1 ? '' : 's')],
        ['Trained this week', $trainedDays . ' day' . ($trainedDays === 1 ? '' : 's')],
        ['Today', $plan ? $plan['name'] : 'Rest day'],
        ['Last workout', $last ? timeAgo($last . ' 12:00:00') : '—'],
    ]];
}
// Focus
if ($tbl('focus_sessions') && function_exists('focusStats')) {
    $fs = focusStats($uid);
    $fm = static fn(int $m) => $m >= 60 ? floor($m / 60) . 'h ' . ($m % 60) . 'm' : $m . 'm';
    if ($fs['month'] > 0) $areas['focus'] = ['Focus', 'fa-stopwatch', '#ef4444', 'focus.php', [
        ['Today', $fm((int)$fs['today'])], ['This week', $fm((int)$fs['week'])], ['This month', $fm((int)$fs['month'])], ['Streak', (int)$fs['streak'] . ' days'],
    ]];
}
// Finance
if ($tbl('transactions')) {
    $mFrom = date('Y-m-01');
    $mx = fetchOne("SELECT COALESCE(SUM(CASE WHEN type='expense' THEN amount END),0) spent, COALESCE(SUM(CASE WHEN type='income' THEN amount END),0) earned, COUNT(*) n
                      FROM transactions WHERE user_id=? AND tx_date >= ?", [$uid, $mFrom]);
    if ((int)$mx['n'] > 0) {
        $topCat = fetchOne("SELECT category, SUM(amount) s FROM transactions WHERE user_id=? AND type='expense' AND tx_date >= ? GROUP BY category ORDER BY s DESC LIMIT 1", [$uid, $mFrom]);
        $budget = $tbl('budgets') ? (float)(fetchOne("SELECT COALESCE(SUM(monthly_limit),0) b FROM budgets WHERE user_id=?", [$uid])['b'] ?? 0) : 0;
        $areas['finance'] = ['Finance', 'fa-wallet', '#22c55e', 'finance.php', array_values(array_filter([
            ['Spent this month', money((float)$mx['spent'], $uid) . ($budget > 0 ? ' of ' . money($budget, $uid) : '')],
            ['Earned this month', money((float)$mx['earned'], $uid)],
            $topCat ? ['Biggest category', $topCat['category'] . ' · ' . money((float)$topCat['s'], $uid)] : null,
            ['Balance this month', money((float)$mx['earned'] - (float)$mx['spent'], $uid)],
        ])), $budget > 0 ? min(100, (int)round((float)$mx['spent'] / $budget * 100)) : null];
    }
}
// Reading
if ($tbl('books')) {
    $reading = fetchAll("SELECT title, current_page, pages_total FROM books WHERE user_id=? AND status='reading' ORDER BY id DESC LIMIT 2", [$uid]);
    $rWeek = $tbl('reading_sessions') ? fetchOne("SELECT COALESCE(SUM(minutes),0) m, COALESCE(SUM(pages),0) p FROM reading_sessions WHERE user_id=? AND session_date >= ?", [$uid, $weekFrom]) : ['m' => 0, 'p' => 0];
    $finishedYear = (int)(fetchOne("SELECT COUNT(*) n FROM books WHERE user_id=? AND status='finished' AND YEAR(COALESCE(finished_at, created_at)) = YEAR(CURDATE())", [$uid])['n'] ?? 0);
    if ($reading || (int)$rWeek['m'] > 0 || $finishedYear) {
        $rows = [];
        foreach ($reading as $b) $rows[] = [$b['title'], $b['pages_total'] ? (int)$b['current_page'] . ' / ' . (int)$b['pages_total'] . ' pages' : 'page ' . (int)$b['current_page']];
        $rows[] = ['This week', (int)$rWeek['p'] . ' pages · ' . (int)$rWeek['m'] . ' min'];
        $rows[] = ['Finished this year', $finishedYear . ' book' . ($finishedYear === 1 ? '' : 's')];
        $areas['reading'] = ['Reading', 'fa-book-open', '#8b5cf6', 'library.php', $rows];
    }
}
// Every other module with a streak (hobbies, meditation, writing…)
$moduleStreaks = activityReady() ? array_values(array_filter(moduleStreaks($uid), static fn($m) => !in_array($m['module'], ['todos', 'habits', 'routines', 'goals', 'fitness', 'focus', 'finance', 'reading'], true))) : [];

$insights     = array_slice(crossInsights($uid), 0, 3);
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

<?php if (($_GET['onboarded'] ?? '') === '1'): ?>
  <?= renderInsight("You're set up! Tick off your first thing below — or type one: “gym tomorrow 7am”.", 'fa-champagne-glasses') ?>
<?php elseif ($crossInsight): ?>
  <?= renderInsight($crossInsight['text'], $crossInsight['icon']) ?>
<?php endif; ?>

<!-- ═══ Hero ═══ -->
<section class="td-hero db-hero" id="dbHero" aria-labelledby="dbHello">
  <div class="td-hero-text">
    <h1 class="td-hello" id="dbHello"><?= h($greeting) ?>, <?= h($firstName) ?></h1>
    <p class="td-date"><?= date('l, j F') ?></p>
    <p class="td-summary">
      <?php if (!$boardTotal): ?>Nothing planned yet — add the first thing below.
      <?php elseif (!$boardOpen): ?>Everything for today is done. 🎉
      <?php else: ?><strong><?= count($boardOpen) ?></strong> left today · next up: <?= h($boardOpen[0]['title']) ?><?php endif; ?>
    </p>
  </div>
  <div class="td-ring<?= $boardTotal && !$boardOpen ? ' is-complete' : '' ?><?= $boardDone ? '' : ' is-empty' ?>" style="--p:<?= $boardPct ?>" role="img" aria-label="<?= $boardDone ?> of <?= $boardTotal ?> done today">
    <svg viewBox="0 0 100 100" aria-hidden="true"><circle class="td-ring-track" cx="50" cy="50" r="42" pathLength="100"/><circle class="td-ring-fill" cx="50" cy="50" r="42" pathLength="100"/></svg>
    <div class="td-ring-label" aria-hidden="true"><span class="td-ring-num"><?= $boardDone ?>/<?= $boardTotal ?></span><span class="td-ring-sub">today</span></div>
  </div>
  <div class="td-chips">
    <a class="td-chip" href="<?= APP_BASE ?>/pages/progress.php"><i class="fas fa-fire" style="color:#f59e0b" aria-hidden="true"></i> <?= (int)$streak['current'] > 0 ? (int)$streak['current'] . '-day streak' : 'Start a streak today' ?></a>
    <a class="td-chip" href="<?= APP_BASE ?>/pages/progress.php"><i class="fas fa-bolt" style="color:var(--accent)" aria-hidden="true"></i> Level <?= (int)$xp['level'] ?></a>
    <?php if ($weatherData): ?><span class="td-chip"><i class="fas fa-temperature-half" aria-hidden="true"></i> <?= isset($weatherData['temp']) ? (int)round($weatherData['temp']) . '°' : '' ?> <?= h(ucfirst((string)($weatherData['desc'] ?? ''))) ?></span><?php endif; ?>
  </div>
</section>

<!-- ═══ KPIs ═══ -->
<section class="ov-kpis db-kpis" aria-label="Key numbers">
  <article class="card ov-kpi">
    <span class="ov-kicker"><i class="fas fa-gauge-high" aria-hidden="true"></i> Trackie Score</span>
    <?php if (!empty($score['has_data'])): ?>
      <div class="ov-big"><span class="stat-val"><?= (int)$score['score'] ?></span><small>/100</small></div>
      <div class="ov-foot">
        <?php if ($trend['delta'] !== null): ?>
          <span class="ov-delta <?= $trend['delta'] > 0 ? 'is-up' : ($trend['delta'] < 0 ? 'is-down' : 'is-flat') ?>"><?php if ($trend['delta']): ?><i class="fas fa-arrow-<?= $trend['delta'] > 0 ? 'up' : 'down' ?>" aria-hidden="true"></i> <?= abs((int)$trend['delta']) ?> this week<?php else: ?>steady this week<?php endif; ?></span>
        <?php else: ?><?= h($score['band'] ?? '') ?><?php endif; ?>
      </div>
    <?php else: ?>
      <div class="ov-big ov-big-muted">—</div><div class="ov-foot">Log a few days to get a score.</div>
    <?php endif; ?>
  </article>
  <article class="card ov-kpi">
    <span class="ov-kicker"><i class="fas fa-bolt" aria-hidden="true"></i> Level</span>
    <div class="ov-big"><span class="stat-val"><?= (int)$xp['level'] ?></span><small><?= h($xp['title'] ?? '') ?></small></div>
    <div class="ov-xp" role="progressbar" aria-valuenow="<?= (int)($xp['pct'] ?? 0) ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Progress to the next level"><span style="--w:<?= max(0, min(100, (int)($xp['pct'] ?? 0))) / 100 ?>"></span></div>
    <div class="ov-foot"><?= (int)($xp['to_next'] ?? 0) ?> XP to level <?= (int)$xp['level'] + 1 ?> · <?= number_format((int)($xp['total'] ?? 0)) ?> XP total</div>
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

<div class="db-layout">
  <div class="db-col db-main">

    <!-- ═══ Today, actionable ═══ -->
    <section class="card db-card db-today" id="dbToday" aria-labelledby="dbTodayTitle" style="--o:1">
      <header class="ov-card-head">
        <h2 id="dbTodayTitle"><i class="fas fa-sun" aria-hidden="true"></i> Today</h2>
        <a class="ov-link" href="<?= APP_BASE ?>/pages/today.php">Full list →</a>
      </header>
      <form class="td-add db-add" id="dbAdd" autocomplete="off">
        <label class="td-add-row">
          <i class="fas fa-plus" aria-hidden="true"></i><span class="sr-only">Add a task</span>
          <input class="td-add-input" id="dbAddInput" maxlength="150" enterkeyhint="done" placeholder="Add a task, e.g. “call mom 6pm”"
                 title="Plain words for the date and time, !high or !low for priority, #tag for tags">
          <button type="submit" class="btn btn-primary btn-sm td-add-btn">Add</button>
        </label>
        <div class="td-add-hint" id="dbAddHint" aria-live="polite"></div>
      </form>
      <?php if (!$boardTotal): ?>
        <p class="db-empty"><i class="fas fa-mug-hot" aria-hidden="true"></i> A clean slate. Type the first thing above, or set up a <a href="<?= APP_BASE ?>/pages/habits.php">habit</a> or <a href="<?= APP_BASE ?>/pages/routines.php">routine</a>.</p>
      <?php else: ?>
        <ul class="td-list db-list" id="dbTodayList">
          <?php foreach (array_slice($boardOpen, 0, $SHOW_OPEN) as $it) echo renderTodayItem($it); ?>
        </ul>
        <div class="db-today-foot">
          <?php if (count($boardOpen) > $SHOW_OPEN): ?><a href="<?= APP_BASE ?>/pages/today.php">+<?= count($boardOpen) - $SHOW_OPEN ?> more on Today</a><?php endif; ?>
          <span><i class="fas fa-circle-check" aria-hidden="true"></i> <b id="dbDoneN"><?= $boardDone ?></b> done today</span>
        </div>
        <?php if (!$boardOpen): ?><p class="db-empty is-clear">🎉 All clear for today. Every habit, task and routine is done.</p><?php endif; ?>
      <?php endif; ?>
    </section>

    <!-- ═══ Habits, last 7 days ═══ -->
    <section class="card db-card" id="dbHabits" aria-labelledby="dbHabitsTitle" style="--o:3">
      <header class="ov-card-head">
        <h2 id="dbHabitsTitle"><i class="fas fa-heart" aria-hidden="true"></i> Habits</h2>
        <a class="ov-link" href="<?= APP_BASE ?>/pages/habits.php">Manage →</a>
      </header>
      <?php if (!$habits): ?>
        <p class="db-empty">No habits yet. <a href="<?= APP_BASE ?>/pages/habits.php">Add one small thing</a> you want to do most days.</p>
      <?php else: ?>
        <div class="db-habit-days" aria-hidden="true"><span></span><?php foreach ($habitDays as $d): ?><span class="<?= $d === $today ? 'is-today' : '' ?>"><?= h(mb_substr(date('D', strtotime($d)), 0, 1)) ?></span><?php endforeach; ?><span></span></div>
        <ul class="db-habits">
          <?php foreach ($habits as $hb):
            $logs = $habitLogs[$hb['id']] ?? [];
            $set = array_flip($logs);
            $hs = habitStreak($hb, $logs, $weekStartDay);
            $sched = habitDays($hb);
            $weekly = $hb['frequency'] === 'weekly';
          ?>
            <li class="db-habit" data-id="<?= (int)$hb['id'] ?>" style="--hc:<?= h($hb['color'] ?: '#ef4444') ?>">
              <span class="db-habit-name"><span class="habit-dot" style="background:<?= h($hb['color'] ?: '#ef4444') ?>" aria-hidden="true"></span><?= h($hb['name']) ?></span>
              <?php foreach ($habitDays as $d):
                $t = strtotime("$d 12:00");
                $st = isset($set[$d]) ? 'done' : ($habitMarks[$hb['id']][$d] ?? null);
                if (!$st) $st = (!$weekly && $sched && !in_array((int)date('w', $t), $sched, true)) ? 'off' : 'open';
                if ($d < substr((string)$hb['created_at'], 0, 10) && $st !== 'done') $st = 'before';
              ?>
                <button type="button" class="hb-day db-dot is-<?= $st ?><?= $d === $today ? ' is-today' : '' ?>" data-date="<?= $d ?>"
                        aria-pressed="<?= $st === 'done' ? 'true' : 'false' ?>" aria-label="<?= h($hb['name'] . ', ' . date('D j M', $t) . ': ' . ($st === 'done' ? 'done' : ($st === 'off' ? 'not scheduled' : 'not done'))) ?>"
                        <?= $st === 'before' ? 'disabled' : '' ?>><span class="hb-day-dot"><i class="fas fa-check" aria-hidden="true"></i></span></button>
              <?php endforeach; ?>
              <span class="hb-flame<?= $hs['current'] > 0 ? ' is-lit' : '' ?>" title="Streak · best <?= (int)$hs['best'] ?>"><i class="fas fa-fire" aria-hidden="true"></i> <?= (int)$hs['current'] ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <!-- ═══ Activity ═══ -->
    <section class="card db-card ov-activity" aria-labelledby="dbActTitle" style="--o:6">
      <header class="ov-card-head">
        <h2 id="dbActTitle"><i class="fas fa-chart-column" aria-hidden="true"></i> Activity · last 14 days</h2>
        <span class="ov-muted"><?= (int)$range['activities'] ?> logged on <?= (int)$range['active_days'] ?> days · <?= number_format((int)$range['xp']) ?> XP</span>
      </header>
      <div class="ov-bars" role="img" aria-label="Things logged per day over the last 14 days">
        <?php foreach ($range['per_day'] as $i => $d): $hgt = $d['count'] ? max(6, (int)round($d['count'] / $maxDay * 100)) : 0; ?>
          <div class="ov-bar<?= $d['date'] === $today ? ' is-today' : '' ?>" style="--i:<?= $i ?>" title="<?= h(date('D j M', strtotime($d['date']))) ?>: <?= (int)$d['count'] ?>">
            <span class="ov-bar-fill" style="--h:<?= $hgt ?>%"></span><span class="ov-bar-lbl"><?= h(mb_substr(date('D', strtotime($d['date'])), 0, 1)) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if ($range['modules']): ?>
        <div class="ov-mods"><?php foreach (array_slice($range['modules'], 0, 6) as $m): ?><span><b><?= (int)$m['count'] ?></b> <?= h($m['label']) ?></span><?php endforeach; ?></div>
      <?php endif; ?>
      <?php if ($week['metrics']): ?>
        <h3 class="db-subhead">This week vs last <a class="ov-link" href="<?= APP_BASE ?>/pages/review.php">Weekly review →</a></h3>
        <ul class="ov-metrics db-metrics">
          <?php foreach ($week['metrics'] as $label => $m): ?>
            <li><span class="ov-metric-label"><?= h($label) ?></span>
              <strong class="ov-metric-now"><?= str_contains($label, 'minutes') ? h($fmtMin((int)$m['now'])) : (int)$m['now'] ?></strong>
              <?= $delta((int)$m['now'], (int)$m['prev']) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <!-- ═══ Areas ═══ -->
    <?php if ($areas || $moduleStreaks): ?>
    <section class="db-areas" aria-label="Your areas" style="--o:7">
      <?php foreach ($areas as $key => $a): [$label, $icon, $color, $href, $rows] = $a; $bar = $a[5] ?? null; ?>
        <a class="card db-area" href="<?= APP_BASE ?>/pages/<?= h($href) ?>" style="--ac:<?= $color ?>">
          <span class="db-area-head"><span class="db-area-ic"><i class="fas <?= $icon ?>" aria-hidden="true"></i></span> <?= h($label) ?> <i class="fas fa-arrow-right db-area-go" aria-hidden="true"></i></span>
          <dl class="db-area-rows">
            <?php foreach ($rows as [$k, $v]): ?><div><dt><?= h($k) ?></dt><dd><?= h((string)$v) ?></dd></div><?php endforeach; ?>
          </dl>
          <?php if ($bar !== null): ?><span class="db-area-bar<?= $bar >= 100 ? ' is-over' : '' ?>" role="progressbar" aria-valuenow="<?= $bar ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Budget used"><span style="--w:<?= $bar / 100 ?>"></span></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
      <?php if ($moduleStreaks): ?>
        <div class="card db-area db-hobbies">
          <span class="db-area-head"><span class="db-area-ic" style="--ac:#f59e0b"><i class="fas fa-star" aria-hidden="true"></i></span> Hobbies &amp; more</span>
          <ul>
            <?php foreach (array_slice($moduleStreaks, 0, 6) as $m): ?>
              <li><span><?= h($m['label']) ?></span><span class="ov-muted">last <?= h(date('j M', strtotime($m['last']))) ?></span><span class="hb-flame<?= $m['current'] > 0 ? ' is-lit' : '' ?>"><i class="fas fa-fire" aria-hidden="true"></i> <?= (int)$m['current'] ?></span></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    </section>
    <?php endif; ?>
  </div>

  <aside class="db-col db-side">
    <!-- ═══ Up next ═══ -->
    <section class="card db-card" aria-labelledby="dbNextTitle" style="--o:2">
      <header class="ov-card-head"><h2 id="dbNextTitle"><i class="fas fa-forward" aria-hidden="true"></i> Up next</h2><a class="ov-link" href="<?= APP_BASE ?>/pages/calendar.php">Calendar →</a></header>
      <?php
        $laterRoutines = array_values(array_filter($routinesNext, static fn($r) => substr($r['time_slot'], 0, 5) >= $nowHm));
        $anyNext = $laterRoutines || $remindersToday || $weekAhead;
      ?>
      <?php if (!$anyNext): ?>
        <p class="db-empty">Nothing scheduled for the rest of today or the coming week.</p>
      <?php endif; ?>
      <?php if ($laterRoutines || $remindersToday): ?>
        <h3 class="db-subhead">Later today</h3>
        <ul class="db-next">
          <?php
            $later = [];
            foreach ($laterRoutines as $r) $later[] = [substr($r['time_slot'], 0, 5), $r['title'], 'fa-clock', 'Routine'];
            foreach ($remindersToday as $r) $later[] = [date('H:i', strtotime($r['next_fire_at'])), $r['title'], 'fa-bell', 'Reminder'];
            usort($later, static fn($a, $b) => strcmp($a[0], $b[0]));
            foreach (array_slice($later, 0, 6) as [$t, $title, $ic, $kind]):
          ?>
            <li><time><?= h(date('g:i A', strtotime("2000-01-01 $t"))) ?></time><i class="fas <?= $ic ?>" aria-hidden="true" title="<?= $kind ?>"></i><span><?= h($title) ?></span></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php if ($weekAhead): ?>
        <h3 class="db-subhead">Next 7 days</h3>
        <ul class="db-agenda">
          <?php $shown = 0; foreach ($weekAhead as $d => $evs): if ($shown >= 6) break; ?>
            <li>
              <span class="db-agenda-day"><b><?= h(date('j', strtotime($d))) ?></b><?= h(date('D', strtotime($d))) ?></span>
              <span class="db-agenda-items">
                <?php foreach (array_slice($evs, 0, 3) as $e): $shown++; $src = CALENDAR_SOURCES[$e['source']] ?? ['', 'fa-circle', '#64748b']; ?>
                  <span><i class="fas <?= h($src[1]) ?>" style="color:<?= h($src[2]) ?>" aria-hidden="true"></i> <?= $e['time'] ? '<b>' . h($e['time']) . '</b> ' : '' ?><?= h($e['title']) ?></span>
                <?php endforeach; ?>
                <?php if (count($evs) > 3): ?><span class="ov-muted">+<?= count($evs) - 3 ?> more</span><?php endif; ?>
              </span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <!-- ═══ Goals ═══ -->
    <section class="card db-card" aria-labelledby="dbGoalsTitle" style="--o:4">
      <header class="ov-card-head"><h2 id="dbGoalsTitle"><i class="fas fa-bullseye" aria-hidden="true"></i> Goals</h2><a class="ov-link" href="<?= APP_BASE ?>/pages/goals.php">All goals →</a></header>
      <?php if ($goals): ?>
        <ul class="ov-goal-list">
          <?php foreach ($goals as $g):
            $t = max(1, (int)$g['target_value']); $p = (int)$g['progress']; $pct = min(100, (int)round($p / $t * 100));
            $pace = goalPace($g);
            $paceTxt = ['ahead' => 'Ahead by ' . $pace['diff'], 'on' => 'On track', 'behind' => 'Behind by ' . -$pace['diff'], 'overdue' => 'Deadline passed'][$pace['state']] ?? '';
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
        <p class="db-empty">No active goals. A goal is a number and a date — <a href="<?= APP_BASE ?>/pages/goals.php">set one</a>.</p>
      <?php endif; ?>
    </section>

    <?php if ($insights): ?>
    <section class="card db-card ov-insights" aria-labelledby="dbInsTitle" style="--o:5">
      <header class="ov-card-head"><h2 id="dbInsTitle"><i class="fas fa-lightbulb" aria-hidden="true"></i> What your data says</h2></header>
      <ul><?php foreach ($insights as $in): ?><li><i class="fas <?= h($in['icon']) ?>" aria-hidden="true"></i> <?= h($in['text']) ?></li><?php endforeach; ?></ul>
    </section>
    <?php endif; ?>

    <?php if ($spotifyOn): ?>
    <section class="card db-card ov-spotify" aria-label="Spotify" style="--o:8">
      <span class="ov-kicker"><i class="fab fa-spotify" style="color:#1db954" aria-hidden="true"></i> Now playing</span>
      <div id="ovSpotify" class="ov-sp-body"><span class="ov-muted">Checking Spotify…</span></div>
      <a class="ov-link" href="<?= APP_BASE ?>/pages/music.php">Open Music →</a>
    </section>
    <?php endif; ?>
  </aside>
</div>

</div>
<?php include '../includes/footer.php'; ?>

<script>
(() => {
  const main = document.getElementById('page-main');
  if (!main || !document.getElementById('dbHero')) return;
  const base = document.querySelector('meta[name="app-base"]')?.content || '';
  const TODAY = <?= json_encode($today) ?>;
  const $ = id => document.getElementById(id);
  const reduce = () => matchMedia('(prefers-reduced-motion: reduce)').matches;
  const refresh = (...ids) => Trackie.refreshFragments(ids);

  // ── Tick things off right here (same APIs as Today) ─────────────────
  function call(kind, id, done) {
    const post = (file, body) => Trackie.API.post(`${base}/api/${file}`, body);
    switch (kind) {
      case 'todo':    return post('todos.php', { action: 'toggle', todo_id: id, completed: done ? 1 : 0 });
      case 'study':   return post('study_plan.php', { action: 'toggle', task_id: id, completed: done ? 1 : 0 });
      case 'habit':   return post('habits.php', done ? { action: 'log', habit_id: id, date: TODAY, status: 'done' } : { action: 'unlog', habit_id: id, date: TODAY });
      case 'routine': return post('routines.php', { action: done ? 'complete' : 'uncomplete', routine_id: id });
    }
    return Promise.reject(new Error('Unknown item'));
  }
  main.addEventListener('click', async e => {
    const btn = e.target.closest('#dbTodayList button.td-check');
    if (btn) {
      if (btn.getAttribute('aria-busy') === 'true') return;
      const li = btn.closest('.td-item');
      const done = btn.getAttribute('aria-checked') !== 'true';
      btn.setAttribute('aria-checked', String(done)); btn.setAttribute('aria-busy', 'true');
      li.classList.toggle('is-done', done);
      if (done) Trackie.Delight?.tap();
      try {
        const res = await call(li.dataset.kind, +li.dataset.id, done);
        if (!res?.success) throw res;
        if (res.achievements?.length) Trackie.showAchievementToasts?.(res.achievements);
        if (done && res.xp?.gained) Trackie.Toast.success(`+${res.xp.gained} XP`, 1600);
        await new Promise(r => setTimeout(r, done && !reduce() ? 450 : 0));
        const allDone = done && ![...$('dbTodayList').querySelectorAll('.td-check')].some(b => b.getAttribute('aria-checked') !== 'true');
        await refresh('dbToday', 'dbHero', 'dbHabits');
        if (allDone) { Trackie.Delight?.burst(document.querySelector('#dbHero .td-ring')); Trackie.Toast.success('All done for today 🎉', 3500); }
      } catch (err) {
        btn.setAttribute('aria-checked', String(!done)); li.classList.toggle('is-done', !done);
        Trackie.UI.toastError(err, "Couldn't update that — please try again.");
      } finally { btn.removeAttribute('aria-busy'); }
      return;
    }
    // ── Habit dots: log / un-log any of the last 7 days ────────────────
    const day = e.target.closest('#dbHabits .db-dot');
    if (day && !day.disabled && day.getAttribute('aria-busy') !== 'true') {
      const id = +day.closest('.db-habit').dataset.id, date = day.dataset.date;
      const wasDone = day.classList.contains('is-done');
      day.setAttribute('aria-busy', 'true');
      day.classList.toggle('is-done', !wasDone); day.classList.toggle('is-open', wasDone);
      if (!wasDone) Trackie.Delight?.tap();
      try {
        const res = await Trackie.API.post(`${base}/api/habits.php`, wasDone ? { action: 'unlog', habit_id: id, date } : { action: 'log', habit_id: id, date, status: 'done' });
        if (!res?.success) throw res;
        if (!wasDone && res.xp?.gained) Trackie.Toast.success(`+${res.xp.gained} XP`, 1600);
        await refresh('dbHabits', 'dbToday', 'dbHero');
      } catch (err) {
        day.classList.toggle('is-done', wasDone); day.classList.toggle('is-open', !wasDone);
        Trackie.UI.toastError(err, "Couldn't update that day.");
      } finally { day.removeAttribute('aria-busy'); }
    }
  });

  // ── Quick add in plain words ────────────────────────────────────────
  const NL = Trackie.NL;
  main.addEventListener('focusin', e => { if (e.target.id === 'dbAddInput') NL.load(); });
  main.addEventListener('input', e => { if (e.target.id === 'dbAddInput') NL.hint($('dbAddHint'), e.target.value, TODAY); });
  main.addEventListener('submit', async e => {
    if (e.target.id !== 'dbAdd') return;
    e.preventDefault();
    await NL.load();
    const p = NL.parse($('dbAddInput').value);
    if (!p.title) return;
    const due = p.due || TODAY;
    const btn = e.target.querySelector('button[type="submit"]'); btn.disabled = true;
    try {
      const res = await Trackie.API.post(`${base}/api/todos.php`, { action: 'add', title: p.title, priority: p.priority, due_date: due, tags: p.tags.join(',') });
      if (!res?.success) throw res;
      if (due === TODAY) {
        await refresh('dbToday', 'dbHero');
        document.querySelector(`#dbTodayList [data-key="todo-${res.id}"]`)?.classList.add('is-new');
        Trackie.Toast.success('Added to today', 1600);
      } else Trackie.Toast.success(`Added for ${NL.describe(due, TODAY)}`, 2200);
    } catch (err) { Trackie.UI.toastError(err, "Couldn't add that task."); }
    finally { const b = document.querySelector('#dbAdd button[type="submit"]'); if (b) b.disabled = false; $('dbAddInput')?.focus(); }
  });

  // ── Spotify now playing (every 30 s while the page is open) ─────────
  if (document.getElementById('ovSpotify')) {
    const poll = async () => {
      const el = document.getElementById('ovSpotify'); if (!el) return;
      try {
        const r = await fetch(`${base}/api/spotify.php`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (!r.ok) return;
        const d = await r.json();
        if (!d.connected) { el.innerHTML = '<span class="ov-muted">Reconnect Spotify in Music.</span>'; return; }
        if (!d.playing || !d.track) { el.innerHTML = '<span class="ov-muted">Nothing playing right now.</span>'; return; }
        const t = d.track;
        const art = typeof t.art === 'string' && t.art.startsWith('https://') ? `<img src="${escHtml(t.art)}" alt="" width="48" height="48">` : '';
        el.innerHTML = `${art}<div class="ov-sp-text"><strong>${escHtml(t.name)}</strong><span>${escHtml(t.artist)}</span></div><span class="ov-sp-eq" aria-label="Playing"><i></i><i></i><i></i></span>`;
      } catch { /* non-critical */ }
    };
    poll(); setInterval(poll, 30000);
  }
})();
</script>
