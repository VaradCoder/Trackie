<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Art';
$currentPage = 'art';

if (!tableExists('artworks')) renderSetupNeeded('Art');

$shelf = in_array($_GET['shelf'] ?? '', ['in_progress','completed']) ? $_GET['shelf'] : 'all';
$sql = "SELECT * FROM artworks WHERE user_id=?";
$params = [$uid];
if ($shelf !== 'all') { $sql .= " AND status=?"; $params[] = $shelf; }
$sql .= " ORDER BY created_at DESC";
$artworks = fetchAll($sql, $params);

$counts = fetchOne("SELECT COUNT(*) total, SUM(status='in_progress') in_progress, SUM(status='completed') completed FROM artworks WHERE user_id=?", [$uid]);
$statusMeta = ['in_progress' => ['label' => 'In progress', 'icon' => 'fa-pen'], 'completed' => ['label' => 'Completed', 'icon' => 'fa-check']];

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem">
  <h1 style="font-size:1.125rem;font-weight:600;margin:0"><i class="fas fa-palette" style="color:var(--accent)"></i> Art</h1>
  <button class="btn btn-primary btn-sm" onclick="openAddArt()"><i class="fas fa-plus"></i> Add Piece</button>
</div>

<div class="grid-stats" style="margin-bottom:1.5rem" id="artStatsWrap">
  <div class="stat-card"><div class="stat-val"><?= (int)$counts['total'] ?></div><div class="stat-label">Total pieces</div></div>
  <div class="stat-card"><div class="stat-val"><?= (int)$counts['in_progress'] ?></div><div class="stat-label">In progress</div></div>
  <div class="stat-card"><div class="stat-val"><?= (int)$counts['completed'] ?></div><div class="stat-label">Completed</div></div>
</div>

<div class="filter-tabs" style="margin-bottom:1.25rem" id="artTabs">
  <button class="filter-tab active" data-tab="gallery">Gallery</button>
  <button class="filter-tab" data-tab="learn">Learn</button>
</div>

<div id="atab-gallery" class="gym-tab-panel">
  <div class="filter-tabs" style="margin-bottom:1.25rem">
    <a class="filter-tab <?= $shelf==='all'?'active':'' ?>" href="?shelf=all">All</a>
    <?php foreach ($statusMeta as $k => $m): ?><a class="filter-tab <?= $shelf===$k?'active':'' ?>" href="?shelf=<?= $k ?>"><?= $m['label'] ?></a><?php endforeach; ?>
  </div>
  <div id="artListWrap">
  <?php if (empty($artworks)): ?>
    <div class="card"><div class="empty-state"><div class="empty-state-icon"><i class="fas fa-palette"></i></div><div class="empty-state-title">Your sketchbook is empty</div><p>Log pieces as you make them — sketches, studies, finished work.</p>
      <button class="btn btn-primary" style="margin-top:.75rem" onclick="openAddArt()"><i class="fas fa-plus"></i> Add your first piece</button>
    </div></div>
  <?php else: ?>
    <div class="grid-cards">
      <?php foreach ($artworks as $a): $sm = $statusMeta[$a['status']]; ?>
        <div class="habit-card" id="art-<?= $a['id'] ?>">
          <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:.5rem">
            <div style="min-width:0">
              <div style="font-weight:600;font-size:.9375rem;color:var(--text)"><?= h($a['title']) ?></div>
              <?php if ($a['medium']): ?><div style="font-size:.8125rem;color:var(--muted)"><?= h($a['medium']) ?></div><?php endif; ?>
            </div>
            <button aria-label="Delete artwork" class="btn btn-icon btn-ghost btn-sm" style="color:var(--accent);flex-shrink:0" onclick="deleteArt(<?= $a['id'] ?>)"><i class="fas fa-trash"></i></button>
          </div>
          <?php if ($a['notes']): ?><p style="font-size:.8125rem;color:var(--muted);margin:0 0 .75rem"><?= h($a['notes']) ?></p><?php endif; ?>
          <select class="form-input" style="width:100%;font-size:.8125rem;padding:.375rem .5rem" onchange="setArtStatus(<?= $a['id'] ?>, this.value)">
            <?php foreach ($statusMeta as $k => $m): ?><option value="<?= $k ?>" <?= $a['status']===$k?'selected':'' ?>><?= $m['label'] ?></option><?php endforeach; ?>
          </select>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  </div>
</div>

<div id="atab-learn" class="gym-tab-panel hidden">
  <div class="grid-cards">
    <?php foreach ([
      ['title' => 'Drawabox', 'desc' => 'Free structured lessons on perspective and constructive drawing — the standard beginner starting point', 'icon' => 'fa-cube', 'url' => 'https://drawabox.com'],
      ['title' => 'Proko', 'desc' => 'The best free resource for human anatomy, with clear, engaging video lessons', 'icon' => 'fa-person', 'url' => 'https://www.proko.com'],
      ['title' => 'Line of Action', 'desc' => 'Free timed figure-drawing practice sessions with reference images', 'icon' => 'fa-stopwatch', 'url' => 'https://line-of-action.com'],
      ['title' => 'Andrew Loomis books', 'desc' => 'Classic drawing fundamentals texts — decades old but still the reference many artists learn from', 'icon' => 'fa-book'],
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

<!-- Add art modal -->
<div id="addArtModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header"><span class="modal-title">Add Piece</span><button class="btn btn-icon btn-ghost btn-sm" data-close-modal="addArtModal" aria-label="Close dialog">&times;</button></div>
    <div class="modal-body">
      <div class="form-group"><label for="artTitle" class="form-label">Title <span style="color:var(--accent)">*</span></label><input id="artTitle" class="form-input" placeholder="e.g. Portrait study"></div>
      <div class="form-group"><label for="artMedium" class="form-label">Medium</label><input id="artMedium" class="form-input" placeholder="e.g. Pencil, Digital"></div>
      <div class="form-group"><label for="artNotes" class="form-label">Notes</label><input id="artNotes" class="form-input" placeholder="Optional"></div>
      <div class="form-group">
        <label for="artStatus" class="form-label">Status</label>
        <select id="artStatus" class="form-input"><?php foreach ($statusMeta as $k => $m): ?><option value="<?= $k ?>"><?= $m['label'] ?></option><?php endforeach; ?></select>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary btn-sm" data-close-modal="addArtModal">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="saveArt()"><i class="fas fa-save"></i> Add</button>
    </div>
  </div>
</div>

</div>
<?php include '../includes/footer.php'; ?>

<script>
const API_BASE = '<?= APP_BASE ?>/api';

function switchArtTab(tab) {
  document.querySelectorAll('#artTabs .filter-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
  document.querySelectorAll('#atab-gallery, #atab-learn').forEach(p => p.classList.toggle('hidden', p.id !== `atab-${tab}`));
}
document.getElementById('artTabs').addEventListener('click', e => {
  const btn = e.target.closest('[data-tab]');
  if (btn) switchArtTab(btn.dataset.tab);
});

function openAddArt() {
  document.getElementById('artTitle').value = '';
  document.getElementById('artMedium').value = '';
  document.getElementById('artNotes').value = '';
  document.getElementById('artStatus').value = 'in_progress';
  Trackie.openModal('addArtModal');
}
async function saveArt() {
  const title = document.getElementById('artTitle').value.trim();
  if (!title) { Trackie.Toast.warning('Title is required.'); return; }
  try {
    const res = await Trackie.API.post(`${API_BASE}/art.php`, {
      action: 'add', title,
      medium: document.getElementById('artMedium').value.trim(),
      notes: document.getElementById('artNotes').value.trim(),
      status: document.getElementById('artStatus').value,
    });
    if (res.success) { Trackie.Toast.success('Piece added!'); Trackie.closeModal('addArtModal'); await Trackie.refreshFragments(['artStatsWrap', 'artListWrap']); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function setArtStatus(id, status) {
  try {
    const res = await Trackie.API.post(`${API_BASE}/art.php`, {action:'update_status', item_id:id, status});
    if (res.success) { Trackie.Toast.success('Status updated.'); await Trackie.refreshFragments(['artStatsWrap', 'artListWrap']); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function deleteArt(id) {
  const ok = await Trackie.confirmDialog('Delete this piece?', {confirmText:'Delete', danger:true});
  if (!ok) return;
  try {
    const res = await Trackie.API.post(`${API_BASE}/art.php`, {action:'delete', item_id:id});
    if (res.success) { document.getElementById(`art-${id}`)?.remove(); Trackie.Toast.success('Deleted.'); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
</script>
