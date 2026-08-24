<?php
/**
 * Dashboard — Quick stats card (column 1, bottom)
 * Replaces the "mobile app promo" from the reference with real data.
 * Expects: $qstats (array of ['label','value','icon','color'])
 */
?>
<div class="d-card" aria-label="Quick statistics">
  <div class="d-card-header">
    <span class="d-card-title">Today at a Glance</span>
  </div>
  <div class="d-card-body" style="padding-top:.5rem;padding-bottom:.5rem">
    <?php foreach ($qstats as $s): ?>
      <div class="qstat-item">
        <span class="qstat-label">
          <i class="fas <?= h($s['icon']) ?>" style="color:<?= h($s['color']) ?>;width:1rem" aria-hidden="true"></i>
          <?= h($s['label']) ?>
        </span>
        <span class="qstat-value"><?= h((string)$s['value']) ?></span>
      </div>
    <?php endforeach; ?>
    <?php if (empty($qstats)): ?>
      <div class="empty-dash">Nothing to show yet.</div>
    <?php endif; ?>
  </div>
</div>
