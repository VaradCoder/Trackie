<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Writing';
$currentPage = 'writing';

if (!tableExists('writings')) renderSetupNeeded('Writing');

$shelf = in_array($_GET['shelf'] ?? '', ['idea','drafting','editing','published']) ? $_GET['shelf'] : 'all';
$sql = "SELECT * FROM writings WHERE user_id=?";
$params = [$uid];
if ($shelf !== 'all') { $sql .= " AND status=?"; $params[] = $shelf; }
$sql .= " ORDER BY created_at DESC";
$writings = fetchAll($sql, $params);

$counts = fetchOne("SELECT COUNT(*) total, SUM(status='published') published, SUM(word_count) words FROM writings WHERE user_id=?", [$uid]);
$statusMeta = [
    'idea'      => ['label' => 'Idea',      'icon' => 'fa-lightbulb'],
    'drafting'  => ['label' => 'Drafting',   'icon' => 'fa-pen'],
    'editing'   => ['label' => 'Editing',    'icon' => 'fa-marker'],
    'published' => ['label' => 'Published',  'icon' => 'fa-check'],
];

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem">
  <h1 style="font-size:1.125rem;font-weight:600;margin:0"><i class="fas fa-pen-nib" style="color:var(--accent)"></i> Writing</h1>
  <button class="btn btn-primary btn-sm" onclick="openAddWriting()"><i class="fas fa-plus"></i> New Piece</button>
</div>

<div class="grid-stats" style="margin-bottom:1.5rem" id="writingStatsWrap">
  <div class="stat-card"><div class="stat-val"><?= (int)$counts['total'] ?></div><div class="stat-label">Total pieces</div></div>
  <div class="stat-card"><div class="stat-val"><?= (int)$counts['published'] ?></div><div class="stat-label">Published</div></div>
  <div class="stat-card"><div class="stat-val"><?= number_format((int)$counts['words']) ?></div><div class="stat-label">Words written</div></div>
</div>

<div class="filter-tabs" style="margin-bottom:1.25rem" id="writingTabs">
  <button class="filter-tab active" data-tab="pieces">Pieces</button>
  <button class="filter-tab" data-tab="learn">Learn</button>
</div>

<div id="wtab-pieces" class="gym-tab-panel">
  <div class="filter-tabs" style="margin-bottom:1.25rem">
    <a class="filter-tab <?= $shelf==='all'?'active':'' ?>" href="?shelf=all">All</a>
    <?php foreach ($statusMeta as $k => $m): ?><a class="filter-tab <?= $shelf===$k?'active':'' ?>" href="?shelf=<?= $k ?>"><?= $m['label'] ?></a><?php endforeach; ?>
  </div>
  <div id="writingListWrap">
  <?php if (empty($writings)): ?>
    <div class="card"><div class="empty-state"><div class="empty-state-icon"><i class="fas fa-pen-nib"></i></div><div class="empty-state-title">No pieces yet</div><p>Track drafts, articles, stories — from idea to published.</p>
      <button class="btn btn-primary" style="margin-top:.75rem" onclick="openAddWriting()"><i class="fas fa-plus"></i> Start your first piece</button>
    </div></div>
  <?php else: ?>
    <div class="grid-cards">
      <?php foreach ($writings as $w): $sm = $statusMeta[$w['status']]; ?>
        <div class="habit-card" id="writing-<?= $w['id'] ?>">
          <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:.5rem">
            <div style="min-width:0">
              <div style="font-weight:600;font-size:.9375rem;color:var(--text)"><?= h($w['title']) ?></div>
              <div style="font-size:.8125rem;color:var(--muted)"><?= ucfirst($w['type']) ?></div>
            </div>
            <button aria-label="Delete writing piece" class="btn btn-icon btn-ghost btn-sm" style="color:var(--accent);flex-shrink:0" onclick="deleteWriting(<?= $w['id'] ?>)"><i class="fas fa-trash"></i></button>
          </div>
          <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.75rem">
            <input type="number" min="0" class="form-input" style="width:100px;font-size:.8125rem;padding:.25rem .5rem" value="<?= (int)$w['word_count'] ?>"
                   onchange="updateWords(<?= $w['id'] ?>, this.value)" title="Word count">
            <span style="font-size:.75rem;color:var(--muted)">words</span>
          </div>
          <select class="form-input" style="width:100%;font-size:.8125rem;padding:.375rem .5rem" onchange="setWritingStatus(<?= $w['id'] ?>, this.value)">
            <?php foreach ($statusMeta as $k => $m): ?><option value="<?= $k ?>" <?= $w['status']===$k?'selected':'' ?>><?= $m['label'] ?></option><?php endforeach; ?>
          </select>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  </div>
</div>

<div id="wtab-learn" class="gym-tab-panel hidden">
  <div class="grid-cards">
    <?php foreach ([
      ['title' => 'Bird by Bird', 'desc' => 'Anne Lamott\'s book — widely considered a must-read for aspiring writers', 'icon' => 'fa-book'],
      ['title' => 'The Creative Penn', 'desc' => 'Over 1,000 articles and hundreds of hours of free audio/video on writing, publishing, and marketing', 'icon' => 'fa-feather', 'url' => 'https://www.thecreativepenn.com'],
      ['title' => 'Helping Writers Become Authors', 'desc' => 'A practical guide to outlining and structuring stories and scenes', 'icon' => 'fa-sitemap', 'url' => 'https://helpingwritersbecomeauthors.com'],
      ['title' => 'DIY MFA', 'desc' => 'Tools and tips for improving your writing outside a formal program', 'icon' => 'fa-graduation-cap', 'url' => 'https://diymfa.com'],
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

<!-- Add writing modal -->
<div id="addWritingModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header"><span class="modal-title">New Piece</span><button class="btn btn-icon btn-ghost btn-sm" data-close-modal="addWritingModal" aria-label="Close dialog">&times;</button></div>
    <div class="modal-body">
      <div class="form-group"><label for="writingTitle" class="form-label">Title <span style="color:var(--accent)">*</span></label><input id="writingTitle" class="form-input" placeholder="e.g. Untitled short story"></div>
      <div class="form-group">
        <label for="writingType" class="form-label">Type</label>
        <select id="writingType" class="form-input">
          <option value="draft">Draft</option><option value="article">Article</option><option value="story">Story</option><option value="book">Book</option><option value="idea">Idea</option>
        </select>
      </div>
      <div class="form-group">
        <label for="writingStatus" class="form-label">Status</label>
        <select id="writingStatus" class="form-input"><?php foreach ($statusMeta as $k => $m): ?><option value="<?= $k ?>"><?= $m['label'] ?></option><?php endforeach; ?></select>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary btn-sm" data-close-modal="addWritingModal">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="saveWriting()"><i class="fas fa-save"></i> Add</button>
    </div>
  </div>
</div>

</div>
<?php include '../includes/footer.php'; ?>

<script>
const API_BASE = '<?= APP_BASE ?>/api';

function switchWritingTab(tab) {
  document.querySelectorAll('#writingTabs .filter-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
  document.querySelectorAll('#wtab-pieces, #wtab-learn').forEach(p => p.classList.toggle('hidden', p.id !== `wtab-${tab}`));
}
document.getElementById('writingTabs').addEventListener('click', e => {
  const btn = e.target.closest('[data-tab]');
  if (btn) switchWritingTab(btn.dataset.tab);
});

function openAddWriting() {
  document.getElementById('writingTitle').value = '';
  document.getElementById('writingType').value = 'draft';
  document.getElementById('writingStatus').value = 'idea';
  Trackie.openModal('addWritingModal');
}
async function saveWriting() {
  const title = document.getElementById('writingTitle').value.trim();
  if (!title) { Trackie.Toast.warning('Title is required.'); return; }
  try {
    const res = await Trackie.API.post(`${API_BASE}/writing.php`, {
      action: 'add', title,
      type: document.getElementById('writingType').value,
      status: document.getElementById('writingStatus').value,
    });
    if (res.success) { Trackie.Toast.success('Piece added!'); Trackie.closeModal('addWritingModal'); await Trackie.refreshFragments(['writingStatsWrap', 'writingListWrap']); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function setWritingStatus(id, status) {
  try {
    const res = await Trackie.API.post(`${API_BASE}/writing.php`, {action:'update_status', item_id:id, status});
    if (res.success) { Trackie.Toast.success('Status updated.'); await Trackie.refreshFragments(['writingStatsWrap', 'writingListWrap']); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function updateWords(id, count) {
  try {
    const res = await Trackie.API.post(`${API_BASE}/writing.php`, {action:'update_words', item_id:id, word_count:count});
    if (res.success) await Trackie.refreshFragments(['writingStatsWrap']);
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function deleteWriting(id) {
  const ok = await Trackie.confirmDialog('Delete this piece?', {confirmText:'Delete', danger:true});
  if (!ok) return;
  try {
    const res = await Trackie.API.post(`${API_BASE}/writing.php`, {action:'delete', item_id:id});
    if (res.success) { document.getElementById(`writing-${id}`)?.remove(); Trackie.Toast.success('Deleted.'); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
</script>
