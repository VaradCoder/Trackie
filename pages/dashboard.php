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

$uid         = currentUserId();
resetRecurringTodos($uid); // keep recurring todos in sync before we compute today's stats
$pageTitle   = 'Dashboard';
$currentPage = 'dashboard';
$userName    = $_SESSION['user_name'] ?? 'there';

// ── Greeting ──────────────────────────────────────────────────
$hour    = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$dayName  = date('l');  // Tuesday
$today    = date('Y-m-d');

// ── Calendar params ────────────────────────────────────────────
$calMonth = max(1, min(12, (int)($_GET['cal_month'] ?? date('n'))));
$calYear  = max(2000, min(2100, (int)($_GET['cal_year']  ?? date('Y'))));
$calStart = date('Y-m-01', mktime(0,0,0,$calMonth,1,$calYear));
$calEnd   = date('Y-m-t',  mktime(0,0,0,$calMonth,1,$calYear));

// Dates that have tasks this calendar month
$calTaskRows = fetchAll(
    "SELECT DISTINCT due_date FROM todos
     WHERE user_id=? AND due_date BETWEEN ? AND ? AND deleted_at IS NULL
     UNION
     SELECT DISTINCT due_date FROM study_plan
     WHERE user_id=? AND due_date BETWEEN ? AND ?",
    [$uid, $calStart, $calEnd, $uid, $calStart, $calEnd]
);
$calTaskDates = array_column($calTaskRows, 'due_date');

// ── Today's / actionable todos ─────────────────────────────────
// Show todos due today, overdue (incomplete), and undated incomplete —
// otherwise undated tasks would never appear on the dashboard.
$todayTodos = fetchAll(
    "SELECT * FROM todos
     WHERE user_id=? AND deleted_at IS NULL AND parent_id IS NULL
       AND ( DATE(due_date)=?
             OR (completed=0 AND due_date < ?)
             OR (completed=0 AND due_date IS NULL) )
     ORDER BY completed ASC,
              (due_date IS NULL) ASC,
              FIELD(priority,'high','medium','low'),
              due_date ASC
     LIMIT 10",
    [$uid, $today, $today]
);

// Stats reflect exactly what the card shows, so the progress bar is honest.
$todoStats = [
    'total'   => count($todayTodos),
    'done'    => count(array_filter($todayTodos, fn($t) => $t['completed'])),
    'pending' => count(array_filter($todayTodos, fn($t) => !$t['completed'])),
];

// ── Habits (with today's log status) ──────────────────────────
$todayHabits = fetchAll(
    "SELECT h.*,
            COUNT(l.id) AS total_logs,
            MAX(CASE WHEN l.date_completed=? THEN 1 ELSE 0 END) AS logged_today
     FROM habits h
     LEFT JOIN logs l ON l.habit_id=h.id
     WHERE h.user_id=?
     GROUP BY h.id
     ORDER BY total_logs DESC",
    [$today, $uid]
);

// ── Habit completion delta (this month vs last month) ──────────
$thisMonthStart = date('Y-m-01');
$lastMonthStart = date('Y-m-01', strtotime('-1 month'));
$lastMonthEnd   = date('Y-m-t',  strtotime('-1 month'));

$totalHabits   = count($todayHabits);
$thisMonthLogs = $totalHabits > 0 ? (int)fetchOne(
    "SELECT COUNT(*) c FROM logs l
     JOIN habits h ON h.id=l.habit_id
     WHERE h.user_id=? AND l.date_completed >= ?",
    [$uid, $thisMonthStart]
)['c'] : 0;

$lastMonthLogs = $totalHabits > 0 ? (int)fetchOne(
    "SELECT COUNT(*) c FROM logs l
     JOIN habits h ON h.id=l.habit_id
     WHERE h.user_id=? AND l.date_completed BETWEEN ? AND ?",
    [$uid, $lastMonthStart, $lastMonthEnd]
)['c'] : 0;

$habitDelta = null;
if ($lastMonthLogs > 0) {
    $habitDelta = (int)round(($thisMonthLogs - $lastMonthLogs) / $lastMonthLogs * 100);
} elseif ($thisMonthLogs > 0) {
    $habitDelta = 100;
}

// ── Quick stats ────────────────────────────────────────────────
$overdueCount = (int)fetchOne(
    "SELECT COUNT(*) c FROM todos
     WHERE user_id=? AND completed=0 AND due_date < ? AND deleted_at IS NULL",
    [$uid, $today]
)['c'];

$goalCount  = (int)fetchOne("SELECT COUNT(*) c FROM goals WHERE user_id=?", [$uid])['c'];
$studyToday = (int)fetchOne(
    "SELECT COUNT(*) c FROM study_plan WHERE user_id=? AND due_date=? AND completed=0",
    [$uid, $today]
)['c'];

$logDates = array_column(
    fetchAll("SELECT DISTINCT l.date_completed FROM logs l JOIN habits h ON h.id=l.habit_id WHERE h.user_id=?", [$uid]),
    'date_completed'
);
$streakData = calculateStreaks($logDates, userNeutralDates($uid));

// ── Hero: XP/level + Trackie Score + today's priority (Phase 2/5/6) ──
$xpData = function_exists('xpSummary')
    ? xpSummary($uid)
    : ['total'=>0,'level'=>1,'title'=>'Beginner','into'=>0,'span'=>1,'next_at'=>100,'to_next'=>100,'pct'=>0];
$scoreData = function_exists('trackieScore')
    ? trackieScore($uid)
    : ['score'=>0,'band'=>'','has_data'=>false];

// Week-over-week movement from daily_snapshots. Null when there isn't enough
// history yet — the hero must say nothing rather than imply a flat trend.
$scoreTrendData = function_exists('scoreTrend') ? scoreTrend($uid) : ['delta'=>null];

// ── Today summary (Sprint 1 — "what should I do / what have I done") ──
$xpToday = (function_exists('tableExists') && tableExists('xp_events')) ? (int)(fetchOne(
    "SELECT COALESCE(SUM(xp),0) s FROM xp_events WHERE user_id=? AND DATE(created_at)=CURDATE()", [$uid]
)['s'] ?? 0) : 0;
$focusToday = function_exists('focusStats') ? focusStats($uid) : ['today' => 0];

// Personalisation: widget order + the focus label shown in the hero.
$prefs        = userPrefs($uid);
$dashLayout   = dashboardLayout($prefs['primary_focus']);
$dashFocus    = focusLabel($prefs['primary_focus']);
$todayPriority = null;
foreach ($todayTodos as $t) {
    if (!$t['completed']) { $todayPriority = $t; break; }
}

$qstats = [
    ['label'=>'Todos today',         'value'=>count($todayTodos),     'icon'=>'fa-check-square', 'color'=>'var(--accent)'],
    ['label'=>'Overdue',             'value'=>$overdueCount,          'icon'=>'fa-exclamation',  'color'=>'var(--warn)'],
    ['label'=>'Study tasks today',   'value'=>$studyToday,            'icon'=>'fa-book-open',    'color'=>'var(--info)'],
    ['label'=>'Current streak',      'value'=>$streakData['current'].' d', 'icon'=>'fa-fire',   'color'=>'#f59e0b'],
    ['label'=>'Active goals',        'value'=>$goalCount,             'icon'=>'fa-bullseye',     'color'=>'var(--ok)'],
];

// ── Weather ────────────────────────────────────────────────────
$weatherData = fetchWeatherData();

// ── Featured goal (nearest deadline, not yet complete) ─────────
$featuredGoal = fetchOne(
    "SELECT * FROM goals WHERE user_id=? AND progress < target_value
     ORDER BY deadline IS NULL ASC, deadline ASC, created_at DESC
     LIMIT 1",
    [$uid]
);

// ── Spotify state ──────────────────────────────────────────────
$spotifyClientId = env('SPOTIFY_CLIENT_ID');
// Connected = a stored (encrypted) connection. An expired access token is
// NOT a disconnect — api/spotify.php refreshes it on the first poll.
require_once '../includes/providers.php';
$spotifyConnected = provider('spotify')?->isConnected($uid) ?? false;

if (!$spotifyClientId) {
    $spotifyState = 'not_configured';
    $spotifyTrack = null;
} elseif (!$spotifyConnected) {
    $spotifyState = 'not_connected';
    $spotifyTrack = null;
} else {
    $spotifyState = 'connected';
    $spotifyTrack = null;  // JS polls for real-time track
}

// ── Analytics bottom section ───────────────────────────────────

// Weekly habit completion rate
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd   = date('Y-m-d', strtotime('sunday this week'));

$weeklyLogRows = fetchAll(
    "SELECT l.habit_id, COUNT(*) c
     FROM logs l JOIN habits h ON h.id=l.habit_id
     WHERE h.user_id=? AND l.date_completed BETWEEN ? AND ?
     GROUP BY l.habit_id",
    [$uid, $weekStart, $weekEnd]
);
$weeklyLogCounts = [];
foreach ($weeklyLogRows as $r) $weeklyLogCounts[$r['habit_id']] = (int)$r['c'];

$expectedThisWeek = 0;
$loggedThisWeek   = 0;
foreach ($todayHabits as $h) {
    require_once __DIR__ . '/../includes/habit_schedule.php';
    $expected = habitWeekTarget($h);   // 7, scheduled days, or 1 for weekly
    $expectedThisWeek += $expected;
    $actual = $weeklyLogCounts[$h['id']] ?? 0;
    $loggedThisWeek += min($actual, $expected);
}
$weeklyHabitRate = $expectedThisWeek > 0
    ? (int)round($loggedThisWeek / $expectedThisWeek * 100)
    : 0;

// Last 7 days chart data
$chartRows = fetchAll(
    "SELECT DATE(l.date_completed) d, COUNT(*) cnt
     FROM logs l JOIN habits h ON h.id=l.habit_id
     WHERE h.user_id=? AND l.date_completed >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
     GROUP BY d ORDER BY d",
    [$uid]
);
$byDate      = [];
foreach ($chartRows as $r) $byDate[$r['d']] = (int)$r['cnt'];
$chartLabels = [];
$chartData   = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $chartLabels[] = date('D j', strtotime($d));
    $chartData[]   = $byDate[$d] ?? 0;
}

// Top habits (for legend colors)
$topHabitsData = fetchAll(
    "SELECT h.name, h.color, COUNT(l.id) cnt
     FROM habits h LEFT JOIN logs l ON l.habit_id=h.id
     WHERE h.user_id=?
     GROUP BY h.id ORDER BY cnt DESC LIMIT 6",
    [$uid]
);

// ── Page assets ────────────────────────────────────────────────
// Cache-bust by file mtime so CSS edits show immediately (the .htaccess
// caches CSS for a year, so without this the browser serves a stale copy).
$dashCssV = @filemtime(ROOT_PATH . '/assets/css/dashboard.css') ?: time();
$extraCss = '<link rel="stylesheet" href="'.assetUrl('assets/css/dashboard.css').'">';

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>

<div class="dash-wrap" id="page-main">

  <?php if (($_GET['onboarded'] ?? '') === '1'): ?>
    <?= renderInsight(
      "You're set up! " . ($todayPriority
        ? "Complete “{$todayPriority['title']}” below to earn your first XP."
        : "Log a habit or add a task below to earn your first XP."),
      'fa-party-horn'
    ) ?>
  <?php endif; ?>

  <?php
  // Phase 2 Week 2 — cross-module intelligence. Only shown when the
  // onboarding banner above isn't (avoid stacking two insight strips on
  // the same first-visit moment); in practice a brand-new account has no
  // history yet, so crossModuleInsights() returns null here anyway.
  if (($_GET['onboarded'] ?? '') !== '1') {
      $crossInsight = crossModuleInsights($uid);
      if ($crossInsight) echo renderInsight($crossInsight['text'], $crossInsight['icon']);
  }
  ?>

  <?php include '../includes/components/dash_today_summary.php'; ?>

  <!-- Reference dashboard composition: personal rail, supportive activity
       column and a primary work area. Fixed placement keeps the visual rhythm
       stable while every included component continues to use live user data. -->
  <main class="dash-reference" role="main">
    <aside class="dash-reference-rail">
      <?php include '../includes/components/dash_hero.php'; ?>
      <?php include '../includes/components/dash_calendar.php'; ?>
      <?php include '../includes/components/dash_quickstats.php'; ?>
    </aside>

    <section class="dash-reference-support">
      <?php include '../includes/components/dash_weather.php'; ?>
      <?php include '../includes/components/dash_habits.php'; ?>
      <?php include '../includes/components/dash_challenge.php'; ?>
    </section>

    <section class="dash-reference-work">
      <div class="dash-reference-work-top">
        <?php include '../includes/components/dash_todos.php'; ?>
        <div class="dash-reference-integrations">
          <?php include '../includes/components/dash_spotify.php'; ?>
          <?php include '../includes/components/dash_integrations.php'; ?>
        </div>
      </div>
      <?php include '../includes/components/dash_analytics.php'; ?>
    </section>
  </main>

</div><!-- /.dash-wrap -->

</div><!-- /.main-wrap -->
</div><!-- /.app-shell -->

<script src="https://cdn.jsdelivr.net/npm/motion@11.15.0/dist/motion.js"></script>
<script id="dash-chartjs-script" src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js" defer></script>
<script>
window.TRACKIE_API = '<?= APP_BASE ?>/api';
window.DASH_CHART_LABELS = <?= json_encode($chartLabels) ?>;
window.DASH_CHART_DATA = <?= json_encode($chartData) ?>;
window.DASH_HABIT_COLORS = <?= json_encode(array_column($topHabitsData, 'color')) ?>;
(function waitMount(tries) {
  if (window.mountDashboard && typeof Chart !== 'undefined') { window.mountDashboard(); return; }
  if (tries < 40) setTimeout(function () { waitMount(tries + 1); }, 60);
})(0);
</script>
<?php
$tourAuto = ($prefs['experience_level'] ?? null) !== 'experienced';
?>
<script src="<?= assetUrl('assets/js/app.js') ?>" defer></script>
<script src="<?= assetUrl('assets/js/dashboard.js') ?>" defer></script>
<script>window.TRACKIE_TOUR_AUTOSTART = <?= $tourAuto ? 'true' : 'false' ?>;</script>
<script src="<?= assetUrl('assets/js/tour.js') ?>" defer></script>

</body>
</html>
