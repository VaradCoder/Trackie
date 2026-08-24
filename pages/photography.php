<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Photography';
$currentPage = 'photography';
$today       = date('Y-m-d');

if (!tableExists('photos')) renderSetupNeeded('Photography');

$shelf = in_array($_GET['shelf'] ?? '', ['to_edit','edited']) ? $_GET['shelf'] : 'all';
$sql = "SELECT * FROM photos WHERE user_id=?";
$params = [$uid];
if ($shelf !== 'all') { $sql .= " AND status=?"; $params[] = $shelf; }
$sql .= " ORDER BY created_at DESC";
$photos = fetchAll($sql, $params);

$counts = fetchOne("SELECT COUNT(*) total, SUM(status='to_edit') to_edit, SUM(status='edited') edited FROM photos WHERE user_id=?", [$uid]);
$statusMeta = ['to_edit' => ['label' => 'To edit', 'icon' => 'fa-hourglass-half'], 'edited' => ['label' => 'Edited', 'icon' => 'fa-check']];

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem">
  <h1 style="font-size:1.125rem;font-weight:600;margin:0"><i class="fas fa-camera" style="color:var(--accent)"></i> Photography</h1>
  <button class="btn btn-primary btn-sm" onclick="openAddPhoto()"><i class="fas fa-plus"></i> Log Shoot</button>
</div>

<div class="grid-stats" style="margin-bottom:1.5rem" id="photoStatsWrap">
  <div class="stat-card"><div class="stat-val"><?= (int)$counts['total'] ?></div><div class="stat-label">Total shoots</div></div>
  <div class="stat-card"><div class="stat-val"><?= (int)$counts['to_edit'] ?></div><div class="stat-label">To edit</div></div>
  <div class="stat-card"><div class="stat-val"><?= (int)$counts['edited'] ?></div><div class="stat-label">Edited</div></div>
</div>

<div class="filter-tabs" style="margin-bottom:1.25rem" id="photoTabs">
  <button class="filter-tab active" data-tab="gallery">Gallery</button>
  <button class="filter-tab" data-tab="learn">Learn</button>
</div>

<div id="ptab-gallery" class="gym-tab-panel">
  <div class="filter-tabs" style="margin-bottom:1.25rem">
    <a class="filter-tab <?= $shelf==='all'?'active':'' ?>" href="?shelf=all">All</a>
    <?php foreach ($statusMeta as $k => $m): ?><a class="filter-tab <?= $shelf===$k?'active':'' ?>" href="?shelf=<?= $k ?>"><?= $m['label'] ?></a><?php endforeach; ?>
  </div>
  <div id="photoListWrap">
  <?php if (empty($photos)): ?>
    <div class="card"><div class="empty-state"><div class="empty-state-icon"><i class="fas fa-camera"></i></div><div class="empty-state-title">No shoots logged yet</div><p>Track locations, gear used, and your edit backlog.</p>
      <button class="btn btn-primary" style="margin-top:.75rem" onclick="openAddPhoto()"><i class="fas fa-plus"></i> Log your first shoot</button>
    </div></div>
  <?php else: ?>
    <div class="grid-cards">
      <?php foreach ($photos as $p): $sm = $statusMeta[$p['status']]; ?>
        <div class="habit-card" id="photo-<?= $p['id'] ?>">
          <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:.5rem">
            <div style="min-width:0">
              <div style="font-weight:600;font-size:.9375rem;color:var(--text)"><?= h($p['title']) ?></div>
              <div style="font-size:.8125rem;color:var(--muted)"><?= h($p['location'] ?? '') ?><?= $p['location'] && $p['camera'] ? ' · ' : '' ?><?= h($p['camera'] ?? '') ?></div>
            </div>
            <button aria-label="Delete photo" class="btn btn-icon btn-ghost btn-sm" style="color:var(--accent);flex-shrink:0" onclick="deletePhoto(<?= $p['id'] ?>)"><i class="fas fa-trash"></i></button>
          </div>
          <?php if ($p['taken_date']): ?><div style="font-size:.75rem;color:var(--subtle);margin-bottom:.75rem"><?= formatDate($p['taken_date']) ?></div><?php endif; ?>
          <select class="form-input" style="width:100%;font-size:.8125rem;padding:.375rem .5rem" onchange="setPhotoStatus(<?= $p['id'] ?>, this.value)">
            <?php foreach ($statusMeta as $k => $m): ?><option value="<?= $k ?>" <?= $p['status']===$k?'selected':'' ?>><?= $m['label'] ?></option><?php endforeach; ?>
          </select>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  </div>
</div>

<div id="ptab-learn" class="gym-tab-panel hidden">
  <div class="grid-cards">
    <?php foreach ([
      ['title' => 'MIT OpenCourseWare — Intro to Photography', 'desc' => 'Free full-semester video lectures covering fundamentals of analog and digital SLR photography', 'icon' => 'fa-graduation-cap', 'url' => 'https://ocw.mit.edu'],
      ['title' => 'Photography Life', 'desc' => 'Deep, free guides on exposure, composition, and gear', 'icon' => 'fa-book-open', 'url' => 'https://photographylife.com/learn-photography'],
      ['title' => 'Understanding Exposure', 'desc' => 'Bryan Peterson\'s widely-recommended book on aperture, shutter speed, and ISO', 'icon' => 'fa-book'],
      ['title' => 'Strobist', 'desc' => 'Free, foundational lighting tutorials — start here to understand flash and off-camera light', 'icon' => 'fa-bolt', 'url' => 'https://strobist.blogspot.com'],
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

<!-- Add photo modal -->
<div id="addPhotoModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header"><span class="modal-title">Log Shoot</span><button class="btn btn-icon btn-ghost btn-sm" data-close-modal="addPhotoModal" aria-label="Close dialog">&times;</button></div>
    <div class="modal-body">
      <div class="form-group"><label for="photoTitle" class="form-label">Title <span style="color:var(--accent)">*</span></label><input id="photoTitle" class="form-input" placeholder="e.g. Sunset at the pier"></div>
      <div class="form-grid-2">
        <div class="form-group"><label for="photoLocation" class="form-label">Location</label><input id="photoLocation" class="form-input"></div>
        <div class="form-group"><label for="photoCamera" class="form-label">Camera</label><input id="photoCamera" class="form-input"></div>
      </div>
      <div class="form-group"><label for="photoDate" class="form-label">Date taken</label><input id="photoDate" type="date" class="form-input" value="<?= $today ?>"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary btn-sm" data-close-modal="addPhotoModal">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="savePhoto()"><i class="fas fa-save"></i> Add</button>
    </div>
  </div>
</div>

</div>
<?php include '../includes/footer.php'; ?>

<script>
const API_BASE = '<?= APP_BASE ?>/api';

function switchPhotoTab(tab) {
  document.querySelectorAll('#photoTabs .filter-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
  document.querySelectorAll('#ptab-gallery, #ptab-learn').forEach(p => p.classList.toggle('hidden', p.id !== `ptab-${tab}`));
}
document.getElementById('photoTabs').addEventListener('click', e => {
  const btn = e.target.closest('[data-tab]');
  if (btn) switchPhotoTab(btn.dataset.tab);
});

function openAddPhoto() {
  document.getElementById('photoTitle').value = '';
  document.getElementById('photoLocation').value = '';
  document.getElementById('photoCamera').value = '';
  document.getElementById('photoDate').value = '<?= $today ?>';
  Trackie.openModal('addPhotoModal');
}
async function savePhoto() {
  const title = document.getElementById('photoTitle').value.trim();
  if (!title) { Trackie.Toast.warning('Title is required.'); return; }
  try {
    const res = await Trackie.API.post(`${API_BASE}/photography.php`, {
      action: 'add', title,
      location: document.getElementById('photoLocation').value.trim(),
      camera: document.getElementById('photoCamera').value.trim(),
      taken_date: document.getElementById('photoDate').value,
    });
    if (res.success) { Trackie.Toast.success('Shoot logged!'); Trackie.closeModal('addPhotoModal'); await Trackie.refreshFragments(['photoStatsWrap', 'photoListWrap']); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function setPhotoStatus(id, status) {
  try {
    const res = await Trackie.API.post(`${API_BASE}/photography.php`, {action:'update_status', item_id:id, status});
    if (res.success) { Trackie.Toast.success('Status updated.'); await Trackie.refreshFragments(['photoStatsWrap', 'photoListWrap']); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function deletePhoto(id) {
  const ok = await Trackie.confirmDialog('Delete this entry?', {confirmText:'Delete', danger:true});
  if (!ok) return;
  try {
    const res = await Trackie.API.post(`${API_BASE}/photography.php`, {action:'delete', item_id:id});
    if (res.success) { document.getElementById(`photo-${id}`)?.remove(); Trackie.Toast.success('Deleted.'); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
</script>
