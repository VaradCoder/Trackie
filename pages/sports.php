<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/activity.php';

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Sports';
$currentPage = 'sports';
$today       = date('Y-m-d');

if (!tableExists('sports_sessions')) renderSetupNeeded('Sports');

$sessions = fetchAll("SELECT * FROM sports_sessions WHERE user_id=? ORDER BY session_date DESC, id DESC LIMIT 60", [$uid]);
$stats = fetchOne(
    "SELECT COUNT(*) total, COALESCE(SUM(duration_min),0) minutes,
            COUNT(DISTINCT CASE WHEN session_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) THEN session_date END) week_days
     FROM sports_sessions WHERE user_id=?", [$uid]
);
$bySport = fetchAll(
    "SELECT sport, COUNT(*) sessions, COALESCE(SUM(duration_min),0) minutes, MAX(session_date) last,
            SUM(result='win') wins, SUM(result='loss') losses, SUM(result='draw') draws, AVG(intensity) intensity
     FROM sports_sessions WHERE user_id=? GROUP BY sport ORDER BY sessions DESC", [$uid]
);
$streak = activityReady() ? activityStreak($uid, 'sports') : ['current' => 0, 'best' => 0];
$sportNames = array_column($bySport, 'sport');
$typeLabel = ['training' => 'Training', 'match' => 'Match', 'practice' => 'Practice', 'casual' => 'Casual'];
$fmtMin = static fn(int $m) => $m >= 60 ? intdiv($m, 60) . 'h' . ($m % 60 ? ' ' . ($m % 60) . 'm' : '') : $m . 'm';

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<div class="rd-head">
  <h1><i class="fas fa-futbol" style="color:var(--accent)"></i> Sports</h1>
  <div class="rd-head-actions"><button class="btn btn-primary btn-sm" onclick="openLogSession()"><i class="fas fa-plus"></i> Log session</button></div>
</div>

<div id="sportsStatsWrap">
  <div class="grid-stats" style="margin-bottom:1.25rem">
    <div class="stat-card"><div class="stat-val">🔥 <?= (int)$streak['current'] ?></div><div class="stat-label">Day streak<?= $streak['best'] > $streak['current'] ? ' · best ' . (int)$streak['best'] : '' ?></div></div>
    <div class="stat-card"><div class="stat-val"><?= (int)$stats['total'] ?></div><div class="stat-label">Sessions logged</div></div>
    <div class="stat-card"><div class="stat-val"><?= $fmtMin((int)$stats['minutes']) ?></div><div class="stat-label">Time played</div></div>
    <div class="stat-card"><div class="stat-val"><?= (int)$stats['week_days'] ?>/7</div><div class="stat-label">Active days this week</div></div>
  </div>
</div>

<div class="filter-tabs" style="margin-bottom:1.25rem" id="sportsTabs" role="tablist">
  <button class="filter-tab active" data-tab="log" role="tab" aria-selected="true">Sessions</button>
  <button class="filter-tab" data-tab="sports" role="tab" aria-selected="false" tabindex="-1">By sport</button>
</div>

<div id="stab-log">
<div id="sportsListWrap">
  <?php if (!$sessions): ?>
    <div class="card"><div class="empty-state"><div class="empty-state-icon"><i class="fas fa-futbol"></i></div>
      <div class="empty-state-title">No sessions yet</div><p>Log training, practice and matches — results and scores build your record per sport.</p>
      <button class="btn btn-primary" style="margin-top:.75rem" onclick="openLogSession()"><i class="fas fa-plus"></i> Log your first session</button></div></div>
  <?php else: ?>
    <div class="card">
      <?php foreach ($sessions as $s): ?>
        <div class="todo-row" id="session-<?= (int)$s['id'] ?>">
          <div style="flex:1;min-width:0">
            <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
              <span class="todo-title"><?= h($s['sport']) ?></span>
              <span class="category-badge"><?= h($typeLabel[$s['session_type']] ?? ucfirst($s['session_type'])) ?></span>
              <?php if ($s['result']): ?>
                <span class="badge <?= ['win' => 'badge-green', 'loss' => 'badge-red', 'draw' => 'badge-gray'][$s['result']] ?? 'badge-gray' ?>"><?= ucfirst($s['result']) ?><?= $s['score'] ? ' · ' . h($s['score']) : '' ?></span>
              <?php endif; ?>
            </div>
            <div class="todo-meta" style="margin-top:.125rem">
              <span><?= h(formatDate($s['session_date'])) ?></span>
              <?php if ($s['duration_min']): ?><span><?= $fmtMin((int)$s['duration_min']) ?></span><?php endif; ?>
              <?php if ($s['intensity']): ?><span>Intensity <?= str_repeat('●', (int)$s['intensity']) . str_repeat('○', 5 - (int)$s['intensity']) ?></span><?php endif; ?>
              <?php if ($s['notes']): ?><span><?= h($s['notes']) ?></span><?php endif; ?>
            </div>
          </div>
          <div class="todo-actions">
            <button class="btn btn-icon btn-ghost btn-sm" onclick="editSession(<?= (int)$s['id'] ?>)" aria-label="Edit session" title="Edit"><i class="fas fa-pen"></i></button>
            <button class="btn btn-icon btn-ghost btn-sm" style="color:var(--accent)" onclick="deleteSession(<?= (int)$s['id'] ?>)" aria-label="Delete session" title="Delete"><i class="fas fa-trash"></i></button>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
</div>

<div id="stab-sports" class="hidden">
  <?php if (!$bySport): ?>
    <div class="card card-body hb-empty-line">Log a session to see stats per sport.</div>
  <?php else: ?>
    <div class="grid-cards">
      <?php foreach ($bySport as $b): $games = (int)$b['wins'] + (int)$b['losses'] + (int)$b['draws']; ?>
        <div class="habit-card">
          <div class="rd-title" style="margin-bottom:.5rem"><?= h($b['sport']) ?></div>
          <div class="hb-row"><span>Sessions</span><b><?= (int)$b['sessions'] ?></b></div>
          <div class="hb-row"><span>Time</span><b><?= $fmtMin((int)$b['minutes']) ?></b></div>
          <div class="hb-row"><span>Last played</span><b><?= h(formatDate($b['last'], 'M j')) ?></b></div>
          <?php if ($games): ?>
            <div class="hb-row"><span>Record (W–L–D)</span><b><?= (int)$b['wins'] ?>–<?= (int)$b['losses'] ?>–<?= (int)$b['draws'] ?></b></div>
            <div class="hb-row"><span>Win rate</span><b><?= (int)round($b['wins'] / $games * 100) ?>%</b></div>
          <?php endif; ?>
          <?php if ($b['intensity']): ?><div class="hb-row"><span>Avg intensity</span><b><?= round((float)$b['intensity'], 1) ?>/5</b></div><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="hb-foot">Record and win rate count only matches with a result.</p>
  <?php endif; ?>
</div>

<!-- Log / edit session -->
<div id="logSessionModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header"><span class="modal-title" id="sessModalTitle">Log session</span><button class="btn btn-icon btn-ghost btn-sm" data-close-modal="logSessionModal" aria-label="Close dialog">&times;</button></div>
    <div class="modal-body">
      <input type="hidden" id="sessId">
      <div class="rd-form-row">
        <div class="form-group"><label for="sessSport" class="form-label">Sport <span style="color:var(--accent)">*</span></label>
          <input id="sessSport" class="form-input" list="sportList" maxlength="50" placeholder="e.g. Football, Badminton">
          <datalist id="sportList"><?php foreach ($sportNames as $n): ?><option value="<?= h($n) ?>"><?php endforeach; ?></datalist></div>
        <div class="form-group"><label for="sessType" class="form-label">Type</label>
          <select id="sessType" class="form-input" onchange="sportsTypeUI()"><?php foreach ($typeLabel as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="rd-form-row" id="sessMatchRow">
        <div class="form-group"><label for="sessResult" class="form-label">Result</label>
          <select id="sessResult" class="form-input"><option value="">—</option><option value="win">Win</option><option value="loss">Loss</option><option value="draw">Draw</option></select></div>
        <div class="form-group"><label for="sessScore" class="form-label">Score</label><input id="sessScore" class="form-input" maxlength="40" placeholder="e.g. 3–1, 21–18 21–15"></div>
      </div>
      <div class="rd-form-row">
        <div class="form-group"><label for="sessDuration" class="form-label">Duration (min)</label><input id="sessDuration" type="number" min="1" max="1440" class="form-input"></div>
        <div class="form-group"><label for="sessDate" class="form-label">Date</label><input id="sessDate" type="date" class="form-input" max="<?= $today ?>"></div>
      </div>
      <div class="form-group"><label for="sessIntensity" class="form-label">Intensity (how hard it felt)</label>
        <select id="sessIntensity" class="form-input"><option value="">—</option><option value="1">1 · Easy</option><option value="2">2</option><option value="3">3 · Moderate</option><option value="4">4</option><option value="5">5 · All-out</option></select></div>
      <div class="form-group"><label for="sessNotes" class="form-label">Notes</label><textarea id="sessNotes" class="form-input" rows="2" maxlength="500"></textarea></div>
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
const SESS_FIELDS = { sessSport: 'sport', sessType: 'session_type', sessResult: 'result', sessScore: 'score',
                      sessDuration: 'duration_min', sessDate: 'session_date', sessIntensity: 'intensity', sessNotes: 'notes' };
function switchSportsTab(tab) {
  document.querySelectorAll('#sportsTabs .filter-tab').forEach(b => { const on = b.dataset.tab === tab; b.classList.toggle('active', on); b.setAttribute('aria-selected', on); });
  ['log', 'sports'].forEach(t => document.getElementById(`stab-${t}`).classList.toggle('hidden', t !== tab));
}
document.getElementById('sportsTabs').addEventListener('click', e => { const b = e.target.closest('[data-tab]'); if (b) switchSportsTab(b.dataset.tab); });
function sportsTypeUI() { document.getElementById('sessMatchRow').classList.toggle('hidden', document.getElementById('sessType').value !== 'match'); }
function openLogSession() {
  document.getElementById('sessId').value = '';
  Object.keys(SESS_FIELDS).forEach(i => document.getElementById(i).value = '');
  document.getElementById('sessType').value = 'training';
  document.getElementById('sessDate').value = '<?= $today ?>';
  document.getElementById('sessModalTitle').textContent = 'Log session';
  sportsTypeUI();
  Trackie.openModal('logSessionModal');
}
async function editSession(id) {
  try {
    const res = await Trackie.API.post(`${API_BASE}/sports.php`, { action: 'get', item_id: id });
    if (!res.success) { Trackie.Toast.error(res.error || 'Not found.'); return; }
    document.getElementById('sessId').value = res.session.id;
    Object.entries(SESS_FIELDS).forEach(([i, k]) => document.getElementById(i).value = res.session[k] ?? '');
    document.getElementById('sessModalTitle').textContent = 'Edit session';
    sportsTypeUI();
    Trackie.openModal('logSessionModal');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function saveSession() {
  if (!document.getElementById('sessSport').value.trim()) { Trackie.Toast.warning('Sport is required.'); return; }
  const id = document.getElementById('sessId').value;
  const data = { action: id ? 'edit' : 'log', item_id: id };
  Object.entries(SESS_FIELDS).forEach(([i, k]) => data[k] = document.getElementById(i).value);
  try {
    const res = await Trackie.API.post(`${API_BASE}/sports.php`, data);
    if (res.success) {
      Trackie.closeModal('logSessionModal');
      Trackie.Toast.success(id ? 'Session updated.' : 'Session logged!' + (res.xp?.ok ? ` +${res.xp.gained} XP` : ''));
      location.reload();
    } else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function deleteSession(id) {
  const ok = await Trackie.confirmDialog('Delete this session?', { confirmText: 'Delete', danger: true });
  if (!ok) return;
  try {
    const res = await Trackie.API.post(`${API_BASE}/sports.php`, { action: 'delete', item_id: id });
    if (res.success) { document.getElementById(`session-${id}`)?.remove(); Trackie.Toast.success('Deleted.'); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
</script>
