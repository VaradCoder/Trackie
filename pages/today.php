<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
if (is_file(__DIR__ . '/../includes/gamification.php')) {
    require_once __DIR__ . '/../includes/gamification.php';
}

requireAuth();

$uid         = currentUserId();
resetRecurringTodos($uid);
$pageTitle   = 'Today';
$currentPage = 'today';
$today       = date('Y-m-d');
$hour        = (int)date('G');
$greeting    = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$userName    = $_SESSION['user_name'] ?? 'there';

// ── Priorities: todos + study tasks due today, unified & priority-sorted ──
$todos = fetchAll(
    "SELECT id, title, priority, completed FROM todos
     WHERE user_id=? AND due_date=? AND deleted_at IS NULL AND parent_id IS NULL",
    [$uid, $today]
);
foreach ($todos as &$t) { $t['source'] = 'todo'; }
unset($t);

$studies = fetchAll(
    "SELECT id, title, priority, completed FROM study_plan
     WHERE user_id=? AND due_date=?",
    [$uid, $today]
);
foreach ($studies as &$t) { $t['source'] = 'study'; }
unset($t);

$priorities = array_merge($todos, $studies);
usort($priorities, function ($a, $b) {
    if ($a['completed'] !== $b['completed']) return $a['completed'] <=> $b['completed'];
    $order = ['high' => 0, 'medium' => 1, 'low' => 2];
    return ($order[$a['priority']] ?? 1) <=> ($order[$b['priority']] ?? 1);
});
$priTotal = count($priorities);
$priDone  = count(array_filter($priorities, fn($t) => (bool)$t['completed']));

// ── Habits due today ─────────────────────────────────────────────
$habits = fetchAll(
    "SELECT h.id, h.name, h.color, h.frequency, h.schedule_days,
            MAX(CASE WHEN l.date_completed=? THEN 1 ELSE 0 END) AS logged_today
     FROM habits h
     LEFT JOIN logs l ON l.habit_id=h.id
     WHERE h.user_id=?
     GROUP BY h.id
     ORDER BY logged_today ASC, h.name ASC",
    [$today, $uid]
);
// Only habits scheduled for today; the rest are listed as "not due".
require_once '../includes/habit_schedule.php';
$weekStart = userSetting($uid, 'week_start');
$hLogs = [];
foreach (fetchAll("SELECT l.habit_id, l.date_completed d FROM logs l JOIN habits h ON h.id=l.habit_id
                   WHERE h.user_id=? AND l.date_completed >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)", [$uid]) as $r) $hLogs[$r['habit_id']][] = $r['d'];
$notDue = [];
$habits = array_values(array_filter($habits, function ($h) use ($today, $hLogs, $weekStart, &$notDue) {
    if ($h['logged_today'] || habitDueOn($h, $today, $hLogs[$h['id']] ?? [], $weekStart)) return true;
    $notDue[] = $h;
    return false;
}));
$habitsDone = count(array_filter($habits, fn($h) => (bool)$h['logged_today']));

// ── Goal snapshot: nearest deadline / most recently moved, not complete ──
$goals = fetchAll(
    "SELECT id, goal_name, progress, target_value, deadline
     FROM goals
     WHERE user_id=? AND progress < target_value
     ORDER BY (deadline IS NULL), deadline ASC
     LIMIT 2",
    [$uid]
);

// ── Routines due today (specific weekdays honoured) ───────────────
$routinesToday = [];
if (tableExists('routines')) {
    $rows = tableExists('routine_logs')
        ? fetchAll("SELECT r.id, r.title, r.time_slot, r.schedule_days, r.category, (rl.id IS NOT NULL) AS done_today
                      FROM routines r
                      LEFT JOIN routine_logs rl ON rl.routine_id = r.id AND rl.log_date = ? AND rl.user_id = r.user_id
                     WHERE r.user_id = ? ORDER BY r.time_slot", [$today, $uid])
        : [];
    $dow = (int)date('w');
    foreach ($rows as $r) {
        $days = habitDays(['frequency' => 'daily', 'schedule_days' => $r['schedule_days'] ?? null]);
        if (!$days || in_array($dow, $days, true)) $routinesToday[] = $r;
    }
}

// ── Reminders still to come today ────────────────────────────────
$remindersToday = tableExists('reminders')
    ? fetchAll("SELECT id, title, next_fire_at FROM reminders
                 WHERE user_id = ? AND active = 1 AND next_fire_at BETWEEN NOW() AND CONCAT(CURDATE(), ' 23:59:59')
                 ORDER BY next_fire_at LIMIT 6", [$uid])
    : [];

// ── Score / streak strip ──────────────────────────────────────────
$xpData    = function_exists('xpSummary')   ? xpSummary($uid)   : ['level' => 1, 'title' => 'Beginner'];
$scoreData = function_exists('trackieScore') ? trackieScore($uid) : ['score' => 0, 'has_data' => false];
// Trackie streak: any meaningful activity counts (habit, todo, workout, reading…), not just habits.
require_once '../includes/activity.php';
if (activityReady()) {
    $streakData = activityStreak($uid);
} else {
    $logDates   = array_column(fetchAll("SELECT DISTINCT l.date_completed FROM logs l JOIN habits h ON h.id=l.habit_id WHERE h.user_id=?", [$uid]), 'date_completed');
    $streakData = calculateStreaks($logDates, function_exists('userNeutralDates') ? userNeutralDates($uid) : []);
}

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<?= renderPageHeader($greeting . ', ' . $userName, [
  'icon' => 'fa-sun',
  'sub'  => date('l, F j'),
]) ?>

<div class="grid-stats" id="todayStatsWrap" style="margin-bottom:var(--sp-4)">
  <?= renderStatCard($priDone . '/' . $priTotal, 'Priorities done', 'fa-list-check', 'var(--accent)') ?>
  <?= renderStatCard($habitsDone . '/' . count($habits), 'Habits done', 'fa-heart', 'var(--ok)') ?>
  <?= renderStatCard($streakData['current'] . 'd', 'Trackie streak', 'fa-fire', '#f59e0b') ?>
  <?= renderStatCard($scoreData['has_data'] ? (string)$scoreData['score'] : '—', 'Trackie Score', 'fa-gauge-high', 'var(--info)') ?>
</div>

<div class="card card-body" style="margin-bottom:var(--sp-4)">
  <h3 style="font-size:.9375rem;font-weight:600;margin:0 0 var(--sp-3)"><i class="fas fa-list-check" style="color:var(--accent);margin-right:.375rem"></i>Priorities</h3>
  <div id="todayPriorities">
    <?php if (!$priorities): ?>
      <?= renderEmptyState('fa-mug-hot', 'Nothing due today', 'Todos and study tasks due today will show up here.') ?>
    <?php else: foreach ($priorities as $t):
      $col = $t['priority'] === 'high' ? '#ef4444' : ($t['priority'] === 'medium' ? '#f59e0b' : '#3b82f6');
    ?>
      <label class="cal-board-check" style="padding:var(--sp-2) 0;border-bottom:1px solid var(--border)">
        <input type="checkbox" <?= $t['completed'] ? 'checked' : '' ?>
               onchange="toggleTodayItem(<?= (int)$t['id'] ?>, <?= json_encode($t['source']) ?>, this.checked)">
        <span style="border-left:3px solid <?= $col ?>;padding-left:var(--sp-2);flex:1;<?= $t['completed'] ? 'text-decoration:line-through;opacity:.5' : '' ?>">
          <?= h($t['title']) ?>
        </span>
      </label>
    <?php endforeach; endif; ?>
  </div>
</div>

<div class="card card-body" style="margin-bottom:var(--sp-4)">
  <h3 style="font-size:.9375rem;font-weight:600;margin:0 0 var(--sp-3)"><i class="fas fa-heart" style="color:var(--accent);margin-right:.375rem"></i>Habits</h3>
  <div id="todayHabits">
    <?php if (!$habits): ?>
      <?= renderEmptyState('fa-heart', 'No habits yet', 'Habits you track will show up here every day.') ?>
    <?php else: foreach ($habits as $h): ?>
      <label class="cal-board-check" style="padding:var(--sp-2) 0;border-bottom:1px solid var(--border)">
        <input type="checkbox" <?= $h['logged_today'] ? 'checked' : '' ?>
               onchange="toggleTodayHabit(<?= (int)$h['id'] ?>, this.checked)">
        <span style="border-left:3px solid <?= h($h['color'] ?: '#ef4444') ?>;padding-left:var(--sp-2);flex:1;<?= $h['logged_today'] ? 'text-decoration:line-through;opacity:.5' : '' ?>">
          <?= h($h['name']) ?>
        </span>
      </label>
    <?php endforeach; endif; ?>
    <?php if ($notDue): ?>
      <p class="form-hint" style="margin:.625rem 0 0"><i class="fas fa-moon"></i> Not due today: <?= h(implode(', ', array_column($notDue, 'name'))) ?></p>
    <?php endif; ?>
  </div>
</div>

<?php if ($routinesToday): ?>
<div class="card card-body" style="margin-bottom:var(--sp-4)">
  <h3 style="font-size:.9375rem;font-weight:600;margin:0 0 var(--sp-3)"><i class="fas fa-clock" style="color:var(--accent);margin-right:.375rem"></i>Routines</h3>
  <div id="todayRoutines">
    <?php foreach ($routinesToday as $r): ?>
      <label class="cal-board-check" style="padding:var(--sp-2) 0;border-bottom:1px solid var(--border)">
        <input type="checkbox" <?= $r['done_today'] ? 'checked' : '' ?>
               onchange="toggleTodayRoutine(<?= (int)$r['id'] ?>, this)">
        <span style="flex:1;<?= $r['done_today'] ? 'text-decoration:line-through;opacity:.5' : '' ?>">
          <b style="font-weight:600;color:var(--muted);margin-right:.375rem"><?= date('g:i A', strtotime($r['time_slot'])) ?></b><?= h($r['title']) ?>
        </span>
      </label>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($remindersToday): ?>
<div class="card card-body" style="margin-bottom:var(--sp-4)">
  <h3 style="font-size:.9375rem;font-weight:600;margin:0 0 var(--sp-3)"><i class="fas fa-bell" style="color:var(--accent);margin-right:.375rem"></i>Later today</h3>
  <?php foreach ($remindersToday as $r): ?>
    <div style="display:flex;gap:var(--sp-3);padding:var(--sp-2) 0;border-bottom:1px solid var(--border);font-size:.875rem">
      <b style="width:4.5rem;flex-shrink:0;color:var(--muted);font-weight:600"><?= date('g:i A', strtotime($r['next_fire_at'])) ?></b>
      <span><?= h($r['title']) ?></span>
    </div>
  <?php endforeach; ?>
  <a href="<?= APP_BASE ?>/pages/reminders.php" class="btn btn-secondary btn-sm" style="margin-top:var(--sp-3)">All reminders</a>
</div>
<?php endif; ?>

<?php if ($goals): ?>
<div class="card card-body">
  <h3 style="font-size:.9375rem;font-weight:600;margin:0 0 var(--sp-3)"><i class="fas fa-bullseye" style="color:var(--accent);margin-right:.375rem"></i>Goal progress</h3>
  <?php foreach ($goals as $g):
    $pct = $g['target_value'] > 0 ? min(100, (int)round($g['progress'] / $g['target_value'] * 100)) : 0;
  ?>
    <div style="margin-bottom:var(--sp-3)">
      <div style="display:flex;justify-content:space-between;font-size:.8125rem;margin-bottom:.25rem">
        <span><?= h($g['goal_name']) ?></span>
        <span style="color:var(--muted)"><?= $pct ?>%</span>
      </div>
      <div class="progress-track"><div class="progress-fill" style="width:<?= $pct ?>%"></div></div>
    </div>
  <?php endforeach; ?>
  <a href="<?= APP_BASE ?>/pages/goals.php" class="btn btn-secondary btn-sm">View all goals</a>
</div>
<?php endif; ?>

<script>
async function toggleTodayItem(id, source, completed) {
  const base = document.querySelector('meta[name="app-base"]')?.content || '';
  const endpoint = base + (source === 'study' ? '/api/study_plan.php' : '/api/todos.php');
  const params = source === 'study' ? { action: 'toggle', task_id: id, completed: completed ? 1 : 0 }
                                     : { action: 'toggle', todo_id: id, completed: completed ? 1 : 0 };
  const box = event?.target;
  try {
    const res = await Trackie.API.post(endpoint, params);
    if (res && res.success) {
      if (res.achievements?.length) Trackie.showAchievementToasts(res.achievements);
      if (completed && res.xp?.gained) Trackie.Toast.success(`Done · +${res.xp.gained} XP`);
      Trackie.refreshFragments(['todayPriorities', 'todayStatsWrap']);
      return;
    }
    Trackie.Toast.error(res?.error || 'Could not update that task.');
  } catch (e) { Trackie.Toast.error(e.message || 'Could not update that task.'); }
  if (box && 'checked' in box) box.checked = !completed;   // put it back
}

async function toggleTodayHabit(id, completed) {
  const base = document.querySelector('meta[name="app-base"]')?.content || '';
  // Unticking undoes the log. It used to record 'skip', and skipped days are
  // neutral for streaks — so a mis-tap silently protected the streak.
  const box = event?.target;
  try {
    const res = await Trackie.API.post(base + '/api/habits.php', completed
      ? { action: 'log', habit_id: id, date: '<?= $today ?>', status: 'done' }
      : { action: 'unlog', habit_id: id, date: '<?= $today ?>' });
    if (res && res.success) {
      if (res.achievements?.length) Trackie.showAchievementToasts(res.achievements);
      if (completed && res.xp?.gained) Trackie.Toast.success(`Habit logged · +${res.xp.gained} XP`);
      Trackie.refreshFragments(['todayHabits', 'todayStatsWrap']);
      return;
    }
    Trackie.Toast.error(res?.error || 'Could not update that habit.');
  } catch (e) { Trackie.Toast.error(e.message || 'Could not update that habit.'); }
  if (box && 'checked' in box) box.checked = !completed;
}

async function toggleTodayRoutine(id, box) {
  const base = document.querySelector('meta[name="app-base"]')?.content || '';
  const done = box.checked;
  try {
    const res = await Trackie.API.post(base + '/api/routines.php', { action: done ? 'complete' : 'uncomplete', routine_id: id });
    if (res && res.success) {
      if (done && res.xp?.gained) Trackie.Toast.success(`Routine done · +${res.xp.gained} XP`);
      Trackie.refreshFragments(['todayRoutines', 'todayStatsWrap']);
      return;
    }
    Trackie.Toast.error(res?.error || 'Could not update that routine.');
  } catch (e) { Trackie.Toast.error(e.message || 'Could not update that routine.'); }
  box.checked = !done;
}
</script>

</div>
<?php include '../includes/footer.php'; ?>
