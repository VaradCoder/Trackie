<?php
/**
 * Dashboard — More Integrations card (column 4, bottom)
 * This card is informational / static — no fake data, just a CTA.
 */
?>
<div class="d-card-muted" aria-label="More integrations">
  <div class="integrations-inner">
    <div class="integrations-title">Integrations</div>
    <div class="integrations-sub">Spotify, Weather, GitHub, Google &amp; more</div>
    <a href="<?= APP_BASE ?>/pages/settings.php"
       class="btn-integrations-outline"
       aria-label="Manage integrations">
      <i class="fas fa-plug" aria-hidden="true"></i>
      Manage
    </a>
  </div>
</div>
