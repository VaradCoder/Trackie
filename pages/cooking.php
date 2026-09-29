<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/activity.php';
require_once '../includes/habit_schedule.php';   // weekStartOf()

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Cooking';
$currentPage = 'cooking';
$today       = date('Y-m-d');

if (!tableExists('recipes')) renderSetupNeeded('Cooking');
if (!tableExists('cook_logs')) renderSetupNeeded('Cooking');

$recipes = fetchAll(
    "SELECT r.*, (SELECT COUNT(*) FROM cook_logs c WHERE c.recipe_id=r.id) times_cooked,
            (SELECT MAX(cooked_on) FROM cook_logs c WHERE c.recipe_id=r.id) last_cooked
     FROM recipes r WHERE r.user_id=? ORDER BY r.status='favorite' DESC, r.created_at DESC", [$uid]
);
$weekStart = userSetting($uid, 'week_start');
$wkFrom = weekStartOf($today, $weekStart);
$wkDays = array_map(static fn($i) => date('Y-m-d', strtotime("+{$i} day", strtotime($wkFrom))), range(0, 6));
$plan = [];
foreach (fetchAll("SELECT m.*, r.title FROM meal_plan m LEFT JOIN recipes r ON r.id=m.recipe_id WHERE m.user_id=? AND m.plan_date BETWEEN ? AND ?",
                  [$uid, $wkDays[0], $wkDays[6]]) as $p) $plan[$p['plan_date']][$p['meal']] = $p;
$cooked = fetchAll("SELECT c.*, r.title FROM cook_logs c JOIN recipes r ON r.id=c.recipe_id WHERE c.user_id=? ORDER BY c.cooked_on DESC, c.id DESC LIMIT 40", [$uid]);
$weekCooks = count(array_filter($cooked, static fn($c) => $c['cooked_on'] >= $wkFrom));
$streak = activityReady() ? activityStreak($uid, 'cooking') : ['current' => 0, 'best' => 0];
$statusMeta = ['want_to_try' => 'Want to try', 'tried' => 'Tried', 'favorite' => 'Favorite'];

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<div class="rd-head">
  <h1><i class="fas fa-utensils" style="color:var(--accent)"></i> Cooking</h1>
  <div class="rd-head-actions"><button class="btn btn-primary btn-sm" onclick="openRecipe()"><i class="fas fa-plus"></i> Add recipe</button></div>
</div>

<div class="grid-stats" style="margin-bottom:1.25rem">
  <div class="stat-card"><div class="stat-val">🔥 <?= (int)$streak['current'] ?></div><div class="stat-label">Cooking streak<?= $streak['best'] > $streak['current'] ? ' · best ' . (int)$streak['best'] : '' ?></div></div>
  <div class="stat-card"><div class="stat-val"><?= $weekCooks ?></div><div class="stat-label">Meals cooked this week</div></div>
  <div class="stat-card"><div class="stat-val"><?= count($recipes) ?></div><div class="stat-label">Recipes</div></div>
  <div class="stat-card"><div class="stat-val"><?= count(array_filter($recipes, fn($r) => $r['status'] === 'favorite')) ?></div><div class="stat-label">Favorites</div></div>
</div>

<div class="filter-tabs" style="margin-bottom:1.25rem" id="ckTabs" role="tablist">
  <button class="filter-tab active" data-tab="recipes" role="tab" aria-selected="true">Recipes</button>
  <button class="filter-tab" data-tab="plan" role="tab" aria-selected="false" tabindex="-1">Meal plan</button>
  <button class="filter-tab" data-tab="log" role="tab" aria-selected="false" tabindex="-1">Cooked</button>
</div>

<div id="cktab-recipes">
  <?php if (!$recipes): ?>
    <div class="card"><div class="empty-state"><div class="empty-state-icon"><i class="fas fa-utensils"></i></div>
      <div class="empty-state-title">Your recipe box is empty</div><p>Save recipes with ingredients and steps, then log each time you cook one.</p>
      <button class="btn btn-primary" style="margin-top:.75rem" onclick="openRecipe()"><i class="fas fa-plus"></i> Add your first recipe</button></div></div>
  <?php else: ?>
    <div class="filter-tabs" id="ckShelf" style="margin-bottom:1rem">
      <button class="filter-tab active" data-shelf="all">All</button>
      <?php foreach ($statusMeta as $k => $l): ?><button class="filter-tab" data-shelf="<?= $k ?>"><?= $l ?></button><?php endforeach; ?>
    </div>
    <div class="grid-cards">
      <?php foreach ($recipes as $r): ?>
        <div class="habit-card ck-recipe" data-status="<?= h($r['status']) ?>" id="recipe-<?= (int)$r['id'] ?>">
          <button class="rd-title rd-link" onclick="viewRecipe(<?= (int)$r['id'] ?>)"><?= h($r['title']) ?></button>
          <div class="rd-author"><?= h(implode(' · ', array_filter([$r['category'], $r['cook_time_min'] ? $r['cook_time_min'] . ' min' : null, $r['servings'] ? $r['servings'] . ' servings' : null]))) ?></div>
          <div class="rd-stars" id="rstars-<?= (int)$r['id'] ?>" style="margin:.375rem 0">
            <?php for ($i = 1; $i <= 5; $i++): ?><button class="rd-star" onclick="rateRecipe(<?= (int)$r['id'] ?>, <?= $i ?>)" aria-label="Rate <?= $i ?>"><i class="<?= $r['rating'] >= $i ? 'fas' : 'far' ?> fa-star"></i></button><?php endfor; ?>
          </div>
          <div class="rd-author" style="margin-bottom:.625rem"><?= $r['times_cooked'] ? 'Cooked ' . (int)$r['times_cooked'] . '× · last ' . h(formatDate($r['last_cooked'], 'M j')) : 'Not cooked yet' ?></div>
          <div class="rd-book-actions">
            <select class="form-input" aria-label="Shelf" onchange="setRecipeStatus(<?= (int)$r['id'] ?>, this.value)">
              <?php foreach ($statusMeta as $k => $l): ?><option value="<?= $k ?>" <?= $r['status'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
            </select>
            <button class="btn btn-secondary btn-sm" onclick="openCook(<?= (int)$r['id'] ?>)"><i class="fas fa-fire-burner"></i> Cooked</button>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div id="cktab-plan" class="hidden">
  <div class="ck-plan">
    <?php foreach ($wkDays as $d): ?>
      <div class="ck-day<?= $d === $today ? ' is-today' : '' ?>">
        <div class="ck-day-head"><?= date('D', strtotime($d)) ?> <small><?= date('M j', strtotime($d)) ?></small></div>
        <?php foreach (['breakfast', 'lunch', 'dinner'] as $meal): $slot = $plan[$d][$meal] ?? null; ?>
          <button class="ck-slot<?= $slot ? ' filled' : '' ?>" onclick="openPlan('<?= $d ?>','<?= $meal ?>')"
                  data-recipe="<?= (int)($slot['recipe_id'] ?? 0) ?>" data-note="<?= h($slot['note'] ?? '') ?>">
            <span class="ck-meal"><?= ucfirst($meal) ?></span>
            <span><?= $slot ? h($slot['title'] ?: $slot['note']) : '+' ?></span>
          </button>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div id="cktab-log" class="hidden">
  <?php if (!$cooked): ?><div class="card card-body hb-empty-line">Nothing cooked yet — tap "Cooked" on a recipe after making it.</div>
  <?php else: ?>
    <div class="card">
      <?php foreach ($cooked as $c): ?>
        <div class="todo-row" id="cook-<?= (int)$c['id'] ?>">
          <div style="flex:1;min-width:0"><span class="todo-title"><?= h($c['title']) ?></span>
            <div class="todo-meta"><span><?= h(formatDate($c['cooked_on'])) ?></span><?php if ($c['rating']): ?><span><?= str_repeat('★', (int)$c['rating']) ?></span><?php endif; ?><?php if ($c['notes']): ?><span><?= h($c['notes']) ?></span><?php endif; ?></div></div>
          <button class="btn btn-icon btn-ghost btn-sm" style="color:var(--accent)" onclick="deleteCook(<?= (int)$c['id'] ?>)" aria-label="Delete entry"><i class="fas fa-trash"></i></button>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- Recipe add/edit -->
<div id="recipeModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header"><span class="modal-title" id="recipeModalTitle">Add recipe</span><button class="btn btn-icon btn-ghost btn-sm" data-close-modal="recipeModal" aria-label="Close dialog">&times;</button></div>
    <div class="modal-body">
      <input type="hidden" id="rcId">
      <div class="form-group"><label for="rcTitle" class="form-label">Title <span style="color:var(--accent)">*</span></label><input id="rcTitle" class="form-input" maxlength="150"></div>
      <div class="rd-form-row">
        <div class="form-group"><label for="rcCategory" class="form-label">Category</label><input id="rcCategory" class="form-input" maxlength="60" placeholder="e.g. Dinner, Dessert"></div>
        <div class="form-group"><label for="rcStatus" class="form-label">Shelf</label><select id="rcStatus" class="form-input"><?php foreach ($statusMeta as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="rd-form-row">
        <div class="form-group"><label for="rcTime" class="form-label">Cook time (min)</label><input id="rcTime" type="number" min="1" max="2880" class="form-input"></div>
        <div class="form-group"><label for="rcServings" class="form-label">Servings</label><input id="rcServings" type="number" min="1" max="100" class="form-input"></div>
      </div>
      <div class="form-group"><label for="rcIngredients" class="form-label">Ingredients <small class="form-hint">(one per line)</small></label><textarea id="rcIngredients" class="form-input" rows="5" placeholder="2 cups rice&#10;1 onion, chopped"></textarea></div>
      <div class="form-group"><label for="rcSteps" class="form-label">Steps <small class="form-hint">(one per line)</small></label><textarea id="rcSteps" class="form-input" rows="5"></textarea></div>
      <div class="form-group"><label for="rcNotes" class="form-label">Notes</label><input id="rcNotes" class="form-input" maxlength="500"></div>
    </div>
    <div class="modal-footer"><button class="btn btn-secondary btn-sm" data-close-modal="recipeModal">Cancel</button><button class="btn btn-primary btn-sm" onclick="saveRecipe()"><i class="fas fa-save"></i> Save</button></div>
  </div>
</div>

<!-- Recipe view -->
<div id="viewRecipeModal" class="modal-backdrop hidden">
  <div class="modal-box rd-detail-box">
    <div class="modal-header"><span class="modal-title" id="vrTitle">Recipe</span><button class="btn btn-icon btn-ghost btn-sm" data-close-modal="viewRecipeModal" aria-label="Close dialog">&times;</button></div>
    <div class="modal-body" id="vrBody"></div>
  </div>
</div>

<!-- Cooked it -->
<div id="cookModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header"><span class="modal-title">I cooked this</span><button class="btn btn-icon btn-ghost btn-sm" data-close-modal="cookModal" aria-label="Close dialog">&times;</button></div>
    <div class="modal-body">
      <input type="hidden" id="ckId">
      <div class="rd-form-row">
        <div class="form-group"><label for="ckDate" class="form-label">Date</label><input id="ckDate" type="date" class="form-input" max="<?= $today ?>"></div>
        <div class="form-group"><label for="ckRating" class="form-label">How was it?</label><select id="ckRating" class="form-input"><option value="">—</option><?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>"><?= str_repeat('★', $i) ?></option><?php endfor; ?></select></div>
      </div>
      <div class="form-group"><label for="ckNotes" class="form-label">Notes</label><input id="ckNotes" class="form-input" maxlength="500" placeholder="e.g. less salt next time"></div>
    </div>
    <div class="modal-footer"><button class="btn btn-secondary btn-sm" data-close-modal="cookModal">Cancel</button><button class="btn btn-primary btn-sm" onclick="saveCook()"><i class="fas fa-check"></i> Log it</button></div>
  </div>
</div>

<!-- Meal plan slot -->
<div id="planModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header"><span class="modal-title" id="planTitle">Plan meal</span><button class="btn btn-icon btn-ghost btn-sm" data-close-modal="planModal" aria-label="Close dialog">&times;</button></div>
    <div class="modal-body">
      <input type="hidden" id="plDate"><input type="hidden" id="plMeal">
      <div class="form-group"><label for="plRecipe" class="form-label">Recipe</label>
        <select id="plRecipe" class="form-input"><option value="">— none —</option><?php foreach ($recipes as $r): ?><option value="<?= (int)$r['id'] ?>"><?= h($r['title']) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label for="plNote" class="form-label">…or a note</label><input id="plNote" class="form-input" maxlength="120" placeholder="e.g. Eating out"></div>
    </div>
    <div class="modal-footer"><button class="btn btn-ghost btn-sm" style="margin-right:auto" onclick="clearPlan()">Clear</button><button class="btn btn-secondary btn-sm" data-close-modal="planModal">Cancel</button><button class="btn btn-primary btn-sm" onclick="savePlan()">Save</button></div>
  </div>
</div>

</div>
<?php include '../includes/footer.php'; ?>

<script>
const API_BASE = '<?= APP_BASE ?>/api';
const ckPost = d => Trackie.API.post(`${API_BASE}/cooking.php`, d);
const RC_FIELDS = { rcTitle: 'title', rcCategory: 'category', rcTime: 'cook_time_min', rcServings: 'servings', rcIngredients: 'ingredients', rcSteps: 'steps', rcNotes: 'notes' };
document.getElementById('ckTabs').addEventListener('click', e => {
  const b = e.target.closest('[data-tab]'); if (!b) return;
  document.querySelectorAll('#ckTabs [data-tab]').forEach(x => { const on = x === b; x.classList.toggle('active', on); x.setAttribute('aria-selected', on); });
  ['recipes', 'plan', 'log'].forEach(t => document.getElementById(`cktab-${t}`).classList.toggle('hidden', t !== b.dataset.tab));
});
document.getElementById('ckShelf')?.addEventListener('click', e => {
  const b = e.target.closest('[data-shelf]'); if (!b) return;
  document.querySelectorAll('#ckShelf [data-shelf]').forEach(x => x.classList.toggle('active', x === b));
  document.querySelectorAll('.ck-recipe').forEach(c => c.classList.toggle('hidden', b.dataset.shelf !== 'all' && c.dataset.status !== b.dataset.shelf));
});
function openRecipe() {
  document.getElementById('rcId').value = '';
  Object.keys(RC_FIELDS).forEach(i => document.getElementById(i).value = '');
  document.getElementById('rcStatus').value = 'want_to_try';
  document.getElementById('rcStatus').closest('.form-group').classList.remove('hidden');
  document.getElementById('recipeModalTitle').textContent = 'Add recipe';
  Trackie.openModal('recipeModal');
}
async function editRecipe(id) {
  const res = await ckPost({ action: 'get', item_id: id });
  if (!res.success) { Trackie.Toast.error(res.error || 'Not found.'); return; }
  document.getElementById('rcId').value = id;
  Object.entries(RC_FIELDS).forEach(([i, k]) => document.getElementById(i).value = res.recipe[k] ?? '');
  document.getElementById('rcStatus').closest('.form-group').classList.add('hidden');
  document.getElementById('recipeModalTitle').textContent = 'Edit recipe';
  Trackie.closeModal('viewRecipeModal'); Trackie.openModal('recipeModal');
}
async function saveRecipe() {
  const id = document.getElementById('rcId').value;
  const data = { action: id ? 'edit' : 'add', item_id: id, status: document.getElementById('rcStatus').value };
  Object.entries(RC_FIELDS).forEach(([i, k]) => data[k] = document.getElementById(i).value);
  if (!data.title.trim()) { Trackie.Toast.warning('Title is required.'); return; }
  const res = await ckPost(data);
  if (res.success) { Trackie.Toast.success(id ? 'Recipe updated.' : 'Recipe added!'); location.reload(); } else Trackie.Toast.error(res.error || 'Failed.');
}
async function viewRecipe(id) {
  const res = await ckPost({ action: 'get', item_id: id });
  if (!res.success) { Trackie.Toast.error(res.error || 'Not found.'); return; }
  const r = res.recipe, list = t => (t || '').split('\n').filter(Boolean);
  document.getElementById('vrTitle').textContent = r.title;
  document.getElementById('vrBody').innerHTML = `
    <div class="rd-author">${escHtml([r.category, r.cook_time_min ? r.cook_time_min + ' min' : '', r.servings ? r.servings + ' servings' : ''].filter(Boolean).join(' · '))}</div>
    <div class="hb-grid2" style="margin-top:1rem">
      <div><div class="fit-card-label">Ingredients</div>${list(r.ingredients).length ? '<ul class="ck-ing">' + list(r.ingredients).map(x => `<li>${escHtml(x)}</li>`).join('') + '</ul>' : '<p class="hb-empty-line">None added.</p>'}</div>
      <div><div class="fit-card-label">Steps</div>${list(r.steps).length ? '<ol class="ck-steps">' + list(r.steps).map(x => `<li>${escHtml(x)}</li>`).join('') + '</ol>' : '<p class="hb-empty-line">None added.</p>'}</div>
    </div>
    ${r.notes ? `<p class="hb-foot">${escHtml(r.notes)}</p>` : ''}
    <div class="fit-card-label" style="margin-top:1rem">Cooked ${r.cooks.length}×</div>
    ${r.cooks.map(c => `<div class="hb-row"><span>${escHtml(c.cooked_on)}${c.notes ? ' — ' + escHtml(c.notes) : ''}</span><b>${c.rating ? '★'.repeat(+c.rating) : ''}</b></div>`).join('')}
    <div class="rd-current-actions"><button class="btn btn-primary btn-sm" onclick="Trackie.closeModal('viewRecipeModal');openCook(${+r.id})"><i class="fas fa-fire-burner"></i> Cooked it</button>
      <button class="btn btn-secondary btn-sm" onclick="editRecipe(${+r.id})"><i class="fas fa-pen"></i> Edit</button>
      <button class="btn btn-ghost btn-sm" style="color:var(--accent);margin-left:auto" onclick="deleteRecipe(${+r.id})"><i class="fas fa-trash"></i> Delete</button></div>`;
  Trackie.openModal('viewRecipeModal');
}
async function setRecipeStatus(id, status) { const r = await ckPost({ action: 'update_status', item_id: id, status }); if (r.success) Trackie.Toast.success('Shelf updated.'); }
async function rateRecipe(id, rating) {
  document.querySelectorAll(`#rstars-${id} i`).forEach((s, i) => s.className = (i < rating ? 'fas' : 'far') + ' fa-star');
  await ckPost({ action: 'rate', item_id: id, rating });
}
async function deleteRecipe(id) {
  if (!await Trackie.confirmDialog('Delete this recipe and its cooking history?', { confirmText: 'Delete', danger: true })) return;
  const r = await ckPost({ action: 'delete', item_id: id }); if (r.success) location.reload();
}
function openCook(id) {
  document.getElementById('ckId').value = id; document.getElementById('ckDate').value = '<?= $today ?>';
  document.getElementById('ckRating').value = ''; document.getElementById('ckNotes').value = '';
  Trackie.openModal('cookModal');
}
async function saveCook() {
  const res = await ckPost({ action: 'cook', item_id: document.getElementById('ckId').value, cooked_on: document.getElementById('ckDate').value,
                             rating: document.getElementById('ckRating').value, notes: document.getElementById('ckNotes').value });
  if (res.success) { Trackie.Toast.success('Logged' + (res.xp?.ok ? ` · +${res.xp.gained} XP` : '')); location.reload(); } else Trackie.Toast.error(res.error || 'Failed.');
}
async function deleteCook(id) { const r = await ckPost({ action: 'cook_delete', log_id: id }); if (r.success) document.getElementById(`cook-${id}`)?.remove(); }
function openPlan(date, meal) {
  const slot = document.querySelector(`.ck-slot[onclick="openPlan('${date}','${meal}')"]`);
  document.getElementById('plDate').value = date; document.getElementById('plMeal').value = meal;
  document.getElementById('plRecipe').value = slot && +slot.dataset.recipe ? slot.dataset.recipe : '';
  document.getElementById('plNote').value = slot ? slot.dataset.note : '';
  document.getElementById('planTitle').textContent = `${meal[0].toUpperCase() + meal.slice(1)} · ${new Date(date + 'T00:00').toLocaleDateString(undefined, { weekday: 'long', month: 'short', day: 'numeric' })}`;
  Trackie.openModal('planModal');
}
async function savePlan() {
  const res = await ckPost({ action: 'plan_set', plan_date: document.getElementById('plDate').value, meal: document.getElementById('plMeal').value,
                             recipe_id: document.getElementById('plRecipe').value, note: document.getElementById('plNote').value });
  if (res.success) location.reload(); else Trackie.Toast.error(res.error || 'Failed.');
}
function clearPlan() { document.getElementById('plRecipe').value = ''; document.getElementById('plNote').value = ''; savePlan(); }
</script>
