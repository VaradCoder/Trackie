<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/activity.php';

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Meditation';
$currentPage = 'meditation';
$today       = date('Y-m-d');

if (!tableExists('meditation_sessions')) renderSetupNeeded('Meditation');

$sessions = fetchAll("SELECT * FROM meditation_sessions WHERE user_id=? ORDER BY session_date DESC, id DESC LIMIT 60", [$uid]);
$stats = fetchOne(
    "SELECT COUNT(*) total, COALESCE(SUM(duration_min),0) minutes,
            COALESCE(SUM(CASE WHEN session_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) THEN duration_min END),0) week_min,
            AVG(CASE WHEN mood_before IS NOT NULL AND mood_after IS NOT NULL THEN mood_after - mood_before END) lift,
            SUM(mood_before IS NOT NULL AND mood_after IS NOT NULL) mood_n
     FROM meditation_sessions WHERE user_id=?", [$uid]
);
$streak = activityReady() ? activityStreak($uid, 'meditation') : ['current' => 0, 'best' => 0];
$moodRows = array_reverse(array_values(array_filter($sessions, static fn($s) => $s['mood_before'] !== null && $s['mood_after'] !== null)));
$moodRows = array_slice($moodRows, -14);
$techniques = ['breath' => 'Breath focus', 'body_scan' => 'Body scan', 'loving_kindness' => 'Loving-kindness',
               'visualization' => 'Visualization', 'mantra' => 'Mantra', 'walking' => 'Walking', 'unguided' => 'Unguided / open'];
$fmtMin = static fn(int $m) => $m >= 60 ? intdiv($m, 60) . 'h' . ($m % 60 ? ' ' . ($m % 60) . 'm' : '') : $m . 'm';

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<div class="rd-head">
  <h1><i class="fas fa-spa" style="color:var(--accent)"></i> Meditation</h1>
  <div class="rd-head-actions"><button class="btn btn-secondary btn-sm" onclick="openLogSession()"><i class="fas fa-plus"></i> Log past session</button></div>
</div>

<div id="medStatsWrap">
  <div class="grid-stats" style="margin-bottom:1.25rem">
    <div class="stat-card"><div class="stat-val">🔥 <?= (int)$streak['current'] ?></div><div class="stat-label">Day streak<?= $streak['best'] > $streak['current'] ? ' · best ' . (int)$streak['best'] : '' ?></div></div>
    <div class="stat-card"><div class="stat-val"><?= $fmtMin((int)$stats['week_min']) ?></div><div class="stat-label">This week</div></div>
    <div class="stat-card"><div class="stat-val"><?= $fmtMin((int)$stats['minutes']) ?></div><div class="stat-label">Total · <?= (int)$stats['total'] ?> sessions</div></div>
    <div class="stat-card"><div class="stat-val"><?= $stats['mood_n'] ? sprintf('%+.1f', (float)$stats['lift']) : '—' ?></div><div class="stat-label">Avg mood lift<?= $stats['mood_n'] ? ' · ' . (int)$stats['mood_n'] . ' rated' : '' ?></div></div>
  </div>
</div>

<!-- Guided timer -->
<div class="card card-body md-timer" id="mdTimer">
  <div class="md-ring"><div class="md-time" id="mdTime">10:00</div><div class="md-phase" id="mdPhase">Ready</div></div>
  <div class="md-controls">
    <div class="filter-tabs" id="mdPresets">
      <?php foreach ([5, 10, 15, 20, 30] as $p): ?><button class="filter-tab<?= $p === 10 ? ' active' : '' ?>" data-min="<?= $p ?>"><?= $p ?> min</button><?php endforeach; ?>
    </div>
    <div class="rd-form-row" style="margin-top:.75rem">
      <div class="form-group"><label for="mdTechnique" class="form-label">Technique</label>
        <select id="mdTechnique" class="form-input"><?php foreach ($techniques as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label for="mdBell" class="form-label">Interval bell</label>
        <select id="mdBell" class="form-input"><option value="0">Only at the end</option><option value="1">Every minute</option><option value="5">Every 5 minutes</option><option value="10">Every 10 minutes</option></select></div>
    </div>
    <div class="rd-timer-btns">
      <button class="btn btn-primary" id="mdStart" onclick="mdToggle()"><i class="fas fa-play"></i> Begin</button>
      <button class="btn btn-secondary" onclick="mdFinish(true)" id="mdEnd" disabled>End &amp; log</button>
      <button class="btn btn-ghost" onclick="mdReset()">Reset</button>
    </div>
  </div>
</div>

<div class="hb-grid2" style="margin-top:1.25rem">
  <div class="card card-body">
    <div class="fit-card-label">Mood before → after · last <?= count($moodRows) ?> rated sessions</div>
    <?php if (!$moodRows): ?>
      <p class="hb-empty-line">Rate your mood before and after a session to see whether meditation lifts it.</p>
    <?php else: ?>
      <div class="md-mood">
        <?php foreach ($moodRows as $r): ?>
          <div class="md-mood-col" title="<?= h(formatDate($r['session_date'])) ?>: <?= (int)$r['mood_before'] ?> → <?= (int)$r['mood_after'] ?>">
            <span class="md-bar md-before" style="height:<?= (int)$r['mood_before'] * 20 ?>%"></span>
            <span class="md-bar md-after" style="height:<?= (int)$r['mood_after'] * 20 ?>%"></span>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="hb-foot"><span class="md-key md-before"></span> before &nbsp; <span class="md-key md-after"></span> after (1–5)</p>
    <?php endif; ?>
  </div>
  <div class="card card-body">
    <div class="fit-card-label">By technique</div>
    <?php $byTech = [];
      foreach ($sessions as $s) if ($s['technique']) { $byTech[$s['technique']] = ($byTech[$s['technique']] ?? 0) + (int)$s['duration_min']; }
      arsort($byTech); ?>
    <?php if (!$byTech): ?><p class="hb-empty-line">Pick a technique when you log to see what you practise most.</p><?php endif; ?>
    <?php foreach ($byTech as $k => $m): ?><div class="hb-row"><span><?= h($techniques[$k] ?? $k) ?></span><b><?= $fmtMin($m) ?></b></div><?php endforeach; ?>
  </div>
</div>

<div class="fit-section-head" style="margin-top:1.25rem"><h2 class="hb-h2">Sessions</h2></div>
<div id="medListWrap">
  <?php if (!$sessions): ?>
    <div class="card card-body hb-empty-line">No sessions yet — start the timer above, or log a past session.</div>
  <?php else: ?>
    <div class="card">
      <?php foreach ($sessions as $s): ?>
        <div class="todo-row" id="med-<?= (int)$s['id'] ?>">
          <div style="flex:1;min-width:0">
            <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
              <span class="todo-title"><?= $fmtMin((int)$s['duration_min']) ?></span>
              <?php if ($s['technique']): ?><span class="category-badge"><?= h($techniques[$s['technique']] ?? $s['technique']) ?></span><?php endif; ?>
              <?php if ($s['mood_before'] && $s['mood_after']): ?><span class="badge badge-gray">Mood <?= (int)$s['mood_before'] ?>→<?= (int)$s['mood_after'] ?></span><?php endif; ?>
            </div>
            <div class="todo-meta"><span><?= h(formatDate($s['session_date'])) ?></span><?php if ($s['notes']): ?><span><?= h($s['notes']) ?></span><?php endif; ?></div>
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

<!-- Log / edit -->
<div id="logSessionModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header"><span class="modal-title" id="medModalTitle">Log session</span><button class="btn btn-icon btn-ghost btn-sm" data-close-modal="logSessionModal" aria-label="Close dialog">&times;</button></div>
    <div class="modal-body">
      <input type="hidden" id="medId">
      <div class="rd-form-row">
        <div class="form-group"><label for="medDuration" class="form-label">Minutes <span style="color:var(--accent)">*</span></label><input id="medDuration" type="number" min="1" max="600" class="form-input"></div>
        <div class="form-group"><label for="medDate" class="form-label">Date</label><input id="medDate" type="date" class="form-input" max="<?= $today ?>"></div>
      </div>
      <div class="form-group"><label for="medTech" class="form-label">Technique</label>
        <select id="medTech" class="form-input"><option value="">—</option><?php foreach ($techniques as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
      <div class="rd-form-row">
        <div class="form-group"><label for="medBefore" class="form-label">Mood before (1–5)</label><select id="medBefore" class="form-input"><option value="">—</option><?php for ($i = 1; $i <= 5; $i++): ?><option><?= $i ?></option><?php endfor; ?></select></div>
        <div class="form-group"><label for="medAfter" class="form-label">Mood after (1–5)</label><select id="medAfter" class="form-input"><option value="">—</option><?php for ($i = 1; $i <= 5; $i++): ?><option><?= $i ?></option><?php endfor; ?></select></div>
      </div>
      <div class="form-group"><label for="medNotes" class="form-label">Notes</label><textarea id="medNotes" class="form-input" rows="2" maxlength="500"></textarea></div>
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
const MED_FIELDS = { medDuration: 'duration_min', medDate: 'session_date', medTech: 'technique', medBefore: 'mood_before', medAfter: 'mood_after', medNotes: 'notes' };

/* ── Guided timer (state survives navigation) ─────────────────── */
const MD_KEY = 'trackie.medTimer';
let mdTick = null;
function mdState() { try { return JSON.parse(localStorage.getItem(MD_KEY)) || null; } catch { return null; } }
function mdSave(s) { try { s ? localStorage.setItem(MD_KEY, JSON.stringify(s)) : localStorage.removeItem(MD_KEY); } catch {} }
function mdPreset() { return +(document.querySelector('#mdPresets .active')?.dataset.min || 10); }
function mdElapsed(s) { return s ? s.acc + (s.start ? Date.now() - s.start : 0) : 0; }
function mdFmt(ms) { const t = Math.max(0, Math.round(ms / 1000)); return `${Math.floor(t / 60)}:${String(t % 60).padStart(2, '0')}`; }
let mdCtx = null;
function mdBell(strong = false) {
  try {
    mdCtx = mdCtx || new (window.AudioContext || window.webkitAudioContext)();
    [528, 792].forEach((f, i) => {
      const o = mdCtx.createOscillator(), g = mdCtx.createGain();
      o.type = 'sine'; o.frequency.value = f;
      g.gain.setValueAtTime(strong ? .35 : .2, mdCtx.currentTime + i * .02);
      g.gain.exponentialRampToValueAtTime(.0001, mdCtx.currentTime + (strong ? 4 : 2.5));
      o.connect(g).connect(mdCtx.destination); o.start(); o.stop(mdCtx.currentTime + (strong ? 4 : 2.5));
    });
  } catch {}
}
function mdRender() {
  const s = mdState();
  const total = (s ? s.minutes : mdPreset()) * 60000;
  const left = total - mdElapsed(s);
  document.getElementById('mdTime').textContent = mdFmt(left);
  document.getElementById('mdPhase').textContent = !s ? 'Ready' : s.start ? 'Breathe' : 'Paused';
  document.getElementById('mdStart').innerHTML = s && s.start ? '<i class="fas fa-pause"></i> Pause' : `<i class="fas fa-play"></i> ${s ? 'Resume' : 'Begin'}`;
  document.getElementById('mdEnd').disabled = !s;
  document.querySelector('.md-ring').style.setProperty('--p', s ? Math.min(1, mdElapsed(s) / total) : 0);
  if (s && s.start) {
    const mins = Math.floor(mdElapsed(s) / 60000);
    if (s.bell > 0 && mins > (s.rang || 0) && mins % s.bell === 0 && left > 1000) { s.rang = mins; mdSave(s); mdBell(); }
    if (left <= 0) mdFinish(false);
  }
}
function mdToggle() {
  let s = mdState();
  if (!s) { s = { minutes: mdPreset(), bell: +document.getElementById('mdBell').value, technique: document.getElementById('mdTechnique').value, acc: 0, start: Date.now(), rang: 0 }; mdBell(); }
  else if (s.start) { s.acc += Date.now() - s.start; s.start = null; }
  else s.start = Date.now();
  mdSave(s); mdRender();
  clearInterval(mdTick); if (s.start) mdTick = setInterval(mdRender, 500);
}
function mdReset() { mdSave(null); clearInterval(mdTick); mdRender(); }
function mdFinish(early) {
  const s = mdState(); if (!s) return;
  clearInterval(mdTick);
  const minutes = Math.max(1, Math.round(mdElapsed(s) / 60000));
  mdSave(null); mdRender();
  if (!early) mdBell(true);
  openLogSession({ duration_min: minutes, technique: s.technique });
  document.getElementById('medModalTitle').textContent = early ? 'Log this session' : 'Session complete — how do you feel?';
}
document.getElementById('mdPresets').addEventListener('click', e => {
  const b = e.target.closest('[data-min]'); if (!b || mdState()) return;
  document.querySelectorAll('#mdPresets [data-min]').forEach(x => x.classList.toggle('active', x === b)); mdRender();
});
mdRender(); if (mdState()?.start) mdTick = setInterval(mdRender, 500);

/* ── Log / edit ───────────────────────────────────────────────── */
function openLogSession(prefill = {}) {
  document.getElementById('medId').value = '';
  Object.entries(MED_FIELDS).forEach(([i, k]) => document.getElementById(i).value = prefill[k] ?? '');
  if (!prefill.duration_min) document.getElementById('medDuration').value = 10;
  document.getElementById('medDate').value = '<?= $today ?>';
  document.getElementById('medModalTitle').textContent = 'Log session';
  Trackie.openModal('logSessionModal');
}
async function editSession(id) {
  try {
    const res = await Trackie.API.post(`${API_BASE}/meditation.php`, { action: 'get', item_id: id });
    if (!res.success) { Trackie.Toast.error(res.error || 'Not found.'); return; }
    document.getElementById('medId').value = res.session.id;
    Object.entries(MED_FIELDS).forEach(([i, k]) => document.getElementById(i).value = res.session[k] ?? '');
    document.getElementById('medModalTitle').textContent = 'Edit session';
    Trackie.openModal('logSessionModal');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function saveSession() {
  const id = document.getElementById('medId').value;
  const data = { action: id ? 'edit' : 'log', item_id: id };
  Object.entries(MED_FIELDS).forEach(([i, k]) => data[k] = document.getElementById(i).value);
  if (!(+data.duration_min >= 1)) { Trackie.Toast.warning('Minutes are required.'); return; }
  try {
    const res = await Trackie.API.post(`${API_BASE}/meditation.php`, data);
    if (res.success) {
      Trackie.closeModal('logSessionModal');
      Trackie.Toast.success(id ? 'Session updated.' : 'Session logged!' + (res.xp?.ok ? ` +${res.xp.gained} XP` : ''));
      Trackie.SpaNav.refresh();
    } else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function deleteSession(id) {
  const ok = await Trackie.confirmDialog('Delete this session?', { confirmText: 'Delete', danger: true });
  if (!ok) return;
  try {
    const res = await Trackie.API.post(`${API_BASE}/meditation.php`, { action: 'delete', item_id: id });
    if (res.success) { document.getElementById(`med-${id}`)?.remove(); Trackie.Toast.success('Deleted.'); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
</script>
