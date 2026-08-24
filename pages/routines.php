<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Routines';
$currentPage = 'routines';

$today = date('Y-m-d');

// LEFT JOIN today's log so each row knows whether it's already done — one
// query rather than an is-it-done lookup per routine.
$routines = tableExists('routine_logs')
    ? fetchAll(
        "SELECT r.*, (rl.id IS NOT NULL) AS done_today
           FROM routines r
           LEFT JOIN routine_logs rl
                  ON rl.routine_id = r.id AND rl.log_date = ? AND rl.user_id = r.user_id
          WHERE r.user_id = ?
          ORDER BY r.time_slot ASC",
        [$today, $uid])
    : fetchAll("SELECT *, 0 AS done_today FROM routines WHERE user_id=? ORDER BY time_slot ASC", [$uid]);

$doneCount  = 0;
foreach ($routines as $r) if ((int)$r['done_today']) $doneCount++;
$totalCount = count($routines);
$pct        = $totalCount ? (int)round($doneCount / $totalCount * 100) : 0;

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
<?php if ($totalCount): ?>
  <div class="card card-body routine-progress" style="margin-bottom:1.25rem">
    <div style="display:flex;align-items:baseline;justify-content:space-between;gap:var(--sp-4);margin-bottom:var(--sp-2)">
      <div style="font-weight:600;color:var(--text)">Today's routine</div>
      <div style="font-size:.8125rem;color:var(--muted)">
        <strong style="color:var(--text)"><?= $doneCount ?></strong> of <?= $totalCount ?> done
      </div>
    </div>
    <div class="progress-track" role="progressbar" aria-valuenow="<?= $pct ?>"
         aria-valuemin="0" aria-valuemax="100"
         aria-label="Routines completed today">
      <div class="progress-fill green" id="routineProgressFill" style="width:<?= $pct ?>%"></div>
    </div>
  </div>
<?php endif; ?>

<?php if (empty($routines)): ?>
  <div class="card">
    <div class="empty-state">
      <div class="empty-state-icon"><i class="fas fa-clock"></i></div>
      <div class="empty-state-title">No routines yet</div>
      <p>Build structure into your day with a daily routine.</p>
      <button class="btn btn-primary" style="margin-top:var(--sp-3)" onclick="openRoutineModal()">
        <i class="fas fa-plus"></i> Add your first routine
      </button>
    </div>
  </div>
<?php else: ?>
  <?php foreach ($groups as $period => $items): if (empty($items)) continue; ?>
    <div style="margin-bottom:var(--sp-5)">
      <div style="font-size:.8125rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:var(--sp-3)">
        <?= $period ?>
      </div>
      <div style="display:flex;flex-direction:column;gap:.625rem">
        <?php foreach ($items as $r):
          $cc   = $catColors[$r['category']] ?? '#64748b';
          $done = (int)$r['done_today'];
        ?>
          <div class="card card-body routine-row<?= $done ? ' is-done' : '' ?>" id="routine-<?= $r['id'] ?>"
               style="display:flex;align-items:center;gap:var(--sp-4)">
            <button type="button"
                    class="routine-check<?= $done ? ' checked' : '' ?>"
                    role="switch"
                    aria-checked="<?= $done ? 'true' : 'false' ?>"
                    aria-label="Mark &quot;<?= h($r['title']) ?>&quot; done for today"
                    onclick="toggleRoutine(<?= $r['id'] ?>, this)">
              <i class="fas fa-check" aria-hidden="true"></i>
            </button>
            <div style="width:52px;text-align:center;flex-shrink:0">
              <div style="font-size:.9375rem;font-weight:700;color:var(--text)">
                <?= date('g:i', strtotime($r['time_slot'])) ?>
              </div>
              <div style="font-size:.6875rem;color:var(--muted)">
                <?= date('A', strtotime($r['time_slot'])) ?>
              </div>
            </div>
            <?php /* Class, not an inline style: the responsive rule needs to
                     change this element's flex basis at narrow widths, and an
                     inline `flex:1` would win over the stylesheet. */ ?>
            <div class="routine-main">
              <div class="routine-title"><?= h($r['title']) ?></div>
              <?php if ($r['description']): ?>
                <div class="routine-desc"><?= h($r['description']) ?></div>
              <?php endif; ?>
            </div>
            <span class="badge" style="background:<?= $cc ?>20;color:<?= $cc ?>;flex-shrink:0">
              <?= h($r['category']) ?>
            </span>
            <div style="display:flex;gap:var(--sp-1);flex-shrink:0">
              <button class="btn btn-icon btn-ghost btn-sm" title="Edit"
                      onclick="editRoutine(<?= $r['id'] ?>)">
                <i class="fas fa-pen"></i>
              </button>
              <button class="btn btn-icon btn-ghost btn-sm" title="Delete" style="color:var(--accent)"
                      onclick="deleteRoutine(<?= $r['id'] ?>)">
                <i class="fas fa-trash"></i>
              </button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
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
        <label for="routineTitle" class="form-label">Title <span style="color:var(--accent)">*</span></label>
        <input id="routineTitle" class="form-input" placeholder="e.g. Morning Run">
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label for="routineTime" class="form-label">Time <span style="color:var(--accent)">*</span></label>
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
  fields:        { title: 'routineTitle', time_slot: 'routineTime', category: 'routineCat', description: 'routineDesc' },
  refreshIds:    ['routinesListWrap'],
  requiredFields:['title', 'time_slot'],
  rowIdPrefix:   'routine-',
  messages: {
    add: 'Routine added.', edit: 'Routine updated.', delete: 'Deleted.',
    validation: 'Title and time are required.',
  },
  confirmDelete: 'Delete this routine?',
});

function openRoutineModal() {
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

function setRoutineChecked(btn, on) {
  btn.classList.toggle('checked', on);
  btn.setAttribute('aria-checked', on ? 'true' : 'false');
  btn.closest('.routine-row')?.classList.toggle('is-done', on);
}

function updateRoutineProgress() {
  const rows = document.querySelectorAll('.routine-row');
  const done = document.querySelectorAll('.routine-row.is-done').length;
  const fill = document.getElementById('routineProgressFill');
  if (!rows.length || !fill) return;
  const pct = Math.round(done / rows.length * 100);
  fill.style.width = pct + '%';
  const bar = fill.parentElement;
  bar?.setAttribute('aria-valuenow', pct);
  const label = bar?.closest('.routine-progress')?.querySelector('strong');
  if (label) label.textContent = done;
}
</script>
