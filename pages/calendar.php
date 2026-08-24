<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Calendar';
$currentPage = 'calendar';

// Validate month/year
$month = max(1, min(12, (int)($_GET['month'] ?? date('n'))));
$year  = max(2000, min(2100, (int)($_GET['year'] ?? date('Y'))));

$firstDay     = mktime(0, 0, 0, $month, 1, $year);
$daysInMonth  = (int)date('t', $firstDay);
$startWeekday = (int)date('w', $firstDay);   // 0=Sun
$startDate    = date('Y-m-01', $firstDay);
$endDate      = date('Y-m-t',  $firstDay);
$todayStr     = date('Y-m-d');

// Load todos and study tasks for this month. `id` + `source` are what let
// the Planner-style day cards be acted on directly (toggle complete) instead
// of just displayed — the old calendar only ever showed dots.
$todos = fetchAll(
    "SELECT id, title, due_date, priority, completed
     FROM todos
     WHERE user_id=? AND due_date BETWEEN ? AND ? AND deleted_at IS NULL",
    [$uid, $startDate, $endDate]
);
foreach ($todos as &$t) { $t['source'] = 'todo'; }
unset($t);

$studies = fetchAll(
    "SELECT id, title, due_date, priority, completed
     FROM study_plan
     WHERE user_id=? AND due_date BETWEEN ? AND ?",
    [$uid, $startDate, $endDate]
);
foreach ($studies as &$t) { $t['source'] = 'study'; }
unset($t);

$allTasks = array_merge($todos, $studies);

$tasksByDate = [];
foreach ($allTasks as $t) {
    $tasksByDate[$t['due_date']][] = $t;
}
ksort($tasksByDate);

// Month-level progress summary (Planner's "Charts" view, condensed to a
// stat row — this is a single-user app, no assignee breakdown to show).
$monthTotal     = count($allTasks);
$monthCompleted = count(array_filter($allTasks, fn($t) => (bool)$t['completed']));
$monthOverdue   = count(array_filter($allTasks, fn($t) => !$t['completed'] && $t['due_date'] < $todayStr));
$monthPct       = $monthTotal > 0 ? (int)round($monthCompleted / $monthTotal * 100) : 0;

// Board view buckets — Planner-style grouping, read/act rather than drag
// (there's no meaningful custom bucket order for todos+study tasks to
// persist, so this groups by real due-date urgency instead).
$weekEndStr = date('Y-m-d', strtotime('sunday this week'));
$buckets = ['overdue' => [], 'today' => [], 'this_week' => [], 'later' => [], 'done' => []];
foreach ($allTasks as $t) {
    if ($t['completed'])                                  { $buckets['done'][] = $t; continue; }
    if ($t['due_date'] < $todayStr)                        { $buckets['overdue'][] = $t; continue; }
    if ($t['due_date'] === $todayStr)                      { $buckets['today'][] = $t; continue; }
    if ($t['due_date'] <= $weekEndStr)                     { $buckets['this_week'][] = $t; continue; }
    $buckets['later'][] = $t;
}
$bucketMeta = [
    'overdue'   => ['label' => 'Overdue',    'icon' => 'fa-triangle-exclamation', 'color' => 'var(--accent)'],
    'today'     => ['label' => 'Today',      'icon' => 'fa-star',                 'color' => '#f59e0b'],
    'this_week' => ['label' => 'This Week',  'icon' => 'fa-calendar-week',        'color' => 'var(--info)'],
    'later'     => ['label' => 'Later',      'icon' => 'fa-clock',                'color' => 'var(--muted)'],
    'done'      => ['label' => 'Done',       'icon' => 'fa-circle-check',         'color' => 'var(--ok)'],
];

// Prev / next navigation
$prevMonth = $month - 1 < 1  ? 12 : $month - 1;
$prevYear  = $month - 1 < 1  ? $year - 1 : $year;
$nextMonth = $month + 1 > 12 ? 1  : $month + 1;
$nextYear  = $month + 1 > 12 ? $year + 1 : $year;

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<?= renderPageHeader(date('F Y', $firstDay), [
  'icon'    => 'fa-calendar',
  'sub'     => 'Todos and study tasks due this month.',
  'actions' =>
      '<a href="?month=' . $prevMonth . '&year=' . $prevYear . '" class="btn btn-icon btn-secondary btn-sm" aria-label="Previous month"><i class="fas fa-chevron-left"></i></a>'
    . '<a href="?month=' . date('n') . '&year=' . date('Y') . '" class="btn btn-secondary btn-sm">Today</a>'
    . '<a href="?month=' . $nextMonth . '&year=' . $nextYear . '" class="btn btn-icon btn-secondary btn-sm" aria-label="Next month"><i class="fas fa-chevron-right"></i></a>',
]) ?>

<!-- Month progress stat row -->
<div class="grid-stats" style="margin-bottom:var(--sp-4)">
  <?= renderStatCard((string)$monthTotal, 'Total tasks', 'fa-list-check', 'var(--info)') ?>
  <?= renderStatCard((string)$monthCompleted, 'Completed', 'fa-circle-check', 'var(--ok)') ?>
  <?= renderStatCard((string)$monthOverdue, 'Overdue', 'fa-triangle-exclamation', 'var(--accent)') ?>
  <?= renderStatCard($monthPct . '%', 'Progress', 'fa-chart-line', 'var(--accent)') ?>
</div>

<!-- View toggle: Schedule (calendar grid) vs Board (Planner buckets) -->
<div class="filter-tabs" style="margin-bottom:var(--sp-4)" role="tablist">
  <button class="filter-tab active" data-view="schedule" onclick="switchCalView('schedule')" role="tab" aria-selected="true">
    <i class="fas fa-calendar-days"></i> Schedule
  </button>
  <button class="filter-tab" data-view="board" onclick="switchCalView('board')" role="tab" aria-selected="false">
    <i class="fas fa-table-columns"></i> Board
  </button>
</div>

<!-- Schedule view -->
<div id="calScheduleView">
  <div class="card card-body">
    <div class="cal-grid">
      <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $d): ?>
        <div class="cal-day-name"><?= $d ?></div>
      <?php endforeach; ?>

      <!-- Empty cells before month start -->
      <?php for ($i = 0; $i < $startWeekday; $i++): ?>
        <div></div>
      <?php endfor; ?>

      <!-- Day cells -->
      <?php for ($day = 1; $day <= $daysInMonth; $day++):
        $dateStr  = date('Y-m-d', mktime(0,0,0,$month,$day,$year));
        $isToday  = $dateStr === $todayStr;
        $dayTasks = $tasksByDate[$dateStr] ?? [];
        $hasTasks = !empty($dayTasks);
      ?>
        <div class="cal-day cal-day-planner <?= $isToday ? 'today' : '' ?> <?= $hasTasks ? 'has-tasks' : '' ?>"
             onclick='openDayDetail(<?= json_encode($dateStr) ?>)'
             role="button" tabindex="0">
          <span class="cal-day-num" style="font-weight:<?= $isToday?'700':'500' ?>"><?= $day ?></span>
          <?php if ($hasTasks): ?>
            <div class="cal-day-chips">
              <?php
              $shown = 0;
              foreach ($dayTasks as $t) {
                  if ($shown >= 2) { echo '<div class="cal-day-more">+'.(count($dayTasks)-2).' more</div>'; break; }
                  $col = $t['priority']==='high' ? '#ef4444' : ($t['priority']==='medium' ? '#f59e0b' : '#3b82f6');
                  $done = $t['completed'] ? 'style="opacity:.5;text-decoration:line-through"' : '';
                  echo '<div class="cal-task-chip" style="border-left-color:'.$col.'" '.$done.' title="'.h($t['title']).'">'.h($t['title']).'</div>';
                  $shown++;
              }
              ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endfor; ?>
    </div>
  </div>
</div>

<!-- Board view: Planner-style urgency buckets -->
<div id="calBoardView" style="display:none">
  <div class="cal-board">
    <?php foreach ($bucketMeta as $key => $meta): $items = $buckets[$key]; ?>
      <div class="cal-board-col">
        <div class="cal-board-col-head">
          <span style="color:<?= $meta['color'] ?>"><i class="fas <?= $meta['icon'] ?>"></i></span>
          <span class="cal-board-col-title"><?= h($meta['label']) ?></span>
          <span class="cal-board-col-count"><?= count($items) ?></span>
        </div>
        <div class="cal-board-col-body">
          <?php if (empty($items)): ?>
            <div class="cal-board-empty">Nothing here</div>
          <?php else: foreach ($items as $t): ?>
            <div class="cal-board-card" data-id="<?= (int)$t['id'] ?>" data-source="<?= h($t['source']) ?>">
              <label class="cal-board-check">
                <input type="checkbox" <?= $t['completed'] ? 'checked' : '' ?>
                       onchange="toggleCalendarTask(<?= (int)$t['id'] ?>, <?= json_encode($t['source']) ?>, this.checked)">
                <span class="cal-board-card-title" <?= $t['completed'] ? 'style="text-decoration:line-through;opacity:.5"' : '' ?>><?= h($t['title']) ?></span>
              </label>
              <div class="cal-board-card-meta">
                <span class="cal-badge cal-badge-<?= h($t['priority']) ?>"><?= h(ucfirst($t['priority'])) ?></span>
                <span><?= date('M j', strtotime($t['due_date'])) ?></span>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Day detail modal -->
<div class="modal-backdrop hidden" id="dayDetailModal" onclick="if(event.target===this) closeDayDetail()">
  <div class="modal-box" style="max-width:420px">
    <div class="modal-header">
      <span class="modal-title" id="dayDetailTitle">Tasks</span>
      <button class="btn-icon btn-secondary btn-sm" onclick="closeDayDetail()" aria-label="Close"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="modal-body" id="dayDetailBody"></div>
  </div>
</div>

<script>
const CAL_TASKS_BY_DATE = <?= json_encode($tasksByDate) ?>;

function switchCalView(view) {
  document.getElementById('calScheduleView').style.display = view === 'schedule' ? '' : 'none';
  document.getElementById('calBoardView').style.display = view === 'board' ? '' : 'none';
  document.querySelectorAll('.filter-tab').forEach(btn => {
    const active = btn.dataset.view === view;
    btn.classList.toggle('active', active);
    btn.setAttribute('aria-selected', active ? 'true' : 'false');
  });
}

function openDayDetail(dateStr) {
  const tasks = CAL_TASKS_BY_DATE[dateStr] || [];
  const d = new Date(dateStr + 'T00:00:00');
  document.getElementById('dayDetailTitle').textContent = d.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' });
  const body = document.getElementById('dayDetailBody');
  if (!tasks.length) {
    body.innerHTML = '<p style="color:var(--muted);font-size:.875rem;padding:var(--sp-3) 0">No tasks due this day.</p>';
  } else {
    body.innerHTML = tasks.map(t => {
      const col = t.priority === 'high' ? '#ef4444' : (t.priority === 'medium' ? '#f59e0b' : '#3b82f6');
      const doneStyle = t.completed ? 'text-decoration:line-through;opacity:.5' : '';
      return `<label class="cal-board-check" style="padding:var(--sp-2) 0;border-bottom:1px solid var(--border)">
        <input type="checkbox" ${t.completed ? 'checked' : ''} onchange="toggleCalendarTask(${t.id}, '${t.source}', this.checked)">
        <span style="border-left:3px solid ${col};padding-left:var(--sp-2);flex:1;${doneStyle}">${t.title}</span>
      </label>`;
    }).join('');
  }
  document.getElementById('dayDetailModal').classList.remove('hidden');
}
function closeDayDetail() { document.getElementById('dayDetailModal').classList.add('hidden'); }

async function toggleCalendarTask(id, source, completed) {
  const base = document.querySelector('meta[name="app-base"]')?.content || '';
  const endpoint = base + (source === 'study' ? '/api/study_plan.php' : '/api/todos.php');
  const params = source === 'study' ? { action: 'toggle', task_id: id, completed: completed ? 1 : 0 }
                                     : { action: 'toggle', todo_id: id, completed: completed ? 1 : 0 };
  const res = await Trackie.API.post(endpoint, params);
  if (res && res.success) {
    refreshFragments(['page-main']);
  } else {
    Trackie.Toast.show('Could not update task', 'error');
  }
}
</script>

<?php include '../includes/footer.php'; ?>
