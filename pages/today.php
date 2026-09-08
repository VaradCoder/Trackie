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
     WHERE user_id=? AND due_date=? AND deleted_at IS NULL",
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
    "SELECT h.id, h.name, h.color,
            MAX(CASE WHEN l.date_completed=? THEN 1 ELSE 0 END) AS logged_today
     FROM habits h
     LEFT JOIN logs l ON l.habit_id=h.id
     WHERE h.user_id=?
     GROUP BY h.id
     ORDER BY logged_today ASC, h.name ASC",
    [$today, $uid]
);
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

// ── Score / streak strip ──────────────────────────────────────────
$xpData    = function_exists('xpSummary')   ? xpSummary($uid)   : ['level' => 1, 'title' => 'Beginner'];
$scoreData = function_exists('trackieScore') ? trackieScore($uid) : ['score' => 0, 'has_data' => false];
$logDates  = array_column(
    fetchAll("SELECT DISTINCT l.date_completed FROM logs l JOIN habits h ON h.id=l.habit_id WHERE h.user_id=?", [$uid]),
    'date_completed'
);
$streakData = calculateStreaks($logDates, function_exists('userNeutralDates') ? userNeutralDates($uid) : []);

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<?= renderPageHeader($greeting . ', ' . h($userName), [
  'icon' => 'fa-sun',
  'sub'  => date('l, F j'),
]) ?>

<div class="grid-stats" id="todayStatsWrap" style="margin-bottom:var(--sp-4)">
  <?= renderStatCard($priDone . '/' . $priTotal, 'Priorities done', 'fa-list-check', 'var(--accent)') ?>
  <?= renderStatCard($habitsDone . '/' . count($habits), 'Habits done', 'fa-heart', 'var(--ok)') ?>
  <?= renderStatCard($streakData['current'] . 'd', 'Streak', 'fa-fire', '#f59e0b') ?>
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
  </div>
</div>

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
  const res = await Trackie.API.post(endpoint, params);
  if (res && res.success) {
    if (res.achievements?.length) Trackie.showAchievementToasts(res.achievements);
    Trackie.refreshFragments(['todayPriorities', 'todayStatsWrap']);
  } else {
    Trackie.Toast.error('Could not update task');
  }
}

async function toggleTodayHabit(id, completed) {
  const base = document.querySelector('meta[name="app-base"]')?.content || '';
  const res = await Trackie.API.post(base + '/api/habits.php', {
    action: 'log', habit_id: id, date: '<?= $today ?>', status: completed ? 'done' : 'skip',
  });
  if (res && res.success) {
    if (res.achievements?.length) Trackie.showAchievementToasts(res.achievements);
    Trackie.refreshFragments(['todayHabits', 'todayStatsWrap']);
  } else {
    Trackie.Toast.error('Could not update habit');
  }
}
</script>

</div>
<?php include '../includes/footer.php'; ?>
