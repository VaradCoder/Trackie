<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Meditation';
$currentPage = 'meditation';

if (!tableExists('meditation_sessions')) renderSetupNeeded('Meditation');

$sessions = fetchAll("SELECT * FROM meditation_sessions WHERE user_id=? ORDER BY session_date DESC, id DESC LIMIT 30", [$uid]);
$stats = fetchOne(
    "SELECT COUNT(*) total, COUNT(DISTINCT session_date) days,
            COUNT(DISTINCT CASE WHEN session_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) THEN session_date END) week_days,
            SUM(duration_min) minutes, AVG(mood_after - mood_before) mood_delta
     FROM meditation_sessions WHERE user_id=?", [$uid]
);

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem">
  <h1 style="font-size:1.125rem;font-weight:600;margin:0"><i class="fas fa-spa" style="color:var(--accent)"></i> Meditation</h1>
  <button class="btn btn-primary btn-sm" onclick="openLogSession()"><i class="fas fa-plus"></i> Log Session</button>
</div>

<div class="grid-stats" style="margin-bottom:1.5rem" id="medStatsWrap">
  <div class="stat-card"><div class="stat-val"><?= (int)$stats['days'] ?></div><div class="stat-label">Days practiced</div></div>
  <div class="stat-card"><div class="stat-val"><?= (int)$stats['week_days'] ?></div><div class="stat-label">This week</div></div>
  <div class="stat-card"><div class="stat-val"><?= round((int)$stats['minutes'] / 60, 1) ?>h</div><div class="stat-label">Total time</div></div>
</div>

<div class="filter-tabs" style="margin-bottom:1.25rem" id="medTabs">
  <button class="filter-tab active" data-tab="log">Log</button>
  <button class="filter-tab" data-tab="learn">Learn</button>
</div>

<div id="mdtab-log" class="gym-tab-panel">
  <div class="card" id="medListWrap">
    <?php if (empty($sessions)): ?>
      <div class="empty-state"><div class="empty-state-icon"><i class="fas fa-spa"></i></div><div class="empty-state-title">No sessions logged yet</div><p>Track your practice and how your mood shifts.</p></div>
    <?php else: foreach ($sessions as $s): ?>
      <div class="todo-row" id="med-<?= $s['id'] ?>">
        <div style="width:36px;height:36px;border-radius:.5rem;background:var(--accent-bg);display:flex;align-items:center;justify-content:center;flex-shrink:0;color:var(--accent)"><i class="fas fa-spa" style="font-size:.8125rem"></i></div>
        <div style="flex:1;min-width:0">
          <span class="todo-title"><?= (int)$s['duration_min'] ?> min session</span>
          <div class="todo-meta">
            <?php if ($s['mood_before'] && $s['mood_after']): ?>Mood <?= (int)$s['mood_before'] ?>→<?= (int)$s['mood_after'] ?><?php endif; ?>
            <span style="color:var(--subtle)"><?= formatDate($s['session_date']) ?></span>
          </div>
        </div>
        <button aria-label="Delete meditation session" class="btn btn-icon btn-ghost btn-sm" onclick="deleteSession(<?= $s['id'] ?>)"><i class="fas fa-trash" style="font-size:.75rem"></i></button>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<div id="mdtab-learn" class="gym-tab-panel hidden">
  <div class="grid-cards">
    <?php foreach ([
      ['title' => 'Palouse Mindfulness', 'desc' => 'A free, complete 8-week mindfulness-based stress reduction (MBSR) course', 'icon' => 'fa-graduation-cap', 'url' => 'https://palousemindfulness.com'],
      ['title' => 'Insight Timer', 'desc' => 'Free guided meditations and talks from teachers at Stanford, Harvard, and Oxford', 'icon' => 'fa-om', 'url' => 'https://insighttimer.com'],
      ['title' => 'Mindfulness Exercises', 'desc' => '15 free, self-paced online courses — no sign-up required', 'icon' => 'fa-book-open', 'url' => 'https://mindfulnessexercises.com'],
      ['title' => 'Start with just 5 minutes', 'desc' => 'Consistency matters more than duration when building a meditation habit', 'icon' => 'fa-clock'],
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
      <div class="form-group"><label for="medDuration" class="form-label">Duration (min) <span style="color:var(--accent)">*</span></label><input id="medDuration" type="number" min="1" class="form-input" value="10"></div>
      <div class="form-grid-2">
        <div class="form-group"><label for="medBefore" class="form-label">Mood before (1-5)</label><input id="medBefore" type="number" min="1" max="5" class="form-input"></div>
        <div class="form-group"><label for="medAfter" class="form-label">Mood after (1-5)</label><input id="medAfter" type="number" min="1" max="5" class="form-input"></div>
      </div>
      <div class="form-group"><label for="medNotes" class="form-label">Notes</label><input id="medNotes" class="form-input" placeholder="Optional"></div>
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

function switchMedTab(tab) {
  document.querySelectorAll('#medTabs .filter-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
  document.querySelectorAll('#mdtab-log, #mdtab-learn').forEach(p => p.classList.toggle('hidden', p.id !== `mdtab-${tab}`));
}
document.getElementById('medTabs').addEventListener('click', e => {
  const btn = e.target.closest('[data-tab]');
  if (btn) switchMedTab(btn.dataset.tab);
});

function openLogSession() {
  document.getElementById('medDuration').value = 10;
  document.getElementById('medBefore').value = '';
  document.getElementById('medAfter').value = '';
  document.getElementById('medNotes').value = '';
  Trackie.openModal('logSessionModal');
}
async function saveSession() {
  const duration = document.getElementById('medDuration').value;
  if (!duration || duration < 1) { Trackie.Toast.warning('Duration is required.'); return; }
  try {
    const res = await Trackie.API.post(`${API_BASE}/meditation.php`, {
      action: 'log', duration_min: duration,
      mood_before: document.getElementById('medBefore').value,
      mood_after: document.getElementById('medAfter').value,
      notes: document.getElementById('medNotes').value,
    });
    if (res.success) {
      Trackie.closeModal('logSessionModal');
      Trackie.Toast.success('Session logged!' + (res.xp ? ` +${res.xp.gained} XP` : ''));
      await Trackie.refreshFragments(['medStatsWrap', 'medListWrap']);
    } else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function deleteSession(id) {
  const ok = await Trackie.confirmDialog('Delete this session?', {confirmText:'Delete', danger:true});
  if (!ok) return;
  try {
    const res = await Trackie.API.post(`${API_BASE}/meditation.php`, {action:'delete', item_id:id});
    if (res.success) { document.getElementById(`med-${id}`)?.remove(); Trackie.Toast.success('Deleted.'); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
</script>
