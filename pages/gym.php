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
// Rough estimate: ~2.5 min per target set (work + a share of rest) — an
// honest ballpark from the plan's own numbers, not an invented constant.
$todaysPlanEstMin = $todaysPlanItems ? (int)round(array_sum(array_column($todaysPlanItems, 'target_sets')) * 2.5) : 0;

// ── Fitness Dashboard header stats (this week) ─────────────────
$hour = (int)date('G');
$fitGreeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

$exercisesThisWeek = (int)fetchOne(
    "SELECT COUNT(DISTINCT exercise_name) c FROM workout_logs WHERE user_id=? AND log_date BETWEEN ? AND ?",
    [$uid, $thisWeekStart, $thisWeekEnd]
)['c'];
$trainingTimeThisWeekSec = (int)(fetchOne(
    "SELECT COALESCE(SUM(duration_sec),0) t FROM workout_sessions WHERE user_id=? AND session_date BETWEEN ? AND ?",
    [$uid, $thisWeekStart, $thisWeekEnd]
)['t'] ?? 0);

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
<!-- lottie-player web component (optional rest-screen animation) -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/lottie-player/2.0.12/lottie-player.js" defer></script>
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
  <div class="fit-streak-badge" id="gymStreakBadge">
    <div class="fit-streak-num"><i class="fas fa-fire"></i> <strong><?= $streak['current'] ?></strong> Day Streak</div>
    <div class="fit-streak-sub">Keep it alive!</div>
  </div>
</div>

<div class="grid-stats" style="margin-bottom:1.5rem" id="gymStatsWrap">
  <div class="stat-card">
    <div class="stat-icon-chip" style="color:var(--accent)"><i class="fas fa-calendar-check"></i></div>
    <div class="stat-val"><?= $sessionsThisWeek ?></div>
    <div class="stat-label">Workouts · This week</div>
    <?php if ($sessionsLastWeek > 0): $d = $sessionsThisWeek - $sessionsLastWeek; ?>
      <div class="stat-trend <?= $d >= 0 ? 'up' : 'down' ?>"><i class="fas fa-arrow-<?= $d >= 0 ? 'up' : 'down' ?>"></i> <?= abs($d) ?> vs last week</div>
    <?php endif; ?>
  </div>
  <div class="stat-card">
    <div class="stat-icon-chip" style="color:var(--info)"><i class="fas fa-list-check"></i></div>
    <div class="stat-val"><?= $exercisesThisWeek ?></div>
    <div class="stat-label">Exercises · This week</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon-chip" style="color:var(--ok)"><i class="fas fa-stopwatch"></i></div>
    <div class="stat-val"><?= $trainingTimeThisWeekSec > 0 ? round($trainingTimeThisWeekSec / 3600, 1) . 'h' : '—' ?></div>
    <div class="stat-label">Training Time · This week</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon-chip" style="color:#f59e0b"><i class="fas fa-fire"></i></div>
    <div class="stat-val"><?= $streak['current'] ?></div>
    <div class="stat-label">Day Streak</div>
    <div class="stat-trend up">Keep it going!</div>
  </div>
</div>

<?php if ($fitnessInsight): ?>
  <?= renderInsight($fitnessInsight, 'fa-dumbbell') ?>
<?php endif; ?>

<!-- Module tabs -->
<div class="filter-tabs" style="margin-bottom:1.25rem" id="gymTabs">
  <button class="filter-tab active" data-tab="overview">Overview</button>
  <button class="filter-tab" data-tab="practice">Planner</button>
  <button class="filter-tab" data-tab="library">Library</button>
  <button class="filter-tab" data-tab="progress">Progress</button>
  <button class="filter-tab" data-tab="track">History</button>
  <button class="filter-tab" data-tab="journal">Journal</button>
  <button class="filter-tab" data-tab="learn">Learn</button>
  <button class="filter-tab" data-tab="ai">AI Coach</button>
</div>

<!-- ═══ Overview ═══ -->
<div id="tab-overview" class="gym-tab-panel">

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
            <?php if ($todaysPlanEstMin > 0): ?>
              <span class="fit-today-chip"><i class="fas fa-clock"></i> ~<?= $todaysPlanEstMin ?> min</span>
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
        <div class="fit-card-label">Recent Workouts <a href="javascript:void(0)" onclick="switchGymTab('track')">View All →</a></div>
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
            <div class="fit-recent-row" onclick="switchGymTab('track')">
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
          <div class="fit-card-label" style="justify-content:center">Daily Goal</div>
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
<div id="workoutSession" class="workout-overlay hidden">
  <div class="workout-overlay-inner">

    <div class="wo-header">
      <div class="wo-plan-name"><i class="fas fa-dumbbell"></i> <span id="sessionPlanName">Workout</span></div>
      <div class="wo-elapsed"><i class="fas fa-clock"></i> <span id="sessionElapsed">00:00</span></div>
      <button class="btn btn-icon btn-ghost" style="color:#fff" onclick="quitSession()" title="Quit workout" aria-label="Quit workout"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="wo-progress-track"><div class="wo-progress-fill" id="sessionProgressBar" style="width:0%"></div></div>
    <div class="wo-progress-label" id="sessionProgress">Exercise 1 of 1</div>

    <!-- ── Exercise phase ── -->
    <div id="woExercisePhase">
      <video id="sessionExerciseVideo" class="wo-exercise-video hidden" muted loop playsinline autoplay></video>
      <div class="wo-exercise-name" id="sessionExerciseName">—</div>
      <div class="wo-prev-performance hidden" id="sessionPrevPerf"></div>
      <div class="wo-set-indicator" id="sessionSetIndicator">Set 1 of 3</div>

      <div class="wo-set-dots" id="sessionSetDots"></div>

      <div class="wo-pr-banner hidden" id="sessionPrBanner"><i class="fas fa-trophy"></i> New personal record!</div>

      <div class="wo-inputs">
        <div class="wo-input-group"><label for="sessReps">Reps</label><input id="sessReps" type="number" min="0" class="form-input"></div>
        <div class="wo-input-group"><label for="sessWeight">Weight (kg)</label><input id="sessWeight" type="number" step="0.5" min="0" class="form-input"></div>
      </div>

      <button class="btn btn-primary wo-btn-lg" onclick="completeSet()"><i class="fas fa-check"></i> Complete Set</button>
      <div class="wo-secondary-actions">
        <button class="btn btn-ghost" style="color:#fff" onclick="finishExerciseEarly()"><i class="fas fa-forward-step"></i> Finish exercise</button>
        <button class="btn btn-ghost" style="color:#fff" onclick="skipSessionExercise()"><i class="fas fa-forward"></i> Skip exercise</button>
      </div>
    </div>

    <!-- ── Rest / break phase ── -->
    <div id="woRestPhase" class="hidden">
      <!-- Set GYM_REST_LOTTIE_SRC below to a .json animation URL to replace the CSS pulse -->
      <lottie-player id="restLottie" class="wo-rest-lottie hidden" loop autoplay></lottie-player>
      <div class="wo-rest-ring" id="restRing">
        <div class="wo-rest-label">BREAK</div>
        <div class="wo-rest-time" id="restTimeDisplay">00:60</div>
      </div>
      <div class="wo-next-preview">Up next: <strong id="nextExerciseName">—</strong></div>
      <div class="wo-secondary-actions">
        <button class="btn btn-secondary wo-btn-lg" id="restPauseBtn" onclick="toggleRestPause()"><i class="fas fa-pause"></i> Pause</button>
        <button class="btn btn-primary wo-btn-lg" onclick="skipRest()"><i class="fas fa-forward"></i> Skip break</button>
      </div>
    </div>

    <!-- ── Summary phase ── -->
    <div id="woSummaryPhase" class="hidden">
      <div class="wo-summary-icon"><i class="fas fa-trophy"></i></div>
      <div class="wo-exercise-name" id="summaryTitle">Workout complete! 🎉</div>
      <div class="wo-summary-stats" id="summaryStats"></div>
      <div class="wo-summary-streak" id="summaryStreak" style="display:none"></div>
      <button class="btn btn-primary wo-btn-lg" onclick="closeSummary()"><i class="fas fa-check"></i> Done</button>
    </div>

  </div>
</div>

<!-- ═══ Practice / Planner ═══ -->
<div id="tab-practice" class="gym-tab-panel hidden">

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
</div>

<!-- ═══ Library ═══ -->
<div id="tab-library" class="gym-tab-panel hidden">
  <div class="card card-body" style="margin-bottom:1.25rem">
    <div style="display:flex;gap:.625rem;flex-wrap:wrap">
      <input id="libSearch" class="form-input" placeholder="Search exercises…" style="flex:2;min-width:180px">
      <select id="libMuscle" class="form-input" style="flex:1;min-width:140px">
        <option value="">All muscle groups</option>
        <?php foreach (['Chest','Back','Legs','Shoulders','Arms','Core','Cardio','Full Body'] as $m): ?>
          <option value="<?= $m ?>"><?= $m ?></option>
        <?php endforeach; ?>
      </select>
      <select id="libEquipment" class="form-input" style="flex:1;min-width:140px">
        <option value="">All equipment</option>
        <?php foreach (['Barbell','Dumbbell','Machine','Cable','Bodyweight'] as $eq): ?>
          <option value="<?= $eq ?>"><?= $eq ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-secondary btn-sm" onclick="openAddExercise()"><i class="fas fa-plus"></i> Custom exercise</button>
    </div>
  </div>
  <div id="libVideoBanner" class="hidden" style="margin-bottom:1.25rem"></div>
  <div id="libResults" class="grid-cards" aria-live="polite"></div>
</div>

<!-- ═══ Progress ═══ -->
<div id="tab-progress" class="gym-tab-panel hidden">

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

<!-- ═══ Track ═══ -->
<div id="tab-track" class="gym-tab-panel hidden">
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

  <div style="font-size:.9375rem;font-weight:600;margin-bottom:.75rem">Full workout history</div>
  <div class="card" id="logListFull">
    <?php if (empty($recentLogs)): ?>
      <div class="empty-state">
        <div class="empty-state-icon"><i class="fas fa-dumbbell"></i></div>
        <div class="empty-state-title">No workouts logged yet</div>
        <p>Log a workout to start tracking your progress.</p>
      </div>
    <?php else: foreach ($recentLogs as $l): ?>
      <div class="todo-row" id="log-full-<?= $l['id'] ?>">
        <div style="width:36px;height:36px;border-radius:.5rem;background:var(--accent-bg);display:flex;align-items:center;justify-content:center;flex-shrink:0;color:var(--accent)">
          <i class="fas fa-dumbbell" style="font-size:.8125rem"></i>
        </div>
        <div style="flex:1;min-width:0">
          <span class="todo-title"><?= h($l['exercise_name']) ?></span>
          <div class="todo-meta">
            <?php
              $parts = [];
              if ($l['sets'])     $parts[] = $l['sets'] . ' sets';
              if ($l['reps'])     $parts[] = $l['reps'] . ' reps';
              if ($l['weight_kg']) $parts[] = $l['weight_kg'] . ' kg';
              echo h(implode(' · ', $parts));
            ?>
            <span style="color:var(--subtle)"><?= formatDate($l['log_date']) ?></span>
          </div>
        </div>
        <button aria-label="Delete workout log" class="btn btn-icon btn-ghost btn-sm" onclick="deleteLog(<?= $l['id'] ?>, true)" title="Delete">
          <i class="fas fa-trash" style="font-size:.75rem"></i>
        </button>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<!-- ═══ Journal ═══ -->
<div id="tab-journal" class="gym-tab-panel hidden">
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

<!-- ═══ Learn ═══ -->
<div id="tab-learn" class="gym-tab-panel hidden">
  <div class="grid-cards">
    <?php
    $gymResources = [
      ['title' => 'Starting Strength basics',      'desc' => 'Barbell fundamentals for beginners', 'icon' => 'fa-book'],
      ['title' => 'Progressive overload explained', 'desc' => 'How to keep making gains over time', 'icon' => 'fa-chart-line'],
      ['title' => 'Form check checklist',           'desc' => 'Common mistakes on the big lifts',   'icon' => 'fa-clipboard-check'],
      ['title' => 'Recovery & sleep basics',        'desc' => 'Why rest days actually build muscle', 'icon' => 'fa-bed'],
    ];
    foreach ($gymResources as $r): ?>
      <div class="habit-card" style="opacity:.85">
        <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.5rem">
          <i class="fas <?= $r['icon'] ?>" style="color:var(--accent);font-size:1.125rem"></i>
          <div style="font-weight:600;font-size:.9375rem;color:var(--text)"><?= h($r['title']) ?></div>
        </div>
        <p style="font-size:.8125rem;color:var(--muted);margin:0"><?= h($r['desc']) ?></p>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="form-hint" style="margin-top:1rem">Starter curated list — real course/book links go here once you decide on sources.</p>
</div>

<!-- ═══ AI Coach ═══ -->
<div id="tab-ai" class="gym-tab-panel hidden">
  <div class="card card-body" style="text-align:center;padding:2.5rem 1.5rem">
    <i class="fas fa-robot" style="font-size:2rem;color:var(--muted);margin-bottom:.75rem"></i>
    <div style="font-weight:600;font-size:1rem;color:var(--text);margin-bottom:.375rem">AI Coach — Coming soon</div>
    <p style="font-size:.875rem;color:var(--muted);max-width:420px;margin:0 auto">
      This will analyze your workout history and give real, data-grounded feedback here. The backend is already built —
      it's just switched off for now.
    </p>
  </div>
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
const gymTabLoaded = { library: false, progress: false };
function switchGymTab(tab) {
  document.querySelectorAll('#gymTabs .filter-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
  document.querySelectorAll('.gym-tab-panel').forEach(p => p.classList.toggle('hidden', p.id !== `tab-${tab}`));
  if (tab === 'library' && !gymTabLoaded.library) { gymTabLoaded.library = true; searchExercises(); checkVideoBanner(); }
  if (tab === 'progress' && !gymTabLoaded.progress) { gymTabLoaded.progress = true; loadProgressTab(); loadBodyStats(); }
}
document.getElementById('gymTabs').addEventListener('click', e => {
  const btn = e.target.closest('[data-tab]');
  if (btn) switchGymTab(btn.dataset.tab);
});

function notifyNewAchievements(keys) {
  if (!keys || !keys.length) return;
  keys.forEach(k => Trackie.Toast.success(`🏆 Achievement unlocked! Check the Track tab.`, 5000));
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

/* ── Workout session — full-screen splash with set tracking + rest breaks ──
   State machine: 'exercise' (log sets one at a time) → 'rest' (countdown
   between exercises) → next exercise, or 'summary' when the plan is done.
   Each completed set is logged to the server immediately (session_log_set)
   instead of batched at the end of the exercise — that's what makes real
   per-set history, "previous performance", and live PR detection possible. */
const REST_SECONDS = 60;
const GYM_REST_LOTTIE_SRC = ''; // paste a .json Lottie animation URL here for the break screen
let session = null;

(function setupRestLottie() {
  const el = document.getElementById('restLottie');
  if (!GYM_REST_LOTTIE_SRC || !el) return;
  el.addEventListener('error', () => el.classList.add('hidden'));
  el.setAttribute('autoplay', '');
  el.setAttribute('src', GYM_REST_LOTTIE_SRC);
  el.classList.remove('hidden');
  document.getElementById('restRing')?.classList.add('has-lottie');
})();

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
      exercises: planRes.items.map(i => ({ name: i.exercise_name, targetSets: i.target_sets, targetReps: i.target_reps, targetWeight: i.target_weight })),
      index: 0, currentSet: 1, lastReps: null, lastWeight: null,
      loggedExercises: new Set(), newAchievements: [],
      elapsedSec: 0, paused: false, timerHandle: null, restHandle: null,
    };
    document.getElementById('sessionPlanName').textContent = planName;
    document.body.style.overflow = 'hidden';
    document.getElementById('workoutSession').classList.remove('hidden');
    showPhase('exercise');
    renderExercisePhase();
    startSessionTimer();
  } catch { Trackie.Toast.error('Network error.'); }
}

function showPhase(phase) {
  document.getElementById('woExercisePhase').classList.toggle('hidden', phase !== 'exercise');
  document.getElementById('woRestPhase').classList.toggle('hidden', phase !== 'rest');
  document.getElementById('woSummaryPhase').classList.toggle('hidden', phase !== 'summary');
}

function renderExercisePhase() {
  const ex = session.exercises[session.index];
  session.currentSet = 1;
  // lastReps/lastWeight pre-fill the NEXT SET of the SAME exercise with what
  // was just typed. Reset per exercise so one exercise's numbers never leak
  // into the next exercise's default (a fixed data-correctness bug).
  session.lastReps = null;
  session.lastWeight = null;
  updateOverallProgress();
  document.getElementById('sessionExerciseName').textContent = ex.name;
  document.getElementById('sessionPrBanner').classList.add('hidden');
  renderSetState(ex);
  loadPreviousPerformance(ex.name);
}

async function loadPreviousPerformance(exerciseName) {
  const el = document.getElementById('sessionPrevPerf');
  const vid = document.getElementById('sessionExerciseVideo');
  el.classList.add('hidden');
  vid.classList.add('hidden');
  vid.removeAttribute('src');
  try {
    const res = await Trackie.API.post(`${API_BASE}/gym.php`, {action:'previous_performance', exercise_name: exerciseName});
    if (!res.success) return;

    if (res.video_url) {
      vid.src = res.video_url;
      vid.classList.remove('hidden');
      vid.play().catch(() => {}); // autoplay can be blocked silently — controls still work
    }

    if (!res.found || !res.sets.length) return;
    const best = res.sets.reduce((a, b) => ((b.weight_kg ?? 0) > (a.weight_kg ?? 0) ? b : a));
    const parts = [];
    if (best.weight_kg) parts.push(`${best.weight_kg} kg`);
    if (best.reps) parts.push(`${best.reps} reps`);
    if (!parts.length) return;
    el.innerHTML = `<i class="fas fa-clock-rotate-left"></i> Last time: ${parts.join(' × ')} (${res.sets.length} set${res.sets.length===1?'':'s'})`;
    el.classList.remove('hidden');
  } catch { /* purely informational — never block the workout on this */ }
}

function renderSetState(ex) {
  document.getElementById('sessionSetIndicator').textContent = `Set ${session.currentSet} of ${ex.targetSets} · target ${ex.targetReps} reps`;
  document.getElementById('sessReps').value = session.lastReps ?? ex.targetReps;
  document.getElementById('sessWeight').value = session.lastWeight ?? (ex.targetWeight ?? '');
  document.getElementById('sessionSetDots').innerHTML = Array.from({length: ex.targetSets}, (_, i) =>
    `<span class="wo-dot ${i < session.currentSet - 1 ? 'done' : (i === session.currentSet - 1 ? 'active' : '')}"></span>`
  ).join('');
}

function updateOverallProgress() {
  document.getElementById('sessionProgress').textContent = `Exercise ${session.index + 1} of ${session.exercises.length}`;
  document.getElementById('sessionProgressBar').style.width = Math.round((session.index / session.exercises.length) * 100) + '%';
}

function startSessionTimer() {
  clearInterval(session.timerHandle);
  session.timerHandle = setInterval(() => {
    if (session.paused) return;
    session.elapsedSec++;
    const m = String(Math.floor(session.elapsedSec / 60)).padStart(2, '0');
    const s = String(session.elapsedSec % 60).padStart(2, '0');
    document.getElementById('sessionElapsed').textContent = `${m}:${s}`;
  }, 1000);
}

async function completeSet() {
  const ex = session.exercises[session.index];
  const reps   = document.getElementById('sessReps').value;
  const weight = document.getElementById('sessWeight').value;
  session.lastReps = reps;
  session.lastWeight = weight;
  const setNumber = session.currentSet;

  try {
    const res = await Trackie.API.post(`${API_BASE}/gym.php`, {
      action: 'session_log_set',
      session_id: session.sessionId,
      exercise_name: ex.name,
      set_number: setNumber,
      reps, weight,
      plan_id: session.planId,
    });
    if (!res.success) { Trackie.Toast.error(res.error || 'Failed to log that set.'); return; }
    session.loggedExercises.add(ex.name);
    if (res.newAchievements?.length) session.newAchievements.push(...res.newAchievements);

    const banner = document.getElementById('sessionPrBanner');
    if (res.is_pr && res.previous_best !== null) {
      banner.classList.remove('hidden');
    } else {
      banner.classList.add('hidden');
    }
  } catch { Trackie.Toast.error('Network error — that set was not saved.'); return; }

  if (session.currentSet < ex.targetSets) {
    session.currentSet++;
    renderSetState(ex);
    return;
  }
  advanceSession();
}

function finishExerciseEarly() {
  advanceSession();
}

function skipSessionExercise() {
  advanceSession();
}

function advanceSession() {
  session.index++;
  if (session.index >= session.exercises.length) { showSummary(); return; }
  startRest();
}

function startRest() {
  showPhase('rest');
  session.restRemaining = REST_SECONDS;
  session.restPaused = false;
  document.getElementById('nextExerciseName').textContent = session.exercises[session.index].name;
  document.getElementById('restPauseBtn').innerHTML = '<i class="fas fa-pause"></i> Pause';
  renderRestTime();
  clearInterval(session.restHandle);
  session.restHandle = setInterval(() => {
    if (session.restPaused) return;
    session.restRemaining--;
    renderRestTime();
    if (session.restRemaining <= 0) { clearInterval(session.restHandle); endRest(); }
  }, 1000);
}
function renderRestTime() {
  const m = String(Math.floor(session.restRemaining / 60)).padStart(2, '0');
  const s = String(session.restRemaining % 60).padStart(2, '0');
  document.getElementById('restTimeDisplay').textContent = `${m}:${s}`;
}
function toggleRestPause() {
  session.restPaused = !session.restPaused;
  document.getElementById('restPauseBtn').innerHTML = session.restPaused
    ? '<i class="fas fa-play"></i> Resume'
    : '<i class="fas fa-pause"></i> Pause';
}
function skipRest() { clearInterval(session.restHandle); endRest(); }
function endRest() {
  showPhase('exercise');
  renderExercisePhase();
}

async function showSummary() {
  clearInterval(session.restHandle);
  clearInterval(session.timerHandle);
  showPhase('summary');
  updateOverallProgress();

  // Server-authoritative counts/duration — the client-side elapsed timer can
  // drift if the tab was backgrounded, so ask the API for the real numbers
  // (this also awards session XP + runs the achievement check, once).
  let exerciseCount = session.loggedExercises.size;
  let mins = Math.round(session.elapsedSec / 60);
  try {
    const res = await Trackie.API.post(`${API_BASE}/gym.php`, {action:'session_complete', session_id: session.sessionId});
    if (res.success) {
      exerciseCount = res.exercise_count;
      mins = Math.round(res.duration_sec / 60);
      if (res.xp) session.xpGained = res.xp.gained;
      if (res.newAchievements?.length) session.newAchievements.push(...res.newAchievements);
      if (res.streak !== null && res.streak !== undefined) {
        const streakEl = document.getElementById('summaryStreak');
        streakEl.innerHTML = `<i class="fas fa-fire"></i> ${res.streak} Day Streak`;
        streakEl.style.display = 'block';
      }
    }
  } catch { /* fall back to client-side numbers below */ }

  document.getElementById('summaryStats').innerHTML =
    `<strong>${exerciseCount}</strong> exercise${exerciseCount===1?'':'s'} logged · <strong>${mins}</strong> min` +
    (session.xpGained ? ` · <strong>+${session.xpGained}</strong> XP` : '');
}
async function closeSummary() {
  document.body.style.overflow = '';
  document.getElementById('workoutSession').classList.add('hidden');
  Trackie.Toast.success(`${session.planName} complete! 💪`);
  notifyNewAchievements(session.newAchievements);
  session = null;
  await Trackie.refreshFragments(['gymStatsWrap', 'gymStreakBadge', 'gymWeeklyPlanWrap', 'gymProgressPreviewWrap', 'gymDailyGoalWrap', 'gymPlansWrap', 'logList', 'logListFull', 'gymAchievementsWrap']);
}

async function quitSession() {
  const hasLogs = session.loggedExercises.size > 0;
  const ok = hasLogs
    ? await Trackie.confirmDialog(`End this workout? ${session.loggedExercises.size} exercise${session.loggedExercises.size===1?'':'s'} already logged will be kept.`, {confirmText:'End workout'})
    : await Trackie.confirmDialog('Quit this workout? Nothing has been logged yet.', {confirmText:'Quit', danger:true});
  if (!ok) return;
  clearInterval(session.timerHandle);
  clearInterval(session.restHandle);
  document.body.style.overflow = '';
  document.getElementById('workoutSession').classList.add('hidden');
  const sessionId = session.sessionId;
  const newAch = session.newAchievements;
  session = null;
  try {
    await Trackie.API.post(`${API_BASE}/gym.php`, {action:'session_cancel', session_id: sessionId});
  } catch {}
  if (hasLogs) {
    Trackie.Toast.info('Workout ended — progress kept.');
    notifyNewAchievements(newAch);
    await Trackie.refreshFragments(['gymStatsWrap', 'gymStreakBadge', 'gymWeeklyPlanWrap', 'gymProgressPreviewWrap', 'gymDailyGoalWrap', 'gymPlansWrap', 'logList', 'logListFull', 'gymAchievementsWrap']);
  } else {
    Trackie.Toast.info('Workout cancelled.');
  }
}

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
      await Trackie.refreshFragments(['gymStatsWrap', 'gymStreakBadge', 'gymWeeklyPlanWrap', 'gymProgressPreviewWrap', 'gymDailyGoalWrap', 'gymPlansWrap', 'logList', 'logListFull', 'gymAchievementsWrap']);
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

/* ── Exercise Library ─────────────────────────────────────────── */
let libSearchDebounce = null;
function exerciseCardHtml(ex) {
  const tags = [ex.muscle_group, ex.equipment].filter(Boolean);
  const safeName = ex.name.replace(/'/g, "\\'");
  return `
    <div class="habit-card gym-ex-card">
      ${ex.video_path ? `
        <button class="gym-ex-video-thumb" onclick="watchExerciseVideo('${safeName}', '${API_BASE.replace('/api','')}/${ex.video_path}')" aria-label="Watch ${ex.name} demo">
          <i class="fas fa-circle-play"></i>
        </button>` : ''}
      <div class="gym-ex-card-head">
        <span class="gym-ex-name">${ex.name}</span>
        <button class="btn btn-icon btn-ghost btn-sm" aria-label="Log ${ex.name}" title="Log a set"
                onclick="openLogWorkout('${safeName}')">
          <i class="fas fa-plus"></i>
        </button>
      </div>
      <div class="gym-ex-tags">${tags.map(t => `<span class="gym-ex-tag">${t}</span>`).join('')}</div>
    </div>`;
}
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
async function searchExercises() {
  const wrap = document.getElementById('libResults');
  wrap.innerHTML = '<p class="form-hint">Searching…</p>';
  try {
    const res = await Trackie.API.post(`${API_BASE}/gym.php`, {
      action: 'exercise_search',
      q: document.getElementById('libSearch').value.trim(),
      muscle: document.getElementById('libMuscle').value,
      equipment: document.getElementById('libEquipment').value,
    });
    if (!res.success || !res.exercises.length) {
      wrap.innerHTML = '<div class="card"><div class="empty-state"><div class="empty-state-icon"><i class="fas fa-search"></i></div><div class="empty-state-title">No exercises found</div><p>Try a different search, or add it as a custom exercise.</p></div></div>';
      return;
    }
    wrap.innerHTML = res.exercises.map(exerciseCardHtml).join('');
  } catch { wrap.innerHTML = '<p class="form-hint">Network error.</p>'; }
}
['libSearch'].forEach(id => document.getElementById(id)?.addEventListener('input', () => {
  clearTimeout(libSearchDebounce);
  libSearchDebounce = setTimeout(searchExercises, 300);
}));
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
  select.innerHTML = '<option value="">— choose one —</option>' +
    videoAssignAllExercises.map(ex => `<option value="${ex.id}">${ex.name}${ex.muscle_group ? ' (' + ex.muscle_group + ')' : ''}</option>`).join('');

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
      <span class="qstat-label"><i class="fas fa-trophy" style="color:#f59e0b"></i> ${r.exercise_name}</span>
      <span class="qstat-value">${r.weight_kg} kg${r.reps ? ` × ${r.reps}` : ''}</span>
    </div>`).join('');
}

/* ── Body stats (optional) ───────────────────────────────────────── */
async function loadBodyStats() {
  const wrap = document.getElementById('bodyStatsList');
  try {
    const res = await Trackie.API.post(`${API_BASE}/gym.php`, {action:'body_stats_list'});
    if (!res.success || !res.entries.length) {
      wrap.innerHTML = '<p class="form-hint">No entries yet — log your weight above to start a trend.</p>';
      return;
    }
    wrap.innerHTML = res.entries.map(e => `
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
