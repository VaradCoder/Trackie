<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Music';
$currentPage = 'music';

$clientId  = env('SPOTIFY_CLIENT_ID');
require_once '../includes/providers.php';
$sp = provider('spotify');
$connected = $sp?->isConnected($uid) ?? false;
// Connected before Liked Songs existed → those scopes are missing until a reconnect.
$needsScopes = $connected && !in_array('user-library-modify', preg_split('/[\s,]+/', (string)($sp->connection($uid)['scopes'] ?? '')), true);

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<div class="page-header page-header-center">
  <h1 class="page-header-title"><i class="fab fa-spotify" style="color:#1db954" aria-hidden="true"></i> Music</h1>
</div>

<?php if (!$clientId): ?>
  <div class="card card-body u-mb-6">
    <p class="u-c-muted u-fs-14 u-m-0">Spotify isn't configured on this server yet.</p>
  </div>
<?php elseif (!$connected): ?>
  <div class="card card-body" style="text-align:center;padding:2.5rem 1.5rem;margin-bottom:1.5rem">
    <i class="fab fa-spotify" style="font-size:2.5rem;color:#1db954;margin-bottom:.75rem"></i>
    <div class="u-fw-6 u-fs-17 u-c-text u-mb-1h">Connect your Spotify account</div>
    <p style="font-size:.875rem;color:var(--muted);max-width:440px;margin:0 auto 1.25rem">
      Control what's playing on any of your devices, see your Liked Songs and recent listening, and pair a playlist with focus sessions.
    </p>
    <a href="<?= APP_BASE ?>/pages/spotify_callback.php" class="btn btn-primary btn-sm" data-no-spa><i class="fab fa-spotify"></i> Connect Spotify</a>
  </div>
<?php else: ?>

  <?php if ($needsScopes): ?>
    <div class="card card-body ms-banner">
      <i class="fas fa-heart"></i>
      <div class="u-flex-1">Reconnect Spotify once to turn on <strong>Liked Songs</strong> and the ❤ save button.</div>
      <a class="btn btn-primary btn-sm" data-no-spa href="<?= APP_BASE ?>/pages/spotify_callback.php">Reconnect</a>
    </div>
  <?php endif; ?>

  <!-- ═══ Now playing: remote control for whatever device Spotify is playing on ═══ -->
  <section class="card ms-now" id="msNow" aria-label="Now playing">
    <div class="ms-now-idle" id="msIdle"><span role="status"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Checking what's playing…</span></div>
    <div class="ms-now-live hidden" id="msLive">
      <img class="ms-art" id="msArt" src="" alt="">
      <div class="ms-now-main">
        <div class="ms-now-top">
          <div class="u-min-w-0">
            <div class="ms-kicker" id="msKicker">Now playing</div>
            <a class="ms-title" id="msTitle" href="#" target="_blank" rel="noopener">—</a>
            <div class="ms-artist" id="msArtist">—</div>
          </div>
          <button class="btn btn-icon btn-ghost ms-heart" id="msHeart" aria-label="Save to Liked Songs" aria-pressed="false" hidden><i class="far fa-heart"></i></button>
        </div>
        <div class="ms-progress">
          <span id="msElapsed">0:00</span>
          <input type="range" id="msSeek" min="0" max="1000" value="0" aria-label="Seek">
          <span id="msDuration">0:00</span>
        </div>
        <div class="ms-controls">
          <button class="btn btn-icon btn-ghost" data-cmd="shuffle" id="msShuffle" aria-label="Shuffle" aria-pressed="false"><i class="fas fa-shuffle"></i></button>
          <button class="btn btn-icon btn-ghost" data-cmd="previous" aria-label="Previous"><i class="fas fa-backward-step"></i></button>
          <button class="btn btn-icon btn-primary ms-play" data-cmd="toggle" id="msToggle" aria-label="Play"><i class="fas fa-play"></i></button>
          <button class="btn btn-icon btn-ghost" data-cmd="next" aria-label="Next"><i class="fas fa-forward-step"></i></button>
          <button class="btn btn-icon btn-ghost" data-cmd="repeat" id="msRepeat" aria-label="Repeat: off"><i class="fas fa-repeat"></i><span class="ms-repeat-one" hidden>1</span></button>
        </div>
        <div class="ms-bottom">
          <button class="btn btn-ghost btn-sm ms-device" id="msDeviceBtn" aria-haspopup="true"><i class="fas fa-mobile-screen"></i> <span id="msDevice">—</span> <i class="fas fa-chevron-down u-fs-10"></i></button>
          <label class="ms-volume" id="msVolumeWrap"><i class="fas fa-volume-low"></i><input type="range" id="msVolume" min="0" max="100" value="50" aria-label="Volume"></label>
          <button class="btn btn-ghost btn-sm" id="msQueueBtn" aria-expanded="false"><i class="fas fa-list-ol"></i> Queue</button>
        </div>
        <div class="ms-devices hidden" id="msDevices" role="menu"></div>
      </div>
    </div>
    <div class="ms-queue hidden" id="msQueue"></div>
  </section>

  <div class="filter-tabs" style="margin:1.25rem 0" id="musicTabs" role="tablist">
    <button class="filter-tab active" data-tab="favorites" role="tab" aria-selected="true">❤️ Favorites</button>
    <button class="filter-tab" data-tab="recent" role="tab" aria-selected="false" tabindex="-1">🕘 Recently Played</button>
    <button class="filter-tab" data-tab="top" role="tab" aria-selected="false" tabindex="-1">⭐ Top</button>
    <button class="filter-tab" data-tab="playlists" role="tab" aria-selected="false" tabindex="-1">📁 Playlists</button>
  </div>

  <div id="mtab-favorites" class="gym-tab-panel">
    <div class="ms-head"><h2 class="hb-h2">Liked Songs</h2><span class="hb-foot u-m-0" id="msLikedCount"></span></div>
    <div class="card" id="msLiked" aria-busy="true"><?= renderLoading('Loading your Liked Songs…') ?></div>
    <button class="btn btn-secondary btn-sm hidden u-mt-3" id="msLikedMore">Load more</button>
  </div>
  <div id="mtab-recent" class="gym-tab-panel hidden">
    <div class="card" id="msRecent" aria-busy="true"><?= renderLoading('Loading your recent listening…') ?></div>
    <p class="hb-foot">Spotify shares your last 50 plays.</p>
  </div>
  <div id="mtab-top" class="gym-tab-panel hidden">
    <div class="ms-head"><h2 class="hb-h2">Your top music</h2>
      <select class="form-input gm-year" id="msRange" aria-label="Time range">
        <option value="short_term">Last 4 weeks</option><option value="medium_term">Last 6 months</option><option value="long_term">Last year</option>
      </select></div>
    <div class="fit-card-label" style="margin:.25rem 0 .5rem">Artists</div>
    <div id="msTopArtists" class="ms-artists" aria-busy="true"><?= renderLoading('Loading your top artists…', 2) ?></div>
    <div class="fit-card-label" style="margin:1.25rem 0 .5rem">Tracks</div>
    <div class="card" id="msTopTracks" aria-busy="true"><?= renderLoading('Loading your top tracks…') ?></div>
  </div>
  <div id="mtab-playlists" class="gym-tab-panel hidden">
    <div class="card card-body ms-focus"><i class="fas fa-bullseye"></i>
      <div><b>Focus Session Sync</b><div class="hb-foot u-m-0">Pick a playlist to start automatically when a focus session starts (and pause when it ends).</div>
        <div id="focusSyncStatus" class="u-fs-13 u-mt-1h"></div></div></div>
    <div id="musicPlaylists" class="grid-cards u-mt-4" aria-busy="true"><?= renderLoading('Loading your playlists…') ?></div>
  </div>

<?php endif; ?>

</div>
<?php include '../includes/footer.php'; ?>

<?php if ($connected): ?>
<script>
const API_BASE = '<?= APP_BASE ?>/api';
const FOCUS_PLAYLIST_KEY = 'trackie_focus_playlist_uri';
const SP_CONNECT = '<?= APP_BASE ?>/pages/spotify_callback.php';
const msIsNative = !!(window.Capacitor && window.Capacitor.isNativePlatform && window.Capacitor.isNativePlatform());

function esc(s) { return escHtml(String(s ?? '')); }
function fmtMs(ms) { const s = Math.max(0, Math.floor((ms || 0) / 1000)); return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`; }
const spPost = (fields, opts = {}) => Trackie.API.post(`${API_BASE}/spotify.php`, fields, Object.assign({ quiet: true }, opts));

/** A failed Spotify call, shown in el: reconnect when that's the fix, otherwise "Try again". */
function spFail(el, res, retry) {
  const reconnect = res && (res.needsReconnect || res.connected === false);
  return Trackie.UI.error(el, res, {
    fallback: 'Spotify could not be reached.',
    retry: reconnect ? null : retry,
    action: reconnect ? { label: 'Reconnect Spotify', icon: 'fa-link', href: SP_CONNECT, noSpa: true } : null,
  });
}
/** Requests that throw become {success:false} with a user-safe message. */
const spSafe = p => p.catch(e => ({ success: false, error: Trackie.UI.errorText(e, 'Spotify could not be reached.') }));
function spToast(res, fallback) {
  if (res && (res.reason === 'no_device' || res.reason === 'premium' || res.reason === 'rate_limited')) return Trackie.Toast.warning(res.error, 6000);
  Trackie.UI.toastError(res, fallback);
}
const spEmpty = (icon, title, text) => Trackie.UI.emptyHTML({ icon, title, text, compact: true });

/* ── Track rows (shared by Favorites / Recent / Top / Queue) ───────── */
function trackRow(t, extra = '') {
  const heart = t.liked === null || t.liked === undefined ? ''
    : `<button class="btn btn-icon btn-ghost btn-sm ms-heart-sm${t.liked ? ' on' : ''}" data-like="${esc(t.id)}" aria-pressed="${t.liked}" aria-label="${t.liked ? 'Remove from' : 'Save to'} Liked Songs"><i class="${t.liked ? 'fas' : 'far'} fa-heart"></i></button>`;
  return `<div class="todo-row ms-row" data-track="${esc(t.id)}">
    ${t.thumb || t.art ? `<img src="${esc(t.thumb || t.art)}" alt="" class="ms-thumb" loading="lazy">` : '<div class="ms-thumb"></div>'}
    <div class="u-flex-1 u-min-w-0"><span class="todo-title">${esc(t.name)}</span>${t.explicit ? ' <span class="ms-e" title="Explicit">E</span>' : ''}
      <div class="todo-meta">${esc(t.artist)}${t.album ? ' · ' + esc(t.album) : ''}</div></div>
    ${extra}${heart}
    <button class="btn btn-icon btn-ghost btn-sm" data-play-track="${esc(t.uri)}" aria-label="Play ${esc(t.name)}"><i class="fas fa-play"></i></button>
  </div>`;
}
const timeAgo = iso => { const d = new Date(iso); return isNaN(d) ? '' : d.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }); };

/* ── Now playing ───────────────────────────────────────────────────── */
let msState = null, msTick = null, msPoll = null, msBusy = false, sdkDeviceId = null;
const $ = id => document.getElementById(id);

function renderNow() {
  const s = msState;
  if (!s || !s.active || !s.track) {
    $('msLive').classList.add('hidden'); $('msIdle').classList.remove('hidden');
    $('msIdle').innerHTML = s && s.active && !s.track
      ? `<i class="fas fa-podcast"></i> Spotify is playing a podcast or ad on <b>${esc(s.device?.name)}</b> — Trackie shows songs only.`
      : `<i class="fab fa-spotify" style="color:#1db954"></i> Nothing playing right now.
         <div class="hb-foot">Open Spotify on your phone or computer and press play — Trackie picks it up here${sdkDeviceId ? ', or <button class="btn btn-secondary btn-sm" id="msPlayHere">play in this browser</button>' : ''}.</div>`;
    $('msPlayHere')?.addEventListener('click', () => transferTo(sdkDeviceId, true));
    $('msQueue').classList.add('hidden');
    return;
  }
  $('msIdle').classList.add('hidden'); $('msLive').classList.remove('hidden');
  const t = s.track;
  $('msArt').src = t.art || ''; $('msArt').alt = `Album art: ${t.album}`;
  $('msTitle').textContent = t.name; $('msTitle').href = /^https:\/\/open\.spotify\.com\//.test(t.url) ? t.url : '#';
  $('msArtist').textContent = `${t.artist}${t.album ? ' · ' + t.album : ''}`;
  $('msKicker').textContent = s.playing ? 'Now playing' : 'Paused';
  $('msToggle').innerHTML = `<i class="fas ${s.playing ? 'fa-pause' : 'fa-play'}"></i>`;
  $('msToggle').setAttribute('aria-label', s.playing ? 'Pause' : 'Play');
  $('msShuffle').classList.toggle('on', s.shuffle); $('msShuffle').setAttribute('aria-pressed', s.shuffle);
  $('msRepeat').classList.toggle('on', s.repeat !== 'off'); $('msRepeat').setAttribute('aria-label', `Repeat: ${s.repeat}`);
  $('msRepeat').querySelector('.ms-repeat-one').hidden = s.repeat !== 'track';
  $('msDevice').textContent = s.device?.name || 'Unknown device';
  $('msVolumeWrap').hidden = !s.device?.supports_volume;
  if (s.device?.volume !== null && !$('msVolume').matches(':active')) $('msVolume').value = s.device.volume;
  const heart = $('msHeart');
  heart.hidden = s.liked === null || s.liked === undefined;
  heart.classList.toggle('on', !!s.liked); heart.setAttribute('aria-pressed', !!s.liked);
  heart.innerHTML = `<i class="${s.liked ? 'fas' : 'far'} fa-heart"></i>`;
  heart.dataset.like = t.id;
  ['previous', 'next'].forEach(c => { const b = document.querySelector(`[data-cmd="${c}"]`); b.disabled = (s.disallows || []).includes(c === 'previous' ? 'skipping_prev' : 'skipping_next'); });
  renderProgress();
}
function renderProgress() {
  const s = msState; if (!s?.track) return;
  $('msElapsed').textContent = fmtMs(s.progress); $('msDuration').textContent = fmtMs(s.track.duration);
  if (!$('msSeek').matches(':active')) $('msSeek').value = s.track.duration ? Math.round(s.progress / s.track.duration * 1000) : 0;
}
async function loadState() {
  if (msBusy || document.visibilityState !== 'visible') return;
  try {
    const res = await spPost({ action: 'state' });
    if (res.success === false) {
      $('msLive').classList.add('hidden'); $('msIdle').classList.remove('hidden');
      spFail($('msIdle'), res, loadState);
      if (res.reason === 'rate_limited') schedulePoll((res.retry_after || 30) * 1000);
      return;
    }
    const prevTrack = msState?.track?.id;
    msState = res; msState.at = Date.now();
    renderNow();
    if (!$('msQueue').classList.contains('hidden') && prevTrack !== res.track?.id) loadQueue();
  } catch (e) {
    $('msLive').classList.add('hidden'); $('msIdle').classList.remove('hidden');
    spFail($('msIdle'), { success: false, error: Trackie.UI.errorText(e, 'Spotify could not be reached.') }, loadState);
  }
}
function schedulePoll(ms = 5000) {
  clearInterval(msPoll); msPoll = setInterval(loadState, ms);
}
clearInterval(msTick);
msTick = setInterval(() => {   // smooth progress between polls
  if (!msState?.playing || !msState.track) return;
  msState.progress = Math.min(msState.track.duration, msState.progress + 1000);
  renderProgress();
  if (msState.progress >= msState.track.duration) setTimeout(loadState, 800);
}, 1000);
document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') loadState(); });
Trackie.SpaNav?.onLeave?.(() => { clearInterval(msPoll); clearInterval(msTick); });

async function control(cmd, value, extra = {}) {
  msBusy = true;
  try {
    const res = await spPost(Object.assign({ action: 'control', cmd, value: value ?? '' }, extra));
    if (!res.success) { spToast(res, 'Spotify could not do that.'); return false; }
    return true;
  } catch (e) { Trackie.UI.toastError(e, 'Spotify could not do that.'); return false; }
  finally { msBusy = false; setTimeout(loadState, 350); }
}
document.querySelectorAll('[data-cmd]').forEach(b => b.addEventListener('click', () => {
  const c = b.dataset.cmd, s = msState || {};
  if (c === 'toggle') { if (s.playing !== undefined) { s.playing = !s.playing; renderNow(); } return control(s.playing ? 'play' : 'pause'); }
  if (c === 'shuffle') return control('shuffle', s.shuffle ? 'false' : 'true');
  if (c === 'repeat') return control('repeat', { off: 'context', context: 'track', track: 'off' }[s.repeat || 'off']);
  control(c);
}));
$('msSeek').addEventListener('change', e => {
  if (!msState?.track) return;
  msState.progress = Math.round(e.target.value / 1000 * msState.track.duration); renderProgress();
  control('seek', msState.progress);
});
let volTimer = null;
$('msVolume').addEventListener('input', e => { clearTimeout(volTimer); volTimer = setTimeout(() => control('volume', e.target.value), 250); });

/* Devices */
async function transferTo(id, play) {
  if (await control('transfer', '', { device_id: id, play: play ? 1 : '' })) Trackie.Toast.success('Switched device.');
}
$('msDeviceBtn').addEventListener('click', async () => {
  const box = $('msDevices');
  if (!box.classList.contains('hidden')) { box.classList.add('hidden'); return; }
  box.classList.remove('hidden'); box.innerHTML = Trackie.UI.loadingHTML('Finding your devices…', { inline: true });
  const res = await spSafe(spPost({ action: 'devices' }));
  if (!res.success) return spFail(box, res, () => { box.classList.add('hidden'); $('msDeviceBtn').click(); });
  const icon = t => ({ Computer: 'fa-laptop', Smartphone: 'fa-mobile-screen', Speaker: 'fa-volume-high', TV: 'fa-tv' }[t] || 'fa-music');
  box.innerHTML = res.devices.length ? res.devices.map(d => `<button class="ms-dev${d.active ? ' on' : ''}" role="menuitem" data-dev="${esc(d.id)}" ${d.restricted ? 'disabled title="This device can\'t be controlled"' : ''}>
      <i class="fas ${icon(d.type)}"></i> ${esc(d.name)}${d.active ? ' <small>playing</small>' : ''}</button>`).join('')
    : spEmpty('fa-mobile-screen', 'No devices found', 'Open Spotify on your phone or computer first.');
});
$('msDevices').addEventListener('click', e => { const b = e.target.closest('[data-dev]'); if (b) { $('msDevices').classList.add('hidden'); transferTo(b.dataset.dev, msState?.playing); } });

/* Queue */
async function loadQueue() {
  const box = $('msQueue');
  Trackie.UI.loading(box, 'Loading the queue…');
  const res = await spSafe(spPost({ action: 'queue' }));
  if (!res.success) return spFail(box, res, loadQueue);
  Trackie.UI.done(box);
  box.innerHTML = `<div class="fit-card-label" style="padding:0 1rem">Up next</div>` + (res.queue.length
    ? res.queue.map(t => trackRow(t)).join('') : spEmpty('fa-list-ol', 'The queue is empty', 'Songs you add to the queue in Spotify show up here.'));
}
$('msQueueBtn').addEventListener('click', () => {
  const open = $('msQueue').classList.toggle('hidden') === false;
  $('msQueueBtn').setAttribute('aria-expanded', open);
  if (open) loadQueue();
});

/* Play a track / playlist on the active device (or this browser's player) */
async function playUri(fields) {
  const res = await spPost(Object.assign({ action: 'play' }, fields, (!msState?.active && sdkDeviceId) ? { device_id: sdkDeviceId } : {}))
    .catch(e => ({ success: false, error: Trackie.UI.errorText(e, 'Could not start playback.') }));
  if (!res.success) return spToast(res, 'Could not start playback.');
  setTimeout(loadState, 600);
}

/* Liked Songs heart (now playing + every row) */
async function toggleLike(id, btn) {
  const on = btn.getAttribute('aria-pressed') !== 'true';
  const set = v => document.querySelectorAll(`[data-like="${CSS.escape(id)}"]`).forEach(b => {
    b.setAttribute('aria-pressed', v); b.classList.toggle('on', v); b.innerHTML = `<i class="${v ? 'fas' : 'far'} fa-heart"></i>`;
  });
  set(on);
  const res = await spSafe(spPost({ action: on ? 'save' : 'unsave', track_id: id }));
  if (!res.success) { set(!on); return spToast(res, 'Could not update Liked Songs.'); }
  if (msState?.track?.id === id) msState.liked = on;
  Trackie.Toast.success(on ? 'Added to Liked Songs.' : 'Removed from Liked Songs.');
  likedLoaded = false;
}
document.getElementById('page-main').addEventListener('click', e => {
  const like = e.target.closest('[data-like]');
  if (like) return toggleLike(like.dataset.like, like);
  const pt = e.target.closest('[data-play-track]');
  if (pt) return playUri({ track_uri: pt.dataset.playTrack });
  const pl = e.target.closest('[data-play-list]');
  if (pl) return playUri({ playlist_uri: pl.dataset.playList });
});

/* ── Tabs ─────────────────────────────────────────────────────────── */
let likedLoaded = false, likedOffset = 0;
const loaded = {};
function switchMusicTab(tab) {
  document.querySelectorAll('#musicTabs [data-tab]').forEach(b => { const on = b.dataset.tab === tab; b.classList.toggle('active', on); b.setAttribute('aria-selected', on); b.tabIndex = on ? 0 : -1; });
  ['favorites', 'recent', 'top', 'playlists'].forEach(t => $(`mtab-${t}`).classList.toggle('hidden', t !== tab));
  if (tab === 'favorites' && !likedLoaded) loadLiked(true);
  if (!loaded[tab]) {
    loaded[tab] = true;
    if (tab === 'recent') loadRecent();
    if (tab === 'top') loadTop();
    if (tab === 'playlists') loadPlaylists();
  }
}
$('musicTabs').addEventListener('click', e => { const b = e.target.closest('[data-tab]'); if (b) switchMusicTab(b.dataset.tab); });

async function loadLiked(reset) {
  const box = $('msLiked');
  if (reset) { likedOffset = 0; Trackie.UI.loading(box, 'Loading your Liked Songs…'); }
  likedLoaded = true;
  const res = await spSafe(spPost({ action: 'liked', offset: likedOffset }));
  if (!res.success) { $('msLikedMore').classList.add('hidden'); return spFail(box, res, () => loadLiked(true)); }
  Trackie.UI.done(box);
  const rows = res.items.map(t => trackRow(Object.assign({ liked: true }, t), t.added_at ? `<span class="ms-when">${esc(new Date(t.added_at).toLocaleDateString())}</span>` : '')).join('');
  if (reset) box.innerHTML = rows || spEmpty('fa-heart', 'No Liked Songs yet', 'Tap ♡ on any track — here, or in Spotify — to save it.');
  else box.insertAdjacentHTML('beforeend', rows);
  $('msLikedCount').textContent = res.total ? `${res.total} songs` : '';
  likedOffset = res.next_offset; $('msLikedMore').classList.toggle('hidden', res.next_offset === null);
}
$('msLikedMore').addEventListener('click', () => loadLiked(false));

async function loadRecent() {
  const box = $('msRecent');
  if (!box.querySelector('.ms-row')) Trackie.UI.loading(box, 'Loading your recent listening…');
  const res = await spSafe(spPost({ action: 'recently_played' }));
  if (!res.success) return spFail(box, res, loadRecent);
  Trackie.UI.done(box);
  box.innerHTML = res.items.length ? res.items.map(t => trackRow(t, `<span class="ms-when">${esc(timeAgo(t.played_at))}</span>`)).join('')
    : spEmpty('fa-clock-rotate-left', 'No recent listening yet', 'Play something on Spotify and it appears here.');
}
async function loadTop() {
  const range = $('msRange').value;
  Trackie.UI.loading($('msTopArtists'), 'Loading your top artists…', { rows: 2 });
  Trackie.UI.loading($('msTopTracks'), 'Loading your top tracks…');
  const [a, t] = await Promise.all([spPost({ action: 'top_artists', range }), spPost({ action: 'top_tracks', range })].map(spSafe));
  Trackie.UI.done($('msTopArtists')); Trackie.UI.done($('msTopTracks'));
  if (!a.success) spFail($('msTopArtists'), a, loadTop);
  else $('msTopArtists').innerHTML = a.items.length ? a.items.map(x => `
    <a href="${/^https:\/\/open\.spotify\.com\//.test(x.url) ? esc(x.url) : '#'}" target="_blank" rel="noopener" class="ms-artist-card">
      ${x.image ? `<img src="${esc(x.image)}" alt="" loading="lazy">` : '<span></span>'}<b>${esc(x.name)}</b>${x.genres.length ? `<small>${esc(x.genres.join(', '))}</small>` : ''}</a>`).join('')
    : spEmpty('fa-microphone', 'Not enough listening yet', 'Spotify needs a little more history for this time range.');
  if (!t.success) spFail($('msTopTracks'), t, loadTop);
  else $('msTopTracks').innerHTML = t.items.length ? t.items.map((x, i) => trackRow(x, `<span class="ms-rank">${i + 1}</span>`)).join('')
    : spEmpty('fa-music', 'Not enough listening yet', 'Spotify needs a little more history for this time range.');
}
$('msRange').addEventListener('change', loadTop);

async function loadPlaylists() {
  const box = $('musicPlaylists');
  const res = await spSafe(spPost({ action: 'playlists' }));
  if (!res.success) return spFail(box, res, loadPlaylists);
  Trackie.UI.done(box);
  const current = localStorage.getItem(FOCUS_PLAYLIST_KEY);
  box.innerHTML = res.items.length ? res.items.map(p => `
    <div class="habit-card ms-pl${p.uri === current ? ' on' : ''}">
      <div class="ms-pl-top">${p.image ? `<img src="${esc(p.image)}" alt="" loading="lazy">` : '<span></span>'}
        <div class="u-min-w-0"><b>${esc(p.name)}</b><small>${+p.tracks} tracks</small></div></div>
      <div class="u-flex u-gap-2">
        <button class="btn btn-primary btn-sm u-flex-1" data-play-list="${esc(p.uri)}"><i class="fas fa-play"></i> Play</button>
        <button class="btn btn-sm ${p.uri === current ? 'btn-primary' : 'btn-secondary'} u-flex-1" data-focus-pl="${esc(p.uri)}" data-name="${esc(p.name)}">
          <i class="fas ${p.uri === current ? 'fa-check' : 'fa-bullseye'}"></i> ${p.uri === current ? 'Focus' : 'Use for focus'}</button>
      </div></div>`).join('') : spEmpty('fa-list', 'No playlists yet', 'Playlists you create or follow in Spotify show up here.');
  updateFocusSyncStatus();
}
$('musicPlaylists').addEventListener('click', e => {
  const b = e.target.closest('[data-focus-pl]'); if (!b) return;
  localStorage.setItem(FOCUS_PLAYLIST_KEY, b.dataset.focusPl);
  localStorage.setItem(FOCUS_PLAYLIST_KEY + '_name', b.dataset.name);
  Trackie.Toast.success(`"${b.dataset.name}" will play during focus sessions.`);
  loadPlaylists();
});
function updateFocusSyncStatus() {
  const uri = localStorage.getItem(FOCUS_PLAYLIST_KEY), name = localStorage.getItem(FOCUS_PLAYLIST_KEY + '_name');
  $('focusSyncStatus').innerHTML = uri ? `<i class="fas fa-check-circle" style="color:#1db954"></i> Focus plays <strong>${esc(name || 'a playlist')}</strong>`
    : '<span class="u-c-muted">No focus playlist yet.</span>';
}

/* ── This browser as a Spotify device (desktop only; Premium) ─────── */
window.onSpotifyWebPlaybackSDKReady = async () => {
  const res = await spPost({ action: 'get_token' }).catch(() => null);
  if (!res?.access_token) return;
  const token = res.access_token;
  const player = new Spotify.Player({ name: 'Trackie (this browser)', getOAuthToken: cb => cb(token), volume: 0.5 });
  player.addListener('ready', ({ device_id }) => { sdkDeviceId = device_id; if (!msState?.active) renderNow(); });
  player.addListener('not_ready', () => { sdkDeviceId = null; });
  player.addListener('player_state_changed', () => setTimeout(loadState, 300));
  player.connect();
};
if (!msIsNative && !/Android|iPhone|iPad/i.test(navigator.userAgent)) {
  const s = document.createElement('script'); s.src = 'https://sdk.scdn.co/spotify-player.js'; document.body.appendChild(s);
}

loadState(); schedulePoll(5000);
switchMusicTab('favorites');
</script>
<?php endif; ?>
