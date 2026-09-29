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
$steamStale = $steamLink && $steamLink['last_error'] !== 'Games list is private'
    && (!$steamLink['last_sync'] || strtotime($steamLink['last_sync']) < time() - 6 * 3600);

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

<div class="filter-tabs" style="margin-bottom:1.25rem" id="gamingTabs" role="tablist" aria-label="Gaming sections">
  <button class="filter-tab active" data-tab="library" role="tab" id="gmtab-library" aria-controls="gtab-library" aria-selected="true">Library</button>
  <button class="filter-tab" data-tab="nextup" role="tab" id="gmtab-nextup" aria-controls="gtab-nextup" aria-selected="false" tabindex="-1">Next Up</button>
  <button class="filter-tab" data-tab="wrapped" role="tab" id="gmtab-wrapped" aria-controls="gtab-wrapped" aria-selected="false" tabindex="-1">Wrapped</button>
  <button class="filter-tab" data-tab="coop" role="tab" id="gmtab-coop" aria-controls="gtab-coop" aria-selected="false" tabindex="-1">Co-op</button>
</div>

<div id="gtab-library" class="gym-tab-panel" role="tabpanel" aria-labelledby="gmtab-library">
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
          <?php if ((int)($g['ach_total'] ?? 0) > 0 || !empty($g['last_played'])): ?>
            <div class="gm-meta">
              <?php if ((int)($g['ach_total'] ?? 0) > 0): ?>
                <span title="Steam achievements"><i class="fas fa-trophy"></i> <?= (int)$g['ach_done'] ?>/<?= (int)$g['ach_total'] ?></span>
              <?php endif; ?>
              <?php if (!empty($g['last_played'])): ?>
                <span title="Last played on Steam"><i class="far fa-clock"></i> <?= h(formatDate($g['last_played'])) ?></span>
              <?php endif; ?>
            </div>
          <?php endif; ?>
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

<div id="gtab-nextup" class="gym-tab-panel hidden" role="tabpanel" aria-labelledby="gmtab-nextup">
  <div class="hb-note"><i class="fas fa-circle-info"></i> Ranked from your own signals: recent playtime, achievement progress, your ratings and the genres you play most. Trackie has no game-length data, so it never guesses how long a game takes.</div>
  <div id="gmNextUp"><div class="hb-loading"><i class="fas fa-spinner fa-spin"></i> Ranking your backlog…</div></div>
</div>

<div id="gtab-wrapped" class="gym-tab-panel hidden" role="tabpanel" aria-labelledby="gmtab-wrapped">
  <div id="gmWrapped"><div class="hb-loading"><i class="fas fa-spinner fa-spin"></i> Building your Wrapped…</div></div>
</div>

<div id="gtab-coop" class="gym-tab-panel hidden" role="tabpanel" aria-labelledby="gmtab-coop">
<?php if (!$steamLink): ?>
  <div class="card"><div class="empty-state"><div class="empty-state-icon"><i class="fa-brands fa-steam"></i></div><div class="empty-state-title">Connect Steam to find co-op games</div><p>Co-op compares your Steam library with your Steam friends' public libraries.</p></div></div>
<?php else: ?>
  <div class="hb-note"><i class="fas fa-circle-info"></i> Uses Steam friends only. A friend's games are visible only if their Steam profile and game details are public. Multiplayer, co-op and cross-platform tags come from each game's Steam Store page.</div>
  <div id="gmFriends"><div class="hb-loading"><i class="fas fa-spinner fa-spin"></i> Loading your Steam friends…</div></div>
  <div id="gmCoopResult"></div>
<?php endif; ?>
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

const GM_TABS = ['library', 'nextup', 'wrapped', 'coop'];
const gmLoaded = {};
function switchGamingTab(tab) {
  if (!GM_TABS.includes(tab)) tab = 'library';
  document.querySelectorAll('#gamingTabs [data-tab]').forEach(b => {
    const on = b.dataset.tab === tab;
    b.classList.toggle('active', on);
    b.setAttribute('aria-selected', on ? 'true' : 'false');
    b.tabIndex = on ? 0 : -1;
  });
  GM_TABS.forEach(t => document.getElementById(`gtab-${t}`)?.classList.toggle('hidden', t !== tab));
  if (!gmLoaded[tab]) {
    gmLoaded[tab] = true;
    if (tab === 'nextup')  loadNextUp();
    if (tab === 'wrapped') loadWrapped();
    if (tab === 'coop')    loadCoopFriends();
  }
}
document.getElementById('gamingTabs').addEventListener('click', e => {
  const btn = e.target.closest('[data-tab]');
  if (btn) switchGamingTab(btn.dataset.tab);
});
document.getElementById('gamingTabs').addEventListener('keydown', e => {
  if (!['ArrowLeft', 'ArrowRight'].includes(e.key)) return;
  const cur = GM_TABS.indexOf(document.querySelector('#gamingTabs [aria-selected="true"]')?.dataset.tab);
  const next = GM_TABS[(cur + (e.key === 'ArrowRight' ? 1 : GM_TABS.length - 1)) % GM_TABS.length];
  switchGamingTab(next);
  document.getElementById(`gmtab-${next}`)?.focus();
});

/* ── Shared render helpers ────────────────────────────────────── */
function gmFmtMins(m) {
  m = Math.round(m || 0);
  if (m < 60) return `${m}m`;
  const h = Math.floor(m / 60), r = m % 60;
  return r ? `${h}h ${r}m` : `${h}h`;
}
function gmFmtDate(d) {
  const dt = new Date(d + 'T00:00:00');
  return isNaN(dt) ? escHtml(d) : dt.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}
function gmCover(url, cls = 'gm-cover') {
  // Only Steam CDN covers are rendered as images.
  return /^https:\/\/cdn\.akamai\.steamstatic\.com\//.test(url || '')
    ? `<img class="${cls}" src="${escHtml(url)}" alt="" loading="lazy" onerror="this.style.visibility='hidden'">`
    : `<div class="${cls} gm-cover-empty"><i class="fas fa-gamepad"></i></div>`;
}
function gmAvatar(url) {
  return /^https:\/\/avatars\.(akamai\.)?steamstatic\.com\//.test(url || '')
    ? `<img class="gm-avatar" src="${escHtml(url)}" alt="" loading="lazy">`
    : `<span class="gm-avatar gm-avatar-empty"><i class="fas fa-user"></i></span>`;
}
function gmError(el, msg) {
  el.innerHTML = `<div class="card card-body hb-error"><i class="fas fa-triangle-exclamation"></i> ${escHtml(msg)}</div>`;
}
function gmEmpty(icon, title, text) {
  return `<div class="card"><div class="empty-state"><div class="empty-state-icon"><i class="fas ${icon}"></i></div><div class="empty-state-title">${title}</div><p>${text}</p></div></div>`;
}

/* ── Next Up ──────────────────────────────────────────────────── */
async function loadNextUp() {
  const el = document.getElementById('gmNextUp');
  try {
    const res = await Trackie.API.post(`${API_BASE}/gaming.php`, { action: 'next_up' });
    if (!res.success) return gmError(el, res.error || 'Could not rank your backlog.');
    if (!res.items.length && !res.finished.length) {
      el.innerHTML = gmEmpty('fa-list-ol', 'Nothing to rank yet', 'Next Up ranks games on your Backlog and Playing shelves. Add a game or sync Steam.');
      return;
    }
    let html = '';
    if (res.top_genres.length) {
      html += `<div class="hb-chips"><span class="hb-chips-label">Your genres</span>${res.top_genres.map(g =>
        `<span class="hb-chip">${escHtml(g.genre)} · ${g.pct}%</span>`).join('')}</div>`;
    }
    html += '<div class="gm-rank">';
    res.items.forEach((g, i) => {
      html += `<div class="gm-rank-row${i === 0 ? ' gm-rank-top' : ''}">
        <div class="gm-rank-n">${i + 1}</div>
        ${gmCover(g.cover_url)}
        <div class="gm-rank-body">
          <div class="gm-rank-title">${escHtml(g.title)}</div>
          <div class="gm-rank-sub">${g.hours > 0 ? `${g.hours}h played` : 'Not started'}${g.genres ? ' · ' + escHtml(g.genres) : ''}</div>
          ${g.ach_pct !== null ? `<div class="hb-progress" title="Achievements ${g.ach_pct}%"><span style="width:${Math.max(0, Math.min(100, g.ach_pct))}%"></span></div>` : ''}
          <ul class="gm-reasons">${g.reasons.map(r => `<li>${escHtml(r)}</li>`).join('')}</ul>
        </div>
      </div>`;
    });
    html += '</div>';
    if (res.finished.length) {
      html += `<div class="fit-section-head" style="margin-top:1.5rem"><h2 class="hb-h2">Looks finished</h2></div><div class="gm-rank">`;
      res.finished.forEach(g => {
        html += `<div class="gm-rank-row">${gmCover(g.cover_url)}
          <div class="gm-rank-body"><div class="gm-rank-title">${escHtml(g.title)}</div>
          <div class="gm-rank-sub">${escHtml(g.reason)}</div></div>
          <button class="btn btn-secondary btn-sm" data-complete="${+g.id}"><i class="fas fa-trophy"></i> Mark completed</button></div>`;
      });
      html += '</div>';
    }
    if (res.meta_pending > 0) {
      html += `<p class="hb-foot">Genre info for ${res.meta_pending} game${res.meta_pending === 1 ? ' is' : 's are'} still loading from the Steam Store. Reopen this tab later for a sharper ranking.</p>`;
    }
    el.innerHTML = html;
  } catch { gmError(el, 'Network error.'); }
}
document.getElementById('gmNextUp').addEventListener('click', async e => {
  const btn = e.target.closest('[data-complete]');
  if (!btn) return;
  btn.disabled = true;
  await setGameStatus(+btn.dataset.complete, 'completed');
  loadNextUp();
});

/* ── Wrapped ──────────────────────────────────────────────────── */
async function loadWrapped(year) {
  const el = document.getElementById('gmWrapped');
  try {
    const res = await Trackie.API.post(`${API_BASE}/gaming.php`, { action: 'wrapped', year: year || '' });
    if (!res.success) return gmError(el, res.error || 'Could not build Wrapped.');
    const w = res.wrapped, lib = w.library, t = w.tracked, p = w.personality;
    if (!lib.owned) {
      el.innerHTML = gmEmpty('fa-gift', 'No games yet', 'Add games or connect Steam to see your Wrapped.');
      return;
    }
    const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const bars = (vals, labels) => {
      const max = Math.max(1, ...vals);
      return `<div class="hb-bars">${vals.map((v, i) => `<div class="hb-bar" title="${labels[i]}: ${gmFmtMins(v)}">
        <span style="height:${Math.round(v / max * 100)}%"></span><em>${labels[i]}</em></div>`).join('')}</div>`;
    };
    const row = (label, val) => `<div class="hb-row"><span>${escHtml(label)}</span><b>${val}</b></div>`;

    let html = `<div class="gm-wrapped-head">
      <h2 class="hb-h2"><i class="fas fa-gift" style="color:var(--accent)"></i> Game Wrapped ${+w.year}</h2>
      ${res.years.length > 1 ? `<select class="form-input gm-year" id="gmYear" aria-label="Year">${res.years.map(y =>
        `<option value="${+y}"${y === w.year ? ' selected' : ''}>${+y}</option>`).join('')}</select>` : ''}
    </div>`;

    html += `<div class="gm-persona card card-body">
      <div class="gm-persona-icon"><i class="fas ${escHtml(p.primary.icon)}"></i></div>
      <div><div class="gm-persona-kicker">Your gaming personality</div>
      <div class="gm-persona-name">${escHtml(p.primary.name)}</div>
      <ul class="gm-reasons">${p.traits.map(tr => `<li><strong>${escHtml(tr.name)}:</strong> ${escHtml(tr.why)}</li>`).join('')}</ul></div>
    </div>`;

    html += `<div class="grid-stats" style="margin:1rem 0">
      <div class="stat-card"><div class="stat-val">${lib.hours}h</div><div class="stat-label">Lifetime playtime</div></div>
      <div class="stat-card"><div class="stat-val">${w.last_played_in_year_count}</div><div class="stat-label">Last played in ${+w.year}</div></div>
      <div class="stat-card"><div class="stat-val">${w.completed.length}</div><div class="stat-label">Completed in ${+w.year}</div></div>
      <div class="stat-card"><div class="stat-val">${lib.never_played}</div><div class="stat-label">Never launched (of ${lib.owned})</div></div>
    </div>`;

    html += '<div class="hb-grid2">';
    html += `<div class="card card-body"><div class="fit-card-label">Most played · all time</div>
      ${w.top.length ? w.top.map((g, i) => `<div class="gm-top-row">${gmCover(g.cover_url, 'gm-cover-sm')}
        <div class="gm-top-body"><div class="gm-rank-title">${i + 1}. ${escHtml(g.title)}</div>
        <div class="hb-progress"><span style="width:${g.share}%"></span></div></div>
        <div class="gm-top-val">${g.hours}h<small>${g.share}%</small></div></div>`).join('')
        : '<p class="hb-empty-line">No playtime recorded yet.</p>'}</div>`;
    html += `<div class="card card-body"><div class="fit-card-label">Genres by playtime</div>
      ${w.genres.length ? w.genres.map(g => `<div class="hb-row"><span>${escHtml(g.genre)}</span>
        <div class="hb-progress"><span style="width:${g.pct}%"></span></div><b>${g.pct}%</b></div>`).join('')
        + `<p class="hb-foot">From Steam Store genres, covering ${w.genre_coverage}% of your hours. A game can have several genres, so shares add up to more than 100%.</p>`
        : '<p class="hb-empty-line">Genre data is still loading from the Steam Store.</p>'}</div>`;
    html += '</div>';

    html += `<div class="card card-body" style="margin-top:1rem"><div class="fit-card-label">Time patterns · tracked by Trackie</div>`;
    if (!t.since) {
      html += `<p class="hb-empty-line">Steam doesn't keep a day-by-day history, so Trackie records your playtime each time it syncs with Steam. Connect Steam to start.</p>`;
    } else if (!t.minutes) {
      html += `<p class="hb-empty-line">Tracking since ${gmFmtDate(t.since)}. Steam doesn't keep a day-by-day history, so your busiest days and weekday patterns build up from here as Trackie records your playtime each day you open Gaming.</p>`;
    } else {
      html += `<p class="hb-foot" style="margin-top:0">Since ${gmFmtDate(t.since)} · ${t.sync_days} day${t.sync_days === 1 ? '' : 's'} with a sync in ${+w.year}. A "day" is the playtime recorded since the previous day's sync.</p>
        <div class="grid-stats" style="margin:.75rem 0">
          <div class="stat-card"><div class="stat-val">${gmFmtMins(t.minutes)}</div><div class="stat-label">Played since tracking began</div></div>
          <div class="stat-card"><div class="stat-val">${t.days_played}</div><div class="stat-label">Days with play</div></div>
          <div class="stat-card"><div class="stat-val">${t.best_day ? gmFmtMins(t.best_day.minutes) : '—'}</div><div class="stat-label">${t.best_day ? 'Biggest day · ' + gmFmtDate(t.best_day.date) : 'Biggest day'}</div></div>
          <div class="stat-card"><div class="stat-val">${t.streak}</div><div class="stat-label">Longest play streak (days)</div></div>
        </div>
        <div class="hb-grid2">
          <div><div class="hb-sub">By weekday</div>${bars(t.weekday, days)}</div>
          <div><div class="hb-sub">By month</div>${bars(Object.values(t.months), months)}</div>
        </div>
        ${t.gap_minutes ? `<p class="hb-foot">${gmFmtMins(t.gap_minutes)} was recorded across multi-day gaps between syncs. It counts in totals but not in the weekday chart.</p>` : ''}
        ${t.top.length ? `<div class="hb-sub" style="margin-top:.75rem">Most played since tracking began</div>${t.top.map(g => row(g.title, gmFmtMins(g.minutes))).join('')}` : ''}`;
    }
    html += '</div>';

    html += '<div class="hb-grid2" style="margin-top:1rem">';
    html += `<div class="card card-body"><div class="fit-card-label">Last played in ${+w.year}</div>
      ${w.last_played_in_year.length ? w.last_played_in_year.map(g => row(g.title, gmFmtDate(g.last_played))).join('') : '<p class="hb-empty-line">None.</p>'}
      ${w.last_played_in_year_count > w.last_played_in_year.length ? `<p class="hb-foot">+${w.last_played_in_year_count - w.last_played_in_year.length} more</p>` : ''}
      <p class="hb-foot">Steam only records the most recent time you played each game.</p></div>`;
    html += `<div class="card card-body"><div class="fit-card-label">Completed in ${+w.year}</div>
      ${w.completed.length ? w.completed.map(g => row(g.title, gmFmtDate(g.completed_at))).join('')
        : '<p class="hb-empty-line">Move a game to the Completed shelf and it shows up here.</p>'}</div>`;
    html += '</div>';

    el.innerHTML = html;
    document.getElementById('gmYear')?.addEventListener('change', e => loadWrapped(e.target.value));
  } catch { gmError(el, 'Network error.'); }
}

/* ── Co-op ────────────────────────────────────────────────────── */
async function loadCoopFriends() {
  const el = document.getElementById('gmFriends');
  if (!el) return;
  try {
    const res = await Trackie.API.post(`${API_BASE}/gaming.php`, { action: 'coop_friends' });
    if (!res.success) return gmError(el, res.error || 'Could not load friends.');
    if (res.private) return gmError(el, "Your Steam friends list is private. Set it to public in Steam's privacy settings to use Co-op.");
    if (!res.friends.length) {
      el.innerHTML = `<div class="card card-body hb-empty-line">No Steam friends found on this account.</div>`;
      return;
    }
    el.innerHTML = `<div class="card card-body">
      <div class="fit-card-label">Pick up to 5 friends</div>
      <div class="gm-friends">${res.friends.map(f => `<label class="gm-friend${f.public ? '' : ' gm-friend-off'}">
        <input type="checkbox" value="${escHtml(f.steamid)}" ${f.public ? '' : 'disabled'}>
        ${gmAvatar(f.avatar)}<span>${escHtml(f.name)}${f.public ? '' : "<small>Private profile, can't compare</small>"}</span></label>`).join('')}</div>
      <button class="btn btn-primary btn-sm" id="gmCoopBtn" style="margin-top:.75rem"><i class="fas fa-users"></i> Find shared games</button>
    </div>`;
    document.getElementById('gmCoopBtn').addEventListener('click', runCoopMatch);
  } catch { gmError(el, 'Network error.'); }
}
let gmCoopFilter = 'multi';
let gmCoopData = null;
async function runCoopMatch() {
  const ids = [...document.querySelectorAll('#gmFriends input:checked')].map(i => i.value);
  const out = document.getElementById('gmCoopResult');
  if (!ids.length) { Trackie.Toast.warning('Pick at least one friend.'); return; }
  if (ids.length > 5) { Trackie.Toast.warning('Compare up to 5 friends at a time.'); return; }
  const btn = document.getElementById('gmCoopBtn');
  btn.disabled = true;
  out.innerHTML = `<div class="hb-loading"><i class="fas fa-spinner fa-spin"></i> Comparing libraries…</div>`;
  try {
    const res = await Trackie.API.post(`${API_BASE}/gaming.php`, { action: 'coop_match', friends: ids.join(',') });
    if (!res.success) return gmError(out, res.error || 'Could not compare libraries.');
    gmCoopData = res;
    renderCoop();
  } catch { gmError(out, 'Network error.'); }
  finally { btn.disabled = false; }
}
function renderCoop() {
  const res = gmCoopData, out = document.getElementById('gmCoopResult');
  let html = '';
  if (res.unavailable.length) {
    html += `<div class="hb-note">${res.unavailable.map(u => `<div><i class="fas fa-lock"></i> ${escHtml(u.name)}: ${escHtml(u.reason)}</div>`).join('')}</div>`;
  }
  if (!res.members.length) { out.innerHTML = html; return; }
  const filters = { multi: 'Multiplayer', coop: 'Co-op', online: 'Online co-op', cross: 'Cross-platform', all: 'All shared' };
  const test = { multi: g => g.multiplayer, coop: g => g.coop, online: g => g.online_coop, cross: g => g.crossplay, all: () => true };
  const games = res.games.filter(test[gmCoopFilter]);
  html += `<div class="fit-section-head" style="margin-top:1.25rem"><h2 class="hb-h2">You + ${res.members.map(m => escHtml(m.name)).join(', ')}</h2>
    <span class="hb-foot" style="margin:0">${res.games.length} shared game${res.games.length === 1 ? '' : 's'}</span></div>
    <div class="filter-tabs" style="margin-bottom:1rem">${Object.entries(filters).map(([k, v]) =>
      `<button class="filter-tab${k === gmCoopFilter ? ' active' : ''}" data-coopf="${k}">${v}</button>`).join('')}</div>`;
  if (!games.length) {
    html += `<div class="card card-body hb-empty-line">${res.games.length
      ? `No shared games match "${filters[gmCoopFilter]}". Try "All shared".`
      : "You don't own any of the same games on Steam."}</div>`;
  } else {
    html += '<div class="gm-coop-grid">' + games.map(g => {
      const tags = [g.online_coop && 'Online co-op', g.coop && !g.online_coop && 'Co-op', g.multiplayer && !g.coop && 'Multiplayer',
        g.crossplay && 'Cross-platform', !g.known && 'Tags loading'].filter(Boolean);
      return `<div class="card gm-coop-card">${gmCover(g.cover_url, 'gm-coop-cover')}
        <div class="card-body" style="padding:.75rem">
          <div class="gm-rank-title">${escHtml(g.title)}</div>
          ${g.genres ? `<div class="gm-rank-sub">${escHtml(g.genres)}</div>` : ''}
          <div class="gm-tags">${tags.map(t => `<span class="gm-tag">${escHtml(t)}</span>`).join('')}</div>
          <div class="gm-hours">${g.hours.map(h => `<span>${escHtml(h.name)} <b>${+h.hours}h</b></span>`).join('')}</div>
        </div></div>`;
    }).join('') + '</div>';
  }
  if (res.meta_pending > 0) {
    html += `<p class="hb-foot">Store tags for ${res.meta_pending} shared game${res.meta_pending === 1 ? ' are' : 's are'} still loading. Run the comparison again in a minute.</p>`;
  }
  out.innerHTML = html;
}
document.getElementById('gmCoopResult')?.addEventListener('click', e => {
  const b = e.target.closest('[data-coopf]');
  if (!b) return;
  gmCoopFilter = b.dataset.coopf;
  renderCoop();
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
    if (res.success) {
      if (res.xp?.leveledUp) Trackie.Toast.success(`⚡ Level up! Level ${res.xp.level} — ${res.xp.title}`, 5000);
      else if (res.xp?.ok) Trackie.Toast.success(`🏆 Completed! +${res.xp.gained} XP`);
      else Trackie.Toast.success('Shelf updated.');
      gmInvalidate();
      await Trackie.refreshFragments(['gamingStatsWrap', 'gamingListWrap']);
    }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
/** Shelf/playtime changed: Next Up + Wrapped re-fetch next time they're opened. */
function gmInvalidate() {
  delete gmLoaded.nextup; delete gmLoaded.wrapped;
  const open = document.querySelector('#gamingTabs [aria-selected="true"]')?.dataset.tab;
  if (open === 'nextup' || open === 'wrapped') { gmLoaded[open] = true; open === 'nextup' ? loadNextUp() : loadWrapped(); }
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
        refreshSteamAchievements();
      }
      await Trackie.refreshFragments(['steamCardWrap', 'gamingStatsWrap', 'gamingListWrap']);
    } else {
      Trackie.Toast.error(res.error || 'Could not connect.');
    }
  } catch { Trackie.Toast.error('Network error.'); }
  finally { btn.disabled = false; btn.innerHTML = '<i class="fas fa-link"></i> Connect'; }
}
/* quiet = the automatic sync on page open: no toasts unless something is wrong. */
async function syncSteam(quiet = false) {
  const btn = document.getElementById('steamSyncBtn');
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Syncing…'; }
  try {
    const res = await Trackie.API.post(`${API_BASE}/gaming.php`, {action:'steam_sync'});
    if (res.success) {
      if (res.private) {
        if (!quiet) Trackie.Toast.warning("This profile's game list is private, so nothing could sync. Set it to public in Steam privacy settings, then try again.", 8000);
      } else {
        if (!quiet) Trackie.Toast.success(`Synced ${res.synced} game${res.synced===1?'':'s'} from Steam.`);
        refreshSteamAchievements();
      }
      gmInvalidate();
      await Trackie.refreshFragments(['steamCardWrap', 'gamingStatsWrap', 'gamingListWrap']);
    } else {
      if (!quiet) Trackie.Toast.error(res.error || 'Sync failed.');
      await Trackie.refreshFragments(['steamCardWrap']);
    }
  } catch { if (!quiet) Trackie.Toast.error('Network error.'); }
  finally {
    const b = document.getElementById('steamSyncBtn');
    if (b) { b.disabled = false; b.innerHTML = '<i class="fas fa-rotate"></i> Sync now'; }
  }
}
/** Achievement counts: a few games per request, a few rounds, in the background. */
async function refreshSteamAchievements(rounds = 3) {
  let changed = false;
  for (let i = 0; i < rounds; i++) {
    try {
      const res = await Trackie.API.post(`${API_BASE}/gaming.php`, {action:'steam_achievements'});
      if (!res.success) break;
      if (res.updated) changed = true;
      if (!res.remaining) break;
    } catch { break; }
  }
  if (changed) { gmInvalidate(); Trackie.refreshFragments(['gamingListWrap']); }
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

// Steam has no playtime history, so Trackie snapshots it: sync quietly when
// the last sync is over 6 hours old (at most once per page open).
<?php if ($steamStale): ?>syncSteam(true);<?php endif; ?>
</script>
