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

// Five sections, organised automatically from Steam playtime + your statuses
// (see GamingService::shelves()). Lists render client-side from one API call.
$gmTabs = [
    'most'      => ['Most Played', '🎮'],
    'playing'   => ['Currently Playing', '🟢'],
    'completed' => ['Completed', '✅'],
    'dropped'   => ['Dropped', '❌'],
    'wrap'      => ['Gaming Wrap', '📊'],
];
$initialTab = isset($gmTabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'most';

$counts = fetchOne(
    "SELECT COALESCE(SUM(hours_played),0) hours, SUM(hours_played > 0) played, SUM(status='completed') completed, SUM(status='dropped') dropped
     FROM games WHERE user_id=?", [$uid]
);

// What a user can set. 'wishlist' rows from before stay valid in the DB but
// show as "Not started"; Steam can't know completed/dropped, so those are yours.
$statusMeta = [
    'playing'   => ['label' => 'Playing'],
    'completed' => ['label' => 'Completed'],
    'dropped'   => ['label' => 'Dropped'],
    'backlog'   => ['label' => 'Not started'],
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
  <div class="stat-card"><div class="stat-val"><?= round((float)$counts['hours'], 0) ?>h</div><div class="stat-label">Total playtime</div></div>
  <div class="stat-card"><div class="stat-val"><?= (int)$counts['played'] ?></div><div class="stat-label">Games played</div></div>
  <div class="stat-card"><div class="stat-val"><?= (int)$counts['completed'] ?></div><div class="stat-label">Completed</div></div>
  <div class="stat-card"><div class="stat-val"><?= (int)$counts['dropped'] ?></div><div class="stat-label">Dropped</div></div>
</div>

<div class="filter-tabs gm-tabs" style="margin-bottom:1.25rem" id="gamingTabs" role="tablist" aria-label="Gaming sections">
  <?php foreach ($gmTabs as $k => [$label, $emoji]): ?>
    <button class="filter-tab<?= $k === $initialTab ? ' active' : '' ?>" data-tab="<?= $k ?>" role="tab" id="gmtab-<?= $k ?>" aria-controls="gtab-<?= $k ?>"
            aria-selected="<?= $k === $initialTab ? 'true' : 'false' ?>"<?= $k === $initialTab ? '' : ' tabindex="-1"' ?>><span aria-hidden="true"><?= $emoji ?></span> <?= $label ?><span class="cd-count" data-count="<?= $k ?>" hidden></span></button>
  <?php endforeach; ?>
</div>

<?php foreach (['most' => 'Ranked by total hours played.', 'playing' => 'Played in the last two weeks or 30 days on Steam, plus games you marked Playing.',
                'completed' => 'Games you marked Completed, newest first.', 'dropped' => 'Games you gave up on, newest first.'] as $k => $hint): ?>
  <div id="gtab-<?= $k ?>" class="gym-tab-panel<?= $k === $initialTab ? '' : ' hidden' ?>" role="tabpanel" aria-labelledby="gmtab-<?= $k ?>">
    <p class="hb-foot gm-hint"><?= h($hint) ?></p>
    <div data-shelf="<?= $k ?>"><div class="hb-loading"><i class="fas fa-spinner fa-spin"></i> Loading your games…</div></div>
  </div>
<?php endforeach; ?>

<div id="gtab-wrap" class="gym-tab-panel<?= $initialTab === 'wrap' ? '' : ' hidden' ?>" role="tabpanel" aria-labelledby="gmtab-wrap">
  <div id="gmWrapped"><div class="hb-loading"><i class="fas fa-spinner fa-spin"></i> Building your Gaming Wrap…</div></div>
</div>

<!-- Add game modal (games Steam doesn't know about: consoles, other launchers) -->
<div id="addGameModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header"><span class="modal-title">Add Game</span><button class="btn btn-icon btn-ghost btn-sm" data-close-modal="addGameModal" aria-label="Close dialog">&times;</button></div>
    <div class="modal-body">
      <p class="hb-foot" style="margin:0 0 .75rem"><?= $steamLink ? 'Steam games appear automatically — add games from consoles or other launchers here.' : 'Connect Steam above to import your library automatically, or add games by hand.' ?></p>
      <div class="form-group"><label for="gameTitle" class="form-label">Title <span style="color:var(--accent)">*</span></label><input id="gameTitle" class="form-input" maxlength="150" placeholder="e.g. Elden Ring"></div>
      <div class="form-group"><label for="gamePlatform" class="form-label">Platform</label><input id="gamePlatform" class="form-input" maxlength="60" placeholder="e.g. PS5, Switch, Epic"></div>
      <div class="form-group">
        <label for="gameStatus" class="form-label">Status</label>
        <select id="gameStatus" class="form-input">
          <?php foreach ($statusMeta as $k => $m): ?><option value="<?= $k ?>"<?= $k === 'playing' ? ' selected' : '' ?>><?= $m['label'] ?></option><?php endforeach; ?>
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
const GM_TABS = <?= json_encode(array_keys($gmTabs)) ?>;
const GM_STATUS = <?= json_encode(array_map(fn($m) => $m['label'], $statusMeta)) ?>;
let gmData = null, gmWrapLoaded = false;

function switchGamingTab(tab) {
  if (!GM_TABS.includes(tab)) tab = 'most';
  document.querySelectorAll('#gamingTabs [data-tab]').forEach(b => {
    const on = b.dataset.tab === tab;
    b.classList.toggle('active', on); b.setAttribute('aria-selected', on ? 'true' : 'false'); b.tabIndex = on ? 0 : -1;
  });
  GM_TABS.forEach(t => document.getElementById(`gtab-${t}`)?.classList.toggle('hidden', t !== tab));
  if (tab === 'wrap' && !gmWrapLoaded) { gmWrapLoaded = true; loadWrapped(); }
  try { history.replaceState(history.state, '', `?tab=${tab}`); } catch (e) {}
}
document.getElementById('gamingTabs').addEventListener('click', e => { const b = e.target.closest('[data-tab]'); if (b) switchGamingTab(b.dataset.tab); });
document.getElementById('gamingTabs').addEventListener('keydown', e => {
  if (!['ArrowLeft', 'ArrowRight'].includes(e.key)) return;
  const cur = GM_TABS.indexOf(document.querySelector('#gamingTabs [aria-selected="true"]')?.dataset.tab);
  const next = GM_TABS[(cur + (e.key === 'ArrowRight' ? 1 : GM_TABS.length - 1)) % GM_TABS.length];
  switchGamingTab(next); document.getElementById(`gmtab-${next}`)?.focus();
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
function gmError(el, msg) { el.innerHTML = `<div class="card card-body hb-error"><i class="fas fa-triangle-exclamation"></i> ${escHtml(msg)}</div>`; }
function gmEmpty(icon, title, text) {
  return `<div class="card"><div class="empty-state"><div class="empty-state-icon"><i class="fas ${icon}"></i></div><div class="empty-state-title">${title}</div><p>${text}</p></div></div>`;
}

/* ── Shelves (Most Played / Currently Playing / Completed / Dropped) ── */
function gmStatusSelect(g) {
  return `<select class="form-input gm-status" data-status-for="${+g.id}" aria-label="Status of ${escHtml(g.title)}">
    ${Object.entries(GM_STATUS).map(([k, l]) => `<option value="${k}"${(g.status === k || (k === 'backlog' && !GM_STATUS[g.status])) ? ' selected' : ''}>${l}</option>`).join('')}</select>`;
}
function gmStars(g) {
  let s = '';
  for (let i = 1; i <= 5; i++) s += `<button type="button" class="gm-star" data-rate="${+g.id}" data-n="${i}" aria-label="Rate ${i} of 5"><i class="${(g.rating || 0) >= i ? 'fas' : 'far'} fa-star"></i></button>`;
  return `<div class="gm-stars">${s}</div>`;
}
function gmCard(g, opts = {}) {
  const facts = [];
  if (g.hours > 0) facts.push(`<span title="Total playtime"><i class="fas fa-hourglass-half"></i> ${g.hours}h</span>`);
  if (g.mins_2weeks > 0) facts.push(`<span title="Played in the last 2 weeks (Steam)"><i class="fas fa-fire"></i> ${gmFmtMins(g.mins_2weeks)} · 2 wk</span>`);
  if (g.last_played) facts.push(`<span title="Last played (Steam)"><i class="far fa-clock"></i> ${gmFmtDate(g.last_played)}</span>`);
  if (g.ach_total > 0) facts.push(`<span title="Steam achievements"><i class="fas fa-trophy"></i> ${+g.ach_done}/${+g.ach_total}</span>`);
  if (opts.date) facts.push(`<span><i class="fas ${opts.dateIcon}"></i> ${escHtml(opts.dateLabel)} ${gmFmtDate(opts.date)}</span>`);
  return `<article class="habit-card gm-card" id="game-${+g.id}">
    ${gmCover(g.cover_url, 'gm-card-cover')}
    <div class="gm-card-body">
      <div class="gm-card-top">
        <div style="min-width:0"><div class="gm-rank-title" title="${escHtml(g.title)}">${escHtml(g.title)}</div>
          <div class="gm-rank-sub">${escHtml([g.platform, g.genres].filter(Boolean).join(' · ') || (g.steam_appid ? 'Steam' : ''))}</div></div>
        ${g.steam_appid ? '' : `<button class="btn btn-icon btn-ghost btn-sm" style="color:var(--accent)" data-delete="${+g.id}" aria-label="Delete ${escHtml(g.title)}"><i class="fas fa-trash"></i></button>`}
      </div>
      ${facts.length ? `<div class="gm-meta">${facts.join('')}</div>` : ''}
      ${g.ach_total > 0 ? `<div class="hb-progress" title="Achievements ${Math.round(g.ach_done / g.ach_total * 100)}%"><span style="width:${Math.min(100, g.ach_done / g.ach_total * 100)}%"></span></div>` : ''}
      <div class="gm-card-foot">${gmStars(g)}${gmStatusSelect(g)}</div>
    </div>
  </article>`;
}
function gmRenderShelves() {
  const d = gmData;
  document.querySelectorAll('[data-count]').forEach(el => {
    const n = { most: d.counts.played, playing: d.counts.playing, completed: d.counts.completed, dropped: d.counts.dropped }[el.dataset.count];
    el.hidden = !n; el.textContent = n || '';
  });
  const steam = <?= $steamLink ? 'true' : 'false' ?>;
  const most = document.querySelector('[data-shelf="most"]');
  if (!d.most_played.length) {
    most.innerHTML = gmEmpty('fa-ranking-star', 'No playtime yet', steam ? 'Play something on Steam — it shows up here after the next sync.' : 'Connect Steam to rank your library by real playtime.');
  } else {
    const top = d.most_played[0];
    most.innerHTML = `<div class="gm-rank">${d.most_played.map((g, i) => `
      <div class="gm-rank-row${i === 0 ? ' gm-rank-top' : ''}" id="game-${+g.id}">
        <div class="gm-rank-n">${i + 1}</div>${gmCover(g.cover_url, 'gm-cover-sm')}
        <div class="gm-rank-body"><div class="gm-rank-title">${escHtml(g.title)}</div>
          <div class="gm-rank-sub">${escHtml([g.genres, g.status === 'completed' ? 'Completed' : g.status === 'dropped' ? 'Dropped' : ''].filter(Boolean).join(' · '))}</div>
          <div class="hb-progress"><span style="width:${top.hours ? g.hours / top.hours * 100 : 0}%"></span></div></div>
        <div class="gm-top-val">${g.hours}h<small>${g.share}% of total</small></div>
      </div>`).join('')}</div>
      ${d.counts.played > d.most_played.length ? `<p class="hb-foot">Top ${d.most_played.length} of ${d.counts.played} played games.</p>` : ''}`;
  }
  const grid = (list, empty, opts) => list.length ? `<div class="grid-cards gm-grid">${list.map(g => gmCard(g, opts && opts(g))).join('')}</div>` : empty;
  document.querySelector('[data-shelf="playing"]').innerHTML = grid(d.playing,
    gmEmpty('fa-circle-play', 'Nothing in progress', steam ? 'Games you play on Steam appear here automatically. For other platforms, add a game as Playing.' : 'Add a game you\'re playing, or connect Steam to fill this in automatically.'));
  document.querySelector('[data-shelf="completed"]').innerHTML = grid(d.completed,
    gmEmpty('fa-trophy', 'No completed games yet', 'Finished a game? Set its status to Completed — it counts toward your Gaming Wrap.'),
    g => ({ date: g.completed_at, dateIcon: 'fa-flag-checkered', dateLabel: 'Completed' }));
  document.querySelector('[data-shelf="dropped"]').innerHTML = grid(d.dropped,
    gmEmpty('fa-circle-xmark', 'Nothing dropped', 'Set a game to Dropped when you stop playing it for good. It leaves Currently Playing.'),
    g => ({ date: g.dropped_at, dateIcon: 'fa-circle-xmark', dateLabel: 'Dropped' }));
  const c = d.counts, stats = document.querySelectorAll('#gamingStatsWrap .stat-val');
  if (stats.length === 4) [`${Math.round(c.hours)}h`, c.played, c.completed, c.dropped].forEach((v, i) => { stats[i].textContent = v; });
}
async function loadShelves() {
  try {
    const res = await Trackie.API.post(`${API_BASE}/gaming.php`, { action: 'shelves' }, { quiet: true });
    if (!res.success) { document.querySelectorAll('[data-shelf]').forEach(el => gmError(el, res.error || 'Could not load your games.')); return; }
    gmData = res; gmRenderShelves();
  } catch (e) { document.querySelectorAll('[data-shelf]').forEach(el => gmError(el, e.message || 'Network error.')); }
}
document.getElementById('page-main').addEventListener('change', e => {
  const sel = e.target.closest('[data-status-for]');
  if (sel) setGameStatus(+sel.dataset.statusFor, sel.value);
});
document.getElementById('page-main').addEventListener('click', e => {
  const star = e.target.closest('[data-rate]');
  if (star) return rateGame(+star.dataset.rate, +star.dataset.n);
  const del = e.target.closest('[data-delete]');
  if (del) deleteGame(+del.dataset.delete);
});

/* ── Gaming Wrap (year or month) ───────────────────────────────── */
const GM_MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
async function loadWrapped(year, month) {
  const el = document.getElementById('gmWrapped');
  try {
    const res = await Trackie.API.post(`${API_BASE}/gaming.php`, { action: 'wrapped', year: year || '', month: month || 0 }, { quiet: true });
    if (!res.success) return gmError(el, res.error || 'Could not build your Gaming Wrap.');
    const w = res.wrapped, lib = w.library, t = w.tracked, p = w.personality;
    if (!lib.owned) { el.innerHTML = gmEmpty('fa-chart-pie', 'No games yet', 'Add games or connect Steam to see your Gaming Wrap.'); return; }
    const period = w.month ? `${GM_MONTHS[w.month - 1]} ${+w.year}` : `${+w.year}`;
    const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    const bars = (vals, labels) => {
      const max = Math.max(1, ...vals);
      return `<div class="hb-bars">${vals.map((v, i) => `<div class="hb-bar" title="${labels[i]}: ${gmFmtMins(v)}">
        <span style="height:${Math.round(v / max * 100)}%"></span><em>${labels[i]}</em></div>`).join('')}</div>`;
    };
    const row = (label, val) => `<div class="hb-row"><span>${escHtml(label)}</span><b>${val}</b></div>`;
    const card = (label, inner) => `<div class="card card-body"><div class="fit-card-label">${label}</div>${inner}</div>`;

    let html = `<div class="gm-wrapped-head">
      <h2 class="hb-h2"><i class="fas fa-chart-pie" style="color:var(--accent)"></i> Gaming Wrap · ${period}</h2>
      <div class="gm-period">
        <select class="form-input gm-year" id="gmYear" aria-label="Year">${res.years.map(y => `<option value="${+y}"${y === w.year ? ' selected' : ''}>${+y}</option>`).join('')}</select>
        <select class="form-input gm-year" id="gmMonth" aria-label="Month"><option value="0">Whole year</option>${GM_MONTHS.map((m, i) =>
          `<option value="${i + 1}"${w.month === i + 1 ? ' selected' : ''}>${m}</option>`).join('')}</select>
      </div></div>`;

    if (!w.month) html += `<div class="gm-persona card card-body">
      <div class="gm-persona-icon"><i class="fas ${escHtml(p.primary.icon)}"></i></div>
      <div><div class="gm-persona-kicker">Your gaming personality</div><div class="gm-persona-name">${escHtml(p.primary.name)}</div>
      <ul class="gm-reasons">${p.traits.map(tr => `<li><strong>${escHtml(tr.name)}:</strong> ${escHtml(tr.why)}</li>`).join('')}</ul></div></div>`;

    html += `<div class="grid-stats" style="margin:1rem 0">
      <div class="stat-card"><div class="stat-val">${w.played_in_period}</div><div class="stat-label">Games played in ${period}</div></div>
      <div class="stat-card"><div class="stat-val">${t.since ? gmFmtMins(t.minutes) : '—'}</div><div class="stat-label">${t.since ? 'Playtime in ' + period : 'Playtime (not tracked yet)'}</div></div>
      <div class="stat-card"><div class="stat-val">${w.completed.length}</div><div class="stat-label">Completed</div></div>
      <div class="stat-card"><div class="stat-val">${w.dropped.length}</div><div class="stat-label">Dropped</div></div>
    </div>`;

    // Most played: in the period when Trackie tracked it, else all time (labelled).
    const periodTop = t.top.length ? t.top : null;
    html += '<div class="hb-grid2">';
    html += card(periodTop ? `Most played · ${period}` : 'Most played · all time',
      periodTop ? periodTop.map((g, i) => row(`${i + 1}. ${g.title}`, gmFmtMins(g.minutes))).join('')
        : (w.top.length ? w.top.map((g, i) => `<div class="gm-top-row">${gmCover(g.cover_url, 'gm-cover-sm')}
            <div class="gm-top-body"><div class="gm-rank-title">${i + 1}. ${escHtml(g.title)}</div><div class="hb-progress"><span style="width:${g.share}%"></span></div></div>
            <div class="gm-top-val">${g.hours}h<small>${g.share}%</small></div></div>`).join('') : '<p class="hb-empty-line">No playtime recorded yet.</p>'));
    html += card(`Recently played · ${period}`,
      (w.last_played_in_year.length ? w.last_played_in_year.map(g => row(g.title, gmFmtDate(g.last_played))).join('') : '<p class="hb-empty-line">Nothing last played in this period.</p>')
      + (w.last_played_in_year_count > w.last_played_in_year.length ? `<p class="hb-foot">+${w.last_played_in_year_count - w.last_played_in_year.length} more</p>` : '')
      + '<p class="hb-foot">Steam only records the most recent time you played each game.</p>');
    html += '</div><div class="hb-grid2" style="margin-top:1rem">';
    html += card(`Completed · ${period}`, w.completed.length ? w.completed.map(g => row(g.title, gmFmtDate(g.completed_at))).join('') : '<p class="hb-empty-line">None — set a game to Completed and it counts here.</p>');
    html += card(`Dropped · ${period}`, w.dropped.length ? w.dropped.map(g => row(g.title, gmFmtDate(g.dropped_at))).join('') : '<p class="hb-empty-line">Nothing dropped.</p>');
    html += '</div><div class="hb-grid2" style="margin-top:1rem">';
    html += card('Favourite genres · by playtime, all time', w.genres.length
      ? w.genres.map(g => `<div class="hb-row"><span>${escHtml(g.genre)}</span><div class="hb-progress" style="flex:1"><span style="width:${g.pct}%"></span></div><b>${g.pct}%</b></div>`).join('')
        + `<p class="hb-foot">From Steam Store genres, covering ${w.genre_coverage}% of your hours. A game can have several genres.</p>`
      : '<p class="hb-empty-line">Genres come from the Steam Store and appear after a Steam sync.</p>');
    const a = w.achievements;
    html += card('Library · all time', row('Games owned', lib.owned) + row('Played', lib.played) + row('Never launched', lib.never_played)
      + row('Lifetime playtime', `${lib.hours}h`)
      + (a.total ? row('Achievements unlocked', `${a.unlocked} / ${a.total} (${Math.round(a.unlocked / a.total * 100)}%)`) + row('Perfect games (100%)', a.perfect) : ''));
    html += '</div>';

    html += `<div class="card card-body" style="margin-top:1rem"><div class="fit-card-label">Time patterns · ${period}</div>`;
    if (!t.since) {
      html += `<p class="hb-empty-line">Steam doesn't keep day-by-day history, so Trackie records your playtime every time it syncs. Connect Steam to start.</p>`;
    } else if (!t.minutes) {
      html += `<p class="hb-empty-line">Tracking since ${gmFmtDate(t.since)}. No playtime was recorded in ${period}${t.sync_days ? '' : ' (no syncs in this period)'}.</p>`;
    } else {
      html += `<p class="hb-foot" style="margin-top:0">Tracked by Trackie since ${gmFmtDate(t.since)} · ${t.sync_days} day${t.sync_days === 1 ? '' : 's'} with a sync in ${period}.</p>
        <div class="grid-stats" style="margin:.75rem 0">
          <div class="stat-card"><div class="stat-val">${t.days_played}</div><div class="stat-label">Days with play</div></div>
          <div class="stat-card"><div class="stat-val">${t.best_day ? gmFmtMins(t.best_day.minutes) : '—'}</div><div class="stat-label">${t.best_day ? 'Biggest day · ' + gmFmtDate(t.best_day.date) : 'Biggest day'}</div></div>
          <div class="stat-card"><div class="stat-val">${t.streak}</div><div class="stat-label">Longest play streak (days)</div></div>
        </div>
        <div class="hb-grid2">
          <div><div class="hb-sub">By weekday</div>${bars(t.weekday, days)}</div>
          <div><div class="hb-sub">${w.month ? 'By day' : 'By month'}</div>${w.month
            ? bars(Object.values(t.days), Object.keys(t.days).map(d => +d % 5 === 1 ? d : ''))
            : bars(Object.values(t.months), GM_MONTHS.map(m => m.slice(0, 3)))}</div>
        </div>
        ${t.gap_minutes ? `<p class="hb-foot">${gmFmtMins(t.gap_minutes)} was recorded across multi-day gaps between syncs — counted in totals, not in the weekday chart.</p>` : ''}`;
    }
    html += '</div>';

    el.innerHTML = html;
    const reload = () => loadWrapped(document.getElementById('gmYear').value, document.getElementById('gmMonth').value);
    document.getElementById('gmYear').addEventListener('change', reload);
    document.getElementById('gmMonth').addEventListener('change', reload);
  } catch (e) { gmError(el, e.message || 'Network error.'); }
}

/* ── Actions ───────────────────────────────────────────────────── */
function openAddGame() {
  document.getElementById('gameTitle').value = '';
  document.getElementById('gamePlatform').value = '';
  document.getElementById('gameStatus').value = 'playing';
  Trackie.openModal('addGameModal');
}
async function saveGame() {
  const title = document.getElementById('gameTitle').value.trim();
  if (!title) { Trackie.Toast.warning('Title is required.'); return; }
  try {
    const res = await Trackie.API.post(`${API_BASE}/gaming.php`, { action: 'add', title,
      platform: document.getElementById('gamePlatform').value.trim(), status: document.getElementById('gameStatus').value });
    if (res.success) { Trackie.Toast.success('Game added!'); Trackie.closeModal('addGameModal'); gmChanged(); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch (e) { Trackie.Toast.error(e.message || 'Network error.'); }
}
async function setGameStatus(id, status) {
  try {
    const res = await Trackie.API.post(`${API_BASE}/gaming.php`, { action: 'update_status', item_id: id, status });
    if (res.success) {
      if (res.xp?.leveledUp) Trackie.Toast.success(`⚡ Level up! Level ${res.xp.level} — ${res.xp.title}`, 5000);
      else if (res.xp?.ok) Trackie.Toast.success(`🏆 Completed! +${res.xp.gained} XP`);
      else Trackie.Toast.success(`Moved to ${GM_STATUS[status]}.`);
      gmChanged();
    } else Trackie.Toast.error(res.error || 'Failed.');
  } catch (e) { Trackie.Toast.error(e.message || 'Network error.'); }
}
async function rateGame(id, rating) {
  document.querySelectorAll(`[data-rate="${id}"] i`).forEach((s, i) => { s.className = (i < rating ? 'fas' : 'far') + ' fa-star'; });
  try {
    const res = await Trackie.API.post(`${API_BASE}/gaming.php`, { action: 'rate', item_id: id, rating });
    if (!res.success) Trackie.Toast.error(res.error || 'Failed.');
    else if (gmData) ['playing', 'completed', 'dropped', 'most_played'].forEach(k => gmData[k].forEach(g => { if (g.id === id) g.rating = rating; }));
  } catch (e) { Trackie.Toast.error(e.message || 'Network error.'); }
}
async function deleteGame(id) {
  if (!await Trackie.confirmDialog('Delete this game?', { confirmText: 'Delete', danger: true })) return;
  try {
    const res = await Trackie.API.post(`${API_BASE}/gaming.php`, { action: 'delete', item_id: id });
    if (res.success) { Trackie.Toast.success('Deleted.'); gmChanged(); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch (e) { Trackie.Toast.error(e.message || 'Network error.'); }
}
/** Library changed: re-read the shelves; the Wrap rebuilds next time it's opened. */
function gmChanged() {
  loadShelves();
  gmWrapLoaded = false;
  if (document.querySelector('#gamingTabs [aria-selected="true"]')?.dataset.tab === 'wrap') { gmWrapLoaded = true; loadWrapped(document.getElementById('gmYear')?.value, document.getElementById('gmMonth')?.value); }
}

/* ── Steam ────────────────────────────────────────────────────── */
async function connectSteam() {
  const steamId = document.getElementById('steamIdInput').value.trim();
  if (!steamId) { Trackie.Toast.warning('Enter your SteamID64 or profile URL.'); return; }
  const btn = document.getElementById('steamConnectBtn');
  btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Connecting…';
  try {
    const res = await Trackie.API.post(`${API_BASE}/gaming.php`, { action: 'steam_connect', steam_id: steamId });
    if (res.success) {
      if (res.private) Trackie.Toast.warning(`Connected${res.persona ? ' as ' + res.persona : ''} — this profile's game list is private, so nothing could sync. Set "Game details" to public in Steam privacy settings, then sync again.`, 8000);
      else { Trackie.Toast.success(`Connected${res.persona ? ' as ' + res.persona : ''} — synced ${res.synced} game${res.synced === 1 ? '' : 's'}.`); refreshSteamAchievements(); }
      await Trackie.refreshFragments(['steamCardWrap']);
      gmChanged();
    } else Trackie.Toast.error(res.error || 'Could not connect.');
  } catch (e) { Trackie.Toast.error(e.message || 'Network error.'); }
  finally { btn.disabled = false; btn.innerHTML = '<i class="fas fa-link"></i> Connect'; }
}
/* quiet = the automatic sync on page open: no toasts unless something is wrong. */
async function syncSteam(quiet = false) {
  const btn = document.getElementById('steamSyncBtn');
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Syncing…'; }
  try {
    const res = await Trackie.API.post(`${API_BASE}/gaming.php`, { action: 'steam_sync' }, { quiet });
    if (res.success) {
      if (res.private) { if (!quiet) Trackie.Toast.warning("This profile's game list is private, so nothing could sync. Set it to public in Steam privacy settings, then try again.", 8000); }
      else { if (!quiet) Trackie.Toast.success(`Synced ${res.synced} game${res.synced === 1 ? '' : 's'} from Steam.`); refreshSteamAchievements(); }
      gmChanged();
    } else if (!quiet) Trackie.Toast.error(res.error || 'Sync failed.');
    await Trackie.refreshFragments(['steamCardWrap']);
  } catch (e) { if (!quiet) Trackie.Toast.error(e.message || 'Network error.'); }
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
      const res = await Trackie.API.post(`${API_BASE}/gaming.php`, { action: 'steam_achievements' }, { quiet: true });
      if (!res.success) break;
      if (res.updated) changed = true;
      if (!res.remaining) break;
    } catch { break; }
  }
  if (changed) gmChanged();
}
async function disconnectSteam() {
  if (!await Trackie.confirmDialog('Disconnect Steam? Your synced games stay, but playtime stops updating.', { confirmText: 'Disconnect', danger: true })) return;
  try {
    const res = await Trackie.API.post(`${API_BASE}/gaming.php`, { action: 'steam_disconnect' });
    if (res.success) { Trackie.Toast.success('Steam disconnected.'); await Trackie.refreshFragments(['steamCardWrap']); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch (e) { Trackie.Toast.error(e.message || 'Network error.'); }
}

loadShelves();
if (<?= json_encode($initialTab) ?> === 'wrap') { gmWrapLoaded = true; loadWrapped(); }
// Steam has no playtime history, so Trackie snapshots it: sync quietly when
// the last sync is over 6 hours old (at most once per page open).
<?php if ($steamStale): ?>syncSteam(true);<?php endif; ?>
</script>
