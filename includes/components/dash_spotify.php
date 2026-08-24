<?php
/**
 * Dashboard — Spotify card (column 4, top)
 * Expects: $spotifyState ('not_configured' | 'not_connected' | 'connected')
 */
?>
<div class="d-card d-card-muted spotify-soon" aria-label="Spotify widget">
  <div class="d-card-header">
    <span class="d-card-title">Spotify</span>
    <?php if ($spotifyState === 'connected'): ?>
      <a href="<?= APP_BASE ?>/pages/music.php" class="d-card-link">Open Music</a>
    <?php endif; ?>
  </div>

  <div class="d-card-body" style="text-align:center">
    <?php if ($spotifyState === 'not_configured'): ?>
      <div class="spotify-logo-circle" aria-hidden="true"><i class="fab fa-spotify"></i></div>
      <div class="spotify-connect-title">Not configured</div>
      <div class="spotify-connect-desc">Spotify integration isn't set up on this server yet.</div>
    <?php elseif ($spotifyState === 'not_connected'): ?>
      <div class="spotify-logo-circle" aria-hidden="true"><i class="fab fa-spotify"></i></div>
      <div class="spotify-connect-title">Connect Spotify</div>
      <div class="spotify-connect-desc" style="margin-bottom:.75rem">See what's playing and sync playlists with focus sessions.</div>
      <a href="<?= APP_BASE ?>/pages/spotify_callback.php" class="btn btn-primary btn-sm"><i class="fab fa-spotify"></i> Connect</a>
    <?php else: ?>
      <div id="spotify-now-playing">
        <div class="empty-dash" role="status">
          <div class="empty-dash-icon" aria-hidden="true"><i class="fab fa-spotify" style="color:#1db954"></i></div>
          <p>Loading…</p>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>
