<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/gamification.php';

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Fitness';
$currentPage = 'gym';
$today       = date('Y-m-d');
$userName    = $_SESSION['user_name'] ?? 'there';

if (!tableExists('workout_plans')) renderSetupNeeded('Gym');

$todayName = date('l');
$plans = fetchAll(
    "SELECT p.*, COUNT(i.id) AS item_count
     FROM workout_plans p
     LEFT JOIN workout_plan_items i ON i.plan_id = p.id
     WHERE p.user_id=? GROUP BY p.id
     ORDER BY (p.day_of_week = ?) DESC, FIELD(p.day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday','Any'), p.created_at DESC",
    [$uid, $todayName]
);

$recentLogs = fetchAll(
    "SELECT * FROM workout_logs WHERE user_id=? ORDER BY log_date DESC, id DESC LIMIT 15",
    [$uid]
);

$stats = fetchOne(
    "SELECT COUNT(DISTINCT log_date) AS total_days,
            COUNT(DISTINCT CASE WHEN log_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) THEN log_date END) AS week_days
     FROM workout_logs WHERE user_id=?",
    [$uid]
);
$logDates = array_column(
    fetchAll("SELECT DISTINCT log_date FROM workout_logs WHERE user_id=? ORDER BY log_date", [$uid]),
    'log_date'
);
$streak = calculateStreaks($logDates);

// ── Achievements (Fitness-related, from the shared gamification system) ──
$gymAchKeys = ['gym_first_rep', 'gym_10', 'gym_50', 'gym_streak_7', 'gym_first_pr'];
$unlockedKeys = tableExists('achievements')
    ? array_column(fetchAll(
        "SELECT key_name FROM achievements WHERE user_id=? AND key_name IN ('gym_first_rep','gym_10','gym_50','gym_streak_7','gym_first_pr')",
        [$uid]
      ), 'key_name')
    : [];
$allAchDefs = achievementDefs();

// ── Weekly insight (Fitness dashboard) — one rule-based sentence comparing
// this week to last week, same pattern as renderInsight() elsewhere in the
// app (habits/todos/finance). Real DB data only; no strip when there isn't
// enough history to say anything honest.
$thisWeekStart = date('Y-m-d', strtotime('monday this week'));
$thisWeekEnd   = date('Y-m-d', strtotime('sunday this week'));
$lastWeekStart = date('Y-m-d', strtotime('monday this week -1 week'));
$lastWeekEnd   = date('Y-m-d', strtotime('sunday this week -1 week'));

$sessionsThisWeek = (int)fetchOne(
    "SELECT COUNT(DISTINCT log_date) c FROM workout_logs WHERE user_id=? AND log_date BETWEEN ? AND ?",
    [$uid, $thisWeekStart, $thisWeekEnd]
)['c'];
$sessionsLastWeek = (int)fetchOne(
    "SELECT COUNT(DISTINCT log_date) c FROM workout_logs WHERE user_id=? AND log_date BETWEEN ? AND ?",
    [$uid, $lastWeekStart, $lastWeekEnd]
)['c'];

$fitnessInsight = null;
if ($sessionsThisWeek > 0 || $sessionsLastWeek > 0) {
    if ($sessionsLastWeek > 0) {
        $delta = $sessionsThisWeek - $sessionsLastWeek;
        if ($delta > 0) {
            $fitnessInsight = "You've trained {$sessionsThisWeek}x this week, up from {$sessionsLastWeek} last week. Keep the momentum. 💪";
        } elseif ($delta < 0) {
            $fitnessInsight = "{$sessionsThisWeek} session" . ($sessionsThisWeek===1?'':'s') . " so far this week, down from {$sessionsLastWeek} last week — still time to catch up.";
        } else {
            $fitnessInsight = "Matching last week's pace at {$sessionsThisWeek} session" . ($sessionsThisWeek===1?'':'s') . " so far — consistency is what compounds.";
        }
    } else {
        $fitnessInsight = "{$sessionsThisWeek} session" . ($sessionsThisWeek===1?'':'s') . " logged this week — no data from last week yet to compare against.";
    }
}

// ── Training journal ─────────────────────────────────────────
$journalEntries = tableExists('hobby_journal')
    ? fetchAll("SELECT * FROM hobby_journal WHERE user_id=? AND hobby='Fitness' ORDER BY entry_date DESC, id DESC LIMIT 30", [$uid])
    : [];

// ── Today's plan (for the Overview tab) ─────────────────────
$todaysPlan = null;
foreach ($plans as $p) { if ($p['day_of_week'] === $todayName) { $todaysPlan = $p; break; } }
$todaysPlanItems = $todaysPlan
    ? fetchAll("SELECT exercise_name, target_sets FROM workout_plan_items WHERE plan_id=? ORDER BY sort_order", [$todaysPlan['id']])
    : [];
// Typical duration = the average of this plan's last 5 FINISHED sessions
// (from real start/end timestamps). No history → no number shown; the old
// "2.5 min per set" guess is gone.
$todaysPlanAvgMin = 0;
if ($todaysPlan) {
    $avg = fetchOne(
        "SELECT AVG(duration_sec) a, COUNT(*) n FROM (
            SELECT duration_sec FROM workout_sessions
             WHERE user_id=? AND plan_id=? AND ended_at IS NOT NULL AND duration_sec >= 60
             ORDER BY started_at DESC LIMIT 5) x",
        [$uid, $todaysPlan['id']]
    );
    if ((int)$avg['n'] > 0) $todaysPlanAvgMin = (int)round($avg['a'] / 60);
}

// ── Today at a glance — every figure from logged data ───────────────
// Volume = Σ weight × reps of today's sets (legacy quick logs: sets × reps ×
// weight). A PR = an exercise whose best weight today beats every earlier
// day; an exercise's first-ever session is not a "record".
$todayVolume = fitWeekVolume($today, $today);
$todaySets = (int)fetchOne(
    "SELECT COALESCE(SUM(CASE WHEN s.c IS NULL THEN COALESCE(wl.sets,0) ELSE s.c END),0) n
       FROM workout_logs wl
       LEFT JOIN (SELECT log_id, COUNT(*) c FROM workout_sets GROUP BY log_id) s ON s.log_id = wl.id
      WHERE wl.user_id=? AND wl.log_date=?",
    [$uid, $today]
)['n'];
$todayTrainingSec = (int)(fetchOne(
    "SELECT COALESCE(SUM(duration_sec),0) t FROM workout_sessions WHERE user_id=? AND session_date=? AND duration_sec IS NOT NULL",
    [$uid, $today]
)['t'] ?? 0);
$todayPrs = (int)fetchOne(
    "SELECT COUNT(*) c FROM (
        SELECT wl.exercise_name, MAX(COALESCE(ws.weight_kg, wl.weight_kg)) best
          FROM workout_logs wl LEFT JOIN workout_sets ws ON ws.log_id = wl.id
         WHERE wl.user_id=? AND wl.log_date=?
         GROUP BY wl.exercise_name) t
      JOIN (
        SELECT wl.exercise_name, MAX(COALESCE(ws.weight_kg, wl.weight_kg)) best
          FROM workout_logs wl LEFT JOIN workout_sets ws ON ws.log_id = wl.id
         WHERE wl.user_id=? AND wl.log_date<?
         GROUP BY wl.exercise_name) b ON b.exercise_name = t.exercise_name
     WHERE t.best > b.best",
    [$uid, $today, $uid, $today]
)['c'];

// XP / level from the shared gamification engine (real totals only).
$xpRow = tableExists('user_xp') ? fetchOne("SELECT total_xp FROM user_xp WHERE user_id=?", [$uid]) : null;
$xpTotal = (int)($xpRow['total_xp'] ?? 0);
$xpLevel = function_exists('levelForXp') ? levelForXp($xpTotal) : null;

$hour = (int)date('G');
$fitGreeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

// ── Weekly plan strip — real completion state per day ───────────
// completed = a plan was scheduled for that weekday AND something was
// logged on that exact date; planned = scheduled but today/future and not
// yet done; rest = nothing scheduled. Dates are this week's actual Mon-Sun,
// not just weekday names, so "completed" reflects real log_date rows.
$weekDays = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
$plansByDay = [];
foreach ($plans as $p) {
    if ($p['day_of_week'] !== 'Any') $plansByDay[$p['day_of_week']][] = $p;
}
$weekMondayTs = strtotime('monday this week');
$weekDayStatus = [];
foreach ($weekDays as $i => $wd) {
    $wDate = date('Y-m-d', strtotime("+{$i} day", $weekMondayTs));
    $dayPlan = $plansByDay[$wd][0] ?? null;
    $weekDayStatus[$wd] = [
        'date'      => $wDate,
        'plan'      => $dayPlan,
        'completed' => $dayPlan && in_array($wDate, $logDates, true),
        'is_today'  => $wDate === $today,
        'is_future' => $wDate > $today,
    ];
}
$scheduledDaysThisWeek = count(array_filter($weekDayStatus, fn($d) => $d['plan'] !== null));

// ── Recent workouts — one row per day trained, not per exercise ─
$recentWorkouts = fetchAll(
    "SELECT wl.log_date,
            COUNT(DISTINCT wl.exercise_name) AS exercise_count,
            COALESCE(SUM(wl.sets),0) AS total_sets,
            COALESCE(MAX(ws.plan_name), MAX(wp.name)) AS plan_name,
            MAX(ws.duration_sec) AS duration_sec
       FROM workout_logs wl
       LEFT JOIN workout_sessions ws ON ws.id = wl.session_id
       LEFT JOIN workout_plans wp ON wp.id = wl.plan_id
      WHERE wl.user_id=?
      GROUP BY wl.log_date
      ORDER BY wl.log_date DESC
      LIMIT 6",
    [$uid]
);

// ── Overview "Progress" preview card — lightweight, real-data summary.
// The Progress TAB has the full Chart.js volume/muscle charts; duplicating
// live chart instances here for the same data would just be wasted work on
// every page load, so this card shows the numbers only, with a link across.
function fitWeekVolume(string $start, string $end): float {
    global $uid;
    return (float)(fetchOne(
        "SELECT COALESCE(SUM(vol),0) v FROM (
            SELECT ws.reps * ws.weight_kg AS vol
              FROM workout_sets ws JOIN workout_logs wl ON wl.id=ws.log_id
             WHERE wl.user_id=? AND wl.log_date BETWEEN ? AND ?
            UNION ALL
            SELECT wl.sets * wl.reps * wl.weight_kg AS vol
              FROM workout_logs wl
             WHERE wl.user_id=? AND wl.log_date BETWEEN ? AND ?
               AND wl.id NOT IN (SELECT DISTINCT log_id FROM workout_sets)
         ) x", [$uid, $start, $end, $uid, $start, $end]
    )['v'] ?? 0);
}
$volumeThisWeek = fitWeekVolume($thisWeekStart, $thisWeekEnd);
$volumeLastWeek = fitWeekVolume($lastWeekStart, $lastWeekEnd);
$volumeDeltaPct = $volumeLastWeek > 0 ? (int)round(($volumeThisWeek - $volumeLastWeek) / $volumeLastWeek * 100) : null;

$muscleTop3 = fetchAll(
    "SELECT COALESCE(el.muscle_group,'Other') AS muscle_group, COUNT(*) AS cnt
       FROM workout_logs wl
       LEFT JOIN exercise_library el
         ON LOWER(el.name) = LOWER(wl.exercise_name) AND (el.user_id IS NULL OR el.user_id=?)
      WHERE wl.user_id=?
      GROUP BY muscle_group ORDER BY cnt DESC LIMIT 3",
    [$uid, $uid]
);

require_once '../includes/head.php';
?>
<!-- Chart.js — Progress tab charts (frequency / volume / muscle distribution) -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js" defer></script>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<div class="fit-header">
  <div>
    <div class="fit-page-title"><i class="fas fa-dumbbell"></i> Fitness</div>
    <h1 class="fit-greeting"><?= h($fitGreeting) ?>, <?= h($userName) ?>! <span aria-hidden="true">👋</span></h1>
    <div class="fit-subgreeting">Let's build consistency. One rep at a time.</div>
  </div>
  <div class="fit-header-badges">
    <div class="fit-streak-badge" id="gymStreakBadge">
      <div class="fit-streak-num"><i class="fas fa-fire"></i> <strong><?= $streak['current'] ?></strong> Day Streak</div>
      <div class="fit-streak-sub"><?= $streak['current'] > 0 ? 'Keep it alive!' : 'Train today to start one' ?></div>
    </div>
    <?php if ($xpLevel !== null): ?>
      <a class="fit-xp-badge" href="<?= APP_BASE ?>/pages/progress.php" title="Your Trackie level — from XP across every module">
        <span class="fit-xp-level">Level <?= $xpLevel ?></span>
        <span class="fit-xp-total"><i class="fas fa-bolt" aria-hidden="true"></i> <?= number_format($xpTotal) ?> XP</span>
      </a>
    <?php endif; ?>
  </div>
</div>

<!-- Today at a glance — all figures come from today's logged sets/sessions -->
<div class="grid-stats" style="margin-bottom:1.5rem" id="gymStatsWrap" aria-label="Today">
  <div class="stat-card">
    <div class="stat-icon-chip" style="color:var(--accent)"><i class="fas fa-weight-hanging"></i></div>
    <div class="stat-val"><?= $todayVolume > 0 ? number_format($todayVolume, 0) . ' kg' : '—' ?></div>
    <div class="stat-label">Volume · Today</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon-chip" style="color:var(--info)"><i class="fas fa-list-check"></i></div>
    <div class="stat-val"><?= $todaySets ?: '—' ?></div>
    <div class="stat-label">Sets · Today</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon-chip" style="color:var(--ok)"><i class="fas fa-stopwatch"></i></div>
    <div class="stat-val"><?= $todayTrainingSec >= 60 ? round($todayTrainingSec / 60) . ' min' : '—' ?></div>
    <div class="stat-label">Workout time · Today</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon-chip" style="color:#f59e0b"><i class="fas fa-trophy"></i></div>
    <div class="stat-val"><?= $todayPrs ?: '—' ?></div>
    <div class="stat-label">PRs · Today</div>
  </div>
</div>

<?php if ($fitnessInsight): ?>
  <?= renderInsight($fitnessInsight, 'fa-dumbbell') ?>
<?php endif; ?>

<!-- Module tabs -->
<div class="filter-tabs fit-tabs" style="margin-bottom:1.25rem" id="gymTabs" role="tablist" aria-label="Fitness sections">
  <button class="filter-tab active" data-tab="overview" role="tab" id="gymtab-overview" aria-controls="tab-overview" aria-selected="true">Dashboard</button>
  <button class="filter-tab" data-tab="practice" role="tab" id="gymtab-practice" aria-controls="tab-practice" aria-selected="false" tabindex="-1">Workouts</button>
  <button class="filter-tab" data-tab="library" role="tab" id="gymtab-library" aria-controls="tab-library" aria-selected="false" tabindex="-1">Exercises</button>
  <button class="filter-tab" data-tab="progress" role="tab" id="gymtab-progress" aria-controls="tab-progress" aria-selected="false" tabindex="-1">Progress</button>
  <button class="filter-tab" data-tab="goals" role="tab" id="gymtab-goals" aria-controls="tab-goals" aria-selected="false" tabindex="-1">Goals</button>
  <button class="filter-tab" data-tab="nutrition" role="tab" id="gymtab-nutrition" aria-controls="tab-nutrition" aria-selected="false" tabindex="-1">Nutrition</button>
  <button class="filter-tab" data-tab="recovery" role="tab" id="gymtab-recovery" aria-controls="tab-recovery" aria-selected="false" tabindex="-1">Recovery</button>
</div>

<!-- ═══ Overview ═══ -->
<div id="tab-overview" class="gym-tab-panel" role="tabpanel" aria-labelledby="gymtab-overview">

  <div class="fit-grid" style="margin-bottom:1.5rem">
    <div class="fit-col">

      <!-- Today's Workout — the primary card -->
      <div class="card card-body fit-today-card <?= $todaysPlan ? 'has-plan' : '' ?>">
        <?php if ($todaysPlan): ?>
          <div class="fit-card-label">Today's Workout</div>
          <div class="fit-today-name"><i class="fas fa-dumbbell"></i> <?= h($todaysPlan['name']) ?></div>
          <?php if ($todaysPlanItems): ?>
            <div class="fit-today-meta"><?= h(implode(' · ', array_unique(array_map(fn($i) => $i['exercise_name'], array_slice($todaysPlanItems, 0, 3))))) ?><?= count($todaysPlanItems) > 3 ? '…' : '' ?></div>
          <?php endif; ?>
          <div class="fit-today-chips">
            <span class="fit-today-chip"><i class="fas fa-list-check"></i> <?= (int)$todaysPlan['item_count'] ?> Exercise<?= $todaysPlan['item_count']==1?'':'s' ?></span>
            <?php if ($todaysPlanAvgMin > 0): ?>
              <span class="fit-today-chip" title="Average of your last finished sessions of this plan"><i class="fas fa-clock"></i> usually <?= $todaysPlanAvgMin ?> min</span>
            <?php endif; ?>
          </div>
          <button class="btn btn-primary" style="width:100%" onclick="startPlanWorkout(<?= $todaysPlan['id'] ?>, '<?= h(addslashes($todaysPlan['name'])) ?>')">
            <i class="fas fa-play"></i> Start Workout
          </button>
        <?php else: ?>
          <div class="fit-empty">
            <div class="fit-empty-icon"><i class="fas fa-calendar-xmark"></i></div>
            <div class="fit-empty-title">No workout scheduled</div>
            <div class="fit-empty-text">You don't have a workout planned for today.</div>
            <div class="fit-empty-actions">
              <button class="btn btn-primary btn-sm" onclick="openAddPlan()"><i class="fas fa-plus"></i> Create Workout</button>
              <button class="btn btn-secondary btn-sm" onclick="switchGymTab('practice')"><i class="fas fa-calendar-days"></i> Browse Plans</button>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <!-- Recent Workouts -->
      <div class="card card-body">
        <div class="fit-card-label">Recent Workouts <a href="javascript:void(0)" onclick="showWorkoutHistory()">View All →</a></div>
        <div id="logList">
          <?php if (empty($recentWorkouts)): ?>
            <div class="fit-empty">
              <div class="fit-empty-icon"><i class="fas fa-dumbbell"></i></div>
              <div class="fit-empty-title">Start Your Fitness Journey</div>
              <div class="fit-empty-text">You haven't logged a workout yet. Create a plan or log a one-off workout to start tracking progress.</div>
              <div class="fit-empty-actions">
                <button class="btn btn-primary btn-sm" onclick="openAddPlan()"><i class="fas fa-clipboard-list"></i> Create Workout Plan</button>
                <button class="btn btn-secondary btn-sm" onclick="openLogWorkout()"><i class="fas fa-plus"></i> Log Workout</button>
              </div>
            </div>
          <?php else: foreach ($recentWorkouts as $w): ?>
            <div class="fit-recent-row" onclick="showWorkoutHistory()">
              <span class="fit-recent-dot"></span>
              <div style="flex:1;min-width:0">
                <div class="fit-recent-name"><?= h($w['plan_name'] ?: 'Workout') ?></div>
                <div class="fit-recent-meta">
                  <?= h(formatDate($w['log_date'])) ?> · <?= (int)$w['exercise_count'] ?> exercise<?= $w['exercise_count']==1?'':'s' ?> · <?= (int)$w['total_sets'] ?> sets
                  <?= $w['duration_sec'] ? ' · ' . round($w['duration_sec']/60) . ' min' : '' ?>
                </div>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

    </div>
    <div class="fit-col">

      <!-- Weekly Plan -->
      <div class="card card-body" id="gymWeeklyPlanWrap">
        <div class="fit-card-label">Weekly Plan <a href="javascript:void(0)" onclick="switchGymTab('practice')">View Calendar →</a></div>
        <div class="fit-week-strip">
          <?php foreach ($weekDays as $wd): $st = $weekDayStatus[$wd]; ?>
            <div class="fit-week-cell <?= $st['is_today'] ? 'is-today' : '' ?>">
              <div class="fit-week-dow"><?= substr($wd, 0, 3) ?></div>
              <div class="fit-week-plan-label"><?= $st['plan'] ? h($st['plan']['name']) : 'Rest' ?></div>
              <?php if ($st['plan'] && $st['completed']): ?>
                <div class="fit-week-mark completed" title="Completed"><i class="fas fa-check"></i></div>
              <?php elseif ($st['plan']): ?>
                <button class="fit-week-mark planned" title="Start <?= h($st['plan']['name']) ?>" onclick="startPlanWorkout(<?= $st['plan']['id'] ?>, '<?= h(addslashes($st['plan']['name'])) ?>')"></button>
              <?php else: ?>
                <div class="fit-week-mark rest" title="Rest day"><i class="fas fa-minus" style="font-size:.625rem"></i></div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Progress Overview (preview — full charts live in the Progress tab) -->
      <div class="card card-body" id="gymProgressPreviewWrap">
        <div class="fit-card-label">Progress Overview <a href="javascript:void(0)" onclick="switchGymTab('progress')">This Week →</a></div>
        <div class="fit-vol-label">Training Volume</div>
        <div class="fit-vol-num"><?= number_format($volumeThisWeek, 0) ?> kg</div>
        <?php if ($volumeDeltaPct !== null): ?>
          <div class="stat-trend <?= $volumeDeltaPct >= 0 ? 'up' : 'down' ?>"><i class="fas fa-arrow-<?= $volumeDeltaPct >= 0 ? 'up' : 'down' ?>"></i> <?= abs($volumeDeltaPct) ?>% vs last week</div>
        <?php endif; ?>
        <?php if ($muscleTop3): ?>
          <div style="margin-top:.875rem;border-top:1px solid var(--border);padding-top:.625rem">
            <?php foreach ($muscleTop3 as $m): ?>
              <div class="fit-muscle-row"><span><?= h($m['muscle_group']) ?></span><span><?= (int)$m['cnt'] ?></span></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Daily Goal -->
      <?php if ($scheduledDaysThisWeek > 0): $goalPct = min(100, round($sessionsThisWeek / $scheduledDaysThisWeek * 100)); ?>
        <div class="card card-body" id="gymDailyGoalWrap" style="text-align:center">
          <div class="fit-card-label" style="justify-content:center">Weekly goal</div>
          <div class="fit-goal-ring" style="--pct:<?= $goalPct ?>">
            <div class="fit-goal-ring-inner">
              <div class="fit-goal-ring-frac"><?= $sessionsThisWeek ?>/<?= $scheduledDaysThisWeek ?></div>
              <div class="fit-goal-ring-label">workouts</div>
            </div>
          </div>
          <div style="font-size:.8125rem;color:var(--muted)">Keep it up, <?= h($userName) ?>! 💪</div>
        </div>
      <?php endif; ?>

    </div>
  </div>

  <!-- Quick Actions -->
  <div class="fit-quick-actions">
    <button class="fit-qa-card" onclick="openAddPlan()">
      <div class="fit-qa-icon"><i class="fas fa-calendar-plus"></i></div>
      <div><div class="fit-qa-title">New Plan</div><div class="fit-qa-sub">Create a workout plan</div></div>
    </button>
    <button class="fit-qa-card" onclick="openLogWorkout()">
      <div class="fit-qa-icon"><i class="fas fa-dumbbell"></i></div>
      <div><div class="fit-qa-title">Log Workout</div><div class="fit-qa-sub">Track your workout</div></div>
    </button>
    <button class="fit-qa-card" onclick="switchGymTab('library')">
      <div class="fit-qa-icon"><i class="fas fa-book-open"></i></div>
      <div><div class="fit-qa-title">Exercises</div><div class="fit-qa-sub">Browse exercises</div></div>
    </button>
    <button class="fit-qa-card" onclick="switchGymTab('progress')">
      <div class="fit-qa-icon"><i class="fas fa-chart-line"></i></div>
      <div><div class="fit-qa-title">Body Stats</div><div class="fit-qa-sub">Track progress</div></div>
    </button>
  </div>

</div>

<!-- Workout session — full-screen splash, hidden until "Start workout" is clicked -->
<div id="workoutSession" class="workout-overlay hidden" role="dialog" aria-modal="true" aria-labelledby="sessionPlanName">
  <div class="wo-shell">

    <header class="wo-topbar">
      <button type="button" class="wo-icon-btn" onclick="quitSession()" aria-label="Leave workout"><i class="fas fa-arrow-left" aria-hidden="true"></i></button>
      <div class="wo-topbar-title">
        <span class="wo-eyebrow">Workout</span>
        <h2 id="sessionPlanName">Workout</h2>
      </div>
      <div class="wo-timer" role="timer" aria-label="Elapsed time">
        <span class="wo-timer-dot" aria-hidden="true"></span>
        <span id="sessionElapsed">00:00</span>
      </div>
    </header>

    <section class="wo-progress" aria-label="Workout progress">
      <div class="wo-progress-row">
        <span class="wo-label">Workout progress</span>
        <span class="wo-progress-pct" id="sessionProgressPct">0%</span>
      </div>
      <div class="wo-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="sessionProgressTrack">
        <div class="wo-progress-fill" id="sessionProgressBar"></div>
      </div>
      <div class="wo-progress-meta" id="sessionProgress">—</div>
    </section>

    <div class="wo-workspace">
      <main class="wo-main">

        <!-- ── Exercise phase ── -->
        <div id="woExercisePhase" class="wo-phase">
          <article class="wo-card wo-exercise-card" id="sessionExerciseCard">
            <div class="wo-media hidden" id="sessionMedia">
              <video id="sessionExerciseVideo" muted loop playsinline autoplay aria-label="Exercise demonstration"></video>
              <img id="sessionExerciseGif" class="hidden" alt="Exercise demonstration" loading="lazy" referrerpolicy="no-referrer">
              <span class="wo-media-credit hidden" id="sessionMediaCredit"></span>
            </div>
            <div class="wo-exercise-head">
              <span class="wo-eyebrow" id="sessionExerciseIndex">Exercise</span>
              <h3 class="wo-exercise-name" id="sessionExerciseName" tabindex="-1">—</h3>
              <div class="wo-facts">
                <div class="wo-fact">
                  <span class="wo-label">Previous</span>
                  <span class="wo-fact-value" id="sessionPrevPerf">First time</span>
                  <span class="wo-fact-sub" id="sessionPrevPerfSub"></span>
                </div>
                <div class="wo-fact">
                  <span class="wo-label">Target</span>
                  <span class="wo-fact-value" id="sessionTarget">—</span>
                  <span class="wo-fact-sub" id="sessionTargetSub"></span>
                </div>
              </div>
              <details class="wo-howto hidden" id="sessionHowTo">
                <summary>How to do it</summary>
                <ol id="sessionHowToSteps"></ol>
              </details>
            </div>
          </article>

          <section class="wo-card wo-set-card" aria-labelledby="sessionSetIndicator">
            <div class="wo-set-card-head">
              <h4 class="wo-set-title" id="sessionSetIndicator">Sets</h4>
              <span class="wo-status" id="sessionSetStatus" role="status" aria-live="polite"></span>
            </div>

            <div class="wo-pr-banner hidden" id="sessionPrBanner"><i class="fas fa-trophy" aria-hidden="true"></i> New personal record</div>

            <table class="wo-table">
              <caption class="sr-only">Sets for this exercise: weight, reps and completion</caption>
              <thead>
                <tr>
                  <th scope="col">Set</th>
                  <th scope="col">Previous</th>
                  <th scope="col">kg</th>
                  <th scope="col">Reps</th>
                  <th scope="col"><span class="sr-only">Done</span><i class="fas fa-check" aria-hidden="true"></i></th>
                </tr>
              </thead>
              <tbody id="sessionSetRows"></tbody>
            </table>

            <div class="wo-set-tools">
              <button type="button" class="wo-btn wo-btn-ghost wo-btn-sm" id="woAddSet"><i class="fas fa-plus" aria-hidden="true"></i> Add set</button>
              <button type="button" class="wo-btn wo-btn-ghost wo-btn-sm" id="woRemoveSet"><i class="fas fa-minus" aria-hidden="true"></i> Remove set</button>
            </div>

            <div class="wo-rest hidden" id="woRest" role="timer" aria-label="Rest timer">
              <div class="wo-rest-head">
                <span class="wo-label">Rest</span>
                <span class="wo-rest-time" id="woRestTime">01:30</span>
              </div>
              <div class="wo-rest-track" aria-hidden="true"><div class="wo-rest-fill" id="woRestFill"></div></div>
              <div class="wo-rest-actions">
                <button type="button" class="wo-btn wo-btn-ghost wo-btn-sm" data-rest="-15" aria-label="Rest 15 seconds less">−15s</button>
                <button type="button" class="wo-btn wo-btn-ghost wo-btn-sm" data-rest="15" aria-label="Rest 15 seconds more">+15s</button>
                <button type="button" class="wo-btn wo-btn-ghost wo-btn-sm" data-rest="skip">Skip rest</button>
              </div>
            </div>
          </section>

          <div class="wo-actions">
            <button type="button" class="wo-btn wo-btn-primary" id="completeSetBtn">
              <i class="fas fa-check" aria-hidden="true"></i><span id="completeSetLabel">Complete set</span>
            </button>
            <div class="wo-actions-secondary">
              <button type="button" class="wo-btn wo-btn-ghost" id="woPrevEx"><i class="fas fa-chevron-left" aria-hidden="true"></i> Previous</button>
              <button type="button" class="wo-btn wo-btn-ghost" id="woNextEx"><span id="woNextLabel">Next exercise</span> <i class="fas fa-chevron-right" aria-hidden="true"></i></button>
            </div>
          </div>
        </div>

        <!-- ── Summary phase ── -->
        <div id="woSummaryPhase" class="wo-phase hidden">
          <section class="wo-card wo-summary">
            <div class="wo-summary-badge" aria-hidden="true"><i class="fas fa-trophy"></i></div>
            <span class="wo-eyebrow wo-eyebrow-ok" id="summaryEyebrow">Workout complete</span>
            <h3 class="wo-summary-title" id="summaryTitle" tabindex="-1">—</h3>
            <dl class="wo-summary-grid" id="summaryStats"></dl>
            <p class="wo-summary-streak hidden" id="summaryStreak"></p>
          </section>
          <section class="wo-card wo-breakdown" aria-labelledby="summaryBreakdownTitle">
            <h4 class="wo-set-title" id="summaryBreakdownTitle">Exercises</h4>
            <ul class="wo-breakdown-list" id="summaryBreakdown"></ul>
          </section>
          <div class="wo-actions">
            <button type="button" class="wo-btn wo-btn-primary" onclick="closeSummary()"><i class="fas fa-check" aria-hidden="true"></i><span>Finish workout</span></button>
          </div>
        </div>

      </main>

      <aside class="wo-context" aria-label="Session overview">
        <span class="wo-eyebrow">Today</span>
        <p class="wo-context-title" id="ctxPlanName">—</p>
        <dl class="wo-context-list">
          <div><dt>Exercises</dt><dd id="ctxExercises">—</dd></div>
          <div><dt>Sets</dt><dd id="ctxSets">—</dd></div>
          <div><dt>Volume</dt><dd id="ctxVolume">—</dd></div>
          <div><dt>Time</dt><dd id="ctxTime">00:00</dd></div>
        </dl>
      </aside>
    </div>

  </div>
</div>

<!-- ═══ Workouts (plans · history · journal) ═══ -->
<div id="tab-practice" class="gym-tab-panel hidden" role="tabpanel" aria-labelledby="gymtab-practice">

<div style="font-size:.9375rem;font-weight:600;margin-bottom:.75rem">This Week</div>
<div class="gym-week-grid" style="margin-bottom:1.75rem">
  <?php
  // $weekDays/$plansByDay computed once near the top of the file (shared
  // with the Overview dashboard's weekly plan card).
  foreach ($weekDays as $wd):
      $isToday = $wd === $todayName;
      $dayPlans = $plansByDay[$wd] ?? [];
  ?>
    <div class="gym-week-day <?= $isToday ? 'is-today' : '' ?>">
      <div class="gym-week-day-label"><?= substr($wd, 0, 3) ?></div>
      <?php if ($dayPlans): foreach ($dayPlans as $dp): ?>
        <button class="gym-week-plan-chip" onclick="startPlanWorkout(<?= $dp['id'] ?>, '<?= h(addslashes($dp['name'])) ?>')" title="Start <?= h($dp['name']) ?>">
          <?= h($dp['name']) ?>
        </button>
      <?php endforeach; else: ?>
        <span class="gym-week-day-rest">Rest</span>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<div style="font-size:.9375rem;font-weight:600;margin-bottom:.75rem">My Workout Plans</div>
<div id="gymPlansWrap">
<?php if (empty($plans)): ?>
  <div class="card" style="margin-bottom:1.5rem">
    <div class="empty-state">
      <div class="empty-state-icon"><i class="fas fa-clipboard-list"></i></div>
      <div class="empty-state-title">No workout plans yet</div>
      <p>Create a plan (e.g. "Push Day") with the exercises you want to track.</p>
      <button class="btn btn-primary" style="margin-top:.75rem" onclick="openAddPlan()"><i class="fas fa-plus"></i> Create a plan</button>
    </div>
  </div>
<?php else: ?>
  <div class="grid-cards" style="margin-bottom:1.5rem">
    <?php foreach ($plans as $p): $isToday = $p['day_of_week'] === $todayName; ?>
      <div class="habit-card" id="plan-<?= $p['id'] ?>" <?= $isToday ? 'style="border-color:var(--accent)"' : '' ?>>
        <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:.5rem">
          <div style="font-weight:600;font-size:.9375rem;color:var(--text)"><i class="fas fa-list-check" style="color:var(--accent);margin-right:.375rem"></i><?= h($p['name']) ?></div>
          <div style="display:flex;gap:.125rem;flex-shrink:0">
            <button aria-label="Edit workout plan" class="btn btn-icon btn-ghost btn-sm" onclick="openEditPlan(<?= $p['id'] ?>)" title="Edit">
              <i class="fas fa-pen"></i>
            </button>
            <button aria-label="Delete workout plan" class="btn btn-icon btn-ghost btn-sm" style="color:var(--accent)" onclick="deletePlan(<?= $p['id'] ?>)" title="Delete">
              <i class="fas fa-trash"></i>
            </button>
          </div>
        </div>
        <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.875rem">
          <span class="badge <?= $isToday ? 'badge-red' : 'badge-gray' ?>">
            <?= $isToday ? '<i class="fas fa-star" style="font-size:.65rem"></i> Today · ' : '' ?><?= h($p['day_of_week']) ?>
          </span>
          <span style="font-size:.8125rem;color:var(--muted)"><?= (int)$p['item_count'] ?> exercise<?= $p['item_count']==1?'':'s' ?></span>
        </div>
        <button class="btn btn-primary btn-sm" style="width:100%" onclick="startPlanWorkout(<?= $p['id'] ?>, '<?= h(addslashes($p['name'])) ?>')">
          <i class="fas fa-play"></i> Start workout
        </button>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
</div>

<div class="fit-section-head">
  <h2 class="fit-h2">History</h2>
  <button class="btn btn-secondary btn-sm" onclick="openLogWorkout()"><i class="fas fa-plus"></i> Quick log</button>
</div>
<div id="historyList" class="fit-history" aria-live="polite"><p class="form-hint">Loading your workouts…</p></div>
<div style="text-align:center;margin:.75rem 0 1.75rem"><button id="historyMore" class="btn btn-secondary btn-sm hidden" type="button">Load older workouts</button></div>

<div class="fit-section-head"><h2 class="fit-h2">Training journal</h2></div>
<div class="card card-body" style="margin-bottom:1.25rem">
    <label for="journalBody" class="form-label">New entry</label>
    <textarea id="journalBody" class="form-input" rows="3" placeholder="How did today's session go? Any PRs, soreness, notes for next time…"></textarea>
    <div style="display:flex;justify-content:flex-end;margin-top:.625rem">
      <button class="btn btn-primary btn-sm" onclick="addJournalEntry()"><i class="fas fa-plus"></i> Add entry</button>
    </div>
  </div>
  <div id="journalListWrap">
    <?php if (empty($journalEntries)): ?>
      <div class="card"><div class="empty-state"><div class="empty-state-icon"><i class="fas fa-book-open"></i></div><div class="empty-state-title">No journal entries yet</div><p>Jot down how your training is going — form notes, energy levels, what worked.</p></div></div>
    <?php else: foreach ($journalEntries as $j): ?>
      <div class="card card-body" style="margin-bottom:.75rem" id="journal-<?= $j['id'] ?>">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:.75rem">
          <div style="font-size:.875rem;color:var(--text);white-space:pre-wrap;flex:1"><?= h($j['body']) ?></div>
          <button aria-label="Delete journal entry" class="btn btn-icon btn-ghost btn-sm" onclick="deleteJournalEntry(<?= $j['id'] ?>)"><i class="fas fa-trash" style="font-size:.75rem"></i></button>
        </div>
        <div style="font-size:.75rem;color:var(--subtle);margin-top:.5rem"><?= formatDate($j['entry_date']) ?></div>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<!-- ═══ Exercises ═══ -->
<div id="tab-library" class="gym-tab-panel hidden" role="tabpanel" aria-labelledby="gymtab-library">
  <div class="card card-body" style="margin-bottom:1.25rem">
    <div style="display:flex;gap:.625rem;flex-wrap:wrap">
      <input id="libSearch" class="form-input" placeholder="Search exercises…" aria-label="Search exercises" style="flex:2;min-width:180px">
      <select id="libMuscle" class="form-input" aria-label="Filter by muscle group" style="flex:1;min-width:140px">
        <option value="">All muscle groups</option>
        <?php foreach (['Chest','Back','Legs','Shoulders','Arms','Core','Cardio','Full Body'] as $m): ?>
          <option value="<?= $m ?>"><?= $m ?></option>
        <?php endforeach; ?>
      </select>
      <select id="libEquipment" class="form-input" aria-label="Filter by equipment" style="flex:1;min-width:140px">
        <option value="">All equipment</option>
        <?php foreach (['Barbell','Dumbbell','Machine','Cable','Bodyweight'] as $eq): ?>
          <option value="<?= $eq ?>"><?= $eq ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-secondary btn-sm" onclick="openAddExercise()"><i class="fas fa-plus"></i> Custom exercise</button>
    </div>
  </div>
  <div id="libVideoBanner" class="hidden" style="margin-bottom:1.25rem"></div>
  <p class="form-hint hidden" id="libRemoteHint" style="margin:0 0 .75rem"></p>
  <div id="libResults" class="fx-grid" aria-live="polite"></div>
</div>

<!-- Exercise detail sheet (Exercises tab) -->
<div id="exerciseDetailModal" class="modal-backdrop hidden">
  <div class="modal-box fx-detail" role="dialog" aria-labelledby="fxDetailName">
    <div class="modal-header">
      <span class="modal-title" id="fxDetailName">Exercise</span>
      <button class="btn btn-icon btn-ghost btn-sm" data-close-modal="exerciseDetailModal" aria-label="Close dialog">&times;</button>
    </div>
    <div class="modal-body">
      <div class="fx-detail-media hidden" id="fxDetailMedia">
        <video id="fxDetailVideo" controls muted loop playsinline preload="metadata"></video>
        <img id="fxDetailGif" class="hidden" alt="" loading="lazy" referrerpolicy="no-referrer">
      </div>
      <p class="fx-credit hidden" id="fxDetailCredit"></p>
      <dl class="fx-facts" id="fxDetailFacts"></dl>
      <div id="fxDetailStepsWrap" class="hidden">
        <div class="form-label">How to do it</div>
        <ol class="fx-steps" id="fxDetailSteps"></ol>
      </div>
      <div class="fx-add" id="fxAddWrap">
        <div class="form-label">Add to a workout</div>
        <?php if ($plans): ?>
          <div class="fx-add-row">
            <select id="fxAddPlan" class="form-input" aria-label="Workout plan">
              <?php foreach ($plans as $p): ?>
                <option value="<?= (int)$p['id'] ?>"><?= h($p['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <label class="fx-add-num"><span>Sets</span><input id="fxAddSets" type="number" min="1" max="50" value="3" class="form-input"></label>
            <label class="fx-add-num"><span>Reps</span><input id="fxAddReps" type="number" min="1" max="100" value="10" class="form-input"></label>
          </div>
        <?php else: ?>
          <p class="form-hint" style="margin:0">Create a workout plan first, then add exercises to it from here.</p>
        <?php endif; ?>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost btn-sm" id="fxSaveBtn" type="button"><i class="fas fa-bookmark"></i> Save to my library</button>
      <button class="btn btn-secondary btn-sm" id="fxLogBtn" type="button"><i class="fas fa-pen"></i> Log a set</button>
      <?php if ($plans): ?>
        <button class="btn btn-primary btn-sm" id="fxAddBtn" type="button"><i class="fas fa-plus"></i> Add to workout</button>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ═══ Progress ═══ -->
<div id="tab-progress" class="gym-tab-panel hidden" role="tabpanel" aria-labelledby="gymtab-progress">
  <div style="font-size:.9375rem;font-weight:600;margin-bottom:.75rem">Achievements</div>
  <div class="grid-stats" style="margin-bottom:1.5rem" id="gymAchievementsWrap">
    <?php foreach ($gymAchKeys as $key): $def = $allAchDefs[$key]; $unlocked = in_array($key, $unlockedKeys, true); ?>
      <div class="stat-card" style="<?= $unlocked ? '' : 'opacity:.45' ?>">
        <div style="font-size:1.5rem"><?= $def['emoji'] ?></div>
        <div class="stat-label" style="margin-top:.25rem"><?= h($def['name']) ?></div>
        <div style="font-size:.6875rem;color:var(--muted);margin-top:.125rem"><?= h($def['desc']) ?></div>
      </div>
    <?php endforeach; ?>
  </div>



  <div class="grid-stats" style="margin-bottom:1.5rem" id="progTotalsWrap">
    <div class="stat-card"><div class="stat-val" id="progTotalSets">—</div><div class="stat-label">Total sets</div></div>
    <div class="stat-card"><div class="stat-val" id="progTotalReps">—</div><div class="stat-label">Total reps</div></div>
    <div class="stat-card"><div class="stat-val" id="progTrainingTime">—</div><div class="stat-label">Training time</div></div>
    <div class="stat-card"><div class="stat-val" id="progTotalLogs">—</div><div class="stat-label">Exercises logged</div></div>
  </div>

  <div class="grid-2" style="margin-bottom:1.5rem;align-items:start">
    <div class="card card-body">
      <div class="chart-card-header"><span class="d-card-title">Weekly frequency</span></div>
      <div style="position:relative;height:180px"><canvas id="progFreqChart"></canvas></div>
    </div>
    <div class="card card-body">
      <div class="chart-card-header"><span class="d-card-title">Training volume (kg lifted)</span></div>
      <div style="position:relative;height:180px"><canvas id="progVolumeChart"></canvas></div>
    </div>
  </div>

  <div class="grid-2" style="margin-bottom:1.5rem;align-items:start">
    <div class="card card-body">
      <div class="chart-card-header"><span class="d-card-title">Muscle group distribution</span></div>
      <div style="position:relative;height:200px"><canvas id="progMuscleChart"></canvas></div>
    </div>
    <div class="card card-body">
      <div class="chart-card-header"><span class="d-card-title">Personal Records</span></div>
      <div id="progPrList"><p class="form-hint">Loading…</p></div>
    </div>
  </div>

  <div class="card card-body" style="margin-bottom:1.25rem">
    <div class="chart-card-header"><span class="d-card-title">Body weight (optional)</span></div>
    <div style="display:flex;gap:.625rem;flex-wrap:wrap;margin-bottom:.875rem">
      <input id="bodyWeightInput" type="number" step="0.1" min="0" class="form-input" placeholder="Weight (kg)" style="max-width:140px">
      <input id="bodyFatInput" type="number" step="0.1" min="0" class="form-input" placeholder="Body fat % (optional)" style="max-width:170px">
      <input id="bodyDateInput" type="date" class="form-input" value="<?= $today ?>" style="max-width:160px">
      <button class="btn btn-primary btn-sm" onclick="saveBodyStat()"><i class="fas fa-plus"></i> Log</button>
    </div>
    <div id="bodyStatsList"><p class="form-hint">No entries yet — log your weight above to start a trend.</p></div>
  </div>

</div>

<!-- ═══ Goals ═══ -->
<div id="tab-goals" class="gym-tab-panel hidden" role="tabpanel" aria-labelledby="gymtab-goals">
<?php include __DIR__ . '/../includes/components/fitness_goals.php'; ?>
</div>

<!-- ═══ Nutrition ═══ -->
<div id="tab-nutrition" class="gym-tab-panel hidden" role="tabpanel" aria-labelledby="gymtab-nutrition">
<?php include __DIR__ . '/../includes/components/fitness_nutrition.php'; ?>
</div>

<!-- ═══ Recovery ═══ -->
<div id="tab-recovery" class="gym-tab-panel hidden" role="tabpanel" aria-labelledby="gymtab-recovery">
<?php include __DIR__ . '/../includes/components/fitness_recovery.php'; ?>
</div>

<!-- Add plan modal -->
<div id="addPlanModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header">
      <span class="modal-title" id="planModalTitle">New Workout Plan</span>
      <button class="btn btn-icon btn-ghost btn-sm" data-close-modal="addPlanModal" aria-label="Close dialog">&times;</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label for="planName" class="form-label">Plan name <span style="color:var(--accent)">*</span></label>
        <input id="planName" class="form-input" placeholder="e.g. Push Day, Leg Day">
      </div>
      <div class="form-group">
        <label for="planDay" class="form-label">Day of week</label>
        <select id="planDay" class="form-input">
          <option value="Any">Any day</option>
          <?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as $d): ?>
            <option value="<?= $d ?>"><?= $d ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <label class="form-label" id="lbl-plan-exercises">Exercises</label>
      <div id="planItems" role="group" aria-labelledby="lbl-plan-exercises"></div>
      <button type="button" class="btn btn-ghost btn-sm" onclick="addExerciseCard()" style="margin-top:.5rem">
        <i class="fas fa-plus"></i> Add exercise
      </button>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary btn-sm" data-close-modal="addPlanModal">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="savePlan()"><i class="fas fa-save"></i> Save plan</button>
    </div>
  </div>
</div>

<!-- Log workout modal -->
<div id="logWorkoutModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header">
      <span class="modal-title" id="logWorkoutTitle">Log Workout</span>
      <button class="btn btn-icon btn-ghost btn-sm" data-close-modal="logWorkoutModal" aria-label="Close dialog">&times;</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label for="logExercise" class="form-label">Exercise <span style="color:var(--accent)">*</span></label>
        <input id="logExercise" class="form-input" placeholder="e.g. Bench Press">
      </div>
      <div class="form-grid-2">
        <div class="form-group"><label for="logSets" class="form-label">Sets</label><input id="logSets" type="number" min="0" class="form-input"></div>
        <div class="form-group"><label for="logReps" class="form-label">Reps</label><input id="logReps" type="number" min="0" class="form-input"></div>
      </div>
      <div class="form-grid-2">
        <div class="form-group"><label for="logWeight" class="form-label">Weight (kg)</label><input id="logWeight" type="number" step="0.5" min="0" class="form-input"></div>
        <div class="form-group"><label for="logDate" class="form-label">Date</label><input id="logDate" type="date" class="form-input" value="<?= $today ?>"></div>
      </div>
      <div class="form-group"><label for="logNotes" class="form-label">Notes</label><input id="logNotes" class="form-input" placeholder="Optional"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary btn-sm" data-close-modal="logWorkoutModal">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="saveLog()"><i class="fas fa-save"></i> Save</button>
    </div>
  </div>
</div>

<!-- Add custom exercise modal -->
<div id="addExerciseModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header">
      <span class="modal-title">Custom Exercise</span>
      <button class="btn btn-icon btn-ghost btn-sm" data-close-modal="addExerciseModal" aria-label="Close dialog">&times;</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label for="exName" class="form-label">Name <span style="color:var(--accent)">*</span></label>
        <input id="exName" class="form-input" placeholder="e.g. Cable Lateral Raise">
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label for="exMuscle" class="form-label">Muscle group</label>
          <select id="exMuscle" class="form-input">
            <?php foreach (['Chest','Back','Legs','Shoulders','Arms','Core','Cardio','Full Body'] as $m): ?>
              <option value="<?= $m ?>"><?= $m ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="exEquipment" class="form-label">Equipment</label>
          <select id="exEquipment" class="form-input">
            <?php foreach (['Barbell','Dumbbell','Machine','Cable','Bodyweight'] as $eq): ?>
              <option value="<?= $eq ?>"><?= $eq ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary btn-sm" data-close-modal="addExerciseModal">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="saveCustomExercise()"><i class="fas fa-save"></i> Save</button>
    </div>
  </div>
</div>

<!-- Assign video modal — walks through unassigned files in assets/vids one
     at a time: preview the clip, then either pick an existing exercise or
     name a new one. -->
<div id="assignVideoModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header">
      <span class="modal-title">Match Exercise Video (<span id="avRemaining">0</span> left)</span>
      <button class="btn btn-icon btn-ghost btn-sm" data-close-modal="assignVideoModal" aria-label="Close dialog">&times;</button>
    </div>
    <div class="modal-body">
      <video id="avPreview" controls muted style="width:100%;border-radius:.625rem;background:#000;margin-bottom:1rem;max-height:280px"></video>
      <div class="form-group">
        <label for="avExisting" class="form-label">Match to an existing exercise</label>
        <select id="avExisting" class="form-input">
          <option value="">— choose one —</option>
        </select>
      </div>
      <div class="form-hint" style="margin:.5rem 0">— or create a new exercise for this clip —</div>
      <div class="form-group">
        <label for="avNewName" class="form-label">New exercise name</label>
        <input id="avNewName" class="form-input" placeholder="e.g. Cross-Body Hammer Curl">
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label for="avMuscle" class="form-label">Muscle group</label>
          <select id="avMuscle" class="form-input">
            <?php foreach (['Chest','Back','Legs','Shoulders','Arms','Core','Cardio','Full Body'] as $m): ?>
              <option value="<?= $m ?>"><?= $m ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="avEquipment" class="form-label">Equipment</label>
          <select id="avEquipment" class="form-input">
            <?php foreach (['Barbell','Dumbbell','Machine','Cable','Bodyweight'] as $eq): ?>
              <option value="<?= $eq ?>"><?= $eq ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost btn-sm" onclick="skipVideoAssign()">Skip</button>
      <button class="btn btn-primary btn-sm" onclick="assignCurrentVideo()"><i class="fas fa-check"></i> Assign & Next</button>
    </div>
  </div>
</div>

<!-- Watch exercise video (lightbox) -->
<div id="watchVideoModal" class="modal-backdrop hidden">
  <div class="modal-box" style="max-width:520px">
    <div class="modal-header">
      <span class="modal-title" id="wvTitle">Exercise</span>
      <button class="btn btn-icon btn-ghost btn-sm" data-close-modal="watchVideoModal" aria-label="Close dialog">&times;</button>
    </div>
    <div class="modal-body" style="padding:0">
      <video id="wvPlayer" controls autoplay loop style="width:100%;display:block;background:#000;max-height:70vh"></video>
    </div>
  </div>
</div>

</div>
<?php include '../includes/footer.php'; ?>

<script>
const API_BASE = '<?= APP_BASE ?>/api';

/* ── Tabs ─────────────────────────────────────────────────────── */
// Library/Progress are expensive-ish (search query / multiple aggregate
// queries + chart render) and below-the-fold on first load, so they lazy-
// load on first activation rather than on every page render.
const gymTabLoaded = {};
const gymTabLoaders = {
  practice:  () => loadHistory(true),
  library:   () => { searchExercises(); checkVideoBanner(); },
  progress:  () => { loadProgressTab(); loadBodyStats(); },
  goals:     () => loadGoals(),
  nutrition: () => loadNutrition(),
  recovery:  () => loadRecovery(),
};
function switchGymTab(tab, { focus = false } = {}) {
  document.querySelectorAll('#gymTabs .filter-tab').forEach(b => {
    const on = b.dataset.tab === tab;
    b.classList.toggle('active', on);
    b.setAttribute('aria-selected', on ? 'true' : 'false');
    b.tabIndex = on ? 0 : -1;
    if (on && focus) b.focus();
  });
  document.querySelectorAll('.gym-tab-panel').forEach(p => p.classList.toggle('hidden', p.id !== `tab-${tab}`));
  // Tabs load their data on first open, not on every page render.
  if (!gymTabLoaded[tab] && gymTabLoaders[tab]) { gymTabLoaded[tab] = true; gymTabLoaders[tab](); }
}
// History lives in the Workouts tab, below the weekly plan and plans list.
function showWorkoutHistory() {
  switchGymTab('practice');
  requestAnimationFrame(() => document.getElementById('historyList')
    ?.previousElementSibling?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
}
document.getElementById('gymTabs').addEventListener('click', e => {
  const btn = e.target.closest('[data-tab]');
  if (btn) switchGymTab(btn.dataset.tab);
});
// WAI-ARIA tabs: arrow keys / Home / End move between tabs.
document.getElementById('gymTabs').addEventListener('keydown', e => {
  const tabs = [...document.querySelectorAll('#gymTabs [data-tab]')];
  const i = tabs.indexOf(document.activeElement);
  if (i < 0) return;
  const to = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: tabs.length - 1 }[e.key];
  if (to === undefined) return;
  e.preventDefault();
  switchGymTab(tabs[(to + tabs.length) % tabs.length].dataset.tab, { focus: true });
});

/* Small DOM helpers shared by the section renderers below. */
function fEl(tag, cls, text) {
  const e = document.createElement(tag);
  if (cls) e.className = cls;
  if (text !== undefined && text !== null) e.textContent = text;
  return e;
}
function fEmpty(icon, title, text) {
  const wrap = fEl('div', 'empty-state');
  const ic = fEl('div', 'empty-state-icon');
  ic.append(fEl('i', `fas ${icon}`));
  wrap.append(ic, fEl('div', 'empty-state-title', title), fEl('p', null, text));
  return wrap;
}
function fKg(w) { return (Math.round(w * 10) / 10).toLocaleString(undefined, { maximumFractionDigits: 1 }); }
function fDate(ymd) {
  const [y, m, d] = String(ymd).split('-').map(Number);
  const dt = new Date(y, m - 1, d);
  const today = new Date(); today.setHours(0, 0, 0, 0);
  const diff = Math.round((today - dt) / 86400000);
  if (diff === 0) return 'Today';
  if (diff === 1) return 'Yesterday';
  return dt.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short', ...(y !== today.getFullYear() ? { year: 'numeric' } : {}) });
}
async function fPost(body) {
  return Trackie.API.post(`${API_BASE}/gym.php`, body);
}

/* ── Workouts → History (every set) ─────────────────────────────── */
let historyOffset = 0;
async function loadHistory(reset = false) {
  const list = document.getElementById('historyList');
  const more = document.getElementById('historyMore');
  if (!list) return;
  if (reset) { historyOffset = 0; list.replaceChildren(fEl('p', 'form-hint', 'Loading your workouts…')); }
  more.disabled = true;
  try {
    const res = await fPost({ action: 'history', limit: 10, offset: historyOffset });
    if (!res.success) throw new Error('history');
    if (reset) list.replaceChildren();
    if (!res.entries.length && historyOffset === 0) {
      const card = fEl('div', 'card');
      card.append(fEmpty('fa-dumbbell', 'No workouts yet', 'Start a plan or quick-log an exercise — every set you log shows up here.'));
      list.append(card);
    }
    res.entries.forEach(e => list.append(historyEntry(e)));
    historyOffset += res.entries.length;
    more.classList.toggle('hidden', !res.has_more);
  } catch {
    if (reset) list.replaceChildren(fEl('p', 'form-hint', "Couldn't load your history — try again in a moment."));
    else Trackie.Toast.error("Couldn't load older workouts.");
  } finally { more.disabled = false; }
}

function historyEntry(e) {
  const det = fEl('details', 'card fit-hist');
  const sum = fEl('summary', 'fit-hist-sum');
  const main = fEl('div', 'fit-hist-main');
  main.append(fEl('span', 'fit-hist-title', e.title), fEl('span', 'fit-hist-date', fDate(e.date)));
  const meta = [];
  meta.push(`${e.exercises.length} exercise${e.exercises.length === 1 ? '' : 's'}`);
  if (e.set_count) meta.push(`${e.set_count} set${e.set_count === 1 ? '' : 's'}`);
  if (e.volume > 0) meta.push(`${Math.round(e.volume).toLocaleString()} kg`);
  if (e.duration_sec) meta.push(`${Math.max(1, Math.round(e.duration_sec / 60))} min`);
  main.append(fEl('span', 'fit-hist-meta', meta.join(' · ')));
  sum.append(main);
  if (e.guided && e.finished === false) sum.append(fEl('span', 'badge badge-gray', 'Not finished'));
  det.append(sum);

  const body = fEl('div', 'fit-hist-body');
  e.exercises.forEach(x => {
    const row = fEl('div', 'fit-hist-ex');
    row.id = `hist-log-${x.log_id}`;
    const head = fEl('div', 'fit-hist-ex-head');
    head.append(fEl('span', 'fit-hist-ex-name', x.name));
    if (x.volume > 0) head.append(fEl('span', 'fit-hist-ex-vol', `${Math.round(x.volume).toLocaleString()} kg`));
    const del = fEl('button', 'btn btn-icon btn-ghost btn-sm');
    del.type = 'button';
    del.dataset.deleteLog = x.log_id;
    del.setAttribute('aria-label', `Delete ${x.name} from this workout`);
    del.append(fEl('i', 'fas fa-trash'));
    head.append(del);
    const sets = fEl('div', 'fit-hist-sets');
    if (x.legacy) {
      const s = x.summary || {};
      const parts = [];
      if (s.sets) parts.push(`${s.sets} × ${s.reps ?? '—'}`);
      else if (s.reps) parts.push(`${s.reps} reps`);
      if (s.weight) parts.push(`${fKg(s.weight)} kg`);
      sets.append(fEl('span', 'fit-hist-set', parts.join(' @ ') || 'Logged'));
    } else {
      x.sets.forEach((s, i) => {
        const t = [s.weight !== null ? `${fKg(s.weight)} kg` : null, s.reps !== null ? `${s.reps}` : null].filter(Boolean).join(' × ');
        const chip = fEl('span', 'fit-hist-set');
        chip.append(fEl('b', null, String(i + 1)), ' ' + (t || 'Logged'));
        sets.append(chip);
      });
    }
    row.append(head, sets);
    if (x.notes) row.append(fEl('p', 'fit-hist-notes', x.notes));
    body.append(row);
  });
  det.append(body);
  return det;
}

document.getElementById('historyMore')?.addEventListener('click', () => loadHistory(false));
document.getElementById('historyList')?.addEventListener('click', async e => {
  const btn = e.target.closest('[data-delete-log]');
  if (!btn) return;
  const ok = await Trackie.confirmDialog('Delete this exercise and all its sets from your history?', { confirmText: 'Delete', danger: true });
  if (!ok) return;
  try {
    const res = await fPost({ action: 'delete_log', log_id: btn.dataset.deleteLog });
    if (!res.success) { Trackie.Toast.error(res.error || 'Failed.'); return; }
    Trackie.Toast.success('Deleted.');
    loadHistory(true);
    Trackie.refreshFragments(['gymStatsWrap', 'gymStreakBadge', 'gymProgressPreviewWrap', 'logList']);
  } catch { Trackie.Toast.error('Network error.'); }
});

/* ── Goals ──────────────────────────────────────────────────────── */
const GOAL_TARGET_UI = {
  workouts:        { label: 'Workouts', min: 1, step: 1, placeholder: 'e.g. 20' },
  exercise_weight: { label: 'Target weight (kg)', min: 0.5, step: 0.5, placeholder: 'e.g. 50' },
  streak:          { label: 'Streak (days)', min: 2, step: 1, placeholder: 'e.g. 30' },
};
function syncGoalForm() {
  const type = document.getElementById('goalType').value;
  const ui = GOAL_TARGET_UI[type];
  const t = document.getElementById('goalTarget');
  document.getElementById('goalTargetLabel').textContent = ui.label;
  t.min = ui.min; t.step = ui.step; t.placeholder = ui.placeholder;
  document.getElementById('goalExerciseWrap').classList.toggle('hidden', type !== 'exercise_weight');
}
function toggleGoalForm(show) {
  document.getElementById('goalForm').classList.toggle('hidden', !show);
  document.getElementById('goalAddToggle').setAttribute('aria-expanded', show ? 'true' : 'false');
  if (show) { syncGoalForm(); document.getElementById('goalType').focus(); }
}
document.getElementById('goalAddToggle')?.addEventListener('click', () => toggleGoalForm(document.getElementById('goalForm').classList.contains('hidden')));
document.getElementById('goalCancel')?.addEventListener('click', () => toggleGoalForm(false));
document.getElementById('goalType')?.addEventListener('change', syncGoalForm);
document.getElementById('goalForm')?.addEventListener('submit', async e => {
  e.preventDefault();
  const btn = e.submitter || e.target.querySelector('[type="submit"]');
  btn.disabled = true;
  try {
    const res = await fPost({
      action: 'goal_add',
      goal_type: document.getElementById('goalType').value,
      target: document.getElementById('goalTarget').value,
      exercise_name: document.getElementById('goalExercise').value.trim(),
      deadline: document.getElementById('goalDeadline').value,
    });
    if (!res.success) { Trackie.Toast.warning(res.error || "Couldn't save that goal."); return; }
    Trackie.Toast.success('Goal set.');
    e.target.reset();
    toggleGoalForm(false);
    loadGoals();
  } catch { Trackie.Toast.error('Network error.'); }
  finally { btn.disabled = false; }
});

async function loadGoals() {
  const list = document.getElementById('goalsList');
  if (!list) return;
  try {
    const res = await fPost({ action: 'goals_list' });
    if (!res.success) throw new Error('goals');
    list.replaceChildren();
    if (!res.goals.length) {
      const card = fEl('div', 'card');
      card.append(fEmpty('fa-bullseye', 'No fitness goals yet', 'Set one — progress fills in automatically from the workouts you log.'));
      list.append(card);
      return;
    }
    res.goals.forEach(g => list.append(goalCard(g)));
  } catch { list.replaceChildren(fEl('p', 'form-hint', "Couldn't load your goals — try again in a moment.")); }
}

function goalCard(g) {
  const card = fEl('div', 'card card-body fit-goal' + (g.completed_at ? ' is-done' : ''));
  const head = fEl('div', 'fit-goal-head');
  head.append(fEl('span', 'fit-goal-title', g.title));
  if (g.completed_at) head.append(fEl('span', 'badge badge-green', 'Reached'));
  else if (g.overdue) head.append(fEl('span', 'badge badge-red', 'Past deadline'));
  const del = fEl('button', 'btn btn-icon btn-ghost btn-sm');
  del.type = 'button';
  del.dataset.deleteGoal = g.id;
  del.setAttribute('aria-label', `Delete goal: ${g.title}`);
  del.append(fEl('i', 'fas fa-trash'));
  head.append(del);

  const cur = g.unit === 'kg' ? fKg(g.current) : Math.round(g.current);
  const tgt = g.unit === 'kg' ? fKg(g.target) : Math.round(g.target);
  const bar = fEl('div', 'fit-goal-bar');
  bar.setAttribute('role', 'progressbar');
  bar.setAttribute('aria-valuemin', '0');
  bar.setAttribute('aria-valuemax', '100');
  bar.setAttribute('aria-valuenow', String(g.pct));
  bar.setAttribute('aria-label', `${g.title}: ${g.pct}%`);
  const fill = fEl('div', 'fit-goal-fill');
  fill.style.width = g.pct + '%';
  bar.append(fill);

  const meta = fEl('div', 'fit-goal-meta');
  meta.append(fEl('span', null, `${cur} / ${tgt} ${g.unit}`));
  const since = g.type === 'workouts' ? `since ${fDate(g.start_date)}` : '';
  const when = g.completed_at ? `Reached ${fDate(g.completed_at.slice(0, 10))}` : (g.deadline ? `Due ${fDate(g.deadline)}` : since);
  if (when) meta.append(fEl('span', null, when));
  card.append(head, bar, meta);
  return card;
}
document.getElementById('goalsList')?.addEventListener('click', async e => {
  const btn = e.target.closest('[data-delete-goal]');
  if (!btn) return;
  const ok = await Trackie.confirmDialog('Delete this goal?', { confirmText: 'Delete', danger: true });
  if (!ok) return;
  try {
    const res = await fPost({ action: 'goal_delete', id: btn.dataset.deleteGoal });
    if (res.success) loadGoals(); else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
});

/* ── Nutrition ──────────────────────────────────────────────────── */
let nutriTargets = null;
async function loadNutrition() {
  const summary = document.getElementById('nutriSummary');
  if (!summary) return;
  try {
    const res = await fPost({ action: 'nutrition_day' });
    if (!res.success) throw new Error('nutrition');
    nutriTargets = res.targets;
    renderNutriSummary(res.totals, res.targets);
    renderNutriEntries(res.entries);
    renderNutriRecent(res.recent);
  } catch { summary.replaceChildren(fEl('p', 'form-hint', "Couldn't load nutrition — try again in a moment.")); }
}

function renderNutriSummary(t, targets) {
  const wrap = document.getElementById('nutriSummary');
  wrap.replaceChildren();
  const metric = (label, value, target, unit) => {
    const card = fEl('div', 'card card-body fit-nutri-metric');
    card.append(fEl('span', 'fit-nutri-label', label));
    const v = fEl('span', 'fit-nutri-value', `${value.toLocaleString()}${unit}`);
    if (target) v.append(fEl('small', null, ` / ${target.toLocaleString()}${unit}`));
    card.append(v);
    if (target) {
      const pct = Math.min(100, Math.round(value / target * 100));
      const bar = fEl('div', 'fit-goal-bar');
      bar.setAttribute('role', 'progressbar');
      bar.setAttribute('aria-valuemin', '0'); bar.setAttribute('aria-valuemax', '100'); bar.setAttribute('aria-valuenow', String(pct));
      bar.setAttribute('aria-label', `${label} ${pct}% of target`);
      const fill = fEl('div', 'fit-goal-fill'); fill.style.width = pct + '%';
      bar.append(fill);
      card.append(bar);
    } else {
      card.append(fEl('span', 'form-hint', 'No target set'));
    }
    return card;
  };
  wrap.append(
    metric('Calories eaten', t.calories, targets?.calories, ' kcal'),
    metric('Protein', t.protein_g, targets?.protein_g, ' g'),
    metric('Water', t.water_ml, targets?.water_ml, ' ml'),
  );
}

function renderNutriEntries(entries) {
  const wrap = document.getElementById('nutriEntries');
  wrap.replaceChildren();
  if (!entries.length) {
    wrap.append(fEmpty('fa-utensils', 'Nothing logged today', 'Add what you eat and drink above — only what you log is counted.'));
    return;
  }
  const mealName = { breakfast: 'Breakfast', lunch: 'Lunch', dinner: 'Dinner', snack: 'Snack', water: 'Water' };
  entries.forEach(en => {
    const row = fEl('div', 'fit-nutri-row');
    const left = fEl('div', 'fit-nutri-row-main');
    left.append(fEl('span', 'fit-nutri-meal', mealName[en.meal] || en.meal));
    left.append(fEl('span', 'fit-nutri-food', en.name || (en.meal === 'water' ? 'Water' : '—')));
    const vals = [];
    if (en.calories !== null) vals.push(`${en.calories.toLocaleString()} kcal`);
    if (en.protein_g !== null) vals.push(`${en.protein_g} g protein`);
    if (en.water_ml !== null) vals.push(`${en.water_ml.toLocaleString()} ml`);
    const del = fEl('button', 'btn btn-icon btn-ghost btn-sm');
    del.type = 'button';
    del.dataset.deleteNutri = en.id;
    del.setAttribute('aria-label', `Delete ${en.name || mealName[en.meal]} entry`);
    del.append(fEl('i', 'fas fa-trash'));
    row.append(left, fEl('span', 'fit-nutri-vals', vals.join(' · ')), del);
    wrap.append(row);
  });
}

function renderNutriRecent(days) {
  const wrap = document.getElementById('nutriRecent');
  wrap.replaceChildren();
  if (!days.length) { wrap.append(fEl('p', 'form-hint fit-pad', 'No days logged in the last week yet.')); return; }
  const table = fEl('table', 'fit-table');
  const thead = fEl('thead');
  const hr = fEl('tr');
  ['Day', 'Calories', 'Protein', 'Water'].forEach(h => { const th = fEl('th', null, h); th.scope = 'col'; hr.append(th); });
  thead.append(hr);
  const tbody = fEl('tbody');
  days.forEach(d => {
    const tr = fEl('tr');
    const th = fEl('th', null, fDate(d.date)); th.scope = 'row';
    tr.append(th, fEl('td', null, d.calories ? `${d.calories.toLocaleString()} kcal` : '—'),
      fEl('td', null, d.protein_g ? `${d.protein_g} g` : '—'), fEl('td', null, d.water_ml ? `${d.water_ml.toLocaleString()} ml` : '—'));
    tbody.append(tr);
  });
  table.append(thead, tbody);
  wrap.append(table);
}

async function addNutrition(fields, btn) {
  if (btn) btn.disabled = true;
  try {
    const res = await fPost({ action: 'nutrition_add', ...fields });
    if (!res.success) { Trackie.Toast.warning(res.error || "Couldn't add that."); return false; }
    loadNutrition();
    return true;
  } catch { Trackie.Toast.error('Network error.'); return false; }
  finally { if (btn) btn.disabled = false; }
}
document.getElementById('nutriForm')?.addEventListener('submit', async e => {
  e.preventDefault();
  const ok = await addNutrition({
    meal: document.getElementById('nutriMeal').value,
    name: document.getElementById('nutriName').value.trim(),
    calories: document.getElementById('nutriCalories').value,
    protein_g: document.getElementById('nutriProtein').value,
  }, e.submitter);
  if (ok) {
    ['nutriName', 'nutriCalories', 'nutriProtein'].forEach(id => document.getElementById(id).value = '');
    Trackie.Toast.success('Added.');
  }
});
document.getElementById('nutriForm')?.addEventListener('click', async e => {
  const b = e.target.closest('[data-water]');
  if (!b) return;
  if (await addNutrition({ meal: 'water', water_ml: b.dataset.water }, b)) Trackie.Toast.success(`+${b.dataset.water} ml water`);
});
document.getElementById('nutriEntries')?.addEventListener('click', async e => {
  const btn = e.target.closest('[data-delete-nutri]');
  if (!btn) return;
  try {
    const res = await fPost({ action: 'nutrition_delete', id: btn.dataset.deleteNutri });
    if (res.success) loadNutrition(); else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
});
document.getElementById('nutriTargetsBtn')?.addEventListener('click', () => {
  document.getElementById('tgtCalories').value = nutriTargets?.calories ?? '';
  document.getElementById('tgtProtein').value = nutriTargets?.protein_g ?? '';
  document.getElementById('tgtWater').value = nutriTargets?.water_ml ?? '';
  Trackie.openModal('nutriTargetsModal');
});
document.getElementById('nutriTargetsSave')?.addEventListener('click', async e => {
  const btn = e.currentTarget;
  btn.disabled = true;
  try {
    const res = await fPost({
      action: 'nutrition_targets_save',
      calories: document.getElementById('tgtCalories').value,
      protein_g: document.getElementById('tgtProtein').value,
      water_ml: document.getElementById('tgtWater').value,
    });
    if (!res.success) { Trackie.Toast.warning(res.error || "Couldn't save targets."); return; }
    Trackie.closeModal('nutriTargetsModal');
    Trackie.Toast.success('Targets saved.');
    loadNutrition();
  } catch { Trackie.Toast.error('Network error.'); }
  finally { btn.disabled = false; }
});

/* ── Recovery ───────────────────────────────────────────────────── */
async function loadRecovery() {
  const daysWrap = document.getElementById('recoveryDays');
  if (!daysWrap) return;
  try {
    const res = await fPost({ action: 'recovery_list' });
    if (!res.success) throw new Error('recovery');
    renderRecoveryWeek(res.week);
    renderRecoveryDays(res.days);
    // Prefill today's form with what's already saved for today.
    const t = res.days[0];
    if (t && t.logged) {
      document.getElementById('recSleep').value = t.sleep_hours ?? '';
      document.getElementById('recNotes').value = t.notes ?? '';
      ['energy', 'soreness'].forEach(k => {
        document.querySelectorAll(`#recoveryForm input[name="${k}"]`).forEach(r => { r.checked = t[k] !== null && +r.value === t[k]; });
      });
    }
  } catch { daysWrap.replaceChildren(fEl('p', 'form-hint fit-pad', "Couldn't load recovery — try again in a moment.")); }
}

function renderRecoveryWeek(w) {
  const wrap = document.getElementById('recoveryWeek');
  wrap.replaceChildren();
  const stat = (icon, color, value, label, sub) => {
    const c = fEl('div', 'stat-card');
    const chip = fEl('div', 'stat-icon-chip');
    chip.style.color = color;
    chip.append(fEl('i', `fas ${icon}`));
    c.append(chip, fEl('div', 'stat-val', value), fEl('div', 'stat-label', label));
    if (sub) c.append(fEl('div', 'form-hint', sub));
    return c;
  };
  const avg = (a, unit = '') => a ? `${a.avg}${unit}` : '—';
  const basis = a => a ? `from ${a.days} logged day${a.days === 1 ? '' : 's'}` : 'nothing logged yet';
  wrap.append(
    stat('fa-bed', 'var(--info)', avg(w.sleep, ' h'), 'Avg sleep', basis(w.sleep)),
    stat('fa-bolt', '#f59e0b', avg(w.energy, ' / 5'), 'Avg energy', basis(w.energy)),
    stat('fa-person-running', 'var(--accent)', avg(w.soreness, ' / 5'), 'Avg soreness', basis(w.soreness)),
    stat('fa-couch', 'var(--ok)', String(w.rest_days), 'Rest days', `${w.trained} training day${w.trained === 1 ? '' : 's'} in the last 7 days`),
  );
}

function renderRecoveryDays(days) {
  const wrap = document.getElementById('recoveryDays');
  const table = fEl('table', 'fit-table');
  const hr = fEl('tr');
  ['Day', 'Sleep', 'Energy', 'Soreness', 'Training', 'Notes'].forEach(h => { const th = fEl('th', null, h); th.scope = 'col'; hr.append(th); });
  const thead = fEl('thead'); thead.append(hr);
  const tbody = fEl('tbody');
  days.forEach(d => {
    const tr = fEl('tr', d.logged ? null : 'is-empty');
    const th = fEl('th', null, fDate(d.date)); th.scope = 'row';
    tr.append(th,
      fEl('td', null, d.sleep_hours !== null ? `${d.sleep_hours} h` : '—'),
      fEl('td', null, d.energy !== null ? `${d.energy}/5` : '—'),
      fEl('td', null, d.soreness !== null ? `${d.soreness}/5` : '—'),
      // Today isn't over — only a finished day without a workout is a rest day.
      fEl('td', null, d.trained ? 'Trained' : (d.date === days[0].date ? 'Not yet' : 'Rest')),
      fEl('td', 'fit-td-notes', d.notes || ''));
    tbody.append(tr);
  });
  table.append(thead, tbody);
  wrap.replaceChildren(table);
}

document.getElementById('recoveryForm')?.addEventListener('submit', async e => {
  e.preventDefault();
  const btn = e.submitter || e.target.querySelector('[type="submit"]');
  const pick = name => document.querySelector(`#recoveryForm input[name="${name}"]:checked`)?.value ?? '';
  btn.disabled = true;
  try {
    const res = await fPost({
      action: 'recovery_save',
      sleep_hours: document.getElementById('recSleep').value,
      energy: pick('energy'),
      soreness: pick('soreness'),
      notes: document.getElementById('recNotes').value.trim(),
    });
    if (!res.success) { Trackie.Toast.warning(res.error || "Couldn't save your check-in."); return; }
    Trackie.Toast.success('Check-in saved.');
    loadRecovery();
  } catch { Trackie.Toast.error('Network error.'); }
  finally { btn.disabled = false; }
});

function notifyNewAchievements(keys) {
  if (!keys || !keys.length) return;
  keys.forEach(k => Trackie.Toast.success(`🏆 Achievement unlocked! See it under Progress.`, 5000));
}

/* ── Journal ──────────────────────────────────────────────────── */
async function addJournalEntry() {
  const body = document.getElementById('journalBody').value.trim();
  if (!body) { Trackie.Toast.warning('Write something first.'); return; }
  try {
    const res = await Trackie.API.post(`${API_BASE}/gym.php`, { action: 'journal_add', body, entry_date: '<?= $today ?>' });
    if (res.success) {
      document.getElementById('journalBody').value = '';
      Trackie.Toast.success('Journal entry added.');
      await Trackie.refreshFragments(['journalListWrap']);
    } else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function deleteJournalEntry(id) {
  const ok = await Trackie.confirmDialog('Delete this journal entry?', {confirmText:'Delete', danger:true});
  if (!ok) return;
  try {
    const res = await Trackie.API.post(`${API_BASE}/gym.php`, { action: 'journal_delete', entry_id: id });
    if (res.success) { document.getElementById(`journal-${id}`)?.remove(); Trackie.Toast.success('Deleted.'); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}

function openAddPlan() {
  document.getElementById('addPlanModal').dataset.editId = '';
  document.getElementById('planModalTitle').textContent = 'New Workout Plan';
  document.getElementById('planName').value = '';
  document.getElementById('planDay').value = 'Any';
  document.getElementById('planItems').innerHTML = '';
  addExerciseCard();
  Trackie.openModal('addPlanModal');
}

async function openEditPlan(planId) {
  try {
    const res = await Trackie.API.post(`${API_BASE}/gym.php`, { action: 'plan_items', plan_id: planId });
    if (!res.success) { Trackie.Toast.error(res.error || 'Could not load this plan.'); return; }

    document.getElementById('addPlanModal').dataset.editId = planId;
    document.getElementById('planModalTitle').textContent = 'Edit Workout Plan';
    document.getElementById('planName').value = res.plan.name;
    document.getElementById('planDay').value = res.plan.day_of_week;
    const wrap = document.getElementById('planItems');
    wrap.innerHTML = '';
    if (res.items.length) {
      res.items.forEach(it => addExerciseCard({
        name: it.exercise_name, sets: it.target_sets, reps: it.target_reps,
        weight: it.target_weight ?? '', rest: it.rest_seconds ?? '', notes: it.notes ?? '',
      }));
    } else {
      addExerciseCard();
    }
    Trackie.openModal('addPlanModal');
  } catch { Trackie.Toast.error('Network error.'); }
}

/* ── Exercise builder v2 — card per exercise ───────────────────────
   Replaces the cramped 2-column grid (a full-width name input beside three
   70px boxes) that was unusable on a phone. Each exercise is now its own
   card with labelled fields, 44px touch targets, and reorder / duplicate /
   delete. Weight, rest and notes persist (see the workout_plan_items
   migration in setup.php) — they are not decorative inputs. */
function exCardTemplate(v = {}) {
  const card = document.createElement('div');
  card.className = 'ex-card';
  card.innerHTML = `
    <div class="ex-card-head">
      <span class="ex-card-num" aria-hidden="true"></span>
      <input class="form-input plan-item-name ex-card-name" placeholder="Exercise name"
             aria-label="Exercise name" value="${(v.name || '').replace(/"/g, '&quot;')}">
      <div class="ex-card-actions">
        <button type="button" class="btn btn-icon btn-ghost btn-sm" data-ex="up"   aria-label="Move exercise up"><i class="fas fa-chevron-up"></i></button>
        <button type="button" class="btn btn-icon btn-ghost btn-sm" data-ex="down" aria-label="Move exercise down"><i class="fas fa-chevron-down"></i></button>
        <button type="button" class="btn btn-icon btn-ghost btn-sm" data-ex="dup"  aria-label="Duplicate exercise"><i class="fas fa-clone"></i></button>
        <button type="button" class="btn btn-icon btn-ghost btn-sm" data-ex="del"  aria-label="Remove exercise" style="color:var(--accent)"><i class="fas fa-trash"></i></button>
      </div>
    </div>
    <div class="ex-card-grid">
      <label class="ex-field"><span>Sets</span>
        <input class="form-input plan-item-sets" type="number" min="1" inputmode="numeric" value="${v.sets ?? 3}"></label>
      <label class="ex-field"><span>Reps</span>
        <input class="form-input plan-item-reps" type="number" min="1" inputmode="numeric" value="${v.reps ?? 10}"></label>
      <label class="ex-field"><span>Weight (kg)</span>
        <input class="form-input plan-item-weight" type="number" min="0" step="0.5" inputmode="decimal" placeholder="—" value="${v.weight ?? ''}"></label>
      <label class="ex-field"><span>Rest (sec)</span>
        <input class="form-input plan-item-rest" type="number" min="0" step="15" inputmode="numeric" placeholder="—" value="${v.rest ?? ''}"></label>
    </div>
    <input class="form-input plan-item-notes ex-card-notes" placeholder="Notes (optional)"
           aria-label="Exercise notes" value="${(v.notes || '').replace(/"/g, '&quot;')}">`;
  return card;
}

function renumberExCards() {
  document.querySelectorAll('#planItems .ex-card').forEach((c, i) => {
    c.querySelector('.ex-card-num').textContent = i + 1;
  });
}

function addExerciseCard(v) {
  const wrap = document.getElementById('planItems');
  if (!wrap) return;
  wrap.appendChild(exCardTemplate(v));
  renumberExCards();
}

// Delegated so cards added later are handled without rebinding.
document.getElementById('planItems')?.addEventListener('click', e => {
  const btn = e.target.closest('[data-ex]');
  if (!btn) return;
  const card = btn.closest('.ex-card');
  const act  = btn.dataset.ex;

  if (act === 'del')  card.remove();
  if (act === 'up'   && card.previousElementSibling) card.parentNode.insertBefore(card, card.previousElementSibling);
  if (act === 'down' && card.nextElementSibling)     card.parentNode.insertBefore(card.nextElementSibling, card);
  if (act === 'dup') {
    card.after(exCardTemplate({
      name:   card.querySelector('.plan-item-name').value,
      sets:   card.querySelector('.plan-item-sets').value,
      reps:   card.querySelector('.plan-item-reps').value,
      weight: card.querySelector('.plan-item-weight').value,
      rest:   card.querySelector('.plan-item-rest').value,
      notes:  card.querySelector('.plan-item-notes').value,
    }));
  }
  renumberExCards();
});
async function savePlan() {
  const name = document.getElementById('planName').value.trim();
  if (!name) { Trackie.Toast.warning('Plan name is required.'); return; }
  const items = Array.from(document.querySelectorAll('#planItems .ex-card')).map(row => ({
    name:   row.querySelector('.plan-item-name').value.trim(),
    sets:   row.querySelector('.plan-item-sets').value,
    reps:   row.querySelector('.plan-item-reps').value,
    weight: row.querySelector('.plan-item-weight').value,
    rest:   row.querySelector('.plan-item-rest').value,
    notes:  row.querySelector('.plan-item-notes').value.trim(),
  })).filter(i => i.name);

  const editId = document.getElementById('addPlanModal').dataset.editId;
  const fd = new URLSearchParams();
  fd.append('action', editId ? 'edit_plan' : 'add_plan');
  if (editId) fd.append('plan_id', editId);
  fd.append('name', name);
  fd.append('day_of_week', document.getElementById('planDay').value);
  items.forEach((it, i) => {
    fd.append(`items[${i}][name]`, it.name);
    fd.append(`items[${i}][sets]`, it.sets);
    fd.append(`items[${i}][reps]`, it.reps);
    // weight/rest/notes were collected above but never appended here, so the
    // exercise builder's three optional fields were silently discarded on the
    // way to an API that reads them. Sent as-is (possibly empty strings) —
    // api/gym.php treats '' as "not set" and stores NULL.
    fd.append(`items[${i}][weight]`, it.weight);
    fd.append(`items[${i}][rest]`,   it.rest);
    fd.append(`items[${i}][notes]`,  it.notes);
  });
  fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
  try {
    const res = await fetch(`${API_BASE}/gym.php`, {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': fd.get('csrf_token'), 'X-Requested-With': 'XMLHttpRequest' },
      body: fd
    }).then(r => r.json());
    if (res.success) {
      Trackie.Toast.success(editId ? 'Plan updated!' : 'Plan created!');
      Trackie.closeModal('addPlanModal');
      await Trackie.refreshFragments(['gymStatsWrap', 'gymPlansWrap']);
    } else Trackie.Toast.error(res.error || 'Failed.');
  } catch {
    // A PHP fatal returns an empty body, so .json() throws and lands here —
    // which is why a missing DB column showed up to users as "Network error"
    // for weeks. Say what we actually know instead of guessing the cause.
    Trackie.Toast.error("Couldn't save this plan — please try again.");
  }
}
async function deletePlan(id) {
  const ok = await Trackie.confirmDialog('Delete this plan?', {confirmText:'Delete', danger:true});
  if (!ok) return;
  try {
    const res = await Trackie.API.post(`${API_BASE}/gym.php`, {action:'delete_plan', plan_id:id});
    if (res.success) { document.getElementById(`plan-${id}`)?.remove(); Trackie.Toast.success('Plan deleted.'); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}

function openLogWorkout(exerciseName, planId) {
  document.getElementById('logWorkoutTitle').textContent = 'Log Workout';
  document.getElementById('logExercise').value = exerciseName || '';
  document.getElementById('logSets').value = '';
  document.getElementById('logReps').value = '';
  document.getElementById('logWeight').value = '';
  document.getElementById('logDate').value = '<?= $today ?>';
  document.getElementById('logNotes').value = '';
  document.getElementById('logWorkoutModal').dataset.planId = planId || '';
  Trackie.openModal('logWorkoutModal');
}

/* ── Active workout (Fitness V2) ────────────────────────────────────
   Full-screen session with DYNAMIC sets. Each exercise holds a list of set
   rows { n, weight, reps, done, pr }; `n` is the set_number the server keys
   on, so ticking a row upserts it (session_log_set), editing a done row
   re-saves it, and un-ticking deletes it (session_delete_set). Every number
   shown — sets, volume, PRs, duration, XP — comes from saved sets or the
   server; nothing is estimated. */
const WO_DEFAULT_REST = 90; // seconds between sets when the plan item sets none
let session = null;

const woEl = id => document.getElementById(id);

function woNum(v) {
  const n = parseFloat(v);
  return Number.isFinite(n) ? n : null;
}
function woFmtWeight(w) {
  return (Math.round(w * 10) / 10).toLocaleString(undefined, { maximumFractionDigits: 1 });
}
function woFmtVolume(v) {
  return v > 0 ? `${Math.round(v).toLocaleString()} kg` : '—';
}
function woFmtClock(sec) {
  return `${String(Math.floor(sec / 60)).padStart(2, '0')}:${String(sec % 60).padStart(2, '0')}`;
}
function woSetLabel(s) {
  const parts = [];
  if (s.weight !== null && s.weight !== undefined) parts.push(`${woFmtWeight(s.weight)} kg`);
  if (s.reps !== null && s.reps !== undefined) parts.push(`${s.reps}`);
  return parts.join(' × ') || 'Logged';
}
function woVolume(sets) {
  return sets.reduce((sum, s) => sum + ((s.reps && s.weight) ? s.reps * s.weight : 0), 0);
}
// Media URLs come from the server (library or a provider). Defence in depth:
// only https:// or a same-origin root path may become a src.
function woMediaUrl(u) {
  return typeof u === 'string' && (/^https:\/\//i.test(u) || /^\/(?!\/)/.test(u)) ? u : null;
}
// free-exercise-db ships two photos (start + end position). Alternating them
// shows the movement like a GIF; with reduced motion the start frame stays put.
function woPlayFrames(img, frames) {
  woStopFrames(img);
  const safe = (frames || []).map(woMediaUrl).filter(Boolean);
  if (!safe.length) return false;
  let i = 0;
  img.src = safe[0];
  safe.slice(1).forEach(u => { const pre = new Image(); pre.src = u; });
  if (safe.length > 1 && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    img._woFrames = setInterval(() => {
      if (!img.isConnected) { woStopFrames(img); return; }
      i = (i + 1) % safe.length;
      img.src = safe[i];
    }, 900);
  }
  return true;
}
function woStopFrames(img) {
  if (img && img._woFrames) { clearInterval(img._woFrames); img._woFrames = null; }
}
function woFmtDate(ymd) {
  const [y, m, d] = String(ymd).split('-').map(Number);
  if (!y || !m || !d) return '';
  const opts = { day: 'numeric', month: 'short' };
  if (y !== new Date().getFullYear()) opts.year = 'numeric';
  return new Date(y, m - 1, d).toLocaleDateString(undefined, opts);
}
function woFocus(id) {
  requestAnimationFrame(() => woEl(id)?.focus({ preventScroll: true }));
}

// #page-main (view-transition-name) and .page-content (a retained entry
// transform) trap anything inside them: the overlay's z-index could not rise
// above the sidebar/topbar/bottom-nav, and `fixed` became relative to the
// content column. While a workout runs, the overlay lives on <body> so it is
// truly full-screen; it goes back home when the workout ends.
function woPortal(toBody) {
  const root = woEl('workoutSession');
  if (!root) return;
  document.body.classList.toggle('workout-open', toBody);
  if (toBody) {
    if (root.parentNode === document.body) return;
    root._woHome = root.parentNode;
    root._woNext = root.nextSibling;
    document.body.appendChild(root);
  } else if (root._woHome && root._woHome.isConnected) {
    root._woHome.insertBefore(root, root._woNext && root._woNext.parentNode === root._woHome ? root._woNext : null);
  } else {
    root.remove(); // its page is gone (SpaNav swapped it) — nothing to return to
  }
}
function woTeardown() {
  if (session) { clearInterval(session.timerHandle); woStopRest(); }
  woStopFrames(woEl('sessionExerciseGif'));
  window.__woActive = null;
  document.body.style.overflow = '';
  woEl('workoutSession')?.classList.add('hidden');
  woPortal(false);
}

/* ── Session state helpers ─────────────────────────────────────────── */
function woCurrent() { return session.exercises[session.index]; }
function woEnsureRows(ex) {
  if (ex.sets) return;
  // woNum: DECIMAL columns arrive as strings ("60.00") — show "60".
  ex.sets = Array.from({ length: ex.targetSets }, (_, i) => ({
    n: i + 1, weight: woNum(ex.targetWeight) ?? '', reps: woNum(ex.targetReps) ?? '',
    done: false, pr: false, saving: false, touched: false,
  }));
}
// Everything already saved on the server, across the whole session.
function woDoneSets() {
  const out = [];
  session.exercises.forEach(ex => (ex.sets || []).forEach(s => {
    if (s.done) out.push({ exercise: ex.name, n: s.n, reps: s.savedReps, weight: s.savedWeight, pr: s.pr });
  }));
  return out;
}
function woRowsTotal() {
  return session.exercises.reduce((n, ex) => n + (ex.sets ? ex.sets.length : ex.targetSets), 0);
}
function woExerciseComplete(ex) {
  return !!ex.sets && ex.sets.length > 0 && ex.sets.every(s => s.done);
}
function woAllComplete() {
  return session.exercises.every(woExerciseComplete);
}

async function startPlanWorkout(planId, planName) {
  try {
    const [planRes, sessRes] = await Promise.all([
      Trackie.API.post(`${API_BASE}/gym.php`, {action:'plan_items', plan_id:planId}),
      Trackie.API.post(`${API_BASE}/gym.php`, {action:'session_start', plan_id:planId, plan_name:planName}),
    ]);
    if (!planRes.success || !planRes.items.length) { Trackie.Toast.warning('This plan has no exercises yet.'); return; }
    if (!sessRes.success) { Trackie.Toast.error('Could not start the session.'); return; }

    session = {
      planId, planName, sessionId: sessRes.session_id,
      exercises: planRes.items.map(i => ({
        name: i.exercise_name,
        targetSets: Math.max(1, parseInt(i.target_sets, 10) || 1),
        targetReps: i.target_reps ?? null,
        targetWeight: i.target_weight ?? null,
        rest: Math.max(0, parseInt(i.rest_seconds, 10) || WO_DEFAULT_REST),
        sets: null, info: null,
      })),
      index: 0, pending: 0, newAchievements: [],
      elapsedSec: 0, timerHandle: null, rest: null,
    };
    woEl('sessionPlanName').textContent = planName;
    woEl('ctxPlanName').textContent = planName;
    woEl('sessionElapsed').textContent = '00:00';
    woEl('ctxTime').textContent = '00:00';
    window.__woActive = session; // lets the popstate guard stop this session's timers
    document.body.style.overflow = 'hidden';
    woPortal(true);
    woEl('workoutSession').classList.remove('hidden');
    showPhase('exercise');
    renderExercisePhase();
    startSessionTimer();
  } catch { Trackie.Toast.error('Network error.'); }
}

function showPhase(phase) {
  woEl('woExercisePhase').classList.toggle('hidden', phase !== 'exercise');
  woEl('woSummaryPhase').classList.toggle('hidden', phase !== 'summary');
  woEl('workoutSession').dataset.phase = phase;
}

function renderExercisePhase() {
  const ex = woCurrent();
  woEnsureRows(ex);
  woEl('sessionExerciseIndex').textContent = `Exercise ${session.index + 1} of ${session.exercises.length}`;
  woEl('sessionExerciseName').textContent = ex.name;
  woEl('sessionTarget').textContent = ex.targetReps
    ? `${ex.targetReps} reps × ${ex.targetSets} sets`
    : `${ex.targetSets} sets`;
  woEl('sessionTargetSub').textContent = woNum(ex.targetWeight) ? `at ${woFmtWeight(woNum(ex.targetWeight))} kg` : '';
  woEl('sessionPrBanner').classList.toggle('hidden', !ex.sets.some(s => s.pr));
  woEl('sessionSetStatus').textContent = '';
  renderSetRows();
  renderNav();
  updateOverallProgress();
  loadPreviousPerformance(ex);
  woFocus('sessionExerciseName');
}

function renderNav() {
  const last = session.index === session.exercises.length - 1;
  woEl('woPrevEx').disabled = session.index === 0;
  woEl('woNextLabel').textContent = last ? 'Finish workout' : 'Next exercise';
}

/* ── Set table ─────────────────────────────────────────────────────── */
function renderSetRows() {
  const ex = woCurrent();
  const prev = ex.info && Array.isArray(ex.info.sets) ? ex.info.sets : [];
  const tbody = woEl('sessionSetRows');
  const nextIdx = ex.sets.findIndex(s => !s.done);
  tbody.replaceChildren(...ex.sets.map((s, i) => {
    const tr = document.createElement('tr');
    tr.className = 'wo-row' + (s.done ? ' is-done' : '') + (i === nextIdx ? ' is-next' : '') + (s.saving ? ' is-saving' : '');
    tr.dataset.n = s.n;

    const th = document.createElement('th');
    th.scope = 'row';
    th.textContent = String(i + 1);
    if (s.pr) {
      const pr = document.createElement('i');
      pr.className = 'fas fa-trophy wo-row-pr';
      pr.setAttribute('aria-label', 'personal record');
      th.append(' ', pr);
    }

    const p = prev[i];
    const tdPrev = document.createElement('td');
    tdPrev.className = 'wo-prev';
    tdPrev.textContent = p ? woSetLabel({ weight: woNum(p.weight_kg), reps: woNum(p.reps) }).replace(' kg', '') : '—';

    const cell = (field, label, step, mode) => {
      const td = document.createElement('td');
      const input = document.createElement('input');
      input.className = 'wo-cell';
      input.type = 'number';
      input.min = '0';
      input.step = step;
      input.inputMode = mode;
      input.dataset.field = field;
      input.placeholder = '—';
      input.value = s[field] ?? '';
      input.setAttribute('aria-label', `Set ${i + 1} ${label}`);
      td.append(input);
      return td;
    };

    const tdCheck = document.createElement('td');
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'wo-check';
    btn.disabled = s.saving;
    btn.setAttribute('aria-pressed', s.done ? 'true' : 'false');
    btn.setAttribute('aria-label', s.done ? `Undo set ${i + 1}` : `Complete set ${i + 1}`);
    btn.innerHTML = s.saving ? '<span class="wo-spin" aria-hidden="true"></span>' : '<i class="fas fa-check" aria-hidden="true"></i>';
    tdCheck.append(btn);

    tr.append(th, tdPrev, cell('weight', 'weight in kilograms', '0.5', 'decimal'), cell('reps', 'reps', '1', 'numeric'), tdCheck);
    return tr;
  }));

  const doneCount = ex.sets.filter(s => s.done).length;
  woEl('sessionSetIndicator').textContent = `Sets · ${doneCount}/${ex.sets.length} done`;
  const btn = woEl('completeSetBtn');
  btn.disabled = nextIdx === -1 || ex.sets[nextIdx]?.saving;
  woEl('completeSetLabel').textContent = nextIdx === -1 ? 'All sets done' : `Complete set ${nextIdx + 1}`;
  woEl('woRemoveSet').disabled = ex.sets.length <= 1;
}

function woRowFor(n) { return woCurrent().sets.find(s => s.n === n); }

async function saveSet(ex, s, { fromEdit = false } = {}) {
  const reps = s.reps === '' || s.reps === null ? '' : String(s.reps);
  const weight = s.weight === '' || s.weight === null ? '' : String(s.weight);
  if (reps === '' && weight === '') {
    Trackie.Toast.warning('Enter reps or weight for this set first.');
    return false;
  }
  s.saving = true;
  session.pending++;
  if (ex === woCurrent()) renderSetRows();
  try {
    const res = await Trackie.API.post(`${API_BASE}/gym.php`, {
      action: 'session_log_set',
      session_id: session.sessionId,
      exercise_name: ex.name,
      set_number: s.n,
      reps, weight,
      plan_id: session.planId,
    });
    if (!res.success) { Trackie.Toast.error(res.error || 'Failed to save that set.'); return false; }
    const wasDone = s.done;
    s.done = true;
    s.savedReps = woNum(reps);
    s.savedWeight = woNum(weight);
    s.pr = !!(res.is_pr && res.previous_best !== null);
    if (res.newAchievements?.length) session.newAchievements.push(...res.newAchievements);
    if (ex === woCurrent()) {
      woEl('sessionPrBanner').classList.toggle('hidden', !ex.sets.some(x => x.pr));
      const pos = ex.sets.indexOf(s) + 1;
      woEl('sessionSetStatus').textContent = fromEdit || wasDone ? `Set ${pos} updated` : `Set ${pos} logged`;
    }
    return true;
  } catch {
    Trackie.Toast.error('Network error — that set was not saved.');
    return false;
  } finally {
    s.saving = false;
    session && session.pending--;
    if (session && ex === woCurrent()) { renderSetRows(); updateOverallProgress(); }
  }
}

async function completeRow(n) {
  if (!session) return;
  const ex = woCurrent();
  const s = ex.sets.find(x => x.n === n);
  if (!s || s.saving || s.done) return;
  const ok = await saveSet(ex, s);
  if (!ok || !session) return;
  const moreToDo = !woAllComplete();
  if (moreToDo && ex.rest > 0) woStartRest(ex.rest);
  if (!moreToDo) woEl('sessionSetStatus').textContent = 'Every set is done — finish when you are ready';
}

async function uncompleteRow(n) {
  const ex = woCurrent();
  const s = ex.sets.find(x => x.n === n);
  if (!s || s.saving || !s.done) return;
  s.saving = true;
  session.pending++;
  renderSetRows();
  try {
    const res = await Trackie.API.post(`${API_BASE}/gym.php`, {
      action: 'session_delete_set', session_id: session.sessionId, exercise_name: ex.name, set_number: s.n,
    });
    if (!res.success) { Trackie.Toast.error(res.error || "Couldn't undo that set."); return; }
    s.done = false;
    s.pr = false;
    woEl('sessionPrBanner').classList.toggle('hidden', !ex.sets.some(x => x.pr));
    woEl('sessionSetStatus').textContent = `Set ${ex.sets.indexOf(s) + 1} marked not done`;
  } catch { Trackie.Toast.error('Network error — nothing was changed.'); }
  finally {
    s.saving = false;
    session && session.pending--;
    if (session) { renderSetRows(); updateOverallProgress(); }
  }
}

function addSet() {
  const ex = woCurrent();
  const last = ex.sets[ex.sets.length - 1];
  const n = ex.sets.reduce((m, s) => Math.max(m, s.n), 0) + 1;
  ex.sets.push({
    n, weight: last ? last.weight : (woNum(ex.targetWeight) ?? ''), reps: last ? last.reps : (woNum(ex.targetReps) ?? ''),
    done: false, pr: false, saving: false, touched: false,
  });
  renderSetRows();
  updateOverallProgress();
  requestAnimationFrame(() => woEl('sessionSetRows').lastElementChild?.querySelector('input')?.focus());
}

async function removeSet() {
  const ex = woCurrent();
  if (ex.sets.length <= 1) return;
  const s = ex.sets[ex.sets.length - 1];
  if (s.saving) return;
  if (s.done) {
    const ok = await Trackie.confirmDialog(`Remove set ${ex.sets.length}? It is already logged and will be deleted.`, { confirmText: 'Remove set', danger: true });
    if (!ok || !session) return;
    await uncompleteRow(s.n);
    if (s.done) return; // the delete failed — keep the row
  }
  ex.sets.pop();
  renderSetRows();
  updateOverallProgress();
}

/* ── Previous performance + demo media (loaded once per exercise) ──── */
async function loadPreviousPerformance(ex) {
  if (ex.info) { applyExerciseInfo(ex); return; }
  resetExerciseMedia();
  try {
    const res = await Trackie.API.post(`${API_BASE}/gym.php`, {action:'previous_performance', exercise_name: ex.name});
    if (!res.success || !session) return;
    ex.info = res;
    // Pre-fill untouched, not-done rows with last time's numbers where the
    // plan set none: real previous data only, never a guess.
    const prevSets = res.found && Array.isArray(res.sets) ? res.sets : [];
    if (ex.sets && prevSets.length) {
      ex.sets.forEach((s, i) => {
        if (s.done || s.touched) return;
        const p = prevSets[Math.min(i, prevSets.length - 1)];
        if ((s.weight === '' || s.weight === null) && p.weight_kg !== null && p.weight_kg !== undefined) s.weight = woNum(p.weight_kg) ?? '';
        if ((s.reps === '' || s.reps === null) && p.reps !== null && p.reps !== undefined) s.reps = woNum(p.reps) ?? '';
      });
    }
    if (woCurrent() === ex) { applyExerciseInfo(ex); renderSetRows(); }
  } catch { /* purely informational — never block the workout on this */ }
}

function resetExerciseMedia() {
  const vid = woEl('sessionExerciseVideo');
  woEl('sessionPrevPerf').textContent = 'First time';
  woEl('sessionPrevPerfSub').textContent = '';
  woEl('sessionMedia').classList.add('hidden');
  woEl('sessionExerciseCard').classList.remove('has-media');
  vid.removeAttribute('src');
  vid.removeAttribute('poster');
  vid.classList.remove('hidden');
  woStopFrames(woEl('sessionExerciseGif'));
  woEl('sessionExerciseGif').classList.add('hidden');
  woEl('sessionExerciseGif').removeAttribute('src');
  woEl('sessionMediaCredit').classList.add('hidden');
  woEl('sessionHowTo').classList.add('hidden');
  woEl('sessionHowTo').open = false;
  woEl('sessionHowToSteps').replaceChildren();
}

function applyExerciseInfo(ex) {
  resetExerciseMedia();
  const res = ex.info;
  if (!res) return;
  const media = woEl('sessionMedia');
  const vid = woEl('sessionExerciseVideo');
  const gif = woEl('sessionExerciseGif');
  const videoUrl = woMediaUrl(res.video_url);
  const gifUrl = woMediaUrl(res.gif_url);
  const frames = Array.isArray(res.frames) ? res.frames : [];
  if (videoUrl || gifUrl || frames.length) {
    if (videoUrl) {
      const poster = woMediaUrl(res.video_poster);
      if (poster) vid.poster = poster;
      vid.src = videoUrl;
      vid.play().catch(() => {}); // autoplay can be blocked silently — controls still work
    } else {
      vid.classList.add('hidden');
      if (gifUrl) gif.src = gifUrl; else woPlayFrames(gif, frames);
      gif.alt = res.demo_name ? `${res.demo_name} demonstration` : 'Exercise demonstration';
      gif.classList.remove('hidden');
    }
    // Third-party media is credited by its source (and names the exact demo shown).
    const credit = woEl('sessionMediaCredit');
    credit.textContent = res.demo_credit || '';
    credit.title = res.demo_name ? `Demo: ${res.demo_name} · ${res.demo_credit}` : '';
    credit.classList.toggle('hidden', !res.demo_credit);
    media.classList.remove('hidden');
    woEl('sessionExerciseCard').classList.add('has-media');
  }
  const steps = Array.isArray(res.instructions) ? res.instructions.filter(s => typeof s === 'string' && s.trim()) : [];
  if (steps.length) {
    const ol = woEl('sessionHowToSteps');
    steps.forEach(s => { const li = document.createElement('li'); li.textContent = s; ol.append(li); });
    woEl('sessionHowTo').classList.remove('hidden');
  }
  if (!res.found || !res.sets?.length) return;
  const best = res.sets.reduce((a, b) => ((b.weight_kg ?? 0) > (a.weight_kg ?? 0) ? b : a));
  const label = woSetLabel({ weight: woNum(best.weight_kg), reps: woNum(best.reps) });
  woEl('sessionPrevPerf').textContent = label === 'Logged' ? '—' : label;
  const n = res.sets.length;
  woEl('sessionPrevPerfSub').textContent = `${n} set${n === 1 ? '' : 's'}` + (res.log_date ? ` · ${woFmtDate(res.log_date)}` : '');
}

/* ── Progress, context panel, timers ───────────────────────────────── */
// Progress = sets done ÷ set rows planned (rows the user added count too).
function updateOverallProgress(finished = false) {
  const total = session.exercises.length;
  const rows = woRowsTotal();
  const done = woDoneSets().length;
  const pct = finished ? 100 : (rows ? Math.round((done / rows) * 100) : 0);
  const completed = session.exercises.filter(woExerciseComplete).length;
  woEl('sessionProgressBar').style.width = pct + '%';
  woEl('sessionProgressPct').textContent = pct + '%';
  woEl('sessionProgressTrack').setAttribute('aria-valuenow', String(pct));
  woEl('sessionProgress').textContent =
    `${total} exercise${total === 1 ? '' : 's'} · ${completed} completed`;
  woEl('ctxExercises').textContent = `${completed} / ${total}`;
  woEl('ctxSets').textContent = `${done} / ${rows}`;
  woEl('ctxVolume').textContent = woFmtVolume(woVolume(woDoneSets()));
}

function startSessionTimer() {
  clearInterval(session.timerHandle);
  session.timerHandle = setInterval(() => {
    if (!session) return;
    session.elapsedSec++;
    const t = woFmtClock(session.elapsedSec);
    woEl('sessionElapsed').textContent = t;
    woEl('ctxTime').textContent = t;
  }, 1000);
}

function woStartRest(seconds) {
  woStopRest();
  session.rest = { total: seconds, remaining: seconds, handle: null };
  woEl('woRest').classList.remove('hidden');
  woRenderRest();
  session.rest.handle = setInterval(() => {
    if (!session || !session.rest) return;
    session.rest.remaining--;
    woRenderRest();
    if (session.rest.remaining <= 0) {
      woStopRest();
      woEl('sessionSetStatus').textContent = 'Rest over — next set';
      if (navigator.vibrate) navigator.vibrate(200);
    }
  }, 1000);
}
function woRenderRest() {
  const r = session.rest;
  woEl('woRestTime').textContent = woFmtClock(Math.max(0, r.remaining));
  woEl('woRestFill').style.width = (r.total ? Math.max(0, r.remaining) / r.total * 100 : 0) + '%';
}
function woStopRest() {
  if (session && session.rest) clearInterval(session.rest.handle);
  if (session) session.rest = null;
  woEl('woRest')?.classList.add('hidden');
}
function woAdjustRest(delta) {
  if (!session.rest) return;
  session.rest.remaining = Math.max(0, session.rest.remaining + delta);
  session.rest.total = Math.max(session.rest.total, session.rest.remaining);
  if (session.rest.remaining === 0) { woStopRest(); return; }
  woRenderRest();
}

/* ── Navigation + finishing ────────────────────────────────────────── */
function goToExercise(i) {
  if (!session || i < 0 || i >= session.exercises.length) return;
  session.index = i;
  renderExercisePhase();
}

async function nextOrFinish() {
  if (!session) return;
  if (session.index < session.exercises.length - 1) { goToExercise(session.index + 1); return; }
  if (session.pending) return; // let in-flight saves land first
  const done = woDoneSets().length;
  if (!done) { quitSession(); return; }
  if (woAllComplete()) { showSummary(); return; }
  const ok = await Trackie.confirmDialog(
    `Finish now? You've logged ${done} of ${woRowsTotal()} sets. Logged sets are saved.`,
    { confirmText: 'Finish workout' }
  );
  if (ok && session) showSummary({ early: true });
}

function woStat(grid, label, value, accent) {
  const row = document.createElement('div');
  if (accent) row.className = `wo-stat-${accent}`;
  const dt = document.createElement('dt');
  dt.textContent = label;
  const dd = document.createElement('dd');
  dd.textContent = value;
  row.append(dt, dd);
  grid.append(row);
}

function renderBreakdown() {
  const list = woEl('summaryBreakdown');
  list.replaceChildren();
  session.exercises.forEach(ex => {
    const done = (ex.sets || []).filter(s => s.done);
    if (!done.length) return;
    const li = document.createElement('li');
    const head = document.createElement('div');
    head.className = 'wo-bd-head';
    const name = document.createElement('span');
    name.className = 'wo-bd-name';
    name.textContent = ex.name;
    const vol = document.createElement('span');
    vol.className = 'wo-bd-vol';
    vol.textContent = woFmtVolume(woVolume(done.map(s => ({ reps: s.savedReps, weight: s.savedWeight }))));
    head.append(name, vol);
    const sets = document.createElement('div');
    sets.className = 'wo-bd-sets';
    done.forEach(s => {
      const chip = document.createElement('span');
      chip.className = 'wo-bd-set' + (s.pr ? ' is-pr' : '');
      chip.textContent = woSetLabel({ weight: s.savedWeight, reps: s.savedReps });
      if (s.pr) chip.setAttribute('title', 'Personal record');
      sets.append(chip);
    });
    li.append(head, sets);
    list.append(li);
  });
  woEl('summaryBreakdown').closest('.wo-breakdown').classList.toggle('hidden', !list.children.length);
}

async function showSummary({ early = false } = {}) {
  clearInterval(session.timerHandle);
  woStopRest();
  showPhase('summary');
  updateOverallProgress(!early); // an early end shows the real progress, not a fake 100%
  session.endedEarly = early;
  woEl('summaryEyebrow').textContent = early ? 'Workout ended early' : 'Workout complete';
  woEl('summaryTitle').textContent = session.planName;
  woEl('summaryStreak').classList.add('hidden');
  renderBreakdown();

  // Server-authoritative counts/duration — the client-side elapsed timer can
  // drift if the tab was backgrounded, so ask the API for the real numbers
  // (this also awards session XP + runs the achievement check, once).
  const doneSets = woDoneSets();
  let exerciseCount = new Set(doneSets.map(s => s.exercise)).size;
  let durationSec = session.elapsedSec;
  let streak = null;
  try {
    const res = await Trackie.API.post(`${API_BASE}/gym.php`, {action:'session_complete', session_id: session.sessionId});
    if (res.success) {
      exerciseCount = res.exercise_count;
      durationSec = res.duration_sec;
      if (res.xp) session.xpGained = res.xp.gained;
      if (res.newAchievements?.length) session.newAchievements.push(...res.newAchievements);
      streak = res.streak ?? null;
    }
  } catch { /* fall back to client-side numbers below */ }
  if (!session) return;

  const mins = Math.round(durationSec / 60);
  const grid = woEl('summaryStats');
  grid.replaceChildren();
  woStat(grid, 'Duration', mins < 1 ? '< 1 min' : `${mins} min`);
  woStat(grid, 'Exercises', String(exerciseCount));
  woStat(grid, 'Sets', String(doneSets.length));
  woStat(grid, 'Volume', woFmtVolume(woVolume(doneSets)));
  const prs = doneSets.filter(s => s.pr).length;
  if (prs) woStat(grid, 'New PRs', String(prs), 'xp');
  if (session.xpGained) woStat(grid, 'XP earned', `+${session.xpGained} XP`, 'xp');
  const achCount = session.newAchievements.length;
  if (achCount) woStat(grid, 'Achievements', `${achCount} new`, 'xp');

  if (streak) {
    const streakEl = woEl('summaryStreak');
    streakEl.innerHTML = '<i class="fas fa-fire" aria-hidden="true"></i> ';
    streakEl.append(`${streak}-day streak`);
    streakEl.classList.remove('hidden');
  }
  woFocus('summaryTitle');
}

async function closeSummary() {
  woTeardown();
  Trackie.Toast.success(session.endedEarly ? `${session.planName} saved.` : `${session.planName} complete! 💪`);
  notifyNewAchievements(session.newAchievements);
  session = null;
  await Trackie.refreshFragments(['gymStatsWrap', 'gymStreakBadge', 'gymWeeklyPlanWrap', 'gymProgressPreviewWrap', 'gymDailyGoalWrap', 'gymPlansWrap', 'logList', 'gymAchievementsWrap']);
  if (typeof loadHistory === 'function') loadHistory(true);
}

// Leaving early. With work logged, "End & save" takes the same server path as
// finishing (session_complete: duration, session XP once, achievements) and
// shows the summary — logged work always counts. With nothing logged, the
// empty session is discarded (session_cancel deletes it server-side).
async function quitSession() {
  if (!session) return;
  if (woEl('workoutSession').dataset.phase === 'summary') { closeSummary(); return; }
  if (session.pending) return; // a set is mid-save; its result decides what "ending" keeps

  const sets = woDoneSets().length;
  if (sets > 0) {
    const ok = await Trackie.confirmDialog(
      `End the workout now? Your ${sets} logged set${sets === 1 ? ' is' : 's are'} saved and will count toward your stats.`,
      { confirmText: 'End & save' }
    );
    if (!ok || !session) return;
    showSummary({ early: true });
    return;
  }

  const ok = await Trackie.confirmDialog('Quit this workout? Nothing has been logged yet.', { confirmText: 'Quit', danger: true });
  if (!ok || !session) return;
  const sessionId = session.sessionId;
  woTeardown();
  session = null;
  try {
    await Trackie.API.post(`${API_BASE}/gym.php`, {action:'session_cancel', session_id: sessionId});
  } catch {}
  Trackie.Toast.info('Workout cancelled.');
}

// All workout-screen controls are delegated from the overlay element itself:
// SpaNav replaces it on every visit, so listeners never stack up.
(function wireWorkoutControls() {
  // Browser Back mid-workout swaps the page under a <body>-level overlay.
  // SpaNav removes this listener when you leave the page and the script adds
  // it again on the next visit, so it never stacks. (Idempotent either way.)
  {
    const teardownLiveWorkout = () => {
      const stale = document.querySelectorAll('body > .workout-overlay');
      // Sets already logged are saved server-side; only the live UI is lost.
      const live = window.__woActive;
      if (live) {
        clearInterval(live.timerHandle);
        if (live.rest) clearInterval(live.rest.handle);
        live.dead = true;
        window.__woActive = null;
      }
      if (!stale.length) return;
      stale.forEach(el => el.remove());
      document.body.classList.remove('workout-open');
      document.body.style.overflow = '';
    };
    window.addEventListener('popstate', teardownLiveWorkout);
    // Leaving through any link / command palette: same cleanup, so no
    // session or rest timer keeps running against a page that's gone.
    Trackie.SpaNav?.onLeave?.(teardownLiveWorkout);
  }
  const root = woEl('workoutSession');
  if (!root) return;

  root.addEventListener('click', e => {
    if (!session) return;
    const check = e.target.closest('.wo-check');
    if (check) {
      const n = +check.closest('tr').dataset.n;
      const s = woRowFor(n);
      if (s) (s.done ? uncompleteRow(n) : completeRow(n));
      return;
    }
    const rest = e.target.closest('[data-rest]');
    if (rest) {
      if (rest.dataset.rest === 'skip') woStopRest();
      else woAdjustRest(parseInt(rest.dataset.rest, 10));
      return;
    }
    if (e.target.closest('#completeSetBtn')) {
      const next = woCurrent().sets.find(s => !s.done);
      if (next) completeRow(next.n);
    } else if (e.target.closest('#woAddSet')) addSet();
    else if (e.target.closest('#woRemoveSet')) removeSet();
    else if (e.target.closest('#woPrevEx')) goToExercise(session.index - 1);
    else if (e.target.closest('#woNextEx')) nextOrFinish();
  });

  // Typing into a row updates local state; a DONE row is re-saved on change.
  root.addEventListener('input', e => {
    const input = e.target.closest('.wo-cell');
    if (!input || !session) return;
    const s = woRowFor(+input.closest('tr').dataset.n);
    if (!s) return;
    s[input.dataset.field] = input.value === '' ? '' : input.value;
    s.touched = true;
  });
  root.addEventListener('change', e => {
    const input = e.target.closest('.wo-cell');
    if (!input || !session) return;
    const s = woRowFor(+input.closest('tr').dataset.n);
    if (s && s.done && !s.saving &&
        (woNum(s.reps) !== s.savedReps || woNum(s.weight) !== s.savedWeight)) {
      saveSet(woCurrent(), s, { fromEdit: true });
    }
  });

  root.addEventListener('keydown', e => {
    if (!session) return;
    const input = e.target.closest('.wo-cell');
    if (e.key === 'Enter' && input) {
      e.preventDefault();
      const s = woRowFor(+input.closest('tr').dataset.n);
      if (s && !s.done) completeRow(s.n);
      else input.blur(); // commits the edit via "change"
    } else if (e.key === 'Escape') {
      e.preventDefault();
      quitSession();
    }
  });
})();

async function saveLog() {
  const exercise = document.getElementById('logExercise').value.trim();
  if (!exercise) { Trackie.Toast.warning('Exercise name is required.'); return; }
  try {
    const res = await Trackie.API.post(`${API_BASE}/gym.php`, {
      action: 'log',
      exercise_name: exercise,
      sets: document.getElementById('logSets').value,
      reps: document.getElementById('logReps').value,
      weight: document.getElementById('logWeight').value,
      log_date: document.getElementById('logDate').value,
      notes: document.getElementById('logNotes').value,
      plan_id: document.getElementById('logWorkoutModal').dataset.planId || '',
    });
    if (res.success) {
      Trackie.closeModal('logWorkoutModal');
      Trackie.Toast.success('Workout logged!' + (res.xp ? ` +${res.xp.gained} XP` : ''));
      notifyNewAchievements(res.newAchievements);
      await Trackie.refreshFragments(['gymStatsWrap', 'gymStreakBadge', 'gymWeeklyPlanWrap', 'gymProgressPreviewWrap', 'gymDailyGoalWrap', 'gymPlansWrap', 'logList', 'gymAchievementsWrap']);
    } else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}

async function deleteLog(id) {
  const ok = await Trackie.confirmDialog('Delete this log entry?', {confirmText:'Delete', danger:true});
  if (!ok) return;
  try {
    const res = await Trackie.API.post(`${API_BASE}/gym.php`, {action:'delete_log', log_id:id});
    if (res.success) {
      document.getElementById(`log-${id}`)?.remove();
      document.getElementById(`log-full-${id}`)?.remove();
      Trackie.Toast.success('Deleted.');
    } else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}

/* ── Exercise Library (Fitness V2) ───────────────────────────────
   Data comes from the `exercise_catalog` action (app/Modules/Fitness):
   the local library plus any remote provider, in one normalized shape.
   Everything user- or provider-supplied is set via textContent / DOM, never
   concatenated into HTML. */
let libSearchDebounce = null;
let libResults = [];
let libRequestSeq = 0;
let fxCurrent = null;

function fxEl(tag, cls, text) {
  const e = document.createElement(tag);
  if (cls) e.className = cls;
  if (text !== undefined && text !== null) e.textContent = text;
  return e;
}
function fxTitle(s) { return s ? String(s).replace(/\b\w/g, c => c.toUpperCase()) : s; }

function exerciseCard(ex, index) {
  const card = fxEl('button', 'fx-card');
  card.type = 'button';
  card.dataset.index = index;
  card.setAttribute('aria-label', `${ex.name} — details`);

  const thumb = fxEl('div', 'fx-thumb');
  const img = woMediaUrl(ex.thumbnail_url) || woMediaUrl(ex.gif_url);
  if (img) {
    const i = fxEl('img');
    i.src = img; i.alt = ''; i.loading = 'lazy'; i.referrerPolicy = 'no-referrer';
    thumb.append(i);
  } else {
    thumb.append(fxEl('i', `fas ${ex.video_url ? 'fa-circle-play' : 'fa-dumbbell'}`));
    thumb.classList.add('is-icon');
  }
  if (ex.video_url) thumb.append(fxEl('span', 'fx-thumb-badge', 'Video'));

  const body = fxEl('div', 'fx-card-body');
  body.append(fxEl('span', 'fx-name', ex.name));
  const muscles = [ex.target || ex.body_part, ...(ex.secondary || []).slice(0, 2)].filter(Boolean).map(fxTitle);
  if (muscles.length) body.append(fxEl('span', 'fx-muscles', muscles.join(' · ')));
  const tags = fxEl('span', 'fx-tags');
  [ex.equipment, ex.difficulty].filter(Boolean).forEach(t => tags.append(fxEl('span', 'fx-tag', fxTitle(t))));
  if (ex.source === 'workoutdb') tags.append(fxEl('span', 'fx-tag fx-tag-src', 'WorkoutDB'));
  else if (ex.custom) tags.append(fxEl('span', 'fx-tag fx-tag-src', 'Mine'));
  body.append(tags);

  card.append(thumb, body);
  return card;
}

async function searchExercises() {
  const wrap = document.getElementById('libResults');
  const hint = document.getElementById('libRemoteHint');
  const q = document.getElementById('libSearch').value.trim();
  const seq = ++libRequestSeq;
  wrap.replaceChildren(fxEl('p', 'form-hint', 'Searching…'));
  try {
    const res = await Trackie.API.post(`${API_BASE}/gym.php`, {
      action: 'exercise_catalog', q,
      body_part: document.getElementById('libMuscle').value,
      equipment: document.getElementById('libEquipment').value,
    });
    if (seq !== libRequestSeq) return; // a newer search is already on its way
    if (!res.success) throw new Error('catalog');
    libResults = res.exercises || [];

    // Honest provider status: only mention WorkoutDB when it is set up.
    const remoteOn = !!(res.remote && res.remote.workoutdb);
    hint.classList.toggle('hidden', !remoteOn || !!q);
    hint.textContent = remoteOn && !q ? 'Type an exercise name to also search WorkoutDB.' : '';

    if (!libResults.length) {
      const icon = fxEl('div', 'empty-state-icon');
      icon.append(fxEl('i', 'fas fa-search'));
      const inner = fxEl('div', 'empty-state');
      inner.append(icon, fxEl('div', 'empty-state-title', 'No exercises found'),
                   fxEl('p', null, 'Try a different search, or add it as a custom exercise.'));
      const empty = fxEl('div', 'card');
      empty.append(inner);
      wrap.replaceChildren(empty);
      return;
    }
    wrap.replaceChildren(...libResults.map(exerciseCard));
  } catch {
    if (seq === libRequestSeq) wrap.replaceChildren(fxEl('p', 'form-hint', "Couldn't load exercises — check your connection and try again."));
  }
}

function openExerciseDetail(ex) {
  if (!ex) return;
  fxCurrent = ex;
  document.getElementById('fxDetailName').textContent = ex.name;

  renderDetailMedia(ex);

  // Library exercises without their own clip: ask the server for the cached
  // demo (ExerciseDB GIF / free-exercise-db photos) and fill it in when it lands.
  if (ex.source === 'library' && !ex.video_url && !ex.gif_url && !(ex.frames || []).length) {
    Trackie.API.post(`${API_BASE}/gym.php`, { action: 'exercise_detail', source: 'library', id: ex.id })
      .then(res => {
        if (!res.success || fxCurrent !== ex) return;
        Object.assign(ex, {
          gif_url: res.exercise.gif_url || null, frames: res.exercise.frames || [],
          demo_credit: res.exercise.demo_credit || null, demo_name: res.exercise.demo_name || null,
          instructions: (ex.instructions || []).length ? ex.instructions : (res.exercise.instructions || []),
        });
        renderDetailMedia(ex);
        renderDetailSteps(ex);
      })
      .catch(() => { /* a demo is optional — the sheet works without it */ });
  }

  const facts = document.getElementById('fxDetailFacts');
  facts.replaceChildren();
  [['Target', ex.target || ex.body_part], ['Secondary', (ex.secondary || []).join(', ')],
   ['Equipment', ex.equipment], ['Difficulty', ex.difficulty]].forEach(([k, v]) => {
    if (!v) return;
    const row = fxEl('div');
    row.append(fxEl('dt', null, k), fxEl('dd', null, fxTitle(v)));
    facts.append(row);
  });

  renderDetailSteps(ex);

  document.getElementById('fxSaveBtn').classList.toggle('hidden', ex.source === 'library');
  Trackie.openModal('exerciseDetailModal');
}

function renderDetailMedia(ex) {
  const media = document.getElementById('fxDetailMedia');
  const vid = document.getElementById('fxDetailVideo');
  const gif = document.getElementById('fxDetailGif');
  const credit = document.getElementById('fxDetailCredit');
  const videoUrl = woMediaUrl(ex.video_url), gifUrl = woMediaUrl(ex.gif_url);
  const frames = Array.isArray(ex.frames) ? ex.frames : [];
  woStopFrames(gif);
  vid.removeAttribute('src'); vid.removeAttribute('poster'); gif.removeAttribute('src');
  vid.classList.toggle('hidden', !videoUrl);
  gif.classList.toggle('hidden', !!videoUrl || (!gifUrl && !frames.length));
  if (videoUrl) {
    const poster = woMediaUrl(ex.thumbnail_url);
    if (poster) vid.poster = poster;
    vid.src = videoUrl;
  } else if (gifUrl || frames.length) {
    if (gifUrl) gif.src = gifUrl; else woPlayFrames(gif, frames);
    gif.alt = `${ex.demo_name || ex.name} demonstration`;
  }
  media.classList.toggle('hidden', !videoUrl && !gifUrl && !frames.length);
  // Name the source — and the exact exercise shown when it's a close match.
  const showCredit = !videoUrl && !!ex.demo_credit;
  credit.textContent = showCredit
    ? `Demo${ex.demo_name && fitnessNameKey(ex.demo_name) !== fitnessNameKey(ex.name) ? `: ${ex.demo_name}` : ''} · ${ex.demo_credit}`
    : '';
  credit.classList.toggle('hidden', !showCredit);
}
function fitnessNameKey(s) { return String(s || '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim(); }

function renderDetailSteps(ex) {
  const steps = ex.instructions || [];
  document.getElementById('fxDetailSteps').replaceChildren(...steps.map(s => fxEl('li', null, s)));
  document.getElementById('fxDetailStepsWrap').classList.toggle('hidden', !steps.length);
}

// Delegated: result cards are re-rendered on every search.
document.getElementById('libResults')?.addEventListener('click', e => {
  const card = e.target.closest('.fx-card');
  if (card) openExerciseDetail(libResults[+card.dataset.index]);
});
document.getElementById('exerciseDetailModal')?.addEventListener('click', e => {
  if (e.target.closest('[data-close-modal]') || e.target.classList.contains('modal-backdrop')) {
    document.getElementById('fxDetailVideo').pause();
    woStopFrames(document.getElementById('fxDetailGif'));
  }
});
document.getElementById('fxLogBtn')?.addEventListener('click', () => {
  if (!fxCurrent) return;
  Trackie.closeModal('exerciseDetailModal');
  openLogWorkout(fxCurrent.name);
});
document.getElementById('fxSaveBtn')?.addEventListener('click', async e => {
  if (!fxCurrent || fxCurrent.source === 'library') return;
  const btn = e.currentTarget;
  btn.disabled = true;
  try {
    const res = await Trackie.API.post(`${API_BASE}/gym.php`, { action: 'exercise_save', source: fxCurrent.source, id: fxCurrent.id });
    if (!res.success) { Trackie.Toast.error(res.error || "Couldn't save that exercise."); return; }
    Trackie.Toast.success(`${fxCurrent.name} saved to your library.`);
    btn.classList.add('hidden');
    searchExercises();
  } catch { Trackie.Toast.error('Network error.'); }
  finally { btn.disabled = false; }
});
document.getElementById('fxAddBtn')?.addEventListener('click', async e => {
  if (!fxCurrent) return;
  const btn = e.currentTarget;
  const planSel = document.getElementById('fxAddPlan');
  btn.disabled = true;
  try {
    const res = await Trackie.API.post(`${API_BASE}/gym.php`, {
      action: 'plan_add_exercise',
      plan_id: planSel.value,
      exercise_name: fxCurrent.name,
      sets: document.getElementById('fxAddSets').value,
      reps: document.getElementById('fxAddReps').value,
    });
    if (!res.success) { Trackie.Toast.error(res.error || "Couldn't add it."); return; }
    Trackie.Toast.success(`Added to ${planSel.options[planSel.selectedIndex].text}.`);
    Trackie.closeModal('exerciseDetailModal');
    await Trackie.refreshFragments(['gymPlansWrap']);
  } catch { Trackie.Toast.error('Network error.'); }
  finally { btn.disabled = false; }
});

function watchExerciseVideo(name, url) {
  document.getElementById('wvTitle').textContent = name;
  document.getElementById('wvPlayer').src = url;
  Trackie.openModal('watchVideoModal');
}
document.getElementById('watchVideoModal')?.addEventListener('click', e => {
  if (e.target.closest('[data-close-modal]') || e.target.classList.contains('modal-backdrop')) {
    document.getElementById('wvPlayer').pause();
    document.getElementById('wvPlayer').removeAttribute('src');
  }
});
document.getElementById('libSearch')?.addEventListener('input', () => {
  clearTimeout(libSearchDebounce);
  libSearchDebounce = setTimeout(searchExercises, 350);
});
['libMuscle', 'libEquipment'].forEach(id => document.getElementById(id)?.addEventListener('change', searchExercises));

/* ── Exercise video matching ─────────────────────────────────────
   Files in assets/vids that don't have a clear name get walked through
   one at a time here — live preview + pick-existing-or-name-new — instead
   of guessing wrong on an ambiguous filename. */
let videoAssignQueue = [];
let videoAssignAllExercises = [];

async function checkVideoBanner() {
  const banner = document.getElementById('libVideoBanner');
  try {
    const res = await Trackie.API.post(`${API_BASE}/gym.php`, {action:'exercise_video_list'});
    if (!res.success || !res.unassigned.length) { banner.classList.add('hidden'); return; }
    banner.innerHTML = `
      <div class="card card-body" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap">
        <div style="display:flex;align-items:center;gap:.75rem">
          <i class="fas fa-clapperboard" style="font-size:1.25rem;color:var(--accent)"></i>
          <div>
            <div style="font-weight:600;font-size:.875rem;color:var(--text)">${res.unassigned.length} exercise video${res.unassigned.length===1?'':'s'} need matching</div>
            <div style="font-size:.8125rem;color:var(--muted)">Preview each clip and match it to an exercise.</div>
          </div>
        </div>
        <button class="btn btn-primary btn-sm" onclick="openVideoAssignFlow()"><i class="fas fa-play"></i> Match now</button>
      </div>`;
    banner.classList.remove('hidden');
  } catch { banner.classList.add('hidden'); }
}

async function openVideoAssignFlow() {
  try {
    const [videosRes, exRes] = await Promise.all([
      Trackie.API.post(`${API_BASE}/gym.php`, {action:'exercise_video_list'}),
      Trackie.API.post(`${API_BASE}/gym.php`, {action:'exercise_search'}),
    ]);
    if (!videosRes.success || !videosRes.unassigned.length) { Trackie.Toast.success('All videos are already matched!'); return; }
    videoAssignQueue = [...videosRes.unassigned];
    videoAssignAllExercises = exRes.success ? exRes.exercises : [];
    Trackie.openModal('assignVideoModal');
    renderCurrentVideoAssign();
  } catch { Trackie.Toast.error('Network error.'); }
}

function renderCurrentVideoAssign() {
  document.getElementById('avRemaining').textContent = videoAssignQueue.length;
  if (!videoAssignQueue.length) {
    Trackie.closeModal('assignVideoModal');
    Trackie.Toast.success('All videos matched!');
    checkVideoBanner();
    searchExercises();
    return;
  }
  const filename = videoAssignQueue[0];
  const player = document.getElementById('avPreview');
  player.src = `${API_BASE.replace('/api','')}/assets/vids/${encodeURIComponent(filename)}`;
  player.load();

  const select = document.getElementById('avExisting');
  select.replaceChildren(new Option('— choose one —', ''), ...videoAssignAllExercises.map(ex =>
    new Option(ex.name + (ex.muscle_group ? ` (${ex.muscle_group})` : ''), ex.id)));

  // Best-effort guess at a name from the filename, so the "new exercise"
  // field isn't blank — still fully editable before assigning.
  document.getElementById('avNewName').value = filename.replace(/\.(mp4|webm|mov)$/i, '').replace(/[_-]+/g, ' ').trim();
}

async function assignCurrentVideo() {
  if (!videoAssignQueue.length) return;
  const filename = videoAssignQueue[0];
  const existingId = document.getElementById('avExisting').value;
  const newName = document.getElementById('avNewName').value.trim();
  if (!existingId && !newName) { Trackie.Toast.warning('Pick an existing exercise or name a new one.'); return; }

  try {
    const res = await Trackie.API.post(`${API_BASE}/gym.php`, {
      action: 'exercise_assign_video',
      filename,
      exercise_id: existingId || '',
      new_name: existingId ? '' : newName,
      muscle_group: document.getElementById('avMuscle').value,
      equipment: document.getElementById('avEquipment').value,
    });
    if (res.success) {
      videoAssignQueue.shift();
      renderCurrentVideoAssign();
    } else {
      Trackie.Toast.error(res.error || 'Failed to assign.');
    }
  } catch { Trackie.Toast.error('Network error.'); }
}
function skipVideoAssign() {
  if (!videoAssignQueue.length) return;
  videoAssignQueue.shift();
  renderCurrentVideoAssign();
}

function openAddExercise() {
  document.getElementById('exName').value = '';
  document.getElementById('exMuscle').value = 'Chest';
  document.getElementById('exEquipment').value = 'Barbell';
  Trackie.openModal('addExerciseModal');
}
async function saveCustomExercise() {
  const name = document.getElementById('exName').value.trim();
  if (!name) { Trackie.Toast.warning('Exercise name is required.'); return; }
  try {
    const res = await Trackie.API.post(`${API_BASE}/gym.php`, {
      action: 'exercise_add_custom', name,
      muscle_group: document.getElementById('exMuscle').value,
      equipment: document.getElementById('exEquipment').value,
    });
    if (res.success) {
      Trackie.closeModal('addExerciseModal');
      Trackie.Toast.success('Exercise added.');
      searchExercises();
    } else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}

/* ── Progress tab: totals, charts, PR list ───────────────────────
   Chart.js loads `defer`, so if this tab is opened before it's ready we
   retry briefly rather than silently failing to render. */
function withChartJs(fn, tries = 40) {
  if (typeof Chart !== 'undefined') { fn(); return; }
  if (tries <= 0) return;
  setTimeout(() => withChartJs(fn, tries - 1), 60);
}

let freqChartInst = null, volChartInst = null, muscleChartInst = null;

async function loadProgressTab() {
  try {
    const [statsRes, prRes] = await Promise.all([
      Trackie.API.post(`${API_BASE}/gym.php`, {action:'progress_stats', weeks:8}),
      Trackie.API.post(`${API_BASE}/gym.php`, {action:'personal_records'}),
    ]);
    if (statsRes.success) renderProgressStats(statsRes);
    if (prRes.success) renderPrList(prRes.records);
  } catch { Trackie.Toast.error('Could not load progress data.'); }
}

function renderProgressStats(res) {
  document.getElementById('progTotalSets').textContent = res.total_sets;
  document.getElementById('progTotalReps').textContent = res.total_reps;
  document.getElementById('progTotalLogs').textContent = res.total_logs;
  const h = Math.floor(res.training_time_sec / 3600), m = Math.round((res.training_time_sec % 3600) / 60);
  document.getElementById('progTrainingTime').textContent = res.training_time_sec > 0 ? `${h}h ${m}m` : '—';

  const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
  const textColor = getComputedStyle(document.documentElement).getPropertyValue('--muted').trim() || '#64748b';
  const gridColor = isDark ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.06)';
  const accent = getComputedStyle(document.documentElement).getPropertyValue('--accent').trim() || '#ef4444';

  withChartJs(() => {
    const labels = res.weekly.map(w => {
      const d = new Date(w.week_start + 'T00:00:00');
      return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    });

    freqChartInst?.destroy();
    freqChartInst = new Chart(document.getElementById('progFreqChart'), {
      type: 'bar',
      data: { labels, datasets: [{ data: res.weekly.map(w => w.sessions), backgroundColor: accent, borderRadius: 4, maxBarThickness: 28 }] },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          x: { grid: { display: false }, ticks: { color: textColor, font: { size: 10 } } },
          y: { beginAtZero: true, ticks: { stepSize: 1, color: textColor, font: { size: 10 } }, grid: { color: gridColor } },
        },
      },
    });

    volChartInst?.destroy();
    volChartInst = new Chart(document.getElementById('progVolumeChart'), {
      type: 'line',
      data: { labels, datasets: [{ data: res.weekly.map(w => w.volume), borderColor: accent, backgroundColor: 'transparent', tension: .35, pointRadius: 3 }] },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          x: { grid: { display: false }, ticks: { color: textColor, font: { size: 10 } } },
          y: { beginAtZero: true, ticks: { color: textColor, font: { size: 10 } }, grid: { color: gridColor } },
        },
      },
    });

    const muscleColors = ['#ef4444','#f59e0b','#22c55e','#3b82f6','#a855f7','#ec4899','#14b8a6','#f97316'];
    muscleChartInst?.destroy();
    if (res.muscle_distribution.length) {
      muscleChartInst = new Chart(document.getElementById('progMuscleChart'), {
        type: 'doughnut',
        data: {
          labels: res.muscle_distribution.map(m => m.muscle_group),
          datasets: [{ data: res.muscle_distribution.map(m => m.cnt), backgroundColor: muscleColors, borderWidth: 0 }],
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          plugins: { legend: { position: 'right', labels: { color: textColor, boxWidth: 10, font: { size: 10 } } } },
        },
      });
    }
  });
}

function renderPrList(records) {
  const wrap = document.getElementById('progPrList');
  if (!records.length) {
    wrap.innerHTML = '<p class="form-hint">No weighted sets logged yet — your personal records will show up here.</p>';
    return;
  }
  wrap.innerHTML = records.slice(0, 8).map(r => `
    <div class="qstat-item">
      <span class="qstat-label"><i class="fas fa-trophy" style="color:#f59e0b"></i> ${escHtml(r.exercise_name)}</span>
      <span class="qstat-value">${escHtml(r.weight_kg)} kg${r.reps ? ` × ${escHtml(r.reps)}` : ''}</span>
    </div>`).join('');
}

/* ── Body stats (optional) ───────────────────────────────────────── */
/** Weight trend: real entries only, oldest → newest, with 30-day and total change. */
function bodyTrendSvg(entries) {
  const pts = entries.filter(e => e.weight_kg !== null && e.weight_kg !== '').map(e => ({ d: e.log_date, w: +e.weight_kg })).reverse();
  if (pts.length < 2) return '';
  const W = 320, H = 90, pad = 6;
  const ws = pts.map(p => p.w), min = Math.min(...ws), max = Math.max(...ws), span = (max - min) || 1;
  const t0 = new Date(pts[0].d).getTime(), t1 = new Date(pts[pts.length - 1].d).getTime(), ts = (t1 - t0) || 1;
  const xy = pts.map(p => [pad + (new Date(p.d).getTime() - t0) / ts * (W - 2 * pad), H - pad - (p.w - min) / span * (H - 2 * pad)]);
  const last = pts[pts.length - 1], cutoff = new Date(last.d); cutoff.setDate(cutoff.getDate() - 30);
  const base30 = pts.find(p => new Date(p.d) >= cutoff) || pts[0];
  const fmt = v => (v > 0 ? '+' : '') + v.toFixed(1) + ' kg';
  return `<div class="body-trend">
    <svg viewBox="0 0 ${W} ${H}" preserveAspectRatio="none" role="img" aria-label="Weight trend from ${pts[0].w} to ${last.w} kg">
      <polyline fill="none" stroke="var(--accent)" stroke-width="2" vector-effect="non-scaling-stroke" points="${xy.map(p => p.map(n => n.toFixed(1)).join(',')).join(' ')}"/>
      ${xy.map(p => `<circle cx="${p[0].toFixed(1)}" cy="${p[1].toFixed(1)}" r="2.5" fill="var(--accent)"/>`).join('')}
    </svg>
    <div class="body-trend-meta"><span>Now <b>${last.w} kg</b></span><span>30 days <b>${fmt(last.w - base30.w)}</b></span>
      <span>Since ${escHtml(pts[0].d)} <b>${fmt(last.w - pts[0].w)}</b></span><span>Range ${min}–${max} kg</span></div>
  </div>`;
}
async function loadBodyStats() {
  const wrap = document.getElementById('bodyStatsList');
  try {
    const res = await Trackie.API.post(`${API_BASE}/gym.php`, {action:'body_stats_list'});
    if (!res.success || !res.entries.length) {
      wrap.innerHTML = '<p class="form-hint">No entries yet — log your weight above to start a trend.</p>';
      return;
    }
    wrap.innerHTML = bodyTrendSvg(res.entries) + res.entries.map(e => `
      <div class="qstat-item" id="bodystat-${e.id}">
        <span class="qstat-label">${e.log_date}${e.body_fat_pct ? ` · ${e.body_fat_pct}% BF` : ''}</span>
        <span style="display:flex;align-items:center;gap:.625rem">
          <span class="qstat-value">${e.weight_kg ? e.weight_kg + ' kg' : '—'}</span>
          <button class="btn btn-icon btn-ghost btn-sm" aria-label="Delete entry" onclick="deleteBodyStat(${e.id})"><i class="fas fa-trash" style="font-size:.7rem"></i></button>
        </span>
      </div>`).join('');
  } catch { wrap.innerHTML = '<p class="form-hint">Network error.</p>'; }
}
async function saveBodyStat() {
  const weight = document.getElementById('bodyWeightInput').value;
  const bf = document.getElementById('bodyFatInput').value;
  if (!weight && !bf) { Trackie.Toast.warning('Enter at least a weight or body fat %.'); return; }
  try {
    const res = await Trackie.API.post(`${API_BASE}/gym.php`, {
      action: 'body_stats_add',
      log_date: document.getElementById('bodyDateInput').value,
      weight_kg: weight, body_fat_pct: bf,
    });
    if (res.success) {
      document.getElementById('bodyWeightInput').value = '';
      document.getElementById('bodyFatInput').value = '';
      Trackie.Toast.success('Logged.');
      loadBodyStats();
    } else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function deleteBodyStat(id) {
  try {
    const res = await Trackie.API.post(`${API_BASE}/gym.php`, {action:'body_stats_delete', id});
    if (res.success) { document.getElementById(`bodystat-${id}`)?.remove(); }
  } catch {}
}
</script>
