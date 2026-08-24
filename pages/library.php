<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Library';
$currentPage = 'library';

if (!tableExists('books')) renderSetupNeeded('Library');

$shelf = in_array($_GET['shelf'] ?? '', ['want','reading','finished']) ? $_GET['shelf'] : 'all';

$sql = "SELECT * FROM books WHERE user_id=?";
$params = [$uid];
if ($shelf !== 'all') { $sql .= " AND status=?"; $params[] = $shelf; }
$sql .= " ORDER BY created_at DESC";
$books = fetchAll($sql, $params);

$counts = fetchOne(
    "SELECT COUNT(*) total,
            SUM(status='want')     want,
            SUM(status='reading')  reading,
            SUM(status='finished') finished
     FROM books WHERE user_id=?",
    [$uid]
);

$statusMeta = [
    'want'     => ['label' => 'Want to read', 'icon' => 'fa-bookmark',     'color' => '#64748b'],
    'reading'  => ['label' => 'Reading',       'icon' => 'fa-book-open',   'color' => '#3b82f6'],
    'finished' => ['label' => 'Finished',      'icon' => 'fa-check-circle','color' => '#16a34a'],
];

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem">
  <h1 style="font-size:1.125rem;font-weight:600;margin:0"><i class="fas fa-book" style="color:var(--accent)"></i> Library</h1>
  <button class="btn btn-primary btn-sm" onclick="openAddBook()"><i class="fas fa-plus"></i> Add Book</button>
</div>

<div class="grid-stats" style="margin-bottom:1.5rem" id="libraryStatsWrap">
  <div class="stat-card"><div class="stat-val"><?= (int)$counts['total'] ?></div><div class="stat-label">Total books</div></div>
  <div class="stat-card"><div class="stat-val"><?= (int)$counts['want'] ?></div><div class="stat-label">Want to read</div></div>
  <div class="stat-card"><div class="stat-val"><?= (int)$counts['reading'] ?></div><div class="stat-label">Reading now</div></div>
  <div class="stat-card"><div class="stat-val"><?= (int)$counts['finished'] ?></div><div class="stat-label">Finished</div></div>
</div>

<div class="filter-tabs" style="margin-bottom:1.25rem">
  <a class="filter-tab <?= $shelf==='all'?'active':'' ?>" href="?shelf=all">All</a>
  <?php foreach ($statusMeta as $k => $m): ?>
    <a class="filter-tab <?= $shelf===$k?'active':'' ?>" href="?shelf=<?= $k ?>"><?= $m['label'] ?></a>
  <?php endforeach; ?>
</div>

<div id="libraryListWrap">
<?php if (empty($books)): ?>
  <div class="card">
    <div class="empty-state">
      <div class="empty-state-icon"><i class="fas fa-book"></i></div>
      <div class="empty-state-title">No books here yet</div>
      <p>Build your own small library — track what you want to read, are reading, and have finished.</p>
      <button class="btn btn-primary" style="margin-top:.75rem" onclick="openAddBook()"><i class="fas fa-plus"></i> Add your first book</button>
    </div>
  </div>
<?php else: ?>
  <div class="grid-cards">
    <?php foreach ($books as $b): $sm = $statusMeta[$b['status']]; ?>
      <div class="habit-card" id="book-<?= $b['id'] ?>">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:.5rem">
          <div style="min-width:0">
            <div style="font-weight:600;font-size:.9375rem;color:var(--text)"><?= h($b['title']) ?></div>
            <?php if ($b['author']): ?><div style="font-size:.8125rem;color:var(--muted)"><?= h($b['author']) ?></div><?php endif; ?>
          </div>
          <button aria-label="Delete book" class="btn btn-icon btn-ghost btn-sm" style="color:var(--accent);flex-shrink:0" onclick="deleteBook(<?= $b['id'] ?>)" title="Delete">
            <i class="fas fa-trash"></i>
          </button>
        </div>

        <div style="margin-bottom:.625rem" id="stars-<?= $b['id'] ?>">
          <?php for ($i = 1; $i <= 5; $i++): ?>
            <i class="<?= $b['rating'] >= $i ? 'fas' : 'far' ?> fa-star" style="color:#f59e0b;cursor:pointer;font-size:.8125rem"
               onclick="rateBook(<?= $b['id'] ?>, <?= $i ?>)"></i>
          <?php endfor; ?>
        </div>

        <select class="form-input" style="width:100%;font-size:.8125rem;padding:.375rem .5rem" onchange="setBookStatus(<?= $b['id'] ?>, this.value)">
          <?php foreach ($statusMeta as $k => $m): ?>
            <option value="<?= $k ?>" <?= $b['status']===$k?'selected':'' ?>><?= $m['label'] ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
</div>

<!-- Add book modal -->
<div id="addBookModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header">
      <span class="modal-title">Add Book</span>
      <button class="btn btn-icon btn-ghost btn-sm" data-close-modal="addBookModal" aria-label="Close dialog">&times;</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label for="bookTitle" class="form-label">Title <span style="color:var(--accent)">*</span></label>
        <input id="bookTitle" class="form-input" placeholder="e.g. Atomic Habits">
      </div>
      <div class="form-group">
        <label for="bookAuthor" class="form-label">Author</label>
        <input id="bookAuthor" class="form-input" placeholder="Optional">
      </div>
      <div class="form-group">
        <label for="bookStatus" class="form-label">Shelf</label>
        <select id="bookStatus" class="form-input">
          <?php foreach ($statusMeta as $k => $m): ?><option value="<?= $k ?>"><?= $m['label'] ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary btn-sm" data-close-modal="addBookModal">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="saveBook()"><i class="fas fa-save"></i> Add</button>
    </div>
  </div>
</div>

</div>
<?php include '../includes/footer.php'; ?>

<script>
const API_BASE = '<?= APP_BASE ?>/api';

function openAddBook() {
  document.getElementById('bookTitle').value = '';
  document.getElementById('bookAuthor').value = '';
  document.getElementById('bookStatus').value = 'want';
  Trackie.openModal('addBookModal');
}
async function saveBook() {
  const title = document.getElementById('bookTitle').value.trim();
  if (!title) { Trackie.Toast.warning('Title is required.'); return; }
  try {
    const res = await Trackie.API.post(`${API_BASE}/library.php`, {
      action: 'add', title,
      author: document.getElementById('bookAuthor').value.trim(),
      status: document.getElementById('bookStatus').value,
    });
    if (res.success) { Trackie.Toast.success('Book added!'); Trackie.closeModal('addBookModal'); await Trackie.refreshFragments(['libraryStatsWrap', 'libraryListWrap']); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function setBookStatus(id, status) {
  try {
    const res = await Trackie.API.post(`${API_BASE}/library.php`, {action:'update_status', book_id:id, status});
    if (res.success) {
      Trackie.Toast.success(status === 'finished' ? 'Finished! +30 XP 🎉' : 'Shelf updated.');
      await Trackie.refreshFragments(['libraryStatsWrap', 'libraryListWrap']);
    } else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function rateBook(id, rating) {
  const stars = document.querySelectorAll(`#stars-${id} i`);
  stars.forEach((s, i) => s.className = (i < rating ? 'fas' : 'far') + ' fa-star');
  try {
    const res = await Trackie.API.post(`${API_BASE}/library.php`, {action:'rate', book_id:id, rating});
    if (!res.success) Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function deleteBook(id) {
  const ok = await Trackie.confirmDialog('Delete this book?', {confirmText:'Delete', danger:true});
  if (!ok) return;
  try {
    const res = await Trackie.API.post(`${API_BASE}/library.php`, {action:'delete', book_id:id});
    if (res.success) { document.getElementById(`book-${id}`)?.remove(); Trackie.Toast.success('Book deleted.'); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
</script>
