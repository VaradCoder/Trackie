<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Gaming';
$currentPage = 'gaming';

if (!tableExists('games')) renderSetupNeeded('Gaming');

$shelf = in_array($_GET['shelf'] ?? '', ['wishlist','backlog','playing','completed']) ? $_GET['shelf'] : 'all';
$sql = "SELECT * FROM games WHERE user_id=?";
$params = [$uid];
if ($shelf !== 'all') { $sql .= " AND status=?"; $params[] = $shelf; }
$sql .= " ORDER BY created_at DESC";
$games = fetchAll($sql, $params);

$counts = fetchOne(
    "SELECT COUNT(*) total, SUM(status='backlog') backlog, SUM(status='playing') playing, SUM(status='completed') completed,
            SUM(hours_played) hours
     FROM games WHERE user_id=?", [$uid]
);

$statusMeta = [
    'wishlist'  => ['label' => 'Wishlist',  'icon' => 'fa-heart'],
    'backlog'   => ['label' => 'Backlog',   'icon' => 'fa-layer-group'],
    'playing'   => ['label' => 'Playing',   'icon' => 'fa-gamepad'],
    'completed' => ['label' => 'Completed', 'icon' => 'fa-trophy'],
];

// ── Steam connection state ───────────────────────────────────────
$steamConfigured = (bool)env('STEAM_API_KEY');
$steamLink = $steamConfigured
    ? fetchOne("SELECT external_id, last_sync, sync_status, last_error FROM user_integrations WHERE user_id=? AND provider='steam'", [$uid])
    : null;

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem">
  <h1 style="font-size:1.125rem;font-weight:600;margin:0"><i class="fas fa-gamepad" style="color:var(--accent)"></i> Gaming</h1>
  <button class="btn btn-primary btn-sm" onclick="openAddGame()"><i class="fas fa-plus"></i> Add Game</button>
</div>

<div id="steamCardWrap" style="margin-bottom:1.5rem">
<?php if (!$steamConfigured): ?>
  <!-- No STEAM_API_KEY set — nothing to show; Settings → Integrations already explains this. -->
<?php elseif (!$steamLink): ?>
  <div class="card card-body" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap">
    <div style="display:flex;align-items:center;gap:.75rem">
      <i class="fa-brands fa-steam" style="font-size:1.75rem;color:#66c0f4"></i>
      <div>
        <div style="font-weight:600;font-size:.9375rem;color:var(--text)">Connect Steam</div>
        <div style="font-size:.8125rem;color:var(--muted)">Pull your real owned games and playtime automatically.</div>
      </div>
    </div>
    <div style="display:flex;gap:.5rem;flex:1;min-width:260px;max-width:420px">
      <input id="steamIdInput" class="form-input" placeholder="SteamID64 or profile URL" style="flex:1">
      <button class="btn btn-primary btn-sm" onclick="connectSteam()" id="steamConnectBtn"><i class="fas fa-link"></i> Connect</button>
    </div>
  </div>
<?php else: ?>
  <div class="card card-body" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap">
    <div style="display:flex;align-items:center;gap:.75rem">
      <i class="fa-brands fa-steam" style="font-size:1.75rem;color:#66c0f4"></i>
      <div>
        <div style="font-weight:600;font-size:.9375rem;color:var(--text)">
          Steam connected
          <?php if ($steamLink['sync_status'] === 'error'): ?>
            <span class="badge badge-red" style="margin-left:.375rem">Sync failed</span>
          <?php endif; ?>
        </div>
        <div style="font-size:.8125rem;color:var(--muted)">
          <?= $steamLink['last_sync'] ? 'Last synced ' . h(formatDate($steamLink['last_sync'])) : 'Never synced yet' ?>
          <?= $steamLink['last_error'] ? ' · ' . h($steamLink['last_error']) : '' ?>
        </div>
      </div>
    </div>
    <div style="display:flex;gap:.5rem">
      <button class="btn btn-secondary btn-sm" onclick="syncSteam()" id="steamSyncBtn"><i class="fas fa-rotate"></i> Sync now</button>
      <button class="btn btn-icon btn-ghost btn-sm" style="color:var(--accent)" onclick="disconnectSteam()" title="Disconnect Steam" aria-label="Disconnect Steam"><i class="fas fa-unlink"></i></button>
    </div>
  </div>
<?php endif; ?>
</div>

<div class="grid-stats" style="margin-bottom:1.5rem" id="gamingStatsWrap">
  <div class="stat-card"><div class="stat-val"><?= (int)$counts['total'] ?></div><div class="stat-label">Total games</div></div>
  <div class="stat-card"><div class="stat-val"><?= (int)$counts['playing'] ?></div><div class="stat-label">Playing</div></div>
  <div class="stat-card"><div class="stat-val"><?= (int)$counts['completed'] ?></div><div class="stat-label">Completed</div></div>
  <div class="stat-card"><div class="stat-val"><?= round((float)$counts['hours'], 0) ?>h</div><div class="stat-label">Hours played</div></div>
</div>

<div class="filter-tabs" style="margin-bottom:1.25rem" id="gamingTabs">
  <button class="filter-tab active" data-tab="library">Library</button>
  <button class="filter-tab" data-tab="learn">Learn</button>
</div>

<div id="gtab-library" class="gym-tab-panel">
  <div class="filter-tabs" style="margin-bottom:1.25rem">
    <a class="filter-tab <?= $shelf==='all'?'active':'' ?>" href="?shelf=all">All</a>
    <?php foreach ($statusMeta as $k => $m): ?>
      <a class="filter-tab <?= $shelf===$k?'active':'' ?>" href="?shelf=<?= $k ?>"><?= $m['label'] ?></a>
    <?php endforeach; ?>
  </div>

  <div id="gamingListWrap">
  <?php if (empty($games)): ?>
    <div class="card"><div class="empty-state"><div class="empty-state-icon"><i class="fas fa-gamepad"></i></div><div class="empty-state-title">No games here yet</div><p>Track what you're playing, your backlog, and what you've finished.</p>
      <button class="btn btn-primary" style="margin-top:.75rem" onclick="openAddGame()"><i class="fas fa-plus"></i> Add your first game</button>
    </div></div>
  <?php else: ?>
    <div class="grid-cards">
      <?php foreach ($games as $g): $sm = $statusMeta[$g['status']]; ?>
        <div class="habit-card" id="game-<?= $g['id'] ?>" style="<?= $g['cover_url'] ? 'padding-top:0;overflow:hidden' : '' ?>">
          <?php if ($g['cover_url']): ?>
            <img src="<?= h($g['cover_url']) ?>" alt="" loading="lazy"
                 style="width:calc(100% + 2.25rem);margin:0 -1.125rem .75rem;display:block;aspect-ratio:460/215;object-fit:cover;background:var(--surface2)"
                 onerror="this.style.display='none'">
          <?php endif; ?>
          <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:.5rem">
            <div style="min-width:0">
              <div style="font-weight:600;font-size:.9375rem;color:var(--text)"><?= h($g['title']) ?></div>
              <?php if ($g['platform']): ?><div style="font-size:.8125rem;color:var(--muted)"><?= h($g['platform']) ?></div><?php endif; ?>
            </div>
            <button aria-label="Delete game" class="btn btn-icon btn-ghost btn-sm" style="color:var(--accent);flex-shrink:0" onclick="deleteGame(<?= $g['id'] ?>)"><i class="fas fa-trash"></i></button>
          </div>
          <div style="margin-bottom:.625rem" id="gstars-<?= $g['id'] ?>">
            <?php for ($i = 1; $i <= 5; $i++): ?>
              <i class="<?= $g['rating'] >= $i ? 'fas' : 'far' ?> fa-star" style="color:#f59e0b;cursor:pointer;font-size:.8125rem" onclick="rateGame(<?= $g['id'] ?>, <?= $i ?>)"></i>
            <?php endfor; ?>
            <?php if ($g['hours_played'] > 0): ?><span style="font-size:.75rem;color:var(--muted);margin-left:.5rem"><?= $g['hours_played'] ?>h</span><?php endif; ?>
          </div>
          <select class="form-input" style="width:100%;font-size:.8125rem;padding:.375rem .5rem" onchange="setGameStatus(<?= $g['id'] ?>, this.value)">
            <?php foreach ($statusMeta as $k => $m): ?>
              <option value="<?= $k ?>" <?= $g['status']===$k?'selected':'' ?>><?= $m['label'] ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  </div>
</div>

<div id="gtab-learn" class="gym-tab-panel hidden">
  <div class="grid-cards">
    <?php foreach ([
      ['title' => 'Backloggd', 'desc' => 'Track your game library and log completions across platforms', 'icon' => 'fa-layer-group', 'url' => 'https://backloggd.com'],
      ['title' => 'HowLongToBeat', 'desc' => 'Playtime estimates so you can plan what fits your schedule', 'icon' => 'fa-clock', 'url' => 'https://howlongtobeat.com'],
      ['title' => 'The 30-day wishlist rule', 'desc' => 'See a game you want? Wishlist it, wait 30 days — buy only if you can name when you\'ll play it', 'icon' => 'fa-heart'],
      ['title' => 'One main game at a time', 'desc' => 'Play one title to completion or until you drop it before starting the next — keeps the backlog from becoming overwhelming', 'icon' => 'fa-list-ol'],
    ] as $r): ?>
      <?php if (!empty($r['url'])): ?>
        <a href="<?= h($r['url']) ?>" target="_blank" rel="noopener" class="habit-card" style="text-decoration:none;opacity:.9">
      <?php else: ?>
        <div class="habit-card" style="opacity:.9">
      <?php endif; ?>
        <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.5rem">
          <i class="fas <?= $r['icon'] ?>" style="color:var(--accent);font-size:1.125rem"></i>
          <div style="font-weight:600;font-size:.9375rem;color:var(--text)"><?= h($r['title']) ?></div>
        </div>
        <p style="font-size:.8125rem;color:var(--muted);margin:0"><?= h($r['desc']) ?></p>
      <?= !empty($r['url']) ? '</a>' : '</div>' ?>
    <?php endforeach; ?>
  </div>
</div>

<!-- Add game modal -->
<div id="addGameModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header"><span class="modal-title">Add Game</span><button class="btn btn-icon btn-ghost btn-sm" data-close-modal="addGameModal" aria-label="Close dialog">&times;</button></div>
    <div class="modal-body">
      <div class="form-group"><label for="gameTitle" class="form-label">Title <span style="color:var(--accent)">*</span></label><input id="gameTitle" class="form-input" placeholder="e.g. Elden Ring"></div>
      <div class="form-group"><label for="gamePlatform" class="form-label">Platform</label><input id="gamePlatform" class="form-input" placeholder="e.g. PC, PS5"></div>
      <div class="form-group">
        <label for="gameStatus" class="form-label">Shelf</label>
        <select id="gameStatus" class="form-input">
          <?php foreach ($statusMeta as $k => $m): ?><option value="<?= $k ?>"><?= $m['label'] ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary btn-sm" data-close-modal="addGameModal">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="saveGame()"><i class="fas fa-save"></i> Add</button>
    </div>
  </div>
</div>

</div>
<?php include '../includes/footer.php'; ?>

<script>
const API_BASE = '<?= APP_BASE ?>/api';

function switchGamingTab(tab) {
  document.querySelectorAll('#gamingTabs .filter-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
  document.querySelectorAll('#gtab-library, #gtab-learn').forEach(p => p.classList.toggle('hidden', p.id !== `gtab-${tab}`));
}
document.getElementById('gamingTabs').addEventListener('click', e => {
  const btn = e.target.closest('[data-tab]');
  if (btn) switchGamingTab(btn.dataset.tab);
});

function openAddGame() {
  document.getElementById('gameTitle').value = '';
  document.getElementById('gamePlatform').value = '';
  document.getElementById('gameStatus').value = 'backlog';
  Trackie.openModal('addGameModal');
}
async function saveGame() {
  const title = document.getElementById('gameTitle').value.trim();
  if (!title) { Trackie.Toast.warning('Title is required.'); return; }
  try {
    const res = await Trackie.API.post(`${API_BASE}/gaming.php`, {
      action: 'add', title,
      platform: document.getElementById('gamePlatform').value.trim(),
      status: document.getElementById('gameStatus').value,
    });
    if (res.success) { Trackie.Toast.success('Game added!'); Trackie.closeModal('addGameModal'); await Trackie.refreshFragments(['gamingStatsWrap', 'gamingListWrap']); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function setGameStatus(id, status) {
  try {
    const res = await Trackie.API.post(`${API_BASE}/gaming.php`, {action:'update_status', item_id:id, status});
    if (res.success) { Trackie.Toast.success('Shelf updated.'); await Trackie.refreshFragments(['gamingStatsWrap', 'gamingListWrap']); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function rateGame(id, rating) {
  document.querySelectorAll(`#gstars-${id} i`).forEach((s, i) => s.className = (i < rating ? 'fas' : 'far') + ' fa-star');
  try {
    const res = await Trackie.API.post(`${API_BASE}/gaming.php`, {action:'rate', item_id:id, rating});
    if (!res.success) Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function deleteGame(id) {
  const ok = await Trackie.confirmDialog('Delete this game?', {confirmText:'Delete', danger:true});
  if (!ok) return;
  try {
    const res = await Trackie.API.post(`${API_BASE}/gaming.php`, {action:'delete', item_id:id});
    if (res.success) { document.getElementById(`game-${id}`)?.remove(); Trackie.Toast.success('Deleted.'); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}

/* ── Steam ────────────────────────────────────────────────────── */
async function connectSteam() {
  const steamId = document.getElementById('steamIdInput').value.trim();
  if (!steamId) { Trackie.Toast.warning('Enter your SteamID64 or profile URL.'); return; }
  const btn = document.getElementById('steamConnectBtn');
  btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Connecting…';
  try {
    const res = await Trackie.API.post(`${API_BASE}/gaming.php`, {action:'steam_connect', steam_id: steamId});
    if (res.success) {
      if (res.private) {
        Trackie.Toast.warning(`Connected${res.persona ? ' as ' + res.persona : ''} — this profile's game list is private, so nothing could sync. Set it to public in Steam privacy settings, then hit Sync again.`, 8000);
      } else {
        Trackie.Toast.success(`Connected${res.persona ? ' as ' + res.persona : ''} — synced ${res.synced} game${res.synced===1?'':'s'}.`);
      }
      await Trackie.refreshFragments(['steamCardWrap', 'gamingStatsWrap', 'gamingListWrap']);
    } else {
      Trackie.Toast.error(res.error || 'Could not connect.');
    }
  } catch { Trackie.Toast.error('Network error.'); }
  finally { btn.disabled = false; btn.innerHTML = '<i class="fas fa-link"></i> Connect'; }
}
async function syncSteam() {
  const btn = document.getElementById('steamSyncBtn');
  btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Syncing…';
  try {
    const res = await Trackie.API.post(`${API_BASE}/gaming.php`, {action:'steam_sync'});
    if (res.success) {
      if (res.private) {
        Trackie.Toast.warning("This profile's game list is private, so nothing could sync. Set it to public in Steam privacy settings, then try again.", 8000);
      } else {
        Trackie.Toast.success(`Synced ${res.synced} game${res.synced===1?'':'s'} from Steam.`);
      }
      await Trackie.refreshFragments(['steamCardWrap', 'gamingStatsWrap', 'gamingListWrap']);
    } else {
      Trackie.Toast.error(res.error || 'Sync failed.');
      await Trackie.refreshFragments(['steamCardWrap']);
    }
  } catch { Trackie.Toast.error('Network error.'); }
  finally { if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-rotate"></i> Sync now'; } }
}
async function disconnectSteam() {
  const ok = await Trackie.confirmDialog('Disconnect Steam? Your synced games stay in your library, but playtime will stop updating.', {confirmText:'Disconnect', danger:true});
  if (!ok) return;
  try {
    const res = await Trackie.API.post(`${API_BASE}/gaming.php`, {action:'steam_disconnect'});
    if (res.success) { Trackie.Toast.success('Steam disconnected.'); await Trackie.refreshFragments(['steamCardWrap']); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
</script>
