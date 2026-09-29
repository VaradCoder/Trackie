<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Hobbies';
$currentPage = 'hobbies';

$userHobbiesRaw = fetchOne("SELECT hobbies FROM users WHERE id=?", [$uid])['hobbies'] ?? '';
$userHobbyList  = array_filter(array_map('trim', explode(',', $userHobbiesRaw)));
$meta = allHobbiesMeta();

// Per-hobby streaks from the activity engine.
require_once '../includes/activity.php';
$hobbyModule = ['Fitness' => 'fitness', 'Reading' => 'reading', 'Gaming' => 'gaming', 'Photography' => 'photography',
                'Sports' => 'sports', 'Meditation' => 'meditation', 'Coding' => 'coding', 'Cooking' => 'cooking',
                'Writing' => 'writing', 'Art' => 'art', 'Gardening' => 'gardening'];
$streaks = activityReady() ? moduleStreaks($uid) : [];

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.25rem">
  <h1 style="font-size:1.125rem;font-weight:600;margin:0"><i class="fas fa-star" style="color:var(--accent)"></i> Your Hobbies</h1>
  <button type="button" class="btn btn-secondary btn-sm" onclick="openEditHobbies()"><i class="fas fa-pen"></i> Edit hobbies</button>
</div>
<p class="form-hint" style="margin-bottom:1.5rem">Trackie personalizes around what you picked — built modules open below; the rest are on the roadmap.</p>

<div id="hobbiesListWrap">
<?php if (empty($userHobbyList)): ?>
  <div class="card">
    <div class="empty-state">
      <div class="empty-state-icon"><i class="fas fa-star"></i></div>
      <div class="empty-state-title">No hobbies picked yet</div>
      <p>Pick a few hobbies and Trackie will build around them — starter habits, workout tracking, project management, and more.</p>
      <button type="button" class="btn btn-primary" style="margin-top:.75rem" onclick="openEditHobbies()"><i class="fas fa-star"></i> Pick your hobbies</button>
    </div>
  </div>
<?php else: ?>
  <div class="grid-cards">
    <?php foreach ($userHobbyList as $hobby):
      if (!isset($meta[$hobby])) continue;
      $m = $meta[$hobby];
      $hasModule = (bool)$m['module'];
    ?>
      <div class="habit-card">
        <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.75rem">
          <div style="width:44px;height:44px;border-radius:.75rem;display:flex;align-items:center;justify-content:center;flex-shrink:0;
                      background:color-mix(in srgb, <?= $m['color'] ?> 15%, transparent);color:<?= $m['color'] ?>">
            <i class="fas <?= $m['icon'] ?>" style="font-size:1.125rem"></i>
          </div>
          <div style="min-width:0">
            <div style="font-weight:600;font-size:.9375rem;color:var(--text)"><?= h($hobby) ?></div>
            <?php if (!$hasModule): ?><span class="badge badge-gray" style="margin-top:.125rem">Coming soon</span><?php endif; ?>
          </div>
        </div>
        <p style="font-size:.8125rem;color:var(--muted);margin-bottom:.75rem;min-height:2.25em"><?= h($m['desc'] ?? '') ?></p>
        <?php if (isset($hobbyModule[$hobby])): $st = $streaks[$hobbyModule[$hobby]] ?? null; ?>
          <div class="hb-streak<?= $st && $st['current'] > 0 ? ' hb-streak-on' : '' ?>">
            <?php if (!$st): ?>
              <i class="fas fa-fire"></i> No activity logged yet
            <?php else: ?>
              <i class="fas fa-fire"></i> <?= (int)$st['current'] ?>-day streak<?= $st['best'] > $st['current'] ? ' · best ' . (int)$st['best'] : '' ?>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <?php if ($hasModule): ?>
          <a href="<?= APP_BASE ?>/pages/<?= $m['module'] ?>" class="btn btn-primary btn-sm" style="width:100%">
            <i class="fas fa-arrow-right"></i> Open <?= h($m['moduleLabel']) ?>
          </a>
        <?php else: ?>
          <button class="btn btn-secondary btn-sm" style="width:100%" disabled>
            <i class="fas fa-hourglass-half"></i> Not built yet
          </button>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <!-- Add more hobbies card -->
    <button type="button" class="habit-card" onclick="openEditHobbies()"
            style="display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;border-style:dashed;min-height:180px;background:transparent;cursor:pointer">
      <i class="fas fa-plus" style="font-size:1.5rem;color:var(--muted);margin-bottom:.5rem"></i>
      <span style="font-size:.875rem;color:var(--muted);font-weight:600">Add more hobbies</span>
    </button>
  </div>
<?php endif; ?>
</div>

<!-- Edit hobbies modal -->
<div id="editHobbiesModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header">
      <span class="modal-title">Your hobbies</span>
      <button class="btn btn-icon btn-ghost btn-sm" data-close-modal="editHobbiesModal" aria-label="Close dialog">&times;</button>
    </div>
    <div class="modal-body">
      <p class="form-hint" style="margin-bottom:.875rem">Pick up to 8 — Trackie personalizes habits, this page, and your sidebar around them.</p>
      <div id="hobbyChipsModal" style="display:flex;flex-wrap:wrap;gap:.5rem">
        <?php foreach ($meta as $hobbyName => $hm): $active = in_array($hobbyName, $userHobbyList, true); ?>
          <button type="button" class="hobby-chip<?= $active ? ' is-active' : '' ?>" data-hobby="<?= h($hobbyName) ?>">
            <i class="fas <?= $hm['icon'] ?>" style="margin-right:.375rem;font-size:.75rem"></i><?= h($hobbyName) ?>
          </button>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary btn-sm" data-close-modal="editHobbiesModal">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="saveHobbiesFromHub()"><i class="fas fa-save"></i> Save</button>
    </div>
  </div>
</div>

<?php include '../includes/footer.php'; ?>

<script>
const API_BASE = '<?= APP_BASE ?>/api';

function openEditHobbies() {
  Trackie.openModal('editHobbiesModal');
}

document.getElementById('hobbyChipsModal').addEventListener('click', function (e) {
  const chip = e.target.closest('.hobby-chip');
  if (!chip) return;
  const active = document.querySelectorAll('#hobbyChipsModal .hobby-chip.is-active').length;
  if (!chip.classList.contains('is-active') && active >= 8) {
    Trackie.Toast.warning('Pick up to 8 hobbies.');
    return;
  }
  chip.classList.toggle('is-active');
});

async function saveHobbiesFromHub() {
  const chosen = Array.from(document.querySelectorAll('#hobbyChipsModal .hobby-chip.is-active')).map(c => c.dataset.hobby);
  try {
    const res = await Trackie.API.post(`${API_BASE}/profile.php`, { action: 'save_hobbies', hobbies: chosen.join(',') });
    if (res.success) {
      Trackie.Toast.success('Hobbies updated!');
      Trackie.closeModal('editHobbiesModal');
      await Trackie.refreshFragments(['hobbiesListWrap', 'sidebarNav']);
    } else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
</script>
