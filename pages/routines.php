<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/habit_schedule.php';

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Routines';
$currentPage = 'routines';

$today = date('Y-m-d');

// Each routine's state today (done / skipped / rest / due / off) from the one
// shared reader, so this page, Today and the Calendar can't disagree.
require_once '../includes/routines.php';
$routineSched = static fn(array $r) => ['frequency' => 'daily', 'schedule_days' => $r['schedule_days'] ?? null];
$notToday = [];
$routines = array_values(array_filter(routinesOnDay($uid, $today), static function ($r) use (&$notToday) {
    if ($r['state'] !== 'off') return true;   // scheduled today, or recorded today anyway
    $notToday[] = $r;
    return false;
}));
foreach ($routines as &$r) $r['done_today'] = $r['state'] === 'done' ? 1 : 0;
unset($r);
$isRestDay  = (bool)routineRestDays($uid, $today, $today);
$canExcept  = routineSkipsReady() && routineRestReady();
// Only 'due' and 'done' are expected: a skip or a rest day never counts against today.
$prog       = routineProgress(array_column($routines, 'state'));
$doneCount  = $prog['done'];
$totalCount = $prog['expected'];
$pct        = $prog['pct'];

// Group by time-of-day bucket
$groups = ['Morning' => [], 'Afternoon' => [], 'Evening' => [], 'Night' => []];
foreach ($routines as $r) {
    $h = (int)substr($r['time_slot'], 0, 2);
    if ($h < 12)      $groups['Morning'][]   = $r;
    elseif ($h < 17)  $groups['Afternoon'][] = $r;
    elseif ($h < 21)  $groups['Evening'][]   = $r;
    else              $groups['Night'][]      = $r;
}

$catColors = [
    'Fitness' => '#ef4444', 'Work' => '#3b82f6', 'Study' => '#8b5cf6',
    'Personal' => '#f59e0b', 'Health' => '#22c55e', 'Break' => '#64748b',
];

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<?= renderPageHeader('Daily Routines', [
  'icon'    => 'fa-clock',
  'sub'     => 'Structure your day around a repeatable rhythm.',
  'actions' => '<button class="btn btn-primary btn-sm" onclick="openRoutineModal()"><i class="fas fa-plus"></i> New Routine</button>',
]) ?>

<div id="routinesListWrap">
<?php
// Next up: the first routine still owed today whose time hasn't passed.
$nowHm = date('H:i');
$nextUp = null;
foreach ($routines as $r) if ($r['state'] === 'due' && substr($r['time_slot'], 0, 5) >= $nowHm) { $nextUp = $r; break; }
?>
<?php if ($isRestDay): ?>
  <div class="card card-body u-mb-4 u-flex u-items-center u-justify-between u-gap-4 rt-rest" role="status">
    <div><div class="u-fw-6 u-c-text"><i class="fas fa-couch u-mr-1h" aria-hidden="true"></i> Rest day</div>
      <div class="u-fs-13 u-c-muted">Nothing on your routine is expected today. Anything you do still counts.</div></div>
    <button type="button" class="btn btn-secondary btn-sm" onclick="setRoutineRestDay(false)">Back to normal</button>
  </div>
<?php endif; ?>

<?php if ($routines || $notToday): ?>
  <section class="rt-head">
    <div class="td-ring rt-ring<?= $totalCount && $doneCount === $totalCount ? ' is-complete' : '' ?><?= $doneCount ? '' : ' is-empty' ?>" style="--p:<?= $pct ?>" aria-hidden="true">
      <svg viewBox="0 0 100 100"><circle class="td-ring-track" cx="50" cy="50" r="42" pathLength="100"/><circle class="td-ring-fill" cx="50" cy="50" r="42" pathLength="100"/></svg>
      <div class="td-ring-label"><span class="td-ring-num"><?= $doneCount ?>/<?= $totalCount ?></span></div>
    </div>
    <div class="rt-head-text routine-progress">
      <div class="u-fs-13 u-c-muted"><strong class="u-c-text"><?= $doneCount ?></strong> of <?= $totalCount ?> done</div>
      <div class="rt-next" id="rtNext" data-time="<?= $nextUp ? h(substr($nextUp['time_slot'], 0, 5)) : '' ?>">
        <?php if ($isRestDay): ?>Taking it easy today.
        <?php elseif ($nextUp): ?>Next up: <strong><?= h($nextUp['title']) ?></strong> at <?= date('g:i A', strtotime($nextUp['time_slot'])) ?> <span class="rt-in"></span>
        <?php elseif ($totalCount && $doneCount === $totalCount): ?>All done for today. 🎉
        <?php elseif ($totalCount): ?>Nothing else is scheduled later today.
        <?php else: ?>Nothing scheduled today.<?php endif; ?>
      </div>
      <div class="progress-track rt-bar" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Routines completed today">
        <div class="progress-fill green" id="routineProgressFill" style="width:<?= $pct ?>%"></div>
      </div>
    </div>
    <?php if (!$isRestDay && $canExcept && $routines): ?>
      <button type="button" class="btn btn-ghost btn-sm rt-rest-btn" onclick="setRoutineRestDay(true)"><i class="fas fa-couch" aria-hidden="true"></i> Make today a rest day</button>
    <?php endif; ?>
  </section>
<?php endif; ?>

<form class="td-add" id="rtAdd" autocomplete="off">
  <label class="td-add-row">
    <i class="fas fa-plus" aria-hidden="true"></i>
    <span class="sr-only">Add a routine</span>
    <input class="td-add-input" id="rtAddInput" maxlength="150" enterkeyhint="done"
           placeholder="Add a routine, e.g. “stretch 7am weekdays”"
           title="A time (7am, 18:30) and, if not every day: weekdays, weekends, or days like mon wed fri">
    <button type="submit" class="btn btn-primary btn-sm td-add-btn">Add</button>
  </label>
  <div class="td-add-hint" id="rtAddHint" aria-live="polite"></div>
</form>

<?php if (!$routines && !$notToday): ?>
  <div class="card"><?= renderEmptyState('fa-clock', 'No routines yet',
    'A routine is something you do at about the same time: “meditate 7am”, “review the day 9pm weekdays”. Type one above.') ?></div>
<?php elseif (!$routines): ?>
  <div class="card card-body u-mb-6"><p class="u-c-muted u-m-0 u-fs-14">Nothing scheduled for today. Your other routines are listed below.</p></div>
<?php else: ?>
  <ol class="rt-timeline" id="rtTimeline" aria-label="Today's routines">
    <?php foreach ($routines as $r):
      $cc   = $catColors[$r['category']] ?? '#64748b';
      $done = (int)$r['done_today'];
      $st   = $r['state'];
      $hm   = substr($r['time_slot'], 0, 5);
    ?>
      <li class="routine-row rt-item<?= $done ? ' is-done' : '' ?><?= $st === 'skipped' ? ' is-skipped' : '' ?><?= $st === 'rest' ? ' is-rest' : '' ?><?= $nextUp && $nextUp['id'] === $r['id'] ? ' is-next' : '' ?>"
          id="routine-<?= (int)$r['id'] ?>" data-time="<?= h($hm) ?>" data-state="<?= h($st) ?>" style="--cc:<?= $cc ?>">
        <time class="rt-time" datetime="<?= h($hm) ?>"><?= date('g:i', strtotime($r['time_slot'])) ?><small><?= date('A', strtotime($r['time_slot'])) ?></small></time>
        <span class="rt-node" aria-hidden="true"></span>
        <div class="rt-card">
          <button type="button" class="routine-check<?= $done ? ' checked' : '' ?>" role="switch"
                  aria-checked="<?= $done ? 'true' : 'false' ?>"
                  aria-label="Mark &quot;<?= h($r['title']) ?>&quot; done for today"
                  onclick="toggleRoutine(<?= (int)$r['id'] ?>, this)">
            <i class="fas fa-check" aria-hidden="true"></i>
          </button>
          <div class="routine-main">
            <div class="routine-title"><?= h($r['title']) ?></div>
            <div class="rt-meta">
              <span class="rt-cat"><?= h($r['category']) ?></span>
              <?php if ((string)($r['schedule_days'] ?? '') !== ''): ?><span><i class="fas fa-calendar-day" aria-hidden="true"></i> <?= h(habitScheduleLabel($routineSched($r))) ?></span><?php endif; ?>
              <?php if ($st === 'skipped'): ?><span><i class="fas fa-forward" aria-hidden="true"></i> Skipped today — your weekly plan is unchanged</span>
              <?php elseif ($st === 'rest'): ?><span><i class="fas fa-couch" aria-hidden="true"></i> Rest day</span>
              <?php elseif (!routineScheduledOn($r, $today)): ?><span><i class="fas fa-plus" aria-hidden="true"></i> Done today as an extra</span>
              <?php endif; ?>
              <span class="rt-late" hidden>Running late</span>
            </div>
            <?php if ($r['description']): ?><div class="routine-desc"><?= h($r['description']) ?></div><?php endif; ?>
          </div>
          <div class="hb-menu rt-actions">
            <?php if ($canExcept && !$done && $st !== 'rest'): ?>
              <button type="button" class="pg-icon-btn" title="<?= $st === 'skipped' ? 'Undo skip' : 'Skip today' ?>"
                      aria-label="<?= $st === 'skipped' ? 'Undo skip for' : 'Skip today:' ?> <?= h($r['title']) ?>"
                      onclick="skipRoutine(<?= (int)$r['id'] ?>, <?= $st === 'skipped' ? 'false' : 'true' ?>)">
                <i class="fas <?= $st === 'skipped' ? 'fa-rotate-left' : 'fa-forward' ?>" aria-hidden="true"></i>
              </button>
            <?php endif; ?>
            <button type="button" class="pg-icon-btn" title="Edit" aria-label="Edit <?= h($r['title']) ?>" onclick="editRoutine(<?= (int)$r['id'] ?>)"><i class="fas fa-pen" aria-hidden="true"></i></button>
            <button type="button" class="pg-icon-btn is-danger" title="Delete" aria-label="Delete <?= h($r['title']) ?>" onclick="deleteRoutine(<?= (int)$r['id'] ?>)"><i class="fas fa-trash" aria-hidden="true"></i></button>
          </div>
        </div>
      </li>
    <?php endforeach; ?>
  </ol>
<?php endif; ?>

<?php if ($notToday): ?>
  <details class="td-section td-done rt-off">
    <summary><i class="fas fa-chevron-right" aria-hidden="true"></i> Not today <span class="td-count"><?= count($notToday) ?></span></summary>
    <ul class="td-list">
      <?php foreach ($notToday as $r): ?>
        <li class="td-item">
          <span class="td-check td-check-static rt-off-time" aria-hidden="true"><?= date('g:i', strtotime($r['time_slot'])) ?></span>
          <div class="td-body"><div class="td-title"><?= h($r['title']) ?></div>
            <div class="td-meta"><span><i class="fas fa-calendar-day" aria-hidden="true"></i> <?= h(habitScheduleLabel($routineSched($r))) ?></span></div></div>
          <div class="hb-menu">
            <button type="button" class="pg-icon-btn" aria-label="Edit <?= h($r['title']) ?>" onclick="editRoutine(<?= (int)$r['id'] ?>)"><i class="fas fa-pen" aria-hidden="true"></i></button>
            <button type="button" class="pg-icon-btn is-danger" aria-label="Delete <?= h($r['title']) ?>" onclick="deleteRoutine(<?= (int)$r['id'] ?>)"><i class="fas fa-trash" aria-hidden="true"></i></button>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  </details>
<?php endif; ?>
</div>

<!-- Add/Edit Modal -->
<div id="routineModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header">
      <span class="modal-title" id="routineModalTitle">New Routine</span>
      <button class="btn btn-icon btn-ghost btn-sm" data-close-modal="routineModal" aria-label="Close dialog">&times;</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="routineId">
      <div class="form-group">
        <label for="routineTitle" class="form-label">Title <span class="u-c-accent">*</span></label>
        <input id="routineTitle" class="form-input" placeholder="e.g. Morning Run">
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label for="routineTime" class="form-label">Time <span class="u-c-accent">*</span></label>
          <input id="routineTime" class="form-input" type="time">
        </div>
        <div class="form-group">
          <label for="routineCat" class="form-label">Category</label>
          <select id="routineCat" class="form-input">
            <?php foreach (array_keys($catColors) as $c): ?>
              <option value="<?= $c ?>"><?= $c ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-group">
        <span class="form-label" id="routineDaysLbl">Repeats on</span>
        <div class="habit-days" id="routineDayPicker" role="group" aria-labelledby="routineDaysLbl">
          <?php foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 0 => 'Sun'] as $v => $l): ?>
            <label class="habit-day"><input type="checkbox" value="<?= $v ?>" checked><span><?= $l ?></span></label>
          <?php endforeach; ?>
        </div>
        <input type="hidden" id="routineDays" value="">
        <p class="form-hint u-mt-1h">All days selected = every day.</p>
      </div>
      <div class="form-group">
        <label for="routineDesc" class="form-label">Description</label>
        <textarea id="routineDesc" class="form-input" rows="2" placeholder="Optional notes"></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary btn-sm" data-close-modal="routineModal">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="saveRoutine()">
        <i class="fas fa-save"></i> Save
      </button>
    </div>
  </div>
</div>

</div>
<?php include '../includes/footer.php'; ?>

<script>
const API_BASE = '<?= APP_BASE ?>/api';

// Phase 2 Week 1 reference implementation — see createCrudModal() in app.js.
const routineCrud = Trackie.createCrudModal({
  endpoint:      `${API_BASE}/routines.php`,
  modalId:       'routineModal',
  idInputId:     'routineId',
  idParam:       'routine_id',
  fields:        { title: 'routineTitle', time_slot: 'routineTime', category: 'routineCat', description: 'routineDesc', schedule_days: 'routineDays' },
  refreshIds:    ['routinesListWrap'],
  requiredFields:['title', 'time_slot'],
  rowIdPrefix:   'routine-',
  messages: {
    add: 'Routine added.', edit: 'Routine updated.', delete: 'Deleted.',
    validation: 'Title and time are required.',
  },
  confirmDelete: 'Delete this routine?',
});

// Day picker ⇄ hidden "1,3,5" field (empty = every day).
function setRoutineDays(csv) {
  const on = new Set(String(csv || '').split(',').filter(Boolean));
  document.querySelectorAll('#routineDayPicker input').forEach(cb => { cb.checked = !on.size || on.has(cb.value); });
  syncRoutineDays();
}
function syncRoutineDays() {
  const boxes = [...document.querySelectorAll('#routineDayPicker input')];
  const on = boxes.filter(cb => cb.checked).map(cb => cb.value);
  document.getElementById('routineDays').value = on.length === boxes.length ? '' : on.join(',');
}
document.getElementById('routineDayPicker').addEventListener('change', e => {
  // At least one day — unticking the last one would mean "never".
  if (!document.querySelector('#routineDayPicker input:checked')) { e.target.checked = true; Trackie.Toast.info('Pick at least one day.'); }
  syncRoutineDays();
});

function openRoutineModal() {
  setRoutineDays('');
  document.getElementById('routineCat').value = 'Personal';
  document.getElementById('routineModalTitle').textContent = 'New Routine';
  routineCrud.openAdd({ routineCat: 'Personal' });
}

async function editRoutine(id) {
  try {
    const res = await Trackie.API.post(`${API_BASE}/routines.php`, {action:'get', routine_id:id});
    if (!res.success) { Trackie.Toast.error('Could not load routine.'); return; }
    const r = res.routine;
    document.getElementById('routineModalTitle').textContent = 'Edit Routine';
    routineCrud.fillForEdit({
      id: r.id, title: r.title, time_slot: r.time_slot?.slice(0,5) || '',
      category: r.category || 'Personal', description: r.description || '',
    });
    setRoutineDays(r.schedule_days || '');
  } catch { Trackie.Toast.error('Network error.'); }
}

function saveRoutine() { routineCrud.save(); }
function deleteRoutine(id) { routineCrud.remove(id); }

// Optimistic toggle: flip immediately so the tap feels instant, then reconcile
// with the server and roll back if it refused. No full-page refresh — the
// progress bar is recomputed from the DOM that's already correct.
async function toggleRoutine(id, btn) {
  const wasDone = btn.classList.contains('checked');
  const nowDone = !wasDone;

  setRoutineChecked(btn, nowDone);
  updateRoutineProgress();

  try {
    const res = await Trackie.API.post(`${API_BASE}/routines.php`, {
      action: nowDone ? 'complete' : 'uncomplete',
      routine_id: id,
    });

    if (res.queued) {
      Trackie.Toast.info('Saved offline — will sync when you reconnect.');
      return;
    }
    if (!res.success) {
      setRoutineChecked(btn, wasDone);        // server refused → put it back
      updateRoutineProgress();
      Trackie.Toast.error(res.error || "Couldn't update that routine.");
      return;
    }
    const row = btn.closest('.routine-row');
    if (row && (row.classList.contains('is-skipped') || row.classList.contains('is-rest'))) Trackie.refreshFragments(['routinesListWrap']).then(rtTick);
    // Every routine expected today is done → celebrate.
    if (nowDone && !document.querySelector('#routinesListWrap .routine-row:not(.is-done):not(.is-skipped):not(.is-rest)')) {
      Trackie.Delight?.burst(btn);
      Trackie.Toast.success('Routine complete for today 🎉', 3500);
    }
    if (nowDone && res.xp?.gained) {
      res.xp.leveledUp
        ? Trackie.Toast.success(`⚡ Level up! Level ${res.xp.level}`, 5000)
        : Trackie.Toast.success(`Routine done! +${res.xp.gained} XP`);
    }
  } catch {
    setRoutineChecked(btn, wasDone);
    updateRoutineProgress();
    Trackie.Toast.error("Couldn't update that routine — please try again.");
  }
}

// One-day exceptions — the weekly plan itself never changes.
async function skipRoutine(id, on) {
  try {
    const res = await Trackie.API.post(`${API_BASE}/routines.php`, { action: on ? 'skip' : 'unskip', routine_id: id });
    if (!res.success) return Trackie.UI.toastError(res, "Couldn't update that routine.");
    Trackie.Toast.success(on ? 'Skipped for today — next week is unchanged.' : 'Skip undone.');
    Trackie.refreshFragments(['routinesListWrap']).then(rtTick);
  } catch (e) { Trackie.UI.toastError(e, "Couldn't update that routine."); }
}

async function setRoutineRestDay(on) {
  try {
    const res = await Trackie.API.post(`${API_BASE}/routines.php`, { action: 'rest_day', on: on ? 1 : 0 });
    if (!res.success) return Trackie.UI.toastError(res, "Couldn't update today.");
    Trackie.Toast.success(on ? 'Rest day — nothing is expected today.' : 'Back to your normal routine.');
    Trackie.refreshFragments(['routinesListWrap']).then(rtTick);
  } catch (e) { Trackie.UI.toastError(e, "Couldn't update today."); }
}

function setRoutineChecked(btn, on) {
  btn.classList.toggle('checked', on);
  btn.setAttribute('aria-checked', on ? 'true' : 'false');
  btn.closest('.routine-row')?.classList.toggle('is-done', on);
}

function updateRoutineProgress() {
  // Expected = done + due; skipped and rest-day rows never count against today.
  const done = document.querySelectorAll('#routinesListWrap .routine-row.is-done').length;
  const due  = document.querySelectorAll('#routinesListWrap .routine-row:not(.is-done):not(.is-skipped):not(.is-rest)').length;
  const expected = done + due;
  const pct = expected ? Math.round(done / expected * 100) : 0;
  const fill = document.getElementById('routineProgressFill');
  if (fill) {
    fill.style.width = pct + '%';
    fill.parentElement?.setAttribute('aria-valuenow', pct);
  }
  const label = document.querySelector('#routinesListWrap .routine-progress strong');
  if (label) label.textContent = done;
  const ring = document.querySelector('.rt-ring');
  if (ring) {
    ring.style.setProperty('--p', pct);
    ring.classList.toggle('is-complete', expected > 0 && done === expected);
    ring.classList.toggle('is-empty', !done);
    const num = ring.querySelector('.td-ring-num'); if (num) num.textContent = `${done}/${expected}`;
  }
  rtTick();
}

/* ── Live timeline: the "now" line, "next up · in 25 min", running late ── */
const hmNow = () => { const d = new Date(); return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`; };
const hmMin = hm => +hm.slice(0, 2) * 60 + +hm.slice(3, 5);
function rtTick() {
  const tl = document.getElementById('rtTimeline');
  const now = hmNow(), nowMin = hmMin(now);
  const items = tl ? [...tl.querySelectorAll('.rt-item')] : [];
  const owed = r => !r.classList.contains('is-done') && !r.classList.contains('is-skipped') && !r.classList.contains('is-rest');
  items.forEach(r => {
    const late = owed(r) && nowMin - hmMin(r.dataset.time) > 15;   // 15 minutes of grace
    r.classList.toggle('is-late', late);
    const tag = r.querySelector('.rt-late'); if (tag) tag.hidden = !late;
  });
  if (tl) {
    let line = tl.querySelector('.rt-now');
    if (!line) {
      line = Object.assign(document.createElement('li'), { className: 'rt-now' });
      line.setAttribute('aria-hidden', 'true');
      line.innerHTML = '<span class="rt-now-time"></span><span class="rt-now-rule"></span>';
    }
    line.querySelector('.rt-now-time').textContent = new Date().toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
    const after = items.find(r => r.dataset.time > now) || null;
    if (!line.isConnected || line.nextElementSibling !== after) tl.insertBefore(line, after);
  }
  // Next up: first routine still owed whose time hasn't passed.
  const next = items.find(r => owed(r) && r.dataset.time >= now);
  items.forEach(r => r.classList.toggle('is-next', r === next));
  const box = document.getElementById('rtNext');
  if (!box || document.querySelector('.rt-rest')) return;
  if (next) {
    const mins = hmMin(next.dataset.time) - nowMin;
    const when = mins <= 0 ? 'now' : mins < 60 ? `in ${mins} min` : `in ${Math.floor(mins / 60)} h${mins % 60 ? ` ${mins % 60} min` : ''}`;
    const t = next.querySelector('.rt-time');
    box.replaceChildren('Next up: ', Object.assign(document.createElement('strong'), { textContent: next.querySelector('.routine-title').textContent }),
      ` at ${t.childNodes[0].textContent} ${t.querySelector('small').textContent} `,
      Object.assign(document.createElement('span'), { className: 'rt-in', textContent: '· ' + when }));
  } else if (items.length && !items.some(owed)) {
    box.textContent = 'All done for today. 🎉';
  } else if (items.length) {
    box.textContent = 'A few are still open from earlier today.';
  }
}
rtTick();
setInterval(rtTick, 30000);

/* ── Quick add: "stretch 7am weekdays", "gym mon wed fri 6:30pm" ────── */
const RT_DAY = { sun: 0, mon: 1, tue: 2, wed: 3, thu: 4, fri: 5, sat: 6 };
const RT_DAY_RE = /\b(?:on\s+|every\s+)?(sun|mon|tue|tues|wed|thu|thur|thurs|fri|sat)(?:day|nesday|sday|urday)?s?\b/gi;
function parseRoutine(text) {
  let rest = ` ${text} `, days = null;
  if (/\b(every\s*day|daily)\b/i.test(rest)) { days = ''; rest = rest.replace(/\b(every\s*day|daily)\b/i, ' '); }
  else if (/\bweekdays?\b/i.test(rest)) { days = '1,2,3,4,5'; rest = rest.replace(/\b(on\s+|every\s+)?weekdays?\b/i, ' '); }
  else if (/\bweekends?\b/i.test(rest)) { days = '0,6'; rest = rest.replace(/\b(on\s+|every\s+)?weekends?\b/i, ' '); }
  else {
    const set = new Set();
    rest = rest.replace(RT_DAY_RE, (m, d) => { set.add(RT_DAY[d.slice(0, 3).toLowerCase()]); return ' '; });
    if (set.size) { days = [...set].sort((a, b) => a - b).join(','); rest = rest.replace(/\s(?:and|&|,)(?=\s)/g, ' ').replace(/,/g, ' '); }
  }
  let time = null;
  const r = window.chrono?.parse(rest, new Date())?.find(x => x.start.isCertain('hour'));
  if (r) {
    time = `${String(r.start.get('hour')).padStart(2, '0')}:${String(r.start.get('minute') || 0).padStart(2, '0')}`;
    rest = rest.replace(r.text, ' ');
  }
  rest = rest.replace(/\s(at|on|every)\s*$/i, ' ');
  return { title: rest.replace(/\s+/g, ' ').trim(), time, days: days ?? '' };
}
function rtDaysLabel(csv) {
  if (!csv) return 'Every day';
  if (csv === '1,2,3,4,5') return 'Weekdays';
  if (csv === '0,6') return 'Weekends';
  const names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  return csv.split(',').map(d => names[+d]).join(' · ');
}
function rtHint() {
  const el = document.getElementById('rtAddHint'), v = document.getElementById('rtAddInput')?.value.trim() || '';
  if (!el) return;
  if (!v) { el.replaceChildren(); return; }
  const r = parseRoutine(v);
  const tag = (icon, label, muted) => {
    const s = document.createElement('span'); s.className = 'td-tag' + (muted ? ' is-muted' : '');
    s.innerHTML = `<i class="fas ${icon}" aria-hidden="true"></i> `; s.append(label); return s;
  };
  const t = r.time ? new Date(`2000-01-01T${r.time}`).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }) : null;
  el.replaceChildren(tag('fa-clock', t || 'Add a time, e.g. 7am', !t), tag('fa-calendar-day', rtDaysLabel(r.days)),
    ...(r.title ? [] : [tag('fa-pen', 'Add a few words', true)]));
}
const rtMain = document.getElementById('page-main');
rtMain?.addEventListener('focusin', e => { if (e.target.id === 'rtAddInput') Trackie.NL.load().then(rtHint); });
rtMain?.addEventListener('input', e => { if (e.target.id === 'rtAddInput') rtHint(); });
rtMain?.addEventListener('submit', async e => {
  if (e.target.id !== 'rtAdd') return;
  e.preventDefault();
  await Trackie.NL.load();
  const input = document.getElementById('rtAddInput');
  const r = parseRoutine(input.value);
  if (!r.title || !r.time) {
    rtHint(); input.focus();
    Trackie.Toast.warning(!r.title ? 'Add a name for the routine.' : 'Add a time, e.g. “7am” or “18:30”.');
    return;
  }
  const btn = e.target.querySelector('button[type="submit"]'); btn.disabled = true;
  try {
    const res = await Trackie.API.post(`${API_BASE}/routines.php`, { action: 'add', title: r.title, time_slot: r.time, schedule_days: r.days, category: 'Personal', description: '' });
    if (!res.success) throw res;
    input.value = ''; rtHint();
    await Trackie.refreshFragments(['routinesListWrap']);
    rtTick();
    const row = document.getElementById('routine-' + res.id);
    if (row) { row.classList.add('is-new'); row.scrollIntoView({ block: 'nearest' }); }
    else Trackie.Toast.success(`Added — runs ${rtDaysLabel(r.days).toLowerCase()}.`, 2500);
  } catch (err) { Trackie.UI.toastError(err, "Couldn't add that routine."); }
  finally {
    const b = document.querySelector('#rtAdd button[type="submit"]'); if (b) b.disabled = false;
    document.getElementById('rtAddInput')?.focus();
  }
});
</script>
