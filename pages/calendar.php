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
require_once '../includes/settings.php';
$weekStart    = userSetting($uid, 'week_start');            // 0 = Sunday, 1 = Monday
// Blank cells before the 1st, counted from the user's first weekday.
$startWeekday = ((int)date('w', $firstDay) - $weekStart + 7) % 7;
$dayNames     = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
if ($weekStart === 1) $dayNames = array_merge(array_slice($dayNames, 1), ['Sun']);
$startDate    = date('Y-m-01', $firstDay);
$endDate      = date('Y-m-t',  $firstDay);
$todayStr     = date('Y-m-d');

// Load todos and study tasks for this month. `id` + `source` are what let
// the Planner-style day cards be acted on directly (toggle complete) instead
// of just displayed — the old calendar only ever showed dots.
require_once '../includes/calendar.php';
$events   = calendarEvents($uid, $startDate, $endDate);
// Tasks (todos + study) drive the stats and the Board — they're what you can tick off.
$allTasks = [];
foreach ($events as $e) {
    if (!$e['actionable']) continue;
    $allTasks[] = ['id' => $e['id'], 'title' => $e['title'], 'due_date' => $e['date'], 'priority' => $e['priority'] ?? 'medium',
                   'completed' => $e['done'] ? 1 : 0, 'source' => $e['source']];
}
$tasksByDate = [];
foreach ($events as $e) $tasksByDate[$e['date']][] = $e;
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
// End of the user's week: Saturday when weeks start on Sunday, else Sunday.
$weekEndStr = date('Y-m-d', strtotime($weekStart === 1 ? 'sunday this week' : (date('w') == 6 ? 'today' : 'next saturday')));
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
  'sub'     => 'Everything dated in Trackie — tasks, reminders, deadlines, workouts and more.',
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

<div class="cal-sources" id="calSources">
  <?php foreach (CALENDAR_SOURCES as $k => [$label, $icon, $color]): ?>
    <button type="button" class="cal-src active" data-src="<?= $k ?>" style="--c:<?= $color ?>"><i class="fas <?= $icon ?>"></i> <?= $label ?></button>
  <?php endforeach; ?>
</div>
<!-- Schedule view -->
<div id="calScheduleView">
  <div class="card card-body">
    <div class="cal-grid">
      <?php foreach ($dayNames as $d): ?>
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
              $col = CALENDAR_SOURCES[$t['source']][2] ?? '#64748b';
              if ($t['actionable']) $col = $t['priority']==='high' ? '#ef4444' : ($t['priority']==='medium' ? '#f59e0b' : '#3b82f6');
              $done = $t['done'] ? 'style="opacity:.5;text-decoration:line-through"' : '';
              echo '<div class="cal-task-chip" data-src="'.h($t['source']).'" style="border-left-color:'.$col.'" '.$done.' title="'.h($t['title']).'">'
                 .($t['time'] ? '<b>'.h($t['time']).'</b> ' : '').h($t['title']).'</div>';
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
const CAL_TASKS_BY_DATE = <?= json_encode($tasksByDate, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function switchCalView(view) {
  document.getElementById('calScheduleView').style.display = view === 'schedule' ? '' : 'none';
  document.getElementById('calBoardView').style.display = view === 'board' ? '' : 'none';
  document.querySelectorAll('.filter-tab').forEach(btn => {
    const active = btn.dataset.view === view;
    btn.classList.toggle('active', active);
    btn.setAttribute('aria-selected', active ? 'true' : 'false');
  });
}

const CAL_SRC = <?= json_encode(array_map(fn($v) => ['label' => $v[0], 'icon' => $v[1], 'color' => $v[2]], CALENDAR_SOURCES)) ?>;
let calHidden = new Set();
try { calHidden = new Set(JSON.parse(localStorage.getItem('trackie.calHidden') || '[]')); } catch {}
function applyCalFilters() {
  document.querySelectorAll('#calSources .cal-src').forEach(b => b.classList.toggle('active', !calHidden.has(b.dataset.src)));
  document.querySelectorAll('.cal-task-chip[data-src]').forEach(c => c.classList.toggle('hidden', calHidden.has(c.dataset.src)));
}
document.getElementById('calSources').addEventListener('click', e => {
  const b = e.target.closest('.cal-src'); if (!b) return;
  calHidden.has(b.dataset.src) ? calHidden.delete(b.dataset.src) : calHidden.add(b.dataset.src);
  try { localStorage.setItem('trackie.calHidden', JSON.stringify([...calHidden])); } catch {}
  applyCalFilters();
});
applyCalFilters();
let calDayOpen = null;
/** Only in-app links, or Google Calendar's own event pages. */
function calSafeLink(u) {
  return typeof u === 'string' && (u.startsWith('/') || u.startsWith('https://www.google.com/calendar') || u.startsWith('https://calendar.google.com/')) ? u : null;
}
function openDayDetail(dateStr) {
  calDayOpen = dateStr;
  const items = (CAL_TASKS_BY_DATE[dateStr] || []).filter(t => !calHidden.has(t.source));
  const d = new Date(dateStr + 'T00:00:00');
  document.getElementById('dayDetailTitle').textContent = d.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' });
  let html = items.length ? '' : '<p style="color:var(--muted);font-size:.875rem;padding:var(--sp-3) 0">Nothing on this day.</p>';
  html += items.map(t => {
    const src = CAL_SRC[t.source] || { icon: 'fa-circle', color: '#64748b', label: '' };
    const done = t.done ? 'text-decoration:line-through;opacity:.5' : '';
    const title = (t.time ? '<b>' + escHtml(t.time) + '</b> ' : '') + escHtml(t.title);
    const link = calSafeLink(t.link);
    const lead = t.actionable
      ? `<input type="checkbox" ${t.done ? 'checked' : ''} onchange="toggleCalendarTask(${+t.id}, '${t.source === 'study' ? 'study' : 'todo'}', this.checked)">`
      : `<i class="fas ${src.icon}" style="color:${src.color};width:16px;text-align:center"></i>`;
    return `<div class="cal-board-check" style="padding:var(--sp-2) 0;border-bottom:1px solid var(--border)">${lead}
      <span style="flex:1;${done}">${title}<small style="display:block;color:var(--muted);font-size:.7rem">${escHtml(src.label)}</small></span>
      ${link ? `<a href="${escHtml(link)}" class="btn btn-ghost btn-sm" ${link.startsWith('http') ? 'target="_blank" rel="noopener"' : ''} aria-label="Open"><i class="fas fa-arrow-right"></i></a>` : ''}</div>`;
  }).join('');
  html += `<div style="display:flex;gap:.5rem;margin-top:.75rem"><input id="calQuickTitle" class="form-input" placeholder="Add a todo for this day…" maxlength="150"
    onkeydown="if(event.key==='Enter')calQuickAdd()"><button class="btn btn-primary btn-sm" onclick="calQuickAdd()" aria-label="Add todo"><i class="fas fa-plus"></i></button></div>`;
  document.getElementById('dayDetailBody').innerHTML = html;
  document.getElementById('dayDetailModal').classList.remove('hidden');
}
async function calQuickAdd() {
  const title = document.getElementById('calQuickTitle').value.trim();
  if (!title || !calDayOpen) return;
  const base = document.querySelector('meta[name="app-base"]')?.content || '';
  try {
    const res = await Trackie.API.post(base + '/api/todos.php', { action: 'add', title, due_date: calDayOpen, priority: 'medium' });
    if (res.success) { Trackie.Toast.success('Todo added.'); location.reload(); } else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
function closeDayDetail() { document.getElementById('dayDetailModal').classList.add('hidden'); }

async function toggleCalendarTask(id, source, completed) {
  const base = document.querySelector('meta[name="app-base"]')?.content || '';
  const endpoint = base + (source === 'study' ? '/api/study_plan.php' : '/api/todos.php');
  const params = source === 'study' ? { action: 'toggle', task_id: id, completed: completed ? 1 : 0 }
                                     : { action: 'toggle', todo_id: id, completed: completed ? 1 : 0 };
  const res = await Trackie.API.post(endpoint, params);
  if (res && res.success) {
    location.reload();
  } else {
    Trackie.Toast.error('Could not update task');
  }
}
</script>

<?php include '../includes/footer.php'; ?>
