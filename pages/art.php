<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/activity.php';

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Art';
$currentPage = 'art';
$today       = date('Y-m-d');

if (!tableExists('artworks')) renderSetupNeeded('Art');
if (!tableExists('art_sessions')) renderSetupNeeded('Art');

$artworks = fetchAll(
    "SELECT a.*, (SELECT COALESCE(SUM(minutes),0) FROM art_sessions s WHERE s.artwork_id=a.id) minutes
     FROM artworks a WHERE a.user_id=? ORDER BY a.status='in_progress' DESC, a.created_at DESC", [$uid]
);
$sessions = fetchAll("SELECT s.*, a.title FROM art_sessions s LEFT JOIN artworks a ON a.id=s.artwork_id WHERE s.user_id=? ORDER BY s.session_date DESC, s.id DESC LIMIT 40", [$uid]);
$weekMin = (int)fetchOne("SELECT COALESCE(SUM(minutes),0) m FROM art_sessions WHERE user_id=? AND session_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)", [$uid])['m'];
$streak = activityReady() ? activityStreak($uid, 'art') : ['current' => 0, 'best' => 0];
$fmtMin = static fn(int $m) => $m >= 60 ? intdiv($m, 60) . 'h' . ($m % 60 ? ' ' . ($m % 60) . 'm' : '') : $m . 'm';
$file = APP_BASE . '/api/art_file.php';

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<div class="rd-head">
  <h1><i class="fas fa-palette" style="color:var(--accent)"></i> Art</h1>
  <div class="rd-head-actions">
    <button class="btn btn-secondary btn-sm" onclick="openPractice()"><i class="fas fa-stopwatch"></i> Log practice</button>
    <button class="btn btn-primary btn-sm" onclick="openArt()"><i class="fas fa-plus"></i> Add piece</button>
  </div>
</div>

<div class="grid-stats" style="margin-bottom:1.25rem">
  <div class="stat-card"><div class="stat-val">🔥 <?= (int)$streak['current'] ?></div><div class="stat-label">Practice streak<?= $streak['best'] > $streak['current'] ? ' · best ' . (int)$streak['best'] : '' ?></div></div>
  <div class="stat-card"><div class="stat-val"><?= $fmtMin($weekMin) ?></div><div class="stat-label">Practised this week</div></div>
  <div class="stat-card"><div class="stat-val"><?= count(array_filter($artworks, fn($a) => $a['status'] === 'in_progress')) ?></div><div class="stat-label">In progress</div></div>
  <div class="stat-card"><div class="stat-val"><?= count(array_filter($artworks, fn($a) => $a['status'] === 'completed')) ?></div><div class="stat-label">Completed</div></div>
</div>

<div class="filter-tabs" style="margin-bottom:1.25rem" id="artTabs" role="tablist">
  <button class="filter-tab active" data-tab="gallery" role="tab" aria-selected="true">Gallery</button>
  <button class="filter-tab" data-tab="practice" role="tab" aria-selected="false" tabindex="-1">Practice log</button>
</div>

<div id="attab-gallery">
  <?php if (!$artworks): ?>
    <div class="card"><div class="empty-state"><div class="empty-state-icon"><i class="fas fa-palette"></i></div>
      <div class="empty-state-title">Your gallery is empty</div><p>Add a piece with a photo of your work, and log practice time to build a streak.</p>
      <button class="btn btn-primary" style="margin-top:.75rem" onclick="openArt()"><i class="fas fa-plus"></i> Add your first piece</button></div></div>
  <?php else: ?>
    <div class="ph-masonry">
      <?php foreach ($artworks as $a): ?>
        <div class="art-card" id="art-<?= (int)$a['id'] ?>">
          <button class="art-img" onclick="openArt(<?= (int)$a['id'] ?>)" aria-label="Edit <?= h($a['title']) ?>">
            <?php if ($a['image_file']): ?><img src="<?= h($file) ?>?id=<?= (int)$a['id'] ?>&amp;s=t" alt="<?= h($a['title']) ?>" loading="lazy">
            <?php else: ?><span class="art-noimg"><i class="fas fa-image"></i></span><?php endif; ?>
          </button>
          <div class="art-body">
            <div class="rd-title"><?= h($a['title']) ?></div>
            <div class="rd-author"><?= h(implode(' · ', array_filter([$a['medium'], (int)$a['minutes'] ? $fmtMin((int)$a['minutes']) . ' practised' : null]))) ?></div>
            <span class="rd-badge<?= $a['status'] === 'completed' ? ' rd-badge-done' : '' ?>"><?= $a['status'] === 'completed' ? 'Completed' : 'In progress' ?></span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div id="attab-practice" class="hidden">
  <?php if (!$sessions): ?><div class="card card-body hb-empty-line">No practice logged yet.</div>
  <?php else: ?>
    <div class="card">
      <?php foreach ($sessions as $s): ?>
        <div class="todo-row" id="asess-<?= (int)$s['id'] ?>">
          <div style="flex:1;min-width:0"><span class="todo-title"><?= $fmtMin((int)$s['minutes']) ?></span><?php if ($s['title']): ?> <span class="category-badge"><?= h($s['title']) ?></span><?php endif; ?>
            <div class="todo-meta"><span><?= h(formatDate($s['session_date'])) ?></span><?php if ($s['notes']): ?><span><?= h($s['notes']) ?></span><?php endif; ?></div></div>
          <button class="btn btn-icon btn-ghost btn-sm" style="color:var(--accent)" onclick="deletePractice(<?= (int)$s['id'] ?>)" aria-label="Delete practice"><i class="fas fa-trash"></i></button>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- Piece add/edit -->
<div id="artModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header"><span class="modal-title" id="artModalTitle">Add piece</span><button class="btn btn-icon btn-ghost btn-sm" data-close-modal="artModal" aria-label="Close dialog">&times;</button></div>
    <div class="modal-body">
      <input type="hidden" id="arId">
      <div id="arPreview" class="art-preview hidden"></div>
      <div class="form-group"><label for="arImage" class="form-label">Photo of the piece</label><input id="arImage" type="file" accept="image/jpeg,image/png,image/webp" class="form-input">
        <p class="form-hint">Stored privately; resized and location data removed before upload.</p></div>
      <div class="form-group"><label for="arTitle" class="form-label">Title <span style="color:var(--accent)">*</span></label><input id="arTitle" class="form-input" maxlength="150"></div>
      <div class="rd-form-row">
        <div class="form-group"><label for="arMedium" class="form-label">Medium</label><input id="arMedium" class="form-input" maxlength="60" placeholder="e.g. Watercolor, Procreate"></div>
        <div class="form-group"><label for="arStatus" class="form-label">Status</label><select id="arStatus" class="form-input"><option value="in_progress">In progress</option><option value="completed">Completed</option></select></div>
      </div>
      <div class="form-group"><label for="arNotes" class="form-label">Notes</label><textarea id="arNotes" class="form-input" rows="2" maxlength="500"></textarea></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost btn-sm hidden" id="arDelete" style="color:var(--accent);margin-right:auto" onclick="deleteArt()"><i class="fas fa-trash"></i> Delete</button>
      <button class="btn btn-secondary btn-sm" data-close-modal="artModal">Cancel</button>
      <button class="btn btn-primary btn-sm" id="arSave" onclick="saveArt()"><i class="fas fa-save"></i> Save</button>
    </div>
  </div>
</div>

<!-- Practice -->
<div id="practiceModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header"><span class="modal-title">Log practice</span><button class="btn btn-icon btn-ghost btn-sm" data-close-modal="practiceModal" aria-label="Close dialog">&times;</button></div>
    <div class="modal-body">
      <div class="rd-form-row">
        <div class="form-group"><label for="apMin" class="form-label">Minutes <span style="color:var(--accent)">*</span></label><input id="apMin" type="number" min="1" max="1440" class="form-input" value="30"></div>
        <div class="form-group"><label for="apDate" class="form-label">Date</label><input id="apDate" type="date" class="form-input" max="<?= $today ?>"></div>
      </div>
      <div class="form-group"><label for="apArt" class="form-label">Piece</label><select id="apArt" class="form-input"><option value="">General practice / studies</option><?php foreach ($artworks as $a): ?><option value="<?= (int)$a['id'] ?>"><?= h($a['title']) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label for="apNotes" class="form-label">What did you practise?</label><input id="apNotes" class="form-input" maxlength="500" placeholder="e.g. hands, perspective boxes"></div>
    </div>
    <div class="modal-footer"><button class="btn btn-secondary btn-sm" data-close-modal="practiceModal">Cancel</button><button class="btn btn-primary btn-sm" onclick="savePractice()">Save</button></div>
  </div>
</div>

</div>
<?php include '../includes/footer.php'; ?>
<script src="<?= assetUrl('assets/js/photo-exif.js') ?>"></script>
<script>
const API_BASE = '<?= APP_BASE ?>/api';
const ART_FILE = '<?= h($file) ?>';
document.getElementById('artTabs').addEventListener('click', e => {
  const b = e.target.closest('[data-tab]'); if (!b) return;
  document.querySelectorAll('#artTabs [data-tab]').forEach(x => { const on = x === b; x.classList.toggle('active', on); x.setAttribute('aria-selected', on); });
  ['gallery', 'practice'].forEach(t => document.getElementById(`attab-${t}`).classList.toggle('hidden', t !== b.dataset.tab));
});
async function openArt(id) {
  document.getElementById('arId').value = id || '';
  ['arTitle', 'arMedium', 'arNotes', 'arImage'].forEach(i => document.getElementById(i).value = '');
  document.getElementById('arStatus').value = 'in_progress';
  document.getElementById('arPreview').classList.add('hidden');
  document.getElementById('arDelete').classList.toggle('hidden', !id);
  document.getElementById('artModalTitle').textContent = id ? 'Edit piece' : 'Add piece';
  if (id) {
    const res = await Trackie.API.post(`${API_BASE}/art.php`, { action: 'get', item_id: id });
    if (!res.success) { Trackie.Toast.error(res.error || 'Not found.'); return; }
    const a = res.artwork;
    document.getElementById('arTitle').value = a.title; document.getElementById('arMedium').value = a.medium || '';
    document.getElementById('arNotes').value = a.notes || ''; document.getElementById('arStatus').value = a.status;
    if (a.image_file) { const p = document.getElementById('arPreview'); p.innerHTML = `<img src="${ART_FILE}?id=${+a.id}" alt="">`; p.classList.remove('hidden'); }
  }
  Trackie.openModal('artModal');
}
async function saveArt() {
  const title = document.getElementById('arTitle').value.trim();
  if (!title) { Trackie.Toast.warning('Title is required.'); return; }
  const id = document.getElementById('arId').value, btn = document.getElementById('arSave'); btn.disabled = true;
  const fd = new FormData();
  fd.append('action', id ? 'edit' : 'add'); fd.append('item_id', id); fd.append('title', title);
  fd.append('medium', document.getElementById('arMedium').value); fd.append('notes', document.getElementById('arNotes').value);
  fd.append('status', document.getElementById('arStatus').value);
  const f = document.getElementById('arImage').files[0];
  try {
    if (f) { const blob = window.PhotoExif ? await PhotoExif.prepare(f) : f; fd.append('image', blob, 'art.jpg'); }
    const res = await Trackie.API.post(`${API_BASE}/art.php`, fd);
    if (res.success) { Trackie.Toast.success('Saved.'); location.reload(); } else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Upload failed.'); } finally { btn.disabled = false; }
}
async function deleteArt() {
  if (!await Trackie.confirmDialog('Delete this piece and its image?', { confirmText: 'Delete', danger: true })) return;
  const r = await Trackie.API.post(`${API_BASE}/art.php`, { action: 'delete', item_id: document.getElementById('arId').value });
  if (r.success) location.reload();
}
function openPractice() { document.getElementById('apDate').value = '<?= $today ?>'; document.getElementById('apNotes').value = ''; Trackie.openModal('practiceModal'); }
async function savePractice() {
  const res = await Trackie.API.post(`${API_BASE}/art.php`, { action: 'session_log', minutes: document.getElementById('apMin').value,
    session_date: document.getElementById('apDate').value, artwork_id: document.getElementById('apArt').value, notes: document.getElementById('apNotes').value });
  if (res.success) { Trackie.Toast.success('Practice logged' + (res.xp?.ok ? ` · +${res.xp.gained} XP` : '')); location.reload(); } else Trackie.Toast.error(res.error || 'Failed.');
}
async function deletePractice(id) { const r = await Trackie.API.post(`${API_BASE}/art.php`, { action: 'session_delete', session_id: id }); if (r.success) document.getElementById(`asess-${id}`)?.remove(); }
</script>
