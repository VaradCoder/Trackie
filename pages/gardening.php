<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/activity.php';

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Gardening';
$currentPage = 'gardening';
$today       = date('Y-m-d');

if (!tableExists('plants')) renderSetupNeeded('Gardening');
if (!tableExists('plant_logs')) renderSetupNeeded('Gardening');

$plants = fetchAll("SELECT * FROM plants WHERE user_id=? ORDER BY name", [$uid]);
foreach ($plants as &$p) {
    // Next watering = last watered + interval. Dormant plants are never "due".
    $p['next_water'] = $p['last_watered'] ? date('Y-m-d', strtotime("+{$p['water_frequency_days']} day", strtotime($p['last_watered']))) : $today;
    $p['due'] = $p['status'] !== 'dormant' && $p['next_water'] <= $today;
    $p['overdue_days'] = $p['due'] ? (int)((strtotime($today) - strtotime($p['next_water'])) / 86400) : 0;
}
unset($p);
$due = array_values(array_filter($plants, static fn($p) => $p['due']));
usort($due, static fn($a, $b) => $b['overdue_days'] <=> $a['overdue_days']);
$upcoming = array_values(array_filter($plants, static fn($p) => !$p['due'] && $p['status'] !== 'dormant' && $p['next_water'] <= date('Y-m-d', strtotime('+3 day'))));
$streak = activityReady() ? activityStreak($uid, 'gardening') : ['current' => 0, 'best' => 0];
$statusMeta = ['healthy' => 'Healthy', 'needs_attention' => 'Needs attention', 'dormant' => 'Dormant'];
$careMeta = ['water' => ['Water', 'fa-droplet'], 'fertilize' => ['Fertilize', 'fa-flask'], 'repot' => ['Repot', 'fa-box-open'], 'prune' => ['Prune', 'fa-scissors'], 'note' => ['Note', 'fa-note-sticky']];

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<div class="rd-head">
  <h1><i class="fas fa-seedling" style="color:var(--accent)"></i> Gardening</h1>
  <div class="rd-head-actions"><button class="btn btn-primary btn-sm" onclick="openPlant()"><i class="fas fa-plus"></i> Add plant</button></div>
</div>

<div class="grid-stats" style="margin-bottom:1.25rem">
  <div class="stat-card"><div class="stat-val"><?= count($plants) ?></div><div class="stat-label">Plants</div></div>
  <div class="stat-card"><div class="stat-val" style="color:<?= $due ? 'var(--info)' : 'inherit' ?>"><?= count($due) ?></div><div class="stat-label">Need water today</div></div>
  <div class="stat-card"><div class="stat-val"><?= count(array_filter($plants, fn($p) => $p['status'] === 'needs_attention')) ?></div><div class="stat-label">Need attention</div></div>
  <div class="stat-card"><div class="stat-val">🔥 <?= (int)$streak['current'] ?></div><div class="stat-label">Care streak<?= $streak['best'] > $streak['current'] ? ' · best ' . (int)$streak['best'] : '' ?></div></div>
</div>

<?php if ($plants): ?>
<div class="card card-body" style="margin-bottom:1.25rem">
  <div class="fit-card-label"><i class="fas fa-droplet" style="color:var(--info)"></i> Today's tasks</div>
  <?php if (!$due): ?><p class="hb-empty-line">Nothing needs water today 🌿<?= $upcoming ? ' · next: ' . h(implode(', ', array_map(fn($p) => $p['name'] . ' (' . formatDate($p['next_water'], 'D') . ')', array_slice($upcoming, 0, 4)))) : '' ?></p><?php endif; ?>
  <?php foreach ($due as $p): ?>
    <div class="hb-row" id="due-<?= (int)$p['id'] ?>">
      <span><?= h($p['name']) ?><small class="rd-author"> · <?= $p['overdue_days'] > 0 ? $p['overdue_days'] . ' day' . ($p['overdue_days'] === 1 ? '' : 's') . ' overdue' : 'due today' ?><?= $p['location'] ? ' · ' . h($p['location']) : '' ?></small></span>
      <button class="btn btn-primary btn-sm" onclick="care(<?= (int)$p['id'] ?>, 'water')"><i class="fas fa-droplet"></i> Watered</button>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!$plants): ?>
  <div class="card"><div class="empty-state"><div class="empty-state-icon"><i class="fas fa-seedling"></i></div>
    <div class="empty-state-title">No plants yet</div><p>Add your plants with how often they need water — Trackie tells you what's due each day.</p>
    <button class="btn btn-primary" style="margin-top:.75rem" onclick="openPlant()"><i class="fas fa-plus"></i> Add your first plant</button></div></div>
<?php else: ?>
  <div class="grid-cards">
    <?php foreach ($plants as $p): ?>
      <div class="habit-card" id="plant-<?= (int)$p['id'] ?>">
        <button class="rd-title rd-link" onclick="viewPlant(<?= (int)$p['id'] ?>)"><?= h($p['name']) ?></button>
        <div class="rd-author"><?= h(implode(' · ', array_filter([$p['species'], $p['location']]))) ?: '&nbsp;' ?></div>
        <div class="hb-row" style="margin-top:.5rem"><span>Water every</span><b><?= (int)$p['water_frequency_days'] ?> day<?= (int)$p['water_frequency_days'] === 1 ? '' : 's' ?></b></div>
        <div class="hb-row"><span>Last watered</span><b><?= $p['last_watered'] ? h(formatDate($p['last_watered'], 'M j')) : '—' ?></b></div>
        <div class="hb-row"><span>Next</span><b style="color:<?= $p['due'] ? 'var(--info)' : 'inherit' ?>"><?= $p['status'] === 'dormant' ? 'Dormant' : ($p['due'] ? 'Due now' : h(formatDate($p['next_water'], 'D M j'))) ?></b></div>
        <div class="rd-book-actions" style="margin-top:.625rem">
          <select class="form-input" aria-label="Health" onchange="setPlantStatus(<?= (int)$p['id'] ?>, this.value)">
            <?php foreach ($statusMeta as $k => $l): ?><option value="<?= $k ?>" <?= $p['status'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
          </select>
          <button class="btn btn-icon btn-ghost btn-sm" onclick="care(<?= (int)$p['id'] ?>, 'water')" aria-label="Log watering" title="Watered"><i class="fas fa-droplet"></i></button>
          <button class="btn btn-icon btn-ghost btn-sm" onclick="viewPlant(<?= (int)$p['id'] ?>)" aria-label="Care log" title="Care log"><i class="fas fa-book"></i></button>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- Plant add/edit -->
<div id="plantModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header"><span class="modal-title" id="plantModalTitle">Add plant</span><button class="btn btn-icon btn-ghost btn-sm" data-close-modal="plantModal" aria-label="Close dialog">&times;</button></div>
    <div class="modal-body">
      <input type="hidden" id="plId">
      <div class="rd-form-row">
        <div class="form-group"><label for="plName" class="form-label">Name <span style="color:var(--accent)">*</span></label><input id="plName" class="form-input" maxlength="100" placeholder="e.g. Monstera"></div>
        <div class="form-group"><label for="plSpecies" class="form-label">Species</label><input id="plSpecies" class="form-input" maxlength="100" placeholder="Optional"></div>
      </div>
      <div class="rd-form-row">
        <div class="form-group"><label for="plLoc" class="form-label">Location</label><input id="plLoc" class="form-input" maxlength="80" placeholder="e.g. Balcony, bedroom window"></div>
        <div class="form-group"><label for="plFreq" class="form-label">Water every (days)</label><input id="plFreq" type="number" min="1" max="365" class="form-input" value="7"></div>
      </div>
      <div class="form-group"><label for="plNotes" class="form-label">Care notes</label><textarea id="plNotes" class="form-input" rows="2" maxlength="500" placeholder="Light, soil, anything to remember"></textarea></div>
    </div>
    <div class="modal-footer"><button class="btn btn-secondary btn-sm" data-close-modal="plantModal">Cancel</button><button class="btn btn-primary btn-sm" onclick="savePlant()"><i class="fas fa-save"></i> Save</button></div>
  </div>
</div>

<!-- Plant detail / care log -->
<div id="plantViewModal" class="modal-backdrop hidden">
  <div class="modal-box rd-detail-box">
    <div class="modal-header"><span class="modal-title" id="pvTitle">Plant</span><button class="btn btn-icon btn-ghost btn-sm" data-close-modal="plantViewModal" aria-label="Close dialog">&times;</button></div>
    <div class="modal-body" id="pvBody"></div>
  </div>
</div>

</div>
<?php include '../includes/footer.php'; ?>
<script>
const API_BASE = '<?= APP_BASE ?>/api';
const gPost = d => Trackie.API.post(`${API_BASE}/gardening.php`, d);
const CARE = <?= json_encode($careMeta) ?>;
function openPlant() {
  document.getElementById('plId').value = '';
  ['plName', 'plSpecies', 'plLoc', 'plNotes'].forEach(i => document.getElementById(i).value = '');
  document.getElementById('plFreq').value = 7;
  document.getElementById('plantModalTitle').textContent = 'Add plant';
  Trackie.openModal('plantModal');
}
async function savePlant() {
  const id = document.getElementById('plId').value;
  const res = await gPost({ action: id ? 'edit' : 'add', item_id: id, name: document.getElementById('plName').value, species: document.getElementById('plSpecies').value,
    location: document.getElementById('plLoc').value, water_frequency_days: document.getElementById('plFreq').value, notes: document.getElementById('plNotes').value });
  if (res.success) { Trackie.Toast.success('Saved.'); location.reload(); } else Trackie.Toast.error(res.error || 'Failed.');
}
async function care(id, kind, note = '') {
  const res = await gPost({ action: 'care', item_id: id, kind, note });
  if (res.success) { Trackie.Toast.success(`${CARE[kind][0]} logged` + (res.xp?.ok ? ` · +${res.xp.gained} XP` : '')); location.reload(); }
  else Trackie.Toast.error(res.error || 'Failed.');
}
async function setPlantStatus(id, status) { const r = await gPost({ action: 'update_status', item_id: id, status }); if (r.success) Trackie.Toast.success('Updated.'); }
async function viewPlant(id) {
  const res = await gPost({ action: 'get', item_id: id });
  if (!res.success) { Trackie.Toast.error(res.error || 'Not found.'); return; }
  const p = res.plant;
  document.getElementById('pvTitle').textContent = p.name;
  document.getElementById('pvBody').innerHTML = `
    <div class="rd-author">${escHtml([p.species, p.location, 'water every ' + p.water_frequency_days + ' days'].filter(Boolean).join(' · '))}</div>
    ${p.notes ? `<p class="ph-notes">${escHtml(p.notes)}</p>` : ''}
    <div class="rd-current-actions">${Object.entries(CARE).filter(([k]) => k !== 'note').map(([k, [l, ic]]) =>
      `<button class="btn btn-secondary btn-sm" onclick="care(${+p.id}, '${k}')"><i class="fas ${ic}"></i> ${l}</button>`).join('')}</div>
    <div class="rd-inline-note" style="grid-template-columns:1fr auto"><textarea id="pvNote" class="form-input" rows="2" placeholder="Growth journal: new leaf, flowering, pests…" aria-label="Journal note"></textarea>
      <button class="btn btn-primary btn-sm" onclick="care(${+p.id}, 'note', document.getElementById('pvNote').value)">Add note</button></div>
    <div class="fit-card-label" style="margin-top:1rem">Care log</div>
    ${p.logs.length ? p.logs.map(l => `<div class="hb-row"><span><i class="fas ${CARE[l.kind]?.[1] || 'fa-circle'}" style="color:var(--muted);width:16px"></i> ${escHtml(CARE[l.kind]?.[0] || l.kind)}${l.note ? ' — ' + escHtml(l.note) : ''}</span><b>${escHtml(l.log_date)}</b></div>`).join('') : '<p class="hb-empty-line">No care logged yet.</p>'}
    <div class="rd-current-actions"><button class="btn btn-ghost btn-sm" onclick="editPlant(${+p.id})"><i class="fas fa-pen"></i> Edit</button>
      <button class="btn btn-ghost btn-sm" style="color:var(--accent);margin-left:auto" onclick="deletePlant(${+p.id})"><i class="fas fa-trash"></i> Delete</button></div>`;
  window.__plant = p;
  Trackie.openModal('plantViewModal');
}
function editPlant() {
  const p = window.__plant; if (!p) return;
  document.getElementById('plId').value = p.id; document.getElementById('plName').value = p.name;
  document.getElementById('plSpecies').value = p.species || ''; document.getElementById('plLoc').value = p.location || '';
  document.getElementById('plFreq').value = p.water_frequency_days; document.getElementById('plNotes').value = p.notes || '';
  document.getElementById('plantModalTitle').textContent = 'Edit plant';
  Trackie.closeModal('plantViewModal'); Trackie.openModal('plantModal');
}
async function deletePlant(id) {
  if (!await Trackie.confirmDialog('Delete this plant and its care log?', { confirmText: 'Delete', danger: true })) return;
  const r = await gPost({ action: 'delete', item_id: id }); if (r.success) location.reload();
}
</script>
