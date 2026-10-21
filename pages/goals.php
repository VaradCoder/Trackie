<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Goals';
$currentPage = 'goals';

// Linked goals fill themselves from logged activity — bring them up to date first.
require_once '../includes/goal_sources.php';
syncLinkedGoals($uid);
$goalSources = availableGoalSources();
$userHabits  = array_column(fetchAll("SELECT id, name FROM habits WHERE user_id=? ORDER BY name", [$uid]), 'name', 'id');

$statusFilter = in_array($_GET['status'] ?? '', ['all','active','completed']) ? $_GET['status'] : 'all';

// All goals; the All / Active / Reached filter runs in the browser (no reload).
$goals = fetchAll("SELECT * FROM goals WHERE user_id=? ORDER BY created_at DESC", [$uid]);

$summary = fetchOne(
    "SELECT COUNT(*) total,
            SUM(progress >= target_value) completed,
            ROUND(AVG(CASE WHEN target_value>0 THEN (progress/target_value)*100 ELSE 0 END),1) avg_pct
     FROM goals WHERE user_id=?",
    [$uid]
);

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

<?php
// Active goals by nearest deadline (none last), then completed.
usort($goals, static function ($a, $b) {
    $da = (int)$a['progress'] >= (int)$a['target_value']; $db = (int)$b['progress'] >= (int)$b['target_value'];
    return [$da, $a['deadline'] === null, $a['deadline'], -(int)$a['id']] <=> [$db, $b['deadline'] === null, $b['deadline'], -(int)$b['id']];
});
$activeN = count(array_filter($goals, static fn($g) => (int)$g['progress'] < (int)$g['target_value']));
$doneN   = count($goals) - $activeN;
?>
<div class="pg-strip" id="goalsStatsWrap">
  <?php if ($goals): ?>
    <span><strong><?= $activeN ?></strong> active</span>
    <span><strong><?= $doneN ?></strong> reached</span>
    <span>average <strong><?= round((float)($summary['avg_pct'] ?? 0)) ?>%</strong></span>
  <?php endif; ?>
</div>

<?php if ($goals): ?>
<div class="pg-chips u-mb-4" role="toolbar" aria-label="Filter goals" id="goalChips">
  <?php foreach (['all' => 'All', 'active' => 'Active', 'done' => 'Reached'] as $k => $lbl): ?>
    <button type="button" class="pg-chip" data-goal-filter="<?= $k ?>" aria-pressed="<?= ($statusFilter === 'completed' ? 'done' : $statusFilter) === $k ? 'true' : 'false' ?>"><?= $lbl ?></button>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div id="goalsListWrap">
<?php if (empty($goals)): ?>
  <div class="card"><?= renderEmptyState('fa-bullseye', 'No goals yet',
    'A goal is a number and a date: “read 12 books by December”. Link it to a habit or your reading and it fills itself in.',
    '<button type="button" class="btn btn-primary btn-sm" onclick="openNewGoal()"><i class="fas fa-plus" aria-hidden="true"></i> Set your first goal</button>') ?></div>
<?php else: ?>
  <div class="gl-grid">
    <?php foreach ($goals as $g):
      $target = max(1, (int)$g['target_value']);
      $prog   = (int)$g['progress'];
      $pct    = min(100, (int)round($prog / $target * 100));
      $done   = $prog >= $target;
      $pace   = goalPace($g);
      $unit   = !empty($g['source']) ? (GOAL_SOURCES[$g['source']][1] ?? '') : '';
      $step   = max(1, (int)round($target / 20));          // ~5% of the target
      $paceTxt = match ($pace['state']) {
          'done'    => 'Reached 🎉',
          'ahead'   => 'Ahead by ' . $pace['diff'],
          'on'      => 'On track',
          'behind'  => 'Behind by ' . -$pace['diff'] . ($pace['perDay'] !== null ? ' · ~' . rtrim(rtrim(number_format($pace['perDay'], 1), '0'), '.') . '/day to finish' : ''),
          'overdue' => ($target - $prog) . ' short · deadline passed',
          default   => '',
      };
      $dl = '';
      if ($g['deadline'] && !$done) {
          $dd = (int)round((strtotime($g['deadline']) - strtotime(date('Y-m-d'))) / 86400);
          $dl = $dd < 0 ? 'Ended ' . formatDate($g['deadline']) : ($dd === 0 ? 'Due today' : ($dd === 1 ? '1 day left' : "$dd days left"));
      }
    ?>
      <article class="card gl-card<?= $done ? ' is-done' : '' ?> pace-<?= h($pace['state']) ?>" id="goal-<?= (int)$g['id'] ?>"
               data-id="<?= (int)$g['id'] ?>" data-done="<?= $done ? 1 : 0 ?>" data-target="<?= $target ?>" data-progress="<?= $prog ?>">
        <div class="gl-ring" style="--p:<?= $pct ?>" role="img" aria-label="<?= $pct ?>% — <?= $prog ?> of <?= $target ?>">
          <svg viewBox="0 0 100 100" aria-hidden="true">
            <circle class="td-ring-track" cx="50" cy="50" r="42" pathLength="100"/>
            <circle class="gl-ring-fill" cx="50" cy="50" r="42" pathLength="100"/>
          </svg>
          <span class="gl-pct" aria-hidden="true"><?= $done ? '<i class="fas fa-trophy"></i>' : $pct . '%' ?></span>
        </div>
        <div class="gl-main">
          <div class="gl-top">
            <h3 class="gl-name"><?= h($g['goal_name']) ?></h3>
            <div class="hb-menu">
              <button type="button" class="pg-icon-btn" onclick="openEditGoal(<?= (int)$g['id'] ?>)" title="Edit" aria-label="Edit <?= h($g['goal_name']) ?>"><i class="fas fa-pen" aria-hidden="true"></i></button>
              <button type="button" class="pg-icon-btn is-danger" onclick="deleteGoal(<?= (int)$g['id'] ?>)" title="Delete" aria-label="Delete <?= h($g['goal_name']) ?>"><i class="fas fa-trash" aria-hidden="true"></i></button>
            </div>
          </div>
          <?php if ($g['description']): ?><p class="gl-desc"><?= h($g['description']) ?></p><?php endif; ?>
          <div class="gl-count">
            <?php if (!$done && empty($g['source'])): ?>
              <button type="button" class="gl-num" data-act="type" title="Tap to type a number" aria-label="Progress <?= $prog ?> of <?= $target ?>, tap to edit"><strong><?= $prog ?></strong></button>
            <?php else: ?>
              <strong><?= $prog ?></strong>
            <?php endif; ?>
            <span>/ <?= $target ?><?= $unit ? ' ' . h($unit) : '' ?></span>
          </div>
          <div class="gl-tags">
            <?php if ($paceTxt): ?><span class="gl-pace"><?= h($paceTxt) ?></span><?php endif; ?>
            <?php if ($dl): ?><span class="gl-dl<?= str_starts_with($dl, 'Ended') ? ' is-late' : '' ?>"><i class="fas fa-flag-checkered" aria-hidden="true"></i> <?= h($dl) ?></span><?php endif; ?>
            <?php if (!empty($g['source'])): ?><span class="goal-source"><i class="fas fa-link" aria-hidden="true"></i> <?= h(goalSourceLabel($g, $userHabits)) ?></span><?php endif; ?>
          </div>
          <?php if (!$done && empty($g['source'])): ?>
            <div class="gl-step" role="group" aria-label="Change progress">
              <button type="button" class="gl-btn" data-act="step" data-delta="-1" aria-label="Minus 1" <?= $prog <= 0 ? 'disabled' : '' ?>><i class="fas fa-minus" aria-hidden="true"></i></button>
              <button type="button" class="gl-btn is-main" data-act="step" data-delta="1">+1</button>
              <?php if ($step > 1): ?><button type="button" class="gl-btn" data-act="step" data-delta="<?= $step ?>">+<?= $step ?></button><?php endif; ?>
            </div>
          <?php endif; ?>
          <button type="button" class="hb-history-btn" onclick="toggleGoalCheckins(<?= (int)$g['id'] ?>, this)" aria-expanded="false">
            <i class="fas fa-clipboard-list" aria-hidden="true"></i> Notes &amp; check-ins
          </button>
          <div id="goal-checkins-<?= (int)$g['id'] ?>" class="gl-checkins" hidden>
            <form class="tdo-subadd" onsubmit="event.preventDefault(); addGoalCheckin(<?= (int)$g['id'] ?>)">
              <input type="text" class="form-input" id="checkin-input-<?= (int)$g['id'] ?>" maxlength="255" placeholder="What did you do towards this?" aria-label="New check-in">
              <button type="submit" class="btn btn-secondary btn-sm" aria-label="Add check-in"><i class="fas fa-plus" aria-hidden="true"></i></button>
            </form>
            <div id="checkin-list-<?= (int)$g['id'] ?>"></div>
          </div>
        </div>
      </article>
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
        <label for="goalName" class="form-label">Goal name <span class="u-c-accent">*</span></label>
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
        <div class="form-group" id="goalProgressWrap">
          <label for="goalProgress" class="form-label">Starting progress</label>
          <input id="goalProgress" class="form-input" type="number" min="0" value="0">
        </div>
      </div>
      <div class="form-group">
        <label for="goalDeadline" class="form-label">Deadline</label>
        <input id="goalDeadline" class="form-input" type="date">
      </div>
      <div class="form-group">
        <label for="goalSource" class="form-label">Progress comes from</label>
        <select id="goalSource" class="form-input">
          <option value="manual">I'll update it myself</option>
          <option value="savings">Savings in Finance (amounts in your currency)</option>
          <?php foreach ($goalSources as $key => [$label]): if ($key === 'habit' && !$userHabits) continue; ?>
            <option value="<?= h($key) ?>"><?= h($label) ?> (automatic)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-grid-2 hidden" id="goalSourceOpts">
        <div class="form-group hidden" id="goalHabitWrap">
          <label for="goalSourceRef" class="form-label">Habit</label>
          <select id="goalSourceRef" class="form-input">
            <?php foreach ($userHabits as $hid => $hname): ?><option value="<?= (int)$hid ?>"><?= h($hname) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="goalSince" class="form-label">Count from</label>
          <input id="goalSince" class="form-input" type="date" max="<?= date('Y-m-d') ?>">
        </div>
      </div>
      <p class="form-hint hidden" id="goalSourceHint" style="margin-top:-.25rem">Progress fills in by itself from what you log. Set the target in the same unit.</p>
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
  document.getElementById('goalSource').value = 'manual';
  document.getElementById('goalSince').value = new Date().toLocaleDateString('en-CA');
  goalSourceUI();
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
    document.getElementById('goalSource').value = g.kind === 'savings' ? 'savings' : (g.source || 'manual');
    if (g.source_ref) document.getElementById('goalSourceRef').value = g.source_ref;
    document.getElementById('goalSince').value = g.source_since || new Date().toLocaleDateString('en-CA');
    goalSourceUI();
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
      kind: document.getElementById('goalSource').value === 'savings' ? 'savings' : 'general',
      source: ['manual', 'savings'].includes(document.getElementById('goalSource').value) ? '' : document.getElementById('goalSource').value,
      source_ref: document.getElementById('goalSourceRef')?.value || '',
      source_since: document.getElementById('goalSince').value,
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

// Show the fields that matter for the chosen progress source.
function goalSourceUI() {
  const v = document.getElementById('goalSource').value;
  const auto = !['manual', 'savings'].includes(v);
  document.getElementById('goalSourceOpts').classList.toggle('hidden', !auto);
  document.getElementById('goalHabitWrap').classList.toggle('hidden', v !== 'habit');
  document.getElementById('goalSourceHint').classList.toggle('hidden', !auto);
  document.getElementById('goalProgressWrap').classList.toggle('hidden', auto);
}
document.getElementById('goalSource').addEventListener('change', goalSourceUI);

// ── Progress: −1 / +1 / +step, or tap the number to type one ─────────
async function setGoalProgress(id, value) {
  const card = document.getElementById('goal-' + id);
  if (!card) return;
  const target = +card.dataset.target, before = +card.dataset.progress;
  const v = Math.max(0, Math.min(target, Math.round(value)));
  if (v === before) return;
  const ringBefore = card.querySelector('.gl-ring')?.style.getPropertyValue('--p');
  card.querySelectorAll('.gl-btn, .gl-num').forEach(b => b.disabled = true);
  if (v > before) Trackie.Delight?.tap();
  try {
    const res = await Trackie.API.post(`${API_BASE}/goals.php`, { action: 'update_progress', goal_id: id, progress: v });
    if (!res.success) throw res;
    await Trackie.refreshFragments(['goal-' + id, 'goalsStatsWrap']);
    // The card was re-rendered: replay the ring from where it was so the change is visible.
    const ring = document.querySelector(`#goal-${id} .gl-ring`);
    if (ring && ringBefore !== undefined) {
      const to = ring.style.getPropertyValue('--p');
      ring.style.setProperty('--p', ringBefore);
      ring.getBoundingClientRect();
      // rAF doesn't run in a background tab; the timer makes sure the ring
      // never stays on the old value.
      const settle = () => ring.style.setProperty('--p', to);
      requestAnimationFrame(settle); setTimeout(settle, 60);
    }
    if (v >= target) {
      Trackie.Delight?.burst(ring || card);
      Trackie.Toast.success(`Goal reached! 🏆${res.xp?.gained ? ` +${res.xp.gained} XP` : ''}`, 4000);
      if (res.achievements?.length) Trackie.showAchievementToasts?.(res.achievements);
    }
    applyGoalFilter();
  } catch (err) {
    Trackie.UI.toastError(err, "Couldn't update the progress.");
    card.querySelectorAll('.gl-btn, .gl-num').forEach(b => b.disabled = false);
  }
}

let goalFilter = new URLSearchParams(location.search).get('status') === 'completed' ? 'done'
               : (new URLSearchParams(location.search).get('status') || 'all');
function applyGoalFilter() {
  document.querySelectorAll('.gl-card').forEach(c => {
    const done = c.dataset.done === '1';
    c.hidden = goalFilter === 'active' ? done : goalFilter === 'done' ? !done : false;
  });
  document.querySelectorAll('[data-goal-filter]').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.goalFilter === goalFilter)));
}

document.getElementById('page-main')?.addEventListener('click', e => {
  const chip = e.target.closest('[data-goal-filter]');
  if (chip) {
    goalFilter = chip.dataset.goalFilter;
    Trackie.UI.transition(applyGoalFilter, [...document.querySelectorAll('.gl-card')]);
    const u = new URL(location.href);
    if (goalFilter === 'all') u.searchParams.delete('status'); else u.searchParams.set('status', goalFilter === 'done' ? 'completed' : goalFilter);
    history.replaceState(history.state, '', u);
    return;
  }
  const btn = e.target.closest('.gl-card [data-act]');
  if (!btn || btn.disabled) return;
  const card = btn.closest('.gl-card'), id = +card.dataset.id;
  if (btn.dataset.act === 'step') return setGoalProgress(id, +card.dataset.progress + +btn.dataset.delta);
  if (btn.dataset.act === 'type') {
    // Swap the number for a small input; Enter saves, Escape / blur cancels.
    const input = Object.assign(document.createElement('input'), {
      type: 'number', min: 0, max: card.dataset.target, value: card.dataset.progress, className: 'form-input gl-num-input',
    });
    input.setAttribute('aria-label', 'New progress');
    btn.replaceWith(input); input.focus(); input.select();
    let done = false;
    const finish = (save, refocus) => {
      if (done) return; done = true;
      if (save && input.value !== '') setGoalProgress(id, +input.value); else input.replaceWith(btn);
      if (refocus && !save) btn.focus();
    };
    input.addEventListener('keydown', ev => { if (ev.key === 'Enter') finish(true); if (ev.key === 'Escape') finish(false, true); });
    input.addEventListener('blur', () => finish(input.value !== card.dataset.progress, false));
  }
});
applyGoalFilter();

async function deleteGoal(id) {
  const ok = await Trackie.confirmDialog('Delete this goal and its check-ins?', { confirmText: 'Delete', danger: true });
  if (!ok) return;
  try {
    const res = await Trackie.API.post(`${API_BASE}/goals.php`, { action: 'delete', goal_id: id });
    if (!res.success) throw res;
    await Trackie.UI.transition(() => document.getElementById(`goal-${id}`)?.remove(), [...document.querySelectorAll('.gl-card')]);
    Trackie.refreshFragments(['goalsStatsWrap']);
    Trackie.Toast.success('Goal deleted.');
  } catch (e) { Trackie.UI.toastError(e, 'Delete failed.'); }
}

function toggleGoalCheckins(id, btn) {
  const panel = document.getElementById('goal-checkins-' + id);
  if (!panel) return;
  panel.hidden = !panel.hidden;
  btn?.setAttribute('aria-expanded', String(!panel.hidden));
  if (!panel.hidden) loadGoalCheckins(id);
}

async function loadGoalCheckins(id) {
  const list = document.getElementById('checkin-list-' + id);
  if (!list) return;
  await Trackie.UI.load(list, () => Trackie.API.post(API_BASE + '/goals.php', { action: 'checkins', goal_id: id }), res => {
    if (!res.checkins.length) { Trackie.UI.empty(list, { compact: true, icon: 'fa-clipboard-list', title: 'No check-ins yet', text: 'Note what you did — it becomes the story of this goal.' }); return; }
    list.innerHTML = '<ul class="gl-log">' + res.checkins.map(c => {
      const when = new Date(c.created_at.replace(' ', 'T')).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
      const prog = c.progress_snapshot !== null ? ` <strong>${escHtml(String(c.progress_snapshot))}</strong>` : '';
      return `<li><span class="gl-log-when">${escHtml(when)}</span> ${c.note ? escHtml(c.note) : '<em>Progress updated</em>'}${prog}</li>`;
    }).join('') + '</ul>';
  }, { label: 'Loading check-ins…', rows: 2, fallback: 'Could not load check-ins.' });
}

async function addGoalCheckin(id) {
  const input = document.getElementById('checkin-input-' + id);
  const note = input?.value.trim();
  if (!note) return;
  try {
    const res = await Trackie.API.post(API_BASE + '/goals.php', { action: 'add_checkin', goal_id: id, note });
    if (!res.success) throw res;
    input.value = '';
    await loadGoalCheckins(id);
  } catch (e) { Trackie.UI.toastError(e, 'Could not add check-in.'); }
}
</script>
