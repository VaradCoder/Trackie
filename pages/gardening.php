<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Gardening';
$currentPage = 'gardening';

if (!tableExists('plants')) renderSetupNeeded('Gardening');

$plants = fetchAll("SELECT * FROM plants WHERE user_id=? ORDER BY created_at DESC", [$uid]);
$counts = fetchOne("SELECT COUNT(*) total, SUM(status='healthy') healthy, SUM(status='needs_attention') needs FROM plants WHERE user_id=?", [$uid]);
$statusMeta = [
    'healthy'         => ['label' => 'Healthy', 'badge' => 'badge-green'],
    'needs_attention' => ['label' => 'Needs attention', 'badge' => 'badge-yellow'],
    'dormant'         => ['label' => 'Dormant', 'badge' => 'badge-gray'],
];

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem">
  <h1 style="font-size:1.125rem;font-weight:600;margin:0"><i class="fas fa-seedling" style="color:var(--accent)"></i> Gardening</h1>
  <button class="btn btn-primary btn-sm" onclick="openAddPlant()"><i class="fas fa-plus"></i> Add Plant</button>
</div>

<div class="grid-stats" style="margin-bottom:1.5rem" id="gardenStatsWrap">
  <div class="stat-card"><div class="stat-val"><?= (int)$counts['total'] ?></div><div class="stat-label">Total plants</div></div>
  <div class="stat-card"><div class="stat-val"><?= (int)$counts['healthy'] ?></div><div class="stat-label">Healthy</div></div>
  <div class="stat-card"><div class="stat-val"><?= (int)$counts['needs'] ?></div><div class="stat-label">Need attention</div></div>
</div>

<div class="filter-tabs" style="margin-bottom:1.25rem" id="gardenTabs">
  <button class="filter-tab active" data-tab="plants">My Plants</button>
  <button class="filter-tab" data-tab="learn">Learn</button>
</div>

<div id="gdtab-plants" class="gym-tab-panel">
  <div id="gardenListWrap">
  <?php if (empty($plants)): ?>
    <div class="card"><div class="empty-state"><div class="empty-state-icon"><i class="fas fa-seedling"></i></div><div class="empty-state-title">No plants yet</div><p>Track watering schedules and plant health.</p>
      <button class="btn btn-primary" style="margin-top:.75rem" onclick="openAddPlant()"><i class="fas fa-plus"></i> Add your first plant</button>
    </div></div>
  <?php else: ?>
    <div class="grid-cards">
      <?php foreach ($plants as $p):
        $sm = $statusMeta[$p['status']];
        $daysSince = $p['last_watered'] ? (int)((strtotime(date('Y-m-d')) - strtotime($p['last_watered'])) / 86400) : null;
        $overdue = $daysSince !== null && $daysSince >= $p['water_frequency_days'];
      ?>
        <div class="habit-card" id="plant-<?= $p['id'] ?>">
          <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:.5rem">
            <div style="min-width:0">
              <div style="font-weight:600;font-size:.9375rem;color:var(--text)"><?= h($p['name']) ?></div>
              <?php if ($p['species']): ?><div style="font-size:.8125rem;color:var(--muted)"><?= h($p['species']) ?></div><?php endif; ?>
            </div>
            <button aria-label="Delete plant" class="btn btn-icon btn-ghost btn-sm" style="color:var(--accent);flex-shrink:0" onclick="deletePlant(<?= $p['id'] ?>)"><i class="fas fa-trash"></i></button>
          </div>
          <div style="margin-bottom:.75rem">
            <span class="badge <?= $overdue ? 'badge-red' : $sm['badge'] ?>"><?= $overdue ? 'Needs water' : $sm['label'] ?></span>
          </div>
          <div style="font-size:.75rem;color:var(--muted);margin-bottom:.75rem">
            <?= $daysSince === null ? 'Never watered' : "Watered {$daysSince}d ago" ?> · every <?= (int)$p['water_frequency_days'] ?>d
          </div>
          <button class="btn btn-primary btn-sm" style="width:100%" onclick="waterPlant(<?= $p['id'] ?>)"><i class="fas fa-tint"></i> Water now</button>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  </div>
</div>

<div id="gdtab-learn" class="gym-tab-panel hidden">
  <div class="grid-cards">
    <?php foreach ([
      ['title' => 'Gardening Know How', 'desc' => 'A beginner\'s guide to houseplants — what to start with and how to diagnose problems', 'icon' => 'fa-leaf', 'url' => 'https://www.gardeningknowhow.com'],
      ['title' => 'Penn State Extension', 'desc' => 'University-run home gardening resources on planting, pests, and plant disease', 'icon' => 'fa-university', 'url' => 'https://extension.psu.edu'],
      ['title' => 'Square Foot Gardening', 'desc' => 'Mel Bartholomew\'s well-known beginner-friendly method for small-space gardens', 'icon' => 'fa-border-all'],
      ['title' => 'Know your light', 'desc' => 'Full sun plants need 6+ hours of direct sunlight a day — matching plant to light is the #1 beginner mistake to avoid', 'icon' => 'fa-sun'],
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

<!-- Add plant modal -->
<div id="addPlantModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header"><span class="modal-title">Add Plant</span><button class="btn btn-icon btn-ghost btn-sm" data-close-modal="addPlantModal" aria-label="Close dialog">&times;</button></div>
    <div class="modal-body">
      <div class="form-group"><label for="plantName" class="form-label">Name <span style="color:var(--accent)">*</span></label><input id="plantName" class="form-input" placeholder="e.g. Living room fern"></div>
      <div class="form-group"><label for="plantSpecies" class="form-label">Species</label><input id="plantSpecies" class="form-input" placeholder="Optional"></div>
      <div class="form-group"><label for="plantWaterFreq" class="form-label">Water every (days)</label><input id="plantWaterFreq" type="number" min="1" class="form-input" value="7"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary btn-sm" data-close-modal="addPlantModal">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="savePlant()"><i class="fas fa-save"></i> Add</button>
    </div>
  </div>
</div>

</div>
<?php include '../includes/footer.php'; ?>

<script>
const API_BASE = '<?= APP_BASE ?>/api';

function switchGardenTab(tab) {
  document.querySelectorAll('#gardenTabs .filter-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
  document.querySelectorAll('#gdtab-plants, #gdtab-learn').forEach(p => p.classList.toggle('hidden', p.id !== `gdtab-${tab}`));
}
document.getElementById('gardenTabs').addEventListener('click', e => {
  const btn = e.target.closest('[data-tab]');
  if (btn) switchGardenTab(btn.dataset.tab);
});

function openAddPlant() {
  document.getElementById('plantName').value = '';
  document.getElementById('plantSpecies').value = '';
  document.getElementById('plantWaterFreq').value = 7;
  Trackie.openModal('addPlantModal');
}
async function savePlant() {
  const name = document.getElementById('plantName').value.trim();
  if (!name) { Trackie.Toast.warning('Name is required.'); return; }
  try {
    const res = await Trackie.API.post(`${API_BASE}/gardening.php`, {
      action: 'add', name,
      species: document.getElementById('plantSpecies').value.trim(),
      water_frequency_days: document.getElementById('plantWaterFreq').value,
    });
    if (res.success) { Trackie.Toast.success('Plant added!'); Trackie.closeModal('addPlantModal'); await Trackie.refreshFragments(['gardenStatsWrap', 'gardenListWrap']); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function waterPlant(id) {
  try {
    const res = await Trackie.API.post(`${API_BASE}/gardening.php`, {action:'water', item_id:id});
    if (res.success) { Trackie.Toast.success('Watered! 💧'); await Trackie.refreshFragments(['gardenStatsWrap', 'gardenListWrap']); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function deletePlant(id) {
  const ok = await Trackie.confirmDialog('Remove this plant?', {confirmText:'Delete', danger:true});
  if (!ok) return;
  try {
    const res = await Trackie.API.post(`${API_BASE}/gardening.php`, {action:'delete', item_id:id});
    if (res.success) { document.getElementById(`plant-${id}`)?.remove(); Trackie.Toast.success('Removed.'); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
</script>
