<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Photography';
$currentPage = 'photography';

if (!tableExists('photos')) renderSetupNeeded('Photography');
if (!tableExists('photo_images')) renderSetupNeeded('Photography');

require_once '../app/Modules/Photography/PhotographyService.php';
$svc = new PhotographyService($uid);

$tabs = ['overview' => 'Overview', 'gallery' => 'Gallery', 'shoots' => 'Shoots', 'projects' => 'Projects',
         'gear' => 'Equipment', 'editing' => 'Editing', 'goals' => 'Goals', 'stats' => 'Statistics'];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : (isset($_GET['shelf']) ? 'shoots' : 'overview');

$photos   = $svc->photos();
$shoots   = $svc->shoots();
$projects = $svc->projects();
$gear     = $svc->gear();
$goals    = $svc->goals();
$month    = $svc->month();
$streak   = $svc->streak();

$editMeta = ['raw' => 'To edit', 'editing' => 'Editing', 'edited' => 'Edited'];
$gearMeta = ['camera' => ['Cameras', 'fa-camera'], 'lens' => ['Lenses', 'fa-circle-dot'], 'accessory' => ['Accessories', 'fa-toolbox']];
$apiFile  = APP_BASE . '/api/photo_file.php';

function phMins(int $m): string { return $m < 60 ? "{$m}m" : intdiv($m, 60) . 'h' . ($m % 60 ? ' ' . ($m % 60) . 'm' : ''); }
function phTile(array $p, string $apiFile, string $extra = ''): string {
    $w = (int)($p['width'] ?: 4); $h = (int)($p['height'] ?: 3);
    return '<button class="ph-tile" data-photo="' . (int)$p['id'] . '" data-fav="' . (int)$p['favorite'] . '" data-shoot="' . (int)$p['shoot_id']
        . '" data-project="' . (int)$p['project_id'] . '" data-edit="' . h($p['edit_status']) . '"' . $extra . ' aria-label="' . h($p['title'] ?: 'Photo') . '">'
        . '<img src="' . h($apiFile) . '?id=' . (int)$p['id'] . '&amp;s=t" width="' . $w . '" height="' . $h . '" alt="' . h($p['title'] ?? '') . '" loading="lazy">'
        . ($p['favorite'] ? '<span class="ph-fav"><i class="fas fa-heart"></i></span>' : '') . '</button>';
}

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<div class="rd-head">
  <h1><i class="fas fa-camera" style="color:var(--accent)"></i> Photography</h1>
  <div class="rd-head-actions">
    <button class="btn btn-secondary btn-sm" onclick="openShoot()"><i class="fas fa-calendar-plus"></i> Log shoot</button>
    <button class="btn btn-primary btn-sm" onclick="openUpload()"><i class="fas fa-upload"></i> Add photos</button>
  </div>
</div>

<div class="filter-tabs" style="margin-bottom:1.25rem" id="phTabs" role="tablist" aria-label="Photography sections">
  <?php foreach ($tabs as $k => $label): ?>
    <button class="filter-tab<?= $tab === $k ? ' active' : '' ?>" data-tab="<?= $k ?>" role="tab" id="phtab-<?= $k ?>"
            aria-controls="ph-<?= $k ?>" aria-selected="<?= $tab === $k ? 'true' : 'false' ?>" <?= $tab === $k ? '' : 'tabindex="-1"' ?>><?= $label ?></button>
  <?php endforeach; ?>
</div>

<!-- ── Overview ─────────────────────────────────────────────── -->
<div id="ph-overview" class="ph-panel<?= $tab === 'overview' ? '' : ' hidden' ?>" role="tabpanel" aria-labelledby="phtab-overview">
<div id="phOverviewWrap">
  <div class="grid-stats" style="margin-bottom:1.25rem">
    <div class="stat-card"><div class="stat-val"><?= $month['photos'] ?><?= $goals['photos_per_month'] ? '<small> / ' . (int)$goals['photos_per_month'] . '</small>' : '' ?></div><div class="stat-label">Photos this month</div></div>
    <div class="stat-card"><div class="stat-val"><?= phMins($month['minutes']) ?></div><div class="stat-label">Shooting time this month</div></div>
    <div class="stat-card"><div class="stat-val"><?= $month['active_projects'] ?></div><div class="stat-label">Active projects</div></div>
    <div class="stat-card"><div class="stat-val">🔥 <?= (int)$streak['current'] ?></div><div class="stat-label">Day streak<?= $streak['best'] > $streak['current'] ? ' · best ' . (int)$streak['best'] : '' ?></div></div>
  </div>
  <?php if (!$photos): ?>
    <div class="card"><div class="empty-state">
      <div class="empty-state-icon"><i class="fas fa-images"></i></div>
      <div class="empty-state-title">Your photo journal is empty</div>
      <p>Add photos from your camera roll. Camera settings are read from each photo's EXIF data when it has any.</p>
      <button class="btn btn-primary" style="margin-top:.75rem" onclick="openUpload()"><i class="fas fa-upload"></i> Add your first photos</button>
    </div></div>
  <?php else: ?>
    <div class="fit-section-head"><h2 class="hb-h2">Recent photos</h2><button class="btn btn-ghost btn-sm" onclick="switchPhTab('gallery')">View gallery</button></div>
    <div class="ph-masonry ph-masonry-lg">
      <?php foreach (array_slice($photos, 0, 12) as $p) echo phTile($p, $apiFile); ?>
    </div>
  <?php endif; ?>
  <?php if ($shoots): ?>
    <div class="fit-section-head" style="margin-top:1.25rem"><h2 class="hb-h2">Recent shoots</h2><button class="btn btn-ghost btn-sm" onclick="switchPhTab('shoots')">All shoots</button></div>
    <div class="card">
      <?php foreach (array_slice($shoots, 0, 4) as $s): ?>
        <div class="ph-shoot-row">
          <div><div class="rd-title"><?= h($s['title']) ?></div>
            <div class="rd-author"><?= $s['taken_date'] ? h(formatDate($s['taken_date'])) : '' ?><?= $s['location'] ? ' · ' . h($s['location']) : '' ?></div></div>
          <div class="rd-author"><?= (int)$s['photo_count'] ?> photo<?= (int)$s['photo_count'] === 1 ? '' : 's' ?><?= $s['duration_min'] ? ' · ' . phMins((int)$s['duration_min']) : '' ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
</div>

<!-- ── Gallery ──────────────────────────────────────────────── -->
<div id="ph-gallery" class="ph-panel<?= $tab === 'gallery' ? '' : ' hidden' ?>" role="tabpanel" aria-labelledby="phtab-gallery">
<div id="phGalleryWrap">
  <div class="rd-toolbar">
    <div class="filter-tabs" id="phFilter">
      <button class="filter-tab active" data-f="all">All <small><?= count($photos) ?></small></button>
      <button class="filter-tab" data-f="fav"><i class="fas fa-heart"></i> Favorites</button>
      <button class="filter-tab" data-f="edit">To edit</button>
    </div>
    <div class="ph-selects">
      <select id="phShootFilter" class="form-input" aria-label="Filter by shoot"><option value="">All shoots</option>
        <?php foreach ($shoots as $s): ?><option value="<?= (int)$s['id'] ?>"><?= h($s['title']) ?></option><?php endforeach; ?></select>
      <select id="phProjectFilter" class="form-input" aria-label="Filter by project"><option value="">All projects</option>
        <?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h($p['name']) ?></option><?php endforeach; ?></select>
    </div>
  </div>
  <?php if (!$photos): ?>
    <div class="card card-body hb-empty-line">No photos yet. <a href="#" onclick="openUpload();return false">Add photos</a></div>
  <?php else: ?>
    <div class="ph-masonry" id="phGallery">
      <?php foreach ($photos as $p) echo phTile($p, $apiFile); ?>
    </div>
    <div class="card card-body hb-empty-line hidden" id="phNoMatch">No photos match these filters.</div>
  <?php endif; ?>
</div>
</div>

<!-- ── Shoots ───────────────────────────────────────────────── -->
<div id="ph-shoots" class="ph-panel<?= $tab === 'shoots' ? '' : ' hidden' ?>" role="tabpanel" aria-labelledby="phtab-shoots">
<div id="phShootsWrap">
  <?php if (!$shoots): ?>
    <div class="card"><div class="empty-state">
      <div class="empty-state-icon"><i class="fas fa-calendar-day"></i></div>
      <div class="empty-state-title">No shoots logged</div>
      <p>Log a shoot (where, when, how long) and add its photos to it.</p>
      <button class="btn btn-primary" style="margin-top:.75rem" onclick="openShoot()"><i class="fas fa-plus"></i> Log a shoot</button>
    </div></div>
  <?php else: ?>
    <div class="ph-shoots">
      <?php foreach ($shoots as $s):
        $thumbs = array_slice(array_values(array_filter($photos, static fn($p) => (int)$p['shoot_id'] === (int)$s['id'])), 0, 4); ?>
        <div class="card ph-shoot" id="shoot-<?= (int)$s['id'] ?>">
          <?php if ($thumbs): ?>
            <div class="ph-shoot-strip ph-strip-<?= count($thumbs) ?>">
              <?php foreach ($thumbs as $t): ?><img src="<?= h($apiFile) ?>?id=<?= (int)$t['id'] ?>&amp;s=t" alt="" loading="lazy"><?php endforeach; ?>
            </div>
          <?php endif; ?>
          <div class="card-body">
            <div class="ph-shoot-head">
              <div>
                <div class="rd-title" style="text-transform:uppercase;letter-spacing:.02em"><?= h($s['title']) ?></div>
                <div class="rd-author"><?= (int)$s['photo_count'] ?> photo<?= (int)$s['photo_count'] === 1 ? '' : 's' ?><?= $s['taken_date'] ? ' · ' . h(formatDate($s['taken_date'], 'M j, Y')) : '' ?></div>
              </div>
              <span class="rd-badge<?= $s['status'] === 'edited' ? ' rd-badge-done' : '' ?>"><?= $s['status'] === 'edited' ? 'Edited' : 'To edit' ?></span>
            </div>
            <dl class="ph-dl">
              <?php if ($s['camera']): ?><dt>Camera</dt><dd><?= h($s['camera']) ?></dd><?php endif; ?>
              <?php if ($s['duration_min']): ?><dt>Duration</dt><dd><?= phMins((int)$s['duration_min']) ?></dd><?php endif; ?>
              <?php if ($s['location']): ?><dt>Location</dt><dd><?= h($s['location']) ?></dd><?php endif; ?>
              <?php if ($s['project_name']): ?><dt>Project</dt><dd><?= h($s['project_name']) ?></dd><?php endif; ?>
            </dl>
            <?php if ($s['notes']): ?><p class="ph-notes"><?= nl2br(h($s['notes'])) ?></p><?php endif; ?>
            <div class="rd-current-actions">
              <button class="btn btn-secondary btn-sm" onclick="showShootPhotos(<?= (int)$s['id'] ?>)"><i class="fas fa-images"></i> Photos</button>
              <button class="btn btn-secondary btn-sm" onclick="openUpload(<?= (int)$s['id'] ?>)"><i class="fas fa-upload"></i> Add</button>
              <button class="btn btn-ghost btn-sm" onclick="openShoot(<?= (int)$s['id'] ?>)"><i class="fas fa-pen"></i> Edit</button>
              <button class="btn btn-ghost btn-sm" onclick="toggleShootStatus(<?= (int)$s['id'] ?>, '<?= $s['status'] === 'edited' ? 'to_edit' : 'edited' ?>')"><?= $s['status'] === 'edited' ? 'Mark to edit' : 'Mark edited' ?></button>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
</div>

<!-- ── Projects ─────────────────────────────────────────────── -->
<div id="ph-projects" class="ph-panel<?= $tab === 'projects' ? '' : ' hidden' ?>" role="tabpanel" aria-labelledby="phtab-projects">
<div id="phProjectsWrap">
  <div class="rd-toolbar"><span class="rd-author">Group photos into series, assignments or long-term themes.</span>
    <button class="btn btn-primary btn-sm" onclick="openProject()"><i class="fas fa-plus"></i> New project</button></div>
  <?php if (!$projects): ?>
    <div class="card card-body hb-empty-line">No projects yet.</div>
  <?php else: ?>
    <div class="ph-projects">
      <?php foreach ($projects as $p): ?>
        <div class="card ph-project">
          <button class="ph-project-cover" onclick="showProjectPhotos(<?= (int)$p['id'] ?>)" aria-label="Show photos in <?= h($p['name']) ?>">
            <?php if ($p['cover_id']): ?><img src="<?= h($apiFile) ?>?id=<?= (int)$p['cover_id'] ?>&amp;s=t" alt="" loading="lazy"><?php else: ?><i class="fas fa-folder-open"></i><?php endif; ?>
          </button>
          <div class="card-body">
            <div class="ph-shoot-head">
              <div class="rd-title"><?= h($p['name']) ?></div>
              <span class="rd-badge<?= $p['status'] === 'done' ? ' rd-badge-done' : '' ?>"><?= $p['status'] === 'done' ? 'Done' : 'Active' ?></span>
            </div>
            <div class="rd-author"><?= (int)$p['photo_count'] ?> photo<?= (int)$p['photo_count'] === 1 ? '' : 's' ?></div>
            <?php if ($p['description']): ?><p class="ph-notes"><?= h($p['description']) ?></p><?php endif; ?>
            <div class="rd-current-actions">
              <button class="btn btn-ghost btn-sm" onclick="openProject(<?= (int)$p['id'] ?>)"><i class="fas fa-pen"></i> Edit</button>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
</div>

<!-- ── Equipment ────────────────────────────────────────────── -->
<div id="ph-gear" class="ph-panel<?= $tab === 'gear' ? '' : ' hidden' ?>" role="tabpanel" aria-labelledby="phtab-gear">
<div id="phGearWrap">
  <div class="card card-body" style="margin-bottom:1rem">
    <div class="ph-gear-form">
      <select id="gearKind" class="form-input" aria-label="Type">
        <option value="camera">Camera</option><option value="lens">Lens</option><option value="accessory">Accessory</option>
      </select>
      <input id="gearName" class="form-input" placeholder="e.g. Sony A6400, 35mm f/1.8, Tripod" maxlength="100" aria-label="Name">
      <input id="gearNotes" class="form-input" placeholder="Notes (optional)" maxlength="255" aria-label="Notes">
      <button class="btn btn-primary btn-sm" onclick="addGear()"><i class="fas fa-plus"></i> Add</button>
    </div>
  </div>
  <div class="ph-gear">
    <?php foreach ($gearMeta as $k => [$label, $icon]): $items = array_filter($gear, static fn($g) => $g['kind'] === $k); ?>
      <div class="card card-body">
        <div class="fit-card-label"><i class="fas <?= $icon ?>"></i> <?= $label ?></div>
        <?php if (!$items): ?><p class="hb-empty-line">None added.</p><?php endif; ?>
        <?php foreach ($items as $g): ?>
          <div class="hb-row">
            <span><?= h($g['name']) ?><?php if ($g['notes']): ?><small class="ph-gear-note"><?= h($g['notes']) ?></small><?php endif; ?></span>
            <?php if ($g['photo_count'] !== null): ?><b title="Photos whose EXIF names this <?= $k ?>"><?= (int)$g['photo_count'] ?> photos</b><?php endif; ?>
            <button class="btn btn-icon btn-ghost btn-sm" onclick="deleteGear(<?= (int)$g['id'] ?>)" aria-label="Remove <?= h($g['name']) ?>"><i class="fas fa-trash"></i></button>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="hb-foot">Photo counts match the camera/lens names found in your photos' EXIF data.</p>
</div>
</div>

<!-- ── Editing queue ────────────────────────────────────────── -->
<div id="ph-editing" class="ph-panel<?= $tab === 'editing' ? '' : ' hidden' ?>" role="tabpanel" aria-labelledby="phtab-editing">
<div id="phEditingWrap">
  <div class="ph-board">
    <?php foreach ($editMeta as $k => $label): $items = array_values(array_filter($photos, static fn($p) => $p['edit_status'] === $k)); ?>
      <div class="ph-col">
        <div class="ph-col-head"><?= $label ?> <small><?= count($items) ?></small></div>
        <?php if (!$items): ?><p class="hb-empty-line">Nothing here.</p><?php endif; ?>
        <div class="ph-col-grid">
          <?php foreach (array_slice($items, 0, 60) as $p): ?>
            <div class="ph-col-item">
              <?= phTile($p, $apiFile) ?>
              <?php if ($k !== 'edited'): ?>
                <button class="btn btn-secondary btn-sm" onclick="setEdit(<?= (int)$p['id'] ?>, '<?= $k === 'raw' ? 'editing' : 'edited' ?>')"><?= $k === 'raw' ? 'Start editing' : 'Done' ?> <i class="fas fa-arrow-right"></i></button>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <?php if (count($items) > 60): ?><p class="hb-foot">+<?= count($items) - 60 ?> more</p><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
</div>

<!-- ── Goals ────────────────────────────────────────────────── -->
<div id="ph-goals" class="ph-panel<?= $tab === 'goals' ? '' : ' hidden' ?>" role="tabpanel" aria-labelledby="phtab-goals">
<div id="phGoalsWrap">
  <?php
    $dom = (int)date('j'); $dim = (int)date('t');
    $rows = [['Photos this month', $month['photos'], $goals['photos_per_month']], ['Shoots this month', $month['shoots'], $goals['shoots_per_month']]];
  ?>
  <div class="hb-grid2">
    <?php foreach ($rows as [$label, $done, $target]): $pct = $target ? min(100, (int)round($done / $target * 100)) : null; ?>
      <div class="card card-body">
        <div class="fit-card-label"><?= $label ?> · <?= date('F') ?></div>
        <div class="rd-goal-num"><?= $done ?><?php if ($target): ?><small> / <?= (int)$target ?></small><?php endif; ?></div>
        <?php if ($target): $expected = $target * $dom / $dim; ?>
          <div class="hb-progress rd-progress-lg"><span style="width:<?= $pct ?>%"></span></div>
          <p class="hb-foot"><?= $pct ?>% · <?= $done >= $target ? 'Goal reached 🎉' : ($done >= $expected ? 'On pace' : ceil($expected - $done) . ' behind the even pace for today') ?></p>
        <?php else: ?><p class="hb-empty-line">No target set.</p><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="card card-body" style="margin-top:1rem">
    <div class="fit-card-label">Monthly targets</div>
    <div class="rd-goal-form">
      <div class="form-group"><label class="form-label" for="goalPhotos">Photos / month</label><input id="goalPhotos" type="number" min="1" max="10000" class="form-input" value="<?= h((string)($goals['photos_per_month'] ?? '')) ?>" placeholder="e.g. 30"></div>
      <div class="form-group"><label class="form-label" for="goalShoots">Shoots / month</label><input id="goalShoots" type="number" min="1" max="100" class="form-input" value="<?= h((string)($goals['shoots_per_month'] ?? '')) ?>" placeholder="e.g. 4"></div>
      <button class="btn btn-primary btn-sm" onclick="saveGoals()"><i class="fas fa-save"></i> Save</button>
    </div>
    <p class="hb-foot">A photo counts in the month it was taken (EXIF date), or the month you added it if it has no date. Leave a field empty to clear it.</p>
  </div>
</div>
</div>

<!-- ── Statistics ───────────────────────────────────────────── -->
<div id="ph-stats" class="ph-panel<?= $tab === 'stats' ? '' : ' hidden' ?>" role="tabpanel" aria-labelledby="phtab-stats">
  <div id="phStats"><div class="hb-loading"><i class="fas fa-spinner fa-spin"></i> Developing your stats…</div></div>
</div>

<!-- ── Upload ───────────────────────────────────────────────── -->
<div id="uploadModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header"><span class="modal-title">Add photos</span><button class="btn btn-icon btn-ghost btn-sm" data-close-modal="uploadModal" aria-label="Close dialog">&times;</button></div>
    <div class="modal-body">
      <label class="ph-drop" id="phDrop">
        <input type="file" id="phFiles" accept="image/jpeg,image/png,image/webp" multiple hidden>
        <i class="fas fa-cloud-arrow-up"></i>
        <span><b>Choose photos</b> or drop them here</span>
        <small>JPEG, PNG or WebP · up to 20 at a time · resized to 2560px, location data removed</small>
      </label>
      <div class="rd-form-row" style="margin-top:1rem">
        <div class="form-group"><label class="form-label" for="upShoot">Shoot</label>
          <select id="upShoot" class="form-input"><option value="">None</option>
            <?php foreach ($shoots as $s): ?><option value="<?= (int)$s['id'] ?>"><?= h($s['title']) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label class="form-label" for="upProject">Project</label>
          <select id="upProject" class="form-input"><option value="">None</option>
            <?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h($p['name']) ?></option><?php endforeach; ?></select></div>
      </div>
      <div id="phQueue" class="ph-queue"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary btn-sm" data-close-modal="uploadModal">Close</button>
      <button class="btn btn-primary btn-sm" id="phUploadBtn" onclick="startUpload()" disabled><i class="fas fa-upload"></i> Upload</button>
    </div>
  </div>
</div>

<!-- ── Photo detail (lightbox) ──────────────────────────────── -->
<div id="photoModal" class="modal-backdrop hidden">
  <div class="modal-box ph-light">
    <div class="modal-header"><span class="modal-title" id="plTitle">Photo</span>
      <div style="display:flex;gap:.25rem">
        <button class="btn btn-icon btn-ghost btn-sm" onclick="stepPhoto(-1)" aria-label="Previous photo"><i class="fas fa-chevron-left"></i></button>
        <button class="btn btn-icon btn-ghost btn-sm" onclick="stepPhoto(1)" aria-label="Next photo"><i class="fas fa-chevron-right"></i></button>
        <button class="btn btn-icon btn-ghost btn-sm" data-close-modal="photoModal" aria-label="Close dialog">&times;</button>
      </div>
    </div>
    <div class="ph-light-body" id="plBody"></div>
  </div>
</div>

<!-- ── Shoot ────────────────────────────────────────────────── -->
<div id="shootModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header"><span class="modal-title" id="shootModalTitle">Log shoot</span><button class="btn btn-icon btn-ghost btn-sm" data-close-modal="shootModal" aria-label="Close dialog">&times;</button></div>
    <div class="modal-body">
      <input type="hidden" id="shId">
      <div class="form-group"><label for="shTitle" class="form-label">Name <span style="color:var(--accent)">*</span></label><input id="shTitle" class="form-input" maxlength="150" placeholder="e.g. Sunset shoot"></div>
      <div class="rd-form-row">
        <div class="form-group"><label for="shDate" class="form-label">Date</label><input id="shDate" type="date" class="form-input" max="<?= date('Y-m-d') ?>"></div>
        <div class="form-group"><label for="shDuration" class="form-label">Duration (min)</label><input id="shDuration" type="number" min="1" max="1440" class="form-input" placeholder="Optional"></div>
      </div>
      <div class="rd-form-row">
        <div class="form-group"><label for="shCamera" class="form-label">Camera</label>
          <input id="shCamera" class="form-input" maxlength="100" list="phCameraList" placeholder="Optional">
          <datalist id="phCameraList"><?php foreach ($gear as $g) if ($g['kind'] === 'camera') echo '<option value="' . h($g['name']) . '">'; ?></datalist></div>
        <div class="form-group"><label for="shLocation" class="form-label">Location</label><input id="shLocation" class="form-input" maxlength="150" placeholder="Optional"></div>
      </div>
      <div class="form-group"><label for="shProject" class="form-label">Project</label>
        <select id="shProject" class="form-input"><option value="">None</option>
          <?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h($p['name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label for="shNotes" class="form-label">Notes</label><textarea id="shNotes" class="form-input" rows="3" maxlength="1000"></textarea></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost btn-sm hidden" id="shDelete" style="color:var(--accent);margin-right:auto" onclick="deleteShoot()"><i class="fas fa-trash"></i> Delete</button>
      <button class="btn btn-secondary btn-sm" data-close-modal="shootModal">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="saveShoot()"><i class="fas fa-save"></i> Save</button>
    </div>
  </div>
</div>

<!-- ── Project ──────────────────────────────────────────────── -->
<div id="projectModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header"><span class="modal-title" id="projectModalTitle">New project</span><button class="btn btn-icon btn-ghost btn-sm" data-close-modal="projectModal" aria-label="Close dialog">&times;</button></div>
    <div class="modal-body">
      <input type="hidden" id="prId">
      <div class="form-group"><label for="prName" class="form-label">Name <span style="color:var(--accent)">*</span></label><input id="prName" class="form-input" maxlength="120" placeholder="e.g. Street portraits"></div>
      <div class="form-group"><label for="prDesc" class="form-label">Description</label><textarea id="prDesc" class="form-input" rows="3" maxlength="500"></textarea></div>
      <div class="form-group"><label for="prStatus" class="form-label">Status</label>
        <select id="prStatus" class="form-input"><option value="active">Active</option><option value="done">Done</option></select></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost btn-sm hidden" id="prDelete" style="color:var(--accent);margin-right:auto" onclick="deleteProject()"><i class="fas fa-trash"></i> Delete</button>
      <button class="btn btn-secondary btn-sm" data-close-modal="projectModal">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="saveProject()"><i class="fas fa-save"></i> Save</button>
    </div>
  </div>
</div>

<!-- Data for the edit dialogs (server-rendered, refreshed with the page fragments). -->
<script type="application/json" id="phData"><?= json_encode([
    'shoots'   => array_map(static fn($s) => array_intersect_key($s, array_flip(['id','title','location','camera','taken_date','duration_min','notes','project_id'])), $shoots),
    'projects' => array_map(static fn($p) => array_intersect_key($p, array_flip(['id','name','description','status'])), $projects),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

</div>
<?php include '../includes/footer.php'; ?>

<script src="<?= assetUrl('assets/js/photo-exif.js') ?>"></script>
<script>
const API_BASE = '<?= APP_BASE ?>/api';
const PH_FILE = `${API_BASE}/photo_file.php`;
const PH_FRAGS = ['phOverviewWrap', 'phGalleryWrap', 'phShootsWrap', 'phProjectsWrap', 'phGearWrap', 'phEditingWrap', 'phGoalsWrap',
                  'phData', 'upShoot', 'upProject', 'shProject', 'phCameraList'];
const phPost = data => Trackie.API.post(`${API_BASE}/photography.php`, data);
const phData = () => { try { return JSON.parse(document.getElementById('phData').textContent); } catch { return { shoots: [], projects: [] }; } };
function phMins(m) { m = Math.round(m || 0); if (m < 60) return `${m}m`; const r = m % 60; return `${Math.floor(m / 60)}h${r ? ' ' + r + 'm' : ''}`; }
async function phRefresh() {
  await Trackie.refreshFragments(PH_FRAGS);
  phApplyFilters();
  phStatsLoaded = false;
  if (phCurrentTab() === 'stats') loadPhStats();
}

/* ── Tabs ─────────────────────────────────────────────────────── */
const PH_TABS = [...document.querySelectorAll('#phTabs [data-tab]')].map(b => b.dataset.tab);
let phStatsLoaded = false;
function phCurrentTab() { return document.querySelector('#phTabs [aria-selected="true"]')?.dataset.tab; }
function switchPhTab(tab) {
  if (!PH_TABS.includes(tab)) tab = 'overview';
  document.querySelectorAll('#phTabs [data-tab]').forEach(b => {
    const on = b.dataset.tab === tab;
    b.classList.toggle('active', on); b.setAttribute('aria-selected', on ? 'true' : 'false'); b.tabIndex = on ? 0 : -1;
  });
  PH_TABS.forEach(t => document.getElementById(`ph-${t}`).classList.toggle('hidden', t !== tab));
  const url = new URL(location.href); url.searchParams.set('tab', tab); url.searchParams.delete('shelf');
  history.replaceState(history.state, '', url);
  if (tab === 'stats' && !phStatsLoaded) loadPhStats();
}
document.getElementById('phTabs').addEventListener('click', e => { const b = e.target.closest('[data-tab]'); if (b) switchPhTab(b.dataset.tab); });
document.getElementById('phTabs').addEventListener('keydown', e => {
  if (!['ArrowLeft', 'ArrowRight'].includes(e.key)) return;
  const i = PH_TABS.indexOf(phCurrentTab());
  const next = PH_TABS[(i + (e.key === 'ArrowRight' ? 1 : PH_TABS.length - 1)) % PH_TABS.length];
  switchPhTab(next); document.getElementById(`phtab-${next}`).focus();
});
if (phCurrentTab() === 'stats') loadPhStats();

/* ── Gallery filters ──────────────────────────────────────────── */
let phFilter = 'all';
function phApplyFilters() {
  const shoot = document.getElementById('phShootFilter')?.value || '';
  const project = document.getElementById('phProjectFilter')?.value || '';
  document.querySelectorAll('#phFilter [data-f]').forEach(b => b.classList.toggle('active', b.dataset.f === phFilter));
  let shown = 0;
  document.querySelectorAll('#phGallery .ph-tile').forEach(t => {
    const ok = (phFilter === 'all' || (phFilter === 'fav' && t.dataset.fav === '1') || (phFilter === 'edit' && t.dataset.edit !== 'edited'))
      && (!shoot || t.dataset.shoot === shoot) && (!project || t.dataset.project === project);
    t.classList.toggle('hidden', !ok); if (ok) shown++;
  });
  document.getElementById('phNoMatch')?.classList.toggle('hidden', shown > 0);
}
document.getElementById('ph-gallery').addEventListener('click', e => {
  const f = e.target.closest('[data-f]'); if (f) { phFilter = f.dataset.f; phApplyFilters(); }
});
document.getElementById('ph-gallery').addEventListener('change', e => {
  if (e.target.id === 'phShootFilter' || e.target.id === 'phProjectFilter') phApplyFilters();
});
function showShootPhotos(id) {
  switchPhTab('gallery'); phFilter = 'all';
  document.getElementById('phProjectFilter').value = ''; document.getElementById('phShootFilter').value = id; phApplyFilters();
}
function showProjectPhotos(id) {
  switchPhTab('gallery'); phFilter = 'all';
  document.getElementById('phShootFilter').value = ''; document.getElementById('phProjectFilter').value = id; phApplyFilters();
}

/* ── Lightbox ─────────────────────────────────────────────────── */
let plList = [], plIndex = 0;
document.getElementById('page-main').addEventListener('click', e => {
  const tile = e.target.closest('.ph-tile');
  if (!tile) return;
  // Step through the photos visible in the same grid, in order.
  const grid = tile.closest('.ph-masonry, .ph-col-grid');
  plList = [...grid.querySelectorAll('.ph-tile:not(.hidden)')].map(t => +t.dataset.photo);
  plIndex = Math.max(0, plList.indexOf(+tile.dataset.photo));
  openPhoto(plList[plIndex]);
});
function stepPhoto(d) {
  if (!plList.length) return;
  plIndex = (plIndex + d + plList.length) % plList.length;
  openPhoto(plList[plIndex]);
}
document.getElementById('photoModal').addEventListener('keydown', e => {
  if (e.target.matches('input, textarea, select')) return;
  if (e.key === 'ArrowLeft') stepPhoto(-1);
  if (e.key === 'ArrowRight') stepPhoto(1);
});
async function openPhoto(id) {
  const body = document.getElementById('plBody');
  if (document.getElementById('photoModal').classList.contains('hidden')) Trackie.openModal('photoModal');
  body.innerHTML = '<div class="hb-loading"><i class="fas fa-spinner fa-spin"></i></div>';
  try {
    const res = await phPost({ action: 'photo_get', photo_id: id });
    if (!res.success) { body.innerHTML = `<p class="hb-error" style="padding:1rem">${escHtml(res.error || 'Not found.')}</p>`; return; }
    renderPhoto(res.photo);
  } catch { body.innerHTML = '<p class="hb-error" style="padding:1rem">Network error.</p>'; }
}
function renderPhoto(p) {
  const d = phData();
  const na = '<span class="ph-na">Not available</span>';
  const dateTxt = p.taken_at
    ? new Date(p.taken_at.replace(' ', 'T')).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
    : null;
  const rows = [
    ['Camera', p.camera], ['Lens', p.lens], ['ISO', p.iso],
    ['Shutter', p.shutter ? `${p.shutter}s` : null], ['Aperture', p.aperture ? `f/${(+p.aperture).toString()}` : null],
    ['Focal length', p.focal_mm ? `${+p.focal_mm}mm` : null], ['Date taken', dateTxt],
  ];
  const opts = (list, key, label, sel) => `<option value="">None</option>` + list.map(x =>
    `<option value="${+x.id}"${+x.id === +sel ? ' selected' : ''}>${escHtml(x[label])}</option>`).join('');
  document.getElementById('plTitle').textContent = p.title || 'Photo';
  document.getElementById('plBody').innerHTML = `
    <div class="ph-light-img"><img src="${PH_FILE}?id=${+p.id}" alt="${escHtml(p.title || '')}"></div>
    <div class="ph-light-side">
      <div class="ph-light-actions">
        <button class="btn btn-sm ${+p.favorite ? 'btn-primary' : 'btn-secondary'}" onclick="updatePhoto(${+p.id}, {favorite: ${+p.favorite ? 0 : 1}})">
          <i class="${+p.favorite ? 'fas' : 'far'} fa-heart"></i> ${+p.favorite ? 'Favorite' : 'Add to favorites'}</button>
        <select class="form-input" aria-label="Edit status" onchange="updatePhoto(${+p.id}, {edit_status: this.value})">
          ${[['raw', 'To edit'], ['editing', 'Editing'], ['edited', 'Edited']].map(([v, l]) => `<option value="${v}"${p.edit_status === v ? ' selected' : ''}>${l}</option>`).join('')}
        </select>
      </div>
      <div class="fit-card-label">Camera data</div>
      <dl class="ph-exif">${rows.map(([k, v]) => `<dt>${k}</dt><dd>${v !== null && v !== undefined && v !== '' ? escHtml(String(v)) : na}</dd>`).join('')}</dl>
      <p class="hb-foot" style="margin-top:.25rem">${p.exif_source ? 'Read from the photo\'s EXIF data.' : 'This photo had no EXIF data — nothing is filled in by guesswork.'}
        Added ${escHtml(new Date(p.created_at.replace(' ', 'T')).toLocaleDateString())}${p.width ? ` · ${+p.width}×${+p.height}` : ''}.</p>
      <div class="fit-card-label" style="margin-top:1rem">Details</div>
      <div class="form-group"><label class="form-label" for="plT">Title</label><input id="plT" class="form-input" maxlength="150" value="${escHtml(p.title || '')}"></div>
      <div class="form-group"><label class="form-label" for="plC">Caption</label><textarea id="plC" class="form-input" rows="2" maxlength="1000">${escHtml(p.caption || '')}</textarea></div>
      <div class="rd-form-row">
        <div class="form-group"><label class="form-label" for="plS">Shoot</label><select id="plS" class="form-input">${opts(d.shoots, 'id', 'title', p.shoot_id)}</select></div>
        <div class="form-group"><label class="form-label" for="plP">Project</label><select id="plP" class="form-input">${opts(d.projects, 'id', 'name', p.project_id)}</select></div>
      </div>
      <div class="ph-light-actions">
        <button class="btn btn-primary btn-sm" onclick="savePhotoDetails(${+p.id})"><i class="fas fa-save"></i> Save</button>
        <a class="btn btn-ghost btn-sm" href="${PH_FILE}?id=${+p.id}" target="_blank" rel="noopener" data-no-spa><i class="fas fa-up-right-from-square"></i> Full size</a>
        <button class="btn btn-ghost btn-sm" style="color:var(--accent);margin-left:auto" onclick="deletePhoto(${+p.id})"><i class="fas fa-trash"></i> Delete</button>
      </div>
    </div>`;
}
async function updatePhoto(id, fields) {
  try {
    const res = await phPost({ action: 'photo_update', photo_id: id, ...fields });
    if (!res.success) { Trackie.Toast.error(res.error || 'Failed.'); return; }
    renderPhoto(res.photo);
    phRefresh();
  } catch { Trackie.Toast.error('Network error.'); }
}
function savePhotoDetails(id) {
  updatePhoto(id, { title: document.getElementById('plT').value, caption: document.getElementById('plC').value,
    shoot_id: document.getElementById('plS').value, project_id: document.getElementById('plP').value })
    .then(() => Trackie.Toast.success('Saved.'));
}
async function deletePhoto(id) {
  const ok = await Trackie.confirmDialog('Delete this photo? The file is removed from Trackie permanently.', { confirmText: 'Delete', danger: true });
  if (!ok) return;
  try {
    const res = await phPost({ action: 'photo_delete', photo_id: id });
    if (!res.success) { Trackie.Toast.error(res.error || 'Failed.'); return; }
    Trackie.Toast.success('Photo deleted.');
    plList = plList.filter(x => x !== id);
    if (plList.length) { plIndex = Math.min(plIndex, plList.length - 1); openPhoto(plList[plIndex]); } else Trackie.closeModal('photoModal');
    await phRefresh();
  } catch { Trackie.Toast.error('Network error.'); }
}
async function setEdit(id, status) {
  try {
    const res = await phPost({ action: 'photo_update', photo_id: id, edit_status: status });
    if (res.success) await phRefresh(); else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}

/* ── Upload ───────────────────────────────────────────────────── */
let phFilesQueue = [];
function openUpload(shootId) {
  phFilesQueue = [];
  renderQueue();
  document.getElementById('upShoot').value = shootId || '';
  const s = shootId && phData().shoots.find(x => +x.id === +shootId);
  document.getElementById('upProject').value = s && s.project_id ? s.project_id : '';
  Trackie.openModal('uploadModal');
}
function addFiles(list) {
  const files = [...list].filter(f => /^image\/(jpeg|png|webp)$/.test(f.type));
  const skipped = list.length - files.length;
  if (skipped) Trackie.Toast.warning(`${skipped} file${skipped === 1 ? '' : 's'} skipped — only JPEG, PNG and WebP are supported (export HEIC photos as JPEG first).`, 6000);
  phFilesQueue = phFilesQueue.concat(files.map(f => ({ file: f, state: 'ready', msg: '' }))).slice(0, 20);
  renderQueue();
}
function renderQueue() {
  const q = document.getElementById('phQueue');
  q.innerHTML = phFilesQueue.map(item => `<div class="ph-q ph-q-${item.state}">
    <i class="fas ${{ ready: 'fa-image', busy: 'fa-spinner fa-spin', done: 'fa-check', error: 'fa-triangle-exclamation' }[item.state]}"></i>
    <span>${escHtml(item.file.name)}</span><small>${escHtml(item.msg || (item.file.size / 1048576).toFixed(1) + ' MB')}</small></div>`).join('');
  document.getElementById('phUploadBtn').disabled = !phFilesQueue.some(i => i.state === 'ready');
}
document.getElementById('phFiles').addEventListener('change', e => { addFiles(e.target.files); e.target.value = ''; });
const phDrop = document.getElementById('phDrop');
['dragenter', 'dragover'].forEach(ev => phDrop.addEventListener(ev, e => { e.preventDefault(); phDrop.classList.add('ph-drop-on'); }));
['dragleave', 'drop'].forEach(ev => phDrop.addEventListener(ev, e => { e.preventDefault(); phDrop.classList.remove('ph-drop-on'); }));
phDrop.addEventListener('drop', e => addFiles(e.dataTransfer.files));
async function startUpload() {
  const btn = document.getElementById('phUploadBtn'); btn.disabled = true;
  let ok = 0, xp = 0;
  for (const item of phFilesQueue.filter(i => i.state === 'ready')) {
    item.state = 'busy'; item.msg = 'Reading EXIF…'; renderQueue();
    try {
      const exif = await PhotoExif.read(item.file);            // from the ORIGINAL
      item.msg = 'Resizing…'; renderQueue();
      const blob = await PhotoExif.prepare(item.file);          // strips GPS + metadata
      item.msg = 'Uploading…'; renderQueue();
      const fd = new FormData();
      fd.append('action', 'photo_upload');
      fd.append('photo', blob, item.file.name.replace(/\.[^.]+$/, '') + (blob.type === 'image/jpeg' ? '.jpg' : ''));
      fd.append('shoot_id', document.getElementById('upShoot').value);
      fd.append('project_id', document.getElementById('upProject').value);
      Object.entries(exif).forEach(([k, v]) => fd.append(k, v));
      const res = await Trackie.API.post(`${API_BASE}/photography.php`, fd);
      if (res.success) {
        ok++; if (res.xp?.ok) xp += res.xp.gained;
        item.state = 'done'; item.msg = Object.keys(exif).length ? 'Uploaded · EXIF read' : 'Uploaded · no EXIF in file';
      } else { item.state = 'error'; item.msg = res.error || 'Failed'; }
    } catch (err) { item.state = 'error'; item.msg = 'Upload failed'; }
    renderQueue();
  }
  if (ok) {
    Trackie.Toast.success(`${ok} photo${ok === 1 ? '' : 's'} added${xp ? ` · +${xp} XP` : ''}.`);
    await phRefresh();
  }
  renderQueue();
}

/* ── Shoots ───────────────────────────────────────────────────── */
function openShoot(id) {
  const s = id ? phData().shoots.find(x => +x.id === +id) : null;
  document.getElementById('shootModalTitle').textContent = s ? 'Edit shoot' : 'Log shoot';
  document.getElementById('shId').value = s ? s.id : '';
  document.getElementById('shTitle').value = s ? s.title : '';
  document.getElementById('shDate').value = s ? (s.taken_date || '') : '<?= date('Y-m-d') ?>';
  document.getElementById('shDuration').value = s ? (s.duration_min || '') : '';
  document.getElementById('shCamera').value = s ? (s.camera || '') : '';
  document.getElementById('shLocation').value = s ? (s.location || '') : '';
  document.getElementById('shProject').value = s ? (s.project_id || '') : '';
  document.getElementById('shNotes').value = s ? (s.notes || '') : '';
  document.getElementById('shDelete').classList.toggle('hidden', !s);
  Trackie.openModal('shootModal');
}
async function saveShoot() {
  const title = document.getElementById('shTitle').value.trim();
  if (!title) { Trackie.Toast.warning('Give the shoot a name.'); return; }
  try {
    const res = await phPost({ action: 'shoot_save', shoot_id: document.getElementById('shId').value, title,
      taken_date: document.getElementById('shDate').value, duration_min: document.getElementById('shDuration').value,
      camera: document.getElementById('shCamera').value, location: document.getElementById('shLocation').value,
      project_id: document.getElementById('shProject').value, notes: document.getElementById('shNotes').value });
    if (!res.success) { Trackie.Toast.error(res.error || 'Failed.'); return; }
    Trackie.Toast.success(res.xp?.ok ? `Shoot logged · +${res.xp.gained} XP` : 'Shoot saved.');
    Trackie.closeModal('shootModal');
    await phRefresh();
  } catch { Trackie.Toast.error('Network error.'); }
}
async function deleteShoot() {
  const ok = await Trackie.confirmDialog('Delete this shoot? Its photos are kept.', { confirmText: 'Delete', danger: true });
  if (!ok) return;
  try {
    const res = await phPost({ action: 'delete', item_id: document.getElementById('shId').value });
    if (res.success) { Trackie.closeModal('shootModal'); Trackie.Toast.success('Shoot deleted.'); await phRefresh(); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function toggleShootStatus(id, status) {
  try { const res = await phPost({ action: 'update_status', item_id: id, status }); if (res.success) await phRefresh(); else Trackie.Toast.error(res.error || 'Failed.'); }
  catch { Trackie.Toast.error('Network error.'); }
}

/* ── Projects ─────────────────────────────────────────────────── */
function openProject(id) {
  const p = id ? phData().projects.find(x => +x.id === +id) : null;
  document.getElementById('projectModalTitle').textContent = p ? 'Edit project' : 'New project';
  document.getElementById('prId').value = p ? p.id : '';
  document.getElementById('prName').value = p ? p.name : '';
  document.getElementById('prDesc').value = p ? (p.description || '') : '';
  document.getElementById('prStatus').value = p ? p.status : 'active';
  document.getElementById('prDelete').classList.toggle('hidden', !p);
  Trackie.openModal('projectModal');
}
async function saveProject() {
  const name = document.getElementById('prName').value.trim();
  if (!name) { Trackie.Toast.warning('Give the project a name.'); return; }
  try {
    const res = await phPost({ action: 'project_save', project_id: document.getElementById('prId').value, name,
      description: document.getElementById('prDesc').value, status: document.getElementById('prStatus').value });
    if (!res.success) { Trackie.Toast.error(res.error || 'Failed.'); return; }
    Trackie.Toast.success('Project saved.'); Trackie.closeModal('projectModal'); await phRefresh();
  } catch { Trackie.Toast.error('Network error.'); }
}
async function deleteProject() {
  const ok = await Trackie.confirmDialog('Delete this project? Its photos and shoots are kept.', { confirmText: 'Delete', danger: true });
  if (!ok) return;
  try {
    const res = await phPost({ action: 'project_delete', project_id: document.getElementById('prId').value });
    if (res.success) { Trackie.closeModal('projectModal'); Trackie.Toast.success('Project deleted.'); await phRefresh(); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}

/* ── Gear ─────────────────────────────────────────────────────── */
async function addGear() {
  const name = document.getElementById('gearName').value.trim();
  if (!name) { Trackie.Toast.warning('Enter a name.'); return; }
  try {
    const res = await phPost({ action: 'gear_add', kind: document.getElementById('gearKind').value, name, notes: document.getElementById('gearNotes').value });
    if (!res.success) { Trackie.Toast.error(res.error || 'Failed.'); return; }
    document.getElementById('gearName').value = ''; document.getElementById('gearNotes').value = '';
    await phRefresh();
  } catch { Trackie.Toast.error('Network error.'); }
}
async function deleteGear(id) {
  try { const res = await phPost({ action: 'gear_delete', gear_id: id }); if (res.success) await phRefresh(); else Trackie.Toast.error(res.error || 'Failed.'); }
  catch { Trackie.Toast.error('Network error.'); }
}

/* ── Goals ────────────────────────────────────────────────────── */
async function saveGoals() {
  try {
    const res = await phPost({ action: 'goals_save', photos_per_month: document.getElementById('goalPhotos').value, shoots_per_month: document.getElementById('goalShoots').value });
    if (res.success) { Trackie.Toast.success('Targets saved.'); await phRefresh(); } else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}

/* ── Statistics ───────────────────────────────────────────────── */
async function loadPhStats(year) {
  phStatsLoaded = true;
  const el = document.getElementById('phStats');
  try {
    const res = await phPost({ action: 'stats', year: year || '' });
    if (!res.success) { el.innerHTML = `<div class="card card-body hb-error">${escHtml(res.error || 'Failed.')}</div>`; return; }
    const s = res.stats, t = s.totals;
    const mo = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    const bars = (key, fmt) => { const v = s.months.map(m => m[key]); const max = Math.max(1, ...v);
      return `<div class="hb-bars">${v.map((x, i) => `<div class="hb-bar" title="${mo[i]}: ${fmt(x)}"><span style="height:${Math.round(x / max * 100)}%"></span><em>${mo[i][0]}</em></div>`).join('')}</div>`; };
    const list = (title, rows) => `<div class="card card-body"><div class="fit-card-label">${title}</div>
      ${rows.length ? rows.map(r => `<div class="hb-row"><span>${escHtml(String(r.v))}</span><b>${+r.n}</b></div>`).join('') : '<p class="hb-empty-line">No EXIF data yet.</p>'}</div>`;
    el.innerHTML = `<div class="rd-stats-head"><h2 class="hb-h2">Photography in ${+s.year}</h2>
      ${s.years.length > 1 ? `<select id="phYear" class="form-input" style="width:auto" aria-label="Year">${s.years.map(y => `<option${y === s.year ? ' selected' : ''}>${+y}</option>`).join('')}</select>` : ''}</div>
      <div class="grid-stats" style="margin-bottom:1rem">
        <div class="stat-card"><div class="stat-val">${t.photos}</div><div class="stat-label">Photos in ${+s.year}</div></div>
        <div class="stat-card"><div class="stat-val">${t.shoots}</div><div class="stat-label">Shoots</div></div>
        <div class="stat-card"><div class="stat-val">${phMins(t.minutes)}</div><div class="stat-label">Shooting time (logged)</div></div>
        <div class="stat-card"><div class="stat-val">${s.streak.best}</div><div class="stat-label">Best streak · now ${s.streak.current}</div></div>
      </div>
      <div class="hb-grid2">
        <div class="card card-body"><div class="fit-card-label">Photos per month</div>${bars('photos', x => x + ' photos')}</div>
        <div class="card card-body"><div class="fit-card-label">Shoots per month</div>${bars('shoots', x => x + ' shoots')}</div>
      </div>
      <div class="rd-stats-head" style="margin-top:1.25rem"><h2 class="hb-h2">Your camera data · all time</h2>
        <span class="rd-author">${t.with_exif} of ${t.all_photos} photos have EXIF data</span></div>
      <div class="ph-stat-grid">
        ${list('Cameras', s.cameras)}${list('Lenses', s.lenses)}${list('Focal lengths', s.focal)}
        ${list('Apertures', s.apertures)}${list('ISO', s.iso)}
        <div class="card card-body"><div class="fit-card-label">Editing</div>
          <div class="hb-row"><span>To edit</span><b>${+(s.edit.raw || 0)}</b></div>
          <div class="hb-row"><span>Editing</span><b>${+(s.edit.editing || 0)}</b></div>
          <div class="hb-row"><span>Edited</span><b>${+(s.edit.edited || 0)}</b></div>
          <div class="hb-row"><span>Favorites</span><b>${t.favorites}</b></div></div>
      </div>
      <p class="hb-foot">Camera stats count only photos whose EXIF data had that field; photos without EXIF are never guessed.</p>`;
    document.getElementById('phYear')?.addEventListener('change', e => loadPhStats(e.target.value));
  } catch { el.innerHTML = '<div class="card card-body hb-error">Network error.</div>'; }
}
</script>
