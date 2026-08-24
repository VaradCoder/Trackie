<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Cooking';
$currentPage = 'cooking';

if (!tableExists('recipes')) renderSetupNeeded('Cooking');

$shelf = in_array($_GET['shelf'] ?? '', ['want_to_try','tried','favorite']) ? $_GET['shelf'] : 'all';
$sql = "SELECT * FROM recipes WHERE user_id=?";
$params = [$uid];
if ($shelf !== 'all') { $sql .= " AND status=?"; $params[] = $shelf; }
$sql .= " ORDER BY created_at DESC";
$recipes = fetchAll($sql, $params);

$counts = fetchOne(
    "SELECT COUNT(*) total, SUM(status='want_to_try') want, SUM(status='tried') tried, SUM(status='favorite') favorite
     FROM recipes WHERE user_id=?", [$uid]
);

$statusMeta = [
    'want_to_try' => ['label' => 'Want to try', 'icon' => 'fa-bookmark'],
    'tried'       => ['label' => 'Tried',       'icon' => 'fa-utensils'],
    'favorite'    => ['label' => 'Favorite',    'icon' => 'fa-star'],
];

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem">
  <h1 style="font-size:1.125rem;font-weight:600;margin:0"><i class="fas fa-utensils" style="color:var(--accent)"></i> Cooking</h1>
  <button class="btn btn-primary btn-sm" onclick="openAddRecipe()"><i class="fas fa-plus"></i> Add Recipe</button>
</div>

<div class="grid-stats" style="margin-bottom:1.5rem" id="cookingStatsWrap">
  <div class="stat-card"><div class="stat-val"><?= (int)$counts['total'] ?></div><div class="stat-label">Total recipes</div></div>
  <div class="stat-card"><div class="stat-val"><?= (int)$counts['want'] ?></div><div class="stat-label">Want to try</div></div>
  <div class="stat-card"><div class="stat-val"><?= (int)$counts['tried'] ?></div><div class="stat-label">Tried</div></div>
  <div class="stat-card"><div class="stat-val"><?= (int)$counts['favorite'] ?></div><div class="stat-label">Favorites</div></div>
</div>

<div class="filter-tabs" style="margin-bottom:1.25rem" id="cookingTabs">
  <button class="filter-tab active" data-tab="library">Recipes</button>
  <button class="filter-tab" data-tab="learn">Learn</button>
</div>

<div id="ctab-library" class="gym-tab-panel">
  <div class="filter-tabs" style="margin-bottom:1.25rem">
    <a class="filter-tab <?= $shelf==='all'?'active':'' ?>" href="?shelf=all">All</a>
    <?php foreach ($statusMeta as $k => $m): ?><a class="filter-tab <?= $shelf===$k?'active':'' ?>" href="?shelf=<?= $k ?>"><?= $m['label'] ?></a><?php endforeach; ?>
  </div>

  <div id="cookingListWrap">
  <?php if (empty($recipes)): ?>
    <div class="card"><div class="empty-state"><div class="empty-state-icon"><i class="fas fa-utensils"></i></div><div class="empty-state-title">No recipes yet</div><p>Track what you want to cook, what you've tried, and your favorites.</p>
      <button class="btn btn-primary" style="margin-top:.75rem" onclick="openAddRecipe()"><i class="fas fa-plus"></i> Add your first recipe</button>
    </div></div>
  <?php else: ?>
    <div class="grid-cards">
      <?php foreach ($recipes as $r): $sm = $statusMeta[$r['status']]; ?>
        <div class="habit-card" id="recipe-<?= $r['id'] ?>">
          <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:.5rem">
            <div style="min-width:0">
              <div style="font-weight:600;font-size:.9375rem;color:var(--text)"><?= h($r['title']) ?></div>
              <div style="font-size:.8125rem;color:var(--muted)">
                <?= $r['category'] ? h($r['category']) : '' ?><?= $r['category'] && $r['cook_time_min'] ? ' · ' : '' ?><?= $r['cook_time_min'] ? (int)$r['cook_time_min'].' min' : '' ?>
              </div>
            </div>
            <button aria-label="Delete recipe" class="btn btn-icon btn-ghost btn-sm" style="color:var(--accent);flex-shrink:0" onclick="deleteRecipe(<?= $r['id'] ?>)"><i class="fas fa-trash"></i></button>
          </div>
          <div style="margin-bottom:.625rem" id="rstars-<?= $r['id'] ?>">
            <?php for ($i = 1; $i <= 5; $i++): ?>
              <i class="<?= $r['rating'] >= $i ? 'fas' : 'far' ?> fa-star" style="color:#f59e0b;cursor:pointer;font-size:.8125rem" onclick="rateRecipe(<?= $r['id'] ?>, <?= $i ?>)"></i>
            <?php endfor; ?>
          </div>
          <select class="form-input" style="width:100%;font-size:.8125rem;padding:.375rem .5rem" onchange="setRecipeStatus(<?= $r['id'] ?>, this.value)">
            <?php foreach ($statusMeta as $k => $m): ?><option value="<?= $k ?>" <?= $r['status']===$k?'selected':'' ?>><?= $m['label'] ?></option><?php endforeach; ?>
          </select>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  </div>
</div>

<div id="ctab-learn" class="gym-tab-panel hidden">
  <div class="grid-cards">
    <?php foreach ([
      ['title' => 'Salt, Fat, Acid, Heat', 'desc' => 'Samin Nosrat\'s book on the four fundamentals of good cooking — balancing flavor, not just following recipes', 'icon' => 'fa-book'],
      ['title' => 'The Art of Simple Food', 'desc' => 'Alice Waters on fresh, seasonal ingredients — a favorite starting point for beginners', 'icon' => 'fa-leaf'],
      ['title' => 'Budget Bytes', 'desc' => 'A site built around cooking well on a budget, with cost breakdowns per recipe', 'icon' => 'fa-wallet', 'url' => 'https://www.budgetbytes.com'],
      ['title' => 'Repeat simple meals', 'desc' => 'Cooking a few times a week builds confidence faster than just reading recipes — repeat a dish until it\'s second nature', 'icon' => 'fa-repeat'],
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

<!-- Add recipe modal -->
<div id="addRecipeModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header"><span class="modal-title">Add Recipe</span><button class="btn btn-icon btn-ghost btn-sm" data-close-modal="addRecipeModal" aria-label="Close dialog">&times;</button></div>
    <div class="modal-body">
      <div class="form-group"><label for="recipeTitle" class="form-label">Title <span style="color:var(--accent)">*</span></label><input id="recipeTitle" class="form-input" placeholder="e.g. Chicken Tikka Masala"></div>
      <div class="form-grid-2">
        <div class="form-group"><label for="recipeCategory" class="form-label">Category</label><input id="recipeCategory" class="form-input" placeholder="e.g. Dinner"></div>
        <div class="form-group"><label for="recipeTime" class="form-label">Cook time (min)</label><input id="recipeTime" type="number" min="0" class="form-input"></div>
      </div>
      <div class="form-group">
        <label for="recipeStatus" class="form-label">Shelf</label>
        <select id="recipeStatus" class="form-input"><?php foreach ($statusMeta as $k => $m): ?><option value="<?= $k ?>"><?= $m['label'] ?></option><?php endforeach; ?></select>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary btn-sm" data-close-modal="addRecipeModal">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="saveRecipe()"><i class="fas fa-save"></i> Add</button>
    </div>
  </div>
</div>

</div>
<?php include '../includes/footer.php'; ?>

<script>
const API_BASE = '<?= APP_BASE ?>/api';

function switchCookingTab(tab) {
  document.querySelectorAll('#cookingTabs .filter-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
  document.querySelectorAll('#ctab-library, #ctab-learn').forEach(p => p.classList.toggle('hidden', p.id !== `ctab-${tab}`));
}
document.getElementById('cookingTabs').addEventListener('click', e => {
  const btn = e.target.closest('[data-tab]');
  if (btn) switchCookingTab(btn.dataset.tab);
});

function openAddRecipe() {
  document.getElementById('recipeTitle').value = '';
  document.getElementById('recipeCategory').value = '';
  document.getElementById('recipeTime').value = '';
  document.getElementById('recipeStatus').value = 'want_to_try';
  Trackie.openModal('addRecipeModal');
}
async function saveRecipe() {
  const title = document.getElementById('recipeTitle').value.trim();
  if (!title) { Trackie.Toast.warning('Title is required.'); return; }
  try {
    const res = await Trackie.API.post(`${API_BASE}/cooking.php`, {
      action: 'add', title,
      category: document.getElementById('recipeCategory').value.trim(),
      cook_time_min: document.getElementById('recipeTime').value,
      status: document.getElementById('recipeStatus').value,
    });
    if (res.success) { Trackie.Toast.success('Recipe added!'); Trackie.closeModal('addRecipeModal'); await Trackie.refreshFragments(['cookingStatsWrap', 'cookingListWrap']); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function setRecipeStatus(id, status) {
  try {
    const res = await Trackie.API.post(`${API_BASE}/cooking.php`, {action:'update_status', item_id:id, status});
    if (res.success) { Trackie.Toast.success('Shelf updated.'); await Trackie.refreshFragments(['cookingStatsWrap', 'cookingListWrap']); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function rateRecipe(id, rating) {
  document.querySelectorAll(`#rstars-${id} i`).forEach((s, i) => s.className = (i < rating ? 'fas' : 'far') + ' fa-star');
  try {
    const res = await Trackie.API.post(`${API_BASE}/cooking.php`, {action:'rate', item_id:id, rating});
    if (!res.success) Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function deleteRecipe(id) {
  const ok = await Trackie.confirmDialog('Delete this recipe?', {confirmText:'Delete', danger:true});
  if (!ok) return;
  try {
    const res = await Trackie.API.post(`${API_BASE}/cooking.php`, {action:'delete', item_id:id});
    if (res.success) { document.getElementById(`recipe-${id}`)?.remove(); Trackie.Toast.success('Deleted.'); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
</script>
