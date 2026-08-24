<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Sports';
$currentPage = 'sports';
$today       = date('Y-m-d');

if (!tableExists('sports_sessions')) renderSetupNeeded('Sports');

$sessions = fetchAll("SELECT * FROM sports_sessions WHERE user_id=? ORDER BY session_date DESC, id DESC LIMIT 30", [$uid]);
$stats = fetchOne(
    "SELECT COUNT(*) total, COUNT(DISTINCT session_date) days,
            COUNT(DISTINCT CASE WHEN session_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) THEN session_date END) week_days,
            SUM(duration_min) minutes
     FROM sports_sessions WHERE user_id=?", [$uid]
);

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem">
  <h1 style="font-size:1.125rem;font-weight:600;margin:0"><i class="fas fa-futbol" style="color:var(--accent)"></i> Sports</h1>
  <button class="btn btn-primary btn-sm" onclick="openLogSession()"><i class="fas fa-plus"></i> Log Session</button>
</div>

<div class="grid-stats" style="margin-bottom:1.5rem" id="sportsStatsWrap">
  <div class="stat-card"><div class="stat-val"><?= (int)$stats['days'] ?></div><div class="stat-label">Active days</div></div>
  <div class="stat-card"><div class="stat-val"><?= (int)$stats['week_days'] ?></div><div class="stat-label">This week</div></div>
  <div class="stat-card"><div class="stat-val"><?= round((int)$stats['minutes'] / 60, 1) ?>h</div><div class="stat-label">Total time</div></div>
</div>

<div class="filter-tabs" style="margin-bottom:1.25rem" id="sportsTabs">
  <button class="filter-tab active" data-tab="log">Log</button>
  <button class="filter-tab" data-tab="learn">Learn</button>
</div>

<div id="stab-log" class="gym-tab-panel">
  <div class="card" id="sportsListWrap">
    <?php if (empty($sessions)): ?>
      <div class="empty-state"><div class="empty-state-icon"><i class="fas fa-futbol"></i></div><div class="empty-state-title">No sessions logged yet</div><p>Track training, matches, and practice.</p></div>
    <?php else: foreach ($sessions as $s): ?>
      <div class="todo-row" id="session-<?= $s['id'] ?>">
        <div style="width:36px;height:36px;border-radius:.5rem;background:var(--accent-bg);display:flex;align-items:center;justify-content:center;flex-shrink:0;color:var(--accent)"><i class="fas fa-futbol" style="font-size:.8125rem"></i></div>
        <div style="flex:1;min-width:0">
          <span class="todo-title"><?= h($s['sport']) ?></span>
          <div class="todo-meta">
            <?= ucfirst($s['session_type']) ?><?= $s['duration_min'] ? ' · ' . (int)$s['duration_min'] . ' min' : '' ?>
            <span style="color:var(--subtle)"><?= formatDate($s['session_date']) ?></span>
          </div>
        </div>
        <button aria-label="Delete sports session" class="btn btn-icon btn-ghost btn-sm" onclick="deleteSession(<?= $s['id'] ?>)"><i class="fas fa-trash" style="font-size:.75rem"></i></button>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<div id="stab-learn" class="gym-tab-panel hidden">
  <div class="grid-cards">
    <?php foreach ([
      ['title' => 'Set a clear training goal', 'desc' => 'Strength → resistance training. Speed/agility → high-intensity aerobic work. Skill → sport-specific drills', 'icon' => 'fa-bullseye'],
      ['title' => 'TrainingPeaks Blog', 'desc' => 'Tips from pro athletes and coaches, adapted for amateur training', 'icon' => 'fa-chart-line', 'url' => 'https://www.trainingpeaks.com/blog'],
      ['title' => 'Recovery matters as much as training', 'desc' => 'Stretching, foam rolling, sleep, and nutrition reduce injury risk and speed up gains', 'icon' => 'fa-bed'],
      ['title' => 'Journal of Sports Science & Medicine', 'desc' => 'Free, research-backed articles across most sports', 'icon' => 'fa-flask', 'url' => 'https://www.jssm.org'],
    ] as $r): ?>
      <?php if (!empty($r['url'])): ?><a href="<?= h($r['url']) ?>" target="_blank" rel="noopener" class="habit-card" style="text-decoration:none;opacity:.9">
      <?php else: ?><div class="habit-card" style="opacity:.9"><?php endif; ?>
        <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.5rem">
          <i class="fas <?= $r['icon'] ?>" style="color:var(--accent);font-size:1.125rem"></i>
          <div style="font-weight:600;font-size:.9375rem;color:var(--text)"><?= h($r['title']) ?></div>
        </div>
        <p style="font-size:.8125rem;color:var(--muted);margin:0"><?= h($r['desc']) ?></p>
      <?= !empty($r['url']) ? '</a>' : '</div>' ?>
    <?php endforeach; ?>
  </div>
</div>

<!-- Log session modal -->
<div id="logSessionModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header"><span class="modal-title">Log Session</span><button class="btn btn-icon btn-ghost btn-sm" data-close-modal="logSessionModal" aria-label="Close dialog">&times;</button></div>
    <div class="modal-body">
      <div class="form-group"><label for="sessSport" class="form-label">Sport <span style="color:var(--accent)">*</span></label><input id="sessSport" class="form-input" placeholder="e.g. Tennis"></div>
      <div class="form-grid-2">
        <div class="form-group">
          <label for="sessType" class="form-label">Type</label>
          <select id="sessType" class="form-input"><option value="training">Training</option><option value="match">Match</option><option value="practice">Practice</option></select>
        </div>
        <div class="form-group"><label for="sessDuration" class="form-label">Duration (min)</label><input id="sessDuration" type="number" min="0" class="form-input"></div>
      </div>
      <div class="form-group"><label for="sessDate" class="form-label">Date</label><input id="sessDate" type="date" class="form-input" value="<?= $today ?>"></div>
      <div class="form-group"><label for="sessNotes" class="form-label">Notes</label><input id="sessNotes" class="form-input" placeholder="Optional"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary btn-sm" data-close-modal="logSessionModal">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="saveSession()"><i class="fas fa-save"></i> Save</button>
    </div>
  </div>
</div>

</div>
<?php include '../includes/footer.php'; ?>

<script>
const API_BASE = '<?= APP_BASE ?>/api';

function switchSportsTab(tab) {
  document.querySelectorAll('#sportsTabs .filter-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
  document.querySelectorAll('#stab-log, #stab-learn').forEach(p => p.classList.toggle('hidden', p.id !== `stab-${tab}`));
}
document.getElementById('sportsTabs').addEventListener('click', e => {
  const btn = e.target.closest('[data-tab]');
  if (btn) switchSportsTab(btn.dataset.tab);
});

function openLogSession() {
  document.getElementById('sessSport').value = '';
  document.getElementById('sessType').value = 'training';
  document.getElementById('sessDuration').value = '';
  document.getElementById('sessDate').value = '<?= $today ?>';
  document.getElementById('sessNotes').value = '';
  Trackie.openModal('logSessionModal');
}
async function saveSession() {
  const sport = document.getElementById('sessSport').value.trim();
  if (!sport) { Trackie.Toast.warning('Sport is required.'); return; }
  try {
    const res = await Trackie.API.post(`${API_BASE}/sports.php`, {
      action: 'log', sport,
      session_type: document.getElementById('sessType').value,
      duration_min: document.getElementById('sessDuration').value,
      session_date: document.getElementById('sessDate').value,
      notes: document.getElementById('sessNotes').value,
    });
    if (res.success) {
      Trackie.closeModal('logSessionModal');
      Trackie.Toast.success('Session logged!' + (res.xp ? ` +${res.xp.gained} XP` : ''));
      await Trackie.refreshFragments(['sportsStatsWrap', 'sportsListWrap']);
    } else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function deleteSession(id) {
  const ok = await Trackie.confirmDialog('Delete this session?', {confirmText:'Delete', danger:true});
  if (!ok) return;
  try {
    const res = await Trackie.API.post(`${API_BASE}/sports.php`, {action:'delete', item_id:id});
    if (res.success) { document.getElementById(`session-${id}`)?.remove(); Trackie.Toast.success('Deleted.'); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
</script>
