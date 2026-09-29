<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Goals';
$currentPage = 'goals';

$statusFilter = in_array($_GET['status'] ?? '', ['all','active','completed']) ? $_GET['status'] : 'all';

$sql = "SELECT * FROM goals WHERE user_id=?";
if ($statusFilter === 'active')    $sql .= " AND progress < target_value";
if ($statusFilter === 'completed') $sql .= " AND progress >= target_value";
$sql   .= " ORDER BY created_at DESC";
$goals  = fetchAll($sql, [$uid]);

$summary = fetchOne(
    "SELECT COUNT(*) total,
            SUM(progress >= target_value) completed,
            ROUND(AVG(CASE WHEN target_value>0 THEN (progress/target_value)*100 ELSE 0 END),1) avg_pct
     FROM goals WHERE user_id=?",
    [$uid]
);

// ── AI-style insight ──
$goalsInsight = '';
$gTotal = (int)($summary['total'] ?? 0);
if ($gTotal > 0) {
    $gDone = (int)($summary['completed'] ?? 0);
    $gAvg  = (float)($summary['avg_pct'] ?? 0);
    $nearest = fetchOne(
        "SELECT goal_name, deadline FROM goals
         WHERE user_id=? AND progress < target_value AND deadline IS NOT NULL AND deadline >= CURDATE()
         ORDER BY deadline ASC LIMIT 1", [$uid]
    );
    if ($gDone === $gTotal) {
        $goalsInsight = "Every goal is complete — time to set your next ambition. 🎯";
    } elseif ($nearest) {
        $days = (int)ceil((strtotime($nearest['deadline']) - time()) / 86400);
        $goalsInsight = "\"{$nearest['goal_name']}\" is your nearest deadline — " . ($days === 0 ? 'due today' : "{$days} day" . ($days !== 1 ? 's' : '') . " left") . ". Overall you're " . round($gAvg) . "% of the way across all goals.";
    } else {
        $goalsInsight = "You're averaging " . round($gAvg) . "% progress across {$gTotal} goal" . ($gTotal !== 1 ? 's' : '') . ". Small weekly updates keep momentum.";
    }
}

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<?= renderPageHeader('My Goals', [
  'icon'    => 'fa-bullseye',
  'sub'     => 'Set targets, track progress, and hit your milestones.',
  'actions' => '<button class="btn btn-primary btn-sm" onclick="openNewGoal()"><i class="fas fa-plus"></i> New Goal</button>',
]) ?>

<!-- Stats -->
<div class="grid-stats" id="goalsStatsWrap">
  <?php
  $gs = [
    ['l'=>'Total goals',     'v'=>$summary['total']     ?? 0, 'c'=>'#64748b'],
    ['l'=>'Completed',       'v'=>$summary['completed'] ?? 0, 'c'=>'#22c55e'],
    ['l'=>'Avg progress',    'v'=>($summary['avg_pct']  ?? 0).'%', 'c'=>'#3b82f6'],
  ];
  foreach ($gs as $s): ?>
    <div class="stat-card">
      <div class="stat-val" style="color:<?= $s['c'] ?>"><?= $s['v'] ?></div>
      <div class="stat-label"><?= $s['l'] ?></div>
    </div>
  <?php endforeach; ?>
</div>

<?= renderInsight($goalsInsight) ?>

<!-- Filter tabs -->
<div style="margin-bottom:var(--sp-4)">
  <div class="filter-tabs">
    <?php foreach (['all'=>'All','active'=>'Active','completed'=>'Completed'] as $k=>$lbl): ?>
      <a class="filter-tab <?= $statusFilter===$k?'active':'' ?>" href="?status=<?= $k ?>">
        <?= $lbl ?>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<div id="goalsListWrap">
<?php if (empty($goals)): ?>
  <div class="card">
    <div class="empty-state">
      <div class="empty-state-icon"><i class="fas fa-bullseye"></i></div>
      <div class="empty-state-title">No goals yet</div>
      <p>Set a goal and start tracking your progress.</p>
      <button class="btn btn-primary" style="margin-top:var(--sp-3)" onclick="openNewGoal()">
        <i class="fas fa-plus"></i> Add your first goal
      </button>
    </div>
  </div>
<?php else: ?>
  <div class="grid-cards-lg">
    <?php foreach ($goals as $g):
      $pct  = $g['target_value'] > 0 ? min(100, round($g['progress'] / $g['target_value'] * 100)) : 0;
      $done = $pct >= 100;
    ?>
      <div class="card card-body" id="goal-<?= $g['id'] ?>">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:.875rem">
          <div style="flex:1;min-width:0">
            <div style="font-weight:600;font-size:.9375rem;color:var(--text)" class="truncate">
              <?= h($g['goal_name']) ?>
              <?php if ($done): ?><span class="badge badge-green" style="margin-left:.375rem"><i class="fas fa-trophy"></i> Done</span><?php endif; ?>
            </div>
            <?php if ($g['description']): ?>
              <div style="font-size:.8125rem;color:var(--muted);margin-top:var(--sp-1)"><?= h($g['description']) ?></div>
            <?php endif; ?>
          </div>
          <button class="btn btn-icon btn-ghost btn-sm" style="flex-shrink:0" onclick="openEditGoal(<?= $g['id'] ?>)" aria-label="Edit goal" title="Edit"><i class="fas fa-pen"></i></button>
          <button class="btn btn-icon btn-ghost btn-sm" style="color:var(--accent);flex-shrink:0"
                  onclick="deleteGoal(<?= $g['id'] ?>)">
            <i class="fas fa-trash"></i>
          </button>
        </div>

        <div style="margin-bottom:var(--sp-3)">
          <div style="display:flex;justify-content:space-between;font-size:.8125rem;margin-bottom:.375rem">
            <span style="color:var(--muted)"><?= $g['progress'] ?> / <?= $g['target_value'] ?></span>
            <strong style="color:<?= $done ? 'var(--ok)' : 'var(--text)' ?>"><?= $pct ?>%</strong>
          </div>
          <div class="progress-track">
            <div class="progress-fill <?= $done ? 'green' : '' ?>" style="width:<?= $pct ?>%"></div>
          </div>
        </div>

        <?php if ($g['deadline']): ?>
          <div style="font-size:.8125rem;color:var(--muted);margin-bottom:var(--sp-3)">
            <i class="fas fa-calendar"></i> <?= formatDate($g['deadline']) ?>
          </div>
        <?php endif; ?>

        <?php if (!$done): ?>
          <div style="display:flex;gap:var(--sp-2);align-items:center">
            <input type="number" id="prog-<?= $g['id'] ?>"
                   min="0" max="<?= $g['target_value'] ?>"
                   value="<?= $g['progress'] ?>"
                   class="form-input" style="font-size:.875rem">
            <button class="btn btn-secondary btn-sm"
                    onclick="updateProgress(<?= $g['id'] ?>, <?= $g['target_value'] ?>)">
              Update
            </button>
          </div>
        <?php endif; ?>

        <!-- Check-ins -->
        <div style="margin-top:var(--sp-3)">
          <button class="btn btn-ghost btn-sm" style="width:100%;font-size:.75rem" onclick="toggleGoalCheckins(<?= $g['id'] ?>)">
            <i class="fas fa-clipboard-list"></i> Check-ins
          </button>
          <div id="goal-checkins-<?= $g['id'] ?>" class="goal-checkins hidden" style="margin-top:var(--sp-2)">
          <div style="display:flex;gap:var(--sp-2);margin-bottom:var(--sp-2);flex-wrap:wrap">
            <input type="text" class="form-input" style="font-size:.8125rem;flex:1;min-width:80px" id="checkin-input-<?= $g['id'] ?>"
                     placeholder="What did you do today?"
                     onkeydown="if(event.key==='Enter'){addGoalCheckin(<?= $g['id'] ?>)}">
              <button class="btn btn-primary btn-sm" onclick="addGoalCheckin(<?= $g['id'] ?>)" aria-label="Add check-in"><i class="fas fa-plus" aria-hidden="true"></i></button>
            </div>
            <div id="checkin-list-<?= $g['id'] ?>" style="font-size:.75rem;color:var(--muted)"><i class="fas fa-spinner fa-spin"></i> Loading\u2026</div>
          </div>
        </div>      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
</div>

<!-- Add Goal Modal -->
<div id="goalModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header">
      <span class="modal-title">New Goal</span>
      <button class="btn btn-icon btn-ghost btn-sm" data-close-modal="goalModal" aria-label="Close dialog">&times;</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label for="goalName" class="form-label">Goal name <span style="color:var(--accent)">*</span></label>
        <input type="hidden" id="goalId">
        <input id="goalName" class="form-input" placeholder="e.g. Read 50 books, Save $10,000">
      </div>
      <div class="form-group">
        <label for="goalDesc" class="form-label">Description</label>
        <textarea id="goalDesc" class="form-input" rows="2" placeholder="Optional details"></textarea>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label for="goalTarget" class="form-label">Target value</label>
          <input id="goalTarget" class="form-input" type="number" min="1" value="100">
        </div>
        <div class="form-group">
          <label for="goalProgress" class="form-label">Starting progress</label>
          <input id="goalProgress" class="form-input" type="number" min="0" value="0">
        </div>
      </div>
      <div class="form-group">
        <label for="goalDeadline" class="form-label">Deadline</label>
        <input id="goalDeadline" class="form-input" type="date">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary btn-sm" data-close-modal="goalModal">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="saveGoal()">
        <i class="fas fa-save"></i> Add Goal
      </button>
    </div>
  </div>
</div>

</div>
<?php include '../includes/footer.php'; ?>

<script>
const API_BASE = '<?= APP_BASE ?>/api';

function openNewGoal() {
  ['goalId', 'goalName', 'goalDesc', 'goalDeadline'].forEach(i => document.getElementById(i).value = '');
  document.getElementById('goalTarget').value = 100;
  document.getElementById('goalProgress').value = 0;
  const t = document.querySelector('#goalModal .modal-title'); if (t) t.textContent = 'New Goal';
  Trackie.openModal('goalModal');
}
async function openEditGoal(id) {
  try {
    const res = await Trackie.API.post(`${API_BASE}/goals.php`, { action: 'get', goal_id: id });
    if (!res.success) { Trackie.Toast.error(res.error || 'Not found.'); return; }
    const g = res.goal;
    document.getElementById('goalId').value = g.id;
    document.getElementById('goalName').value = g.goal_name;
    document.getElementById('goalDesc').value = g.description || '';
    document.getElementById('goalTarget').value = g.target_value;
    document.getElementById('goalProgress').value = g.progress;
    document.getElementById('goalDeadline').value = g.deadline || '';
    const t = document.querySelector('#goalModal .modal-title'); if (t) t.textContent = 'Edit Goal';
    Trackie.openModal('goalModal');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function saveGoal() {
  const name = document.getElementById('goalName').value.trim();
  if (!name) { Trackie.Toast.warning('Goal name required.'); return; }
  try {
    const editId = document.getElementById('goalId').value;
    const res = await Trackie.API.post(`${API_BASE}/goals.php`, {
      action: editId ? 'edit' : 'add', goal_id: editId, goal_name: name,
      description:  document.getElementById('goalDesc').value,
      target_value: document.getElementById('goalTarget').value,
      progress:     document.getElementById('goalProgress').value,
      deadline:     document.getElementById('goalDeadline').value,
    });
    if (res.success) {
      Trackie.Toast.success(editId ? 'Goal updated.' : 'Goal added!');
      Trackie.closeModal('goalModal');
      await Trackie.refreshFragments(['goalsStatsWrap', 'goalsListWrap']);
    }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}

async function updateProgress(id, max) {
  const inp = document.getElementById(`prog-${id}`);
  const v   = Math.min(Math.max(0, parseInt(inp.value) || 0), max);
  inp.value = v;
  try {
    const res = await Trackie.API.post(`${API_BASE}/goals.php`, {action:'update_progress', goal_id:id, progress:v});
    if (res.success) {
      Trackie.Toast.success('Progress updated.');
      await Trackie.refreshFragments(['goalsStatsWrap', 'goalsListWrap']);
    }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}

async function deleteGoal(id) {
  const ok = await Trackie.confirmDialog('Delete this goal?', {confirmText:'Delete', danger:true});
  if (!ok) return;
  try {
    const res = await Trackie.API.post(`${API_BASE}/goals.php`, {action:'delete', goal_id:id});
    if (res.success) { document.getElementById(`goal-${id}`)?.remove(); Trackie.Toast.success('Goal deleted.'); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
function toggleGoalCheckins(id) {
  const panel = document.getElementById('goal-checkins-' + id);
  if (!panel) return;
  const wasHidden = panel.classList.contains('hidden');
  panel.classList.toggle('hidden');
  if (wasHidden) { loadGoalCheckins(id); }
}

function escCheckin(s) {
  return String(s).replace(/[&<>"']/g, function(c) {
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
  });
}

async function loadGoalCheckins(id) {
  const list = document.getElementById('checkin-list-' + id);
  if (!list) return;
  list.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading\u2026';
  try {
    const res = await Trackie.API.post(API_BASE + '/goals.php', {action:'checkins', goal_id:id});
    if (!res.success) { list.innerHTML = 'Could not load check-ins.'; return; }
    if (!res.checkins.length) { list.innerHTML = 'No check-ins yet.'; return; }
    list.innerHTML = res.checkins.map(function(c) {
      const when = new Date(c.created_at.replace(' ', 'T')).toLocaleDateString(undefined, {month:'short', day:'numeric'});
      const prog = c.progress_snapshot !== null ? ' <strong style="color:var(--text)">(' + c.progress_snapshot + ')</strong>' : '';
      const note = c.note ? escCheckin(c.note) : '<em>Progress updated</em>';
      return '<div style="padding:.375rem 0;border-bottom:1px solid var(--surface2)"><span style="color:var(--muted)">' + when + '</span> \u2014 ' + note + prog + '</div>';
    }).join('');
  } catch (e) {
    list.innerHTML = 'Network error.';
  }
}

async function addGoalCheckin(id) {
  const input = document.getElementById('checkin-input-' + id);
  if (!input) return;
  const note = input.value.trim();
  if (!note) return;
  try {
    const res = await Trackie.API.post(API_BASE + '/goals.php', {action:'add_checkin', goal_id:id, note:note});
    if (res.success) {
      input.value = '';
      Trackie.Toast.success('Check-in added.');
      await loadGoalCheckins(id);
    } else {
      Trackie.Toast.error(res.error || 'Could not add check-in.');
    }
  } catch (e) {
    Trackie.Toast.error('Network error.');
  }
}

</script>
