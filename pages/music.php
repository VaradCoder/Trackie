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
$connected = provider('spotify')?->isConnected($uid) ?? false;

require_once '../includes/head.php';
?>
<!-- Spotify Web Playback SDK — in-app playback with real player controls -->
<script src="https://sdk.scdn.co/spotify-player.js"></script>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem">
  <h1 style="font-size:1.125rem;font-weight:600;margin:0"><i class="fab fa-spotify" style="color:#1db954"></i> Music</h1>
</div>

<?php if (!$clientId): ?>
  <div class="card card-body" style="margin-bottom:1.5rem">
    <p style="color:var(--muted);font-size:.875rem;margin:0">Spotify isn't configured on this server yet.</p>
  </div>
<?php elseif (!$connected): ?>
  <div class="card card-body" style="text-align:center;padding:2.5rem 1.5rem;margin-bottom:1.5rem">
    <i class="fab fa-spotify" style="font-size:2.5rem;color:#1db954;margin-bottom:.75rem"></i>
    <div style="font-weight:600;font-size:1.0625rem;color:var(--text);margin-bottom:.375rem">Connect your Spotify account</div>
    <p style="font-size:.875rem;color:var(--muted);max-width:420px;margin:0 auto 1.25rem">
      Play music right here in Trackie, see your top artists and tracks, and sync playlists with your focus sessions.
    </p>
    <a href="<?= APP_BASE ?>/pages/spotify_callback.php" class="btn btn-primary btn-sm"><i class="fab fa-spotify"></i> Connect Spotify</a>
  </div>
<?php else: ?>

  <!-- Module tabs -->
  <div class="filter-tabs" style="margin-bottom:1.25rem" id="musicTabs">
    <button class="filter-tab active" data-tab="overview">Overview</button>
    <button class="filter-tab" data-tab="listen">Listen</button>
    <button class="filter-tab" data-tab="track">Track</button>
  </div>

  <!-- ═══ Overview ═══ -->
  <div id="mtab-overview" class="gym-tab-panel">
    <div class="card card-body" style="margin-bottom:1.5rem">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem">
        <div style="font-size:.8125rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted)">Focus Session Sync</div>
      </div>
      <p style="font-size:.8125rem;color:var(--muted);margin-bottom:.875rem">
        Pick a playlist on the Listen tab to auto-play when you start a focus session, and auto-pause when it ends.
      </p>
      <div id="focusSyncStatus" style="font-size:.875rem;color:var(--text)"></div>
    </div>
  </div>

  <!-- ═══ Listen ═══ -->
  <div id="mtab-listen" class="gym-tab-panel hidden">
    <div style="font-size:.9375rem;font-weight:600;margin-bottom:.75rem">Your playlists</div>
    <div id="musicPlaylists" class="grid-cards" style="margin-bottom:1.5rem">
      <div style="font-size:.875rem;color:var(--muted)"><i class="fas fa-spinner fa-spin"></i> Loading…</div>
    </div>

    <div style="font-size:.9375rem;font-weight:600;margin-bottom:.75rem">Top artists (last 4 weeks)</div>
    <div id="musicTopArtists" class="grid-cards" style="margin-bottom:1.5rem">
      <div style="font-size:.875rem;color:var(--muted)"><i class="fas fa-spinner fa-spin"></i> Loading…</div>
    </div>

    <div style="font-size:.9375rem;font-weight:600;margin-bottom:.75rem">Top tracks (last 4 weeks)</div>
    <div id="musicTopTracks" class="card">
      <div style="padding:1rem;font-size:.875rem;color:var(--muted)"><i class="fas fa-spinner fa-spin"></i> Loading…</div>
    </div>
  </div>

  <!-- ═══ Track ═══ -->
  <div id="mtab-track" class="gym-tab-panel hidden">
    <div style="font-size:.9375rem;font-weight:600;margin-bottom:.75rem">Recently played</div>
    <div id="musicRecentlyPlayed" class="card">
      <div style="padding:1rem;font-size:.875rem;color:var(--muted)"><i class="fas fa-spinner fa-spin"></i> Loading…</div>
    </div>
  </div>

<?php endif; ?>

</div>
<?php include '../includes/footer.php'; ?>

<?php if ($connected): ?>
<!-- ── In-app player bar (sticky bottom) ─────────────────────────── -->
<div id="playerBar" class="player-bar hidden">
  <img id="playerArt" src="" alt="" class="player-bar-art">
  <div class="player-bar-info">
    <div class="player-bar-title" id="playerTrackName">—</div>
    <div class="player-bar-artist" id="playerTrackArtist">—</div>
  </div>
  <div class="player-bar-controls">
    <button class="btn btn-icon btn-ghost btn-sm" id="playerPrevBtn" title="Previous" aria-label="Previous"><i class="fas fa-backward-step"></i></button>
    <button class="btn btn-icon btn-primary" id="playerToggleBtn" title="Play/Pause" aria-label="Play/Pause"><i class="fas fa-play"></i></button>
    <button class="btn btn-icon btn-ghost btn-sm" id="playerNextBtn" title="Next" aria-label="Next"><i class="fas fa-forward-step"></i></button>
  </div>
  <div class="player-bar-progress">
    <span id="playerElapsed" class="player-bar-time">0:00</span>
    <input type="range" id="playerSeek" min="0" max="100" value="0" class="player-bar-seek">
    <span id="playerDuration" class="player-bar-time">0:00</span>
  </div>
  <div class="player-bar-volume">
    <i class="fas fa-volume-low" style="font-size:.75rem;color:var(--muted)"></i>
    <input type="range" id="playerVolume" min="0" max="100" value="50" class="player-bar-seek" style="width:70px">
  </div>
</div>
<?php endif; ?>

<?php if ($connected): ?>
<script>
const API_BASE = '<?= APP_BASE ?>/api';
const FOCUS_PLAYLIST_KEY = 'trackie_focus_playlist_uri';

function switchMusicTab(tab) {
  document.querySelectorAll('#musicTabs .filter-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
  document.querySelectorAll('#mtab-overview, #mtab-listen, #mtab-track').forEach(p => p.classList.toggle('hidden', p.id !== `mtab-${tab}`));
  if (tab === 'listen' && !window._musicListenLoaded) { window._musicListenLoaded = true; loadPlaylists(); loadTopArtists(); loadTopTracks(); }
  if (tab === 'track' && !window._musicTrackLoaded) { window._musicTrackLoaded = true; loadRecentlyPlayed(); }
}
document.getElementById('musicTabs').addEventListener('click', e => {
  const btn = e.target.closest('[data-tab]');
  if (btn) switchMusicTab(btn.dataset.tab);
});

function escMusic(s) {
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

/* ── Web Playback SDK — real in-app player ───────────────────────
   Registers Trackie as a Spotify Connect device. Requires Spotify
   Premium (a Spotify platform limitation, not ours) — free accounts
   will see a clear message instead of a silent failure. */
/** Spotify said no: say why and how to fix it, instead of "nothing found". */
function spReconnectHtml(res) {
  return `<p style="font-size:.875rem;color:var(--muted)">${escHtml(res.error || 'Spotify needs to be reconnected.')}
    <a href="${API_BASE.replace(/\/api$/, '')}/pages/spotify_callback.php" data-no-spa>Reconnect Spotify</a></p>`;
}
let spotifyPlayer = null;
let spotifyDeviceId = null;

window.onSpotifyWebPlaybackSDKReady = async () => {
  let token;
  try {
    const res = await Trackie.API.post(`${API_BASE}/spotify.php`, { action: 'get_token' });
    if (!res.success || !res.access_token) return;
    token = res.access_token;
  } catch { return; }

  spotifyPlayer = new Spotify.Player({
    name: 'Trackie',
    getOAuthToken: cb => cb(token),
    volume: 0.5,
  });

  spotifyPlayer.addListener('ready', ({ device_id }) => { spotifyDeviceId = device_id; });
  spotifyPlayer.addListener('not_ready', () => { spotifyDeviceId = null; });
  spotifyPlayer.addListener('initialization_error', () => {});
  spotifyPlayer.addListener('authentication_error', () => {});
  spotifyPlayer.addListener('account_error', () => {
    Trackie.Toast.warning('In-app playback needs Spotify Premium. You can still browse and open tracks in Spotify.');
  });
  spotifyPlayer.addListener('player_state_changed', renderPlayerState);

  spotifyPlayer.connect();
};

function renderPlayerState(state) {
  const bar = document.getElementById('playerBar');
  if (!state || !state.track_window || !state.track_window.current_track) { bar.classList.add('hidden'); return; }
  bar.classList.remove('hidden');
  const t = state.track_window.current_track;
  document.getElementById('playerArt').src = t.album.images[0]?.url || '';
  document.getElementById('playerTrackName').textContent = t.name;
  document.getElementById('playerTrackArtist').textContent = t.artists.map(a => a.name).join(', ');
  document.getElementById('playerToggleBtn').innerHTML = `<i class="fas ${state.paused ? 'fa-play' : 'fa-pause'}"></i>`;
  document.getElementById('playerElapsed').textContent = fmtMs(state.position);
  document.getElementById('playerDuration').textContent = fmtMs(state.duration);
  const seek = document.getElementById('playerSeek');
  if (!seek.matches(':active')) seek.value = state.duration ? (state.position / state.duration) * 100 : 0;
}
function fmtMs(ms) {
  const s = Math.floor(ms / 1000);
  return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
}

document.getElementById('playerToggleBtn')?.addEventListener('click', () => spotifyPlayer?.togglePlay());
document.getElementById('playerNextBtn')?.addEventListener('click', () => spotifyPlayer?.nextTrack());
document.getElementById('playerPrevBtn')?.addEventListener('click', () => spotifyPlayer?.previousTrack());
document.getElementById('playerVolume')?.addEventListener('input', e => spotifyPlayer?.setVolume(e.target.value / 100));
document.getElementById('playerSeek')?.addEventListener('change', async e => {
  const state = await spotifyPlayer?.getCurrentState();
  if (state) spotifyPlayer.seek((e.target.value / 100) * state.duration);
});

/** Starts playback of a playlist or single track on Trackie's own player device. */
async function playOnTrackie(playlistUri, trackUri) {
  if (!spotifyDeviceId) { Trackie.Toast.warning('Player is still connecting — try again in a moment.'); return; }
  try {
    const res = await Trackie.API.post(`${API_BASE}/spotify.php`, {
      action: 'play', device_id: spotifyDeviceId,
      playlist_uri: playlistUri || '', track_uri: trackUri || '',
    });
    if (res.noActiveDevice) Trackie.Toast.warning('Could not start playback on Trackie\'s player.');
  } catch { Trackie.Toast.error('Network error.'); }
}

/* ── Focus Session Sync ───────────────────────────────────────── */
async function loadPlaylists() {
  const box = document.getElementById('musicPlaylists');
  try {
    const res = await Trackie.API.post(`${API_BASE}/spotify.php`, { action: 'playlists' });
    if (res.needsReconnect || res.connected === false) { box.innerHTML = spReconnectHtml(res); return; }
    if (!res.success || !res.items.length) { box.innerHTML = '<p style="font-size:.875rem;color:var(--muted)">No playlists found.</p>'; return; }
    const current = localStorage.getItem(FOCUS_PLAYLIST_KEY);
    box.innerHTML = res.items.map(p => `
      <div class="habit-card" style="${p.uri === current ? 'border-color:var(--accent)' : ''}">
        <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.75rem">
          ${p.image ? `<img src="${p.image}" alt="Cover art for ${escMusic(p.name || 'playlist')}" style="width:44px;height:44px;border-radius:.5rem;object-fit:cover">` : '<div style="width:44px;height:44px;border-radius:.5rem;background:var(--surface2)"></div>'}
          <div style="min-width:0">
            <div style="font-weight:600;font-size:.875rem;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${escMusic(p.name)}</div>
            <div style="font-size:.75rem;color:var(--muted)">${p.tracks} tracks</div>
          </div>
        </div>
        <div style="display:flex;gap:.5rem">
          <button class="btn btn-primary btn-sm" style="flex:1" onclick="playOnTrackie('${p.uri}')"><i class="fas fa-play"></i> Play</button>
          <button class="btn btn-sm ${p.uri === current ? 'btn-primary' : 'btn-secondary'}" style="flex:1" onclick="setFocusPlaylist('${p.uri}', '${escMusic(p.name).replace(/'/g,"\\'")}')">
            <i class="fas ${p.uri === current ? 'fa-check' : 'fa-link'}"></i> ${p.uri === current ? 'Synced' : 'Sync'}
          </button>
        </div>
      </div>`).join('');
    updateFocusSyncStatus();
  } catch { box.innerHTML = '<p style="font-size:.875rem;color:var(--muted)">Network error.</p>'; }
}
function setFocusPlaylist(uri, name) {
  localStorage.setItem(FOCUS_PLAYLIST_KEY, uri);
  localStorage.setItem(FOCUS_PLAYLIST_KEY + '_name', name);
  Trackie.Toast.success(`"${name}" will play during focus sessions.`);
  loadPlaylists();
  updateFocusSyncStatus();
}
function updateFocusSyncStatus() {
  const uri = localStorage.getItem(FOCUS_PLAYLIST_KEY);
  const name = localStorage.getItem(FOCUS_PLAYLIST_KEY + '_name');
  const el = document.getElementById('focusSyncStatus');
  if (!el) return;
  el.innerHTML = uri
    ? `<i class="fas fa-check-circle" style="color:#1db954"></i> Synced with <strong>${escMusic(name || 'a playlist')}</strong>`
    : `<span style="color:var(--muted)">No playlist synced yet.</span>`;
}
updateFocusSyncStatus();

async function loadTopArtists() {
  const box = document.getElementById('musicTopArtists');
  try {
    const res = await Trackie.API.post(`${API_BASE}/spotify.php`, { action: 'top_artists' });
    if (res.needsReconnect || res.connected === false) { box.innerHTML = spReconnectHtml(res); return; }
    if (!res.success || !res.items.length) { box.innerHTML = '<p style="font-size:.875rem;color:var(--muted)">Not enough listening history yet.</p>'; return; }
    box.innerHTML = res.items.map(a => `
      <a href="${a.url}" target="_blank" rel="noopener" class="habit-card" style="text-decoration:none;text-align:center">
        ${a.image ? `<img src="${a.image}" alt="" style="width:64px;height:64px;border-radius:50%;object-fit:cover;margin:0 auto .625rem">` : ''}
        <div style="font-weight:600;font-size:.8125rem;color:var(--text)">${escMusic(a.name)}</div>
        ${a.genres.length ? `<div style="font-size:.6875rem;color:var(--muted);margin-top:.25rem">${escMusic(a.genres.join(', '))}</div>` : ''}
      </a>`).join('');
  } catch { box.innerHTML = '<p style="font-size:.875rem;color:var(--muted)">Network error.</p>'; }
}

async function loadTopTracks() {
  const box = document.getElementById('musicTopTracks');
  try {
    const res = await Trackie.API.post(`${API_BASE}/spotify.php`, { action: 'top_tracks' });
    if (res.needsReconnect || res.connected === false) { box.innerHTML = spReconnectHtml(res); return; }
    if (!res.success || !res.items.length) { box.innerHTML = '<div style="padding:1rem;font-size:.875rem;color:var(--muted)">Not enough listening history yet.</div>'; return; }
    box.innerHTML = res.items.map((t, i) => `
      <div class="todo-row" style="cursor:pointer" onclick="playOnTrackie(null, '${t.uri || ''}')">
        ${t.art ? `<img src="${t.art}" alt="" style="width:36px;height:36px;border-radius:.375rem;object-fit:cover;flex-shrink:0">` : ''}
        <div style="flex:1;min-width:0">
          <span class="todo-title">${escMusic(t.name)}</span>
          <div class="todo-meta">${escMusic(t.artist)}</div>
        </div>
        <i class="fas fa-play" style="color:var(--muted);font-size:.75rem"></i>
      </div>`).join('');
  } catch { box.innerHTML = '<div style="padding:1rem;font-size:.875rem;color:var(--muted)">Network error.</div>'; }
}

/* ── Track tab ────────────────────────────────────────────────── */
async function loadRecentlyPlayed() {
  const box = document.getElementById('musicRecentlyPlayed');
  try {
    const res = await Trackie.API.post(`${API_BASE}/spotify.php`, { action: 'recently_played' });
    if (res.needsReconnect || res.connected === false) { box.innerHTML = spReconnectHtml(res); return; }
    if (!res.success || !res.items.length) { box.innerHTML = '<div style="padding:1rem;font-size:.875rem;color:var(--muted)">No recent listening history.</div>'; return; }
    box.innerHTML = res.items.map(it => `
      <div class="todo-row">
        ${it.art ? `<img src="${it.art}" alt="" style="width:36px;height:36px;border-radius:.375rem;object-fit:cover;flex-shrink:0">` : ''}
        <div style="flex:1;min-width:0">
          <span class="todo-title">${escMusic(it.name)}</span>
          <div class="todo-meta">${escMusic(it.artist)}</div>
        </div>
        <span style="font-size:.75rem;color:var(--subtle)">${new Date(it.played_at).toLocaleString('en-US',{month:'short',day:'numeric',hour:'numeric',minute:'2-digit'})}</span>
      </div>`).join('');
  } catch { box.innerHTML = '<div style="padding:1rem;font-size:.875rem;color:var(--muted)">Network error.</div>'; }
}
</script>
<?php endif; ?>
