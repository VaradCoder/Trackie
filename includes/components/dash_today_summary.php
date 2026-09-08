<?php
/**
 * Dashboard — "Today" summary (Sprint 1: clean UI).
 * Answers "what should I do / what have I done / how am I progressing" in
 * one glance, above the full widget grid below it. Purely additive — every
 * existing dashboard widget stays exactly as it was.
 *
 * Expects: $todoStats, $streakData, $xpData, $xpToday, $focusToday, $userName
 */
$ts_focusMin = (int)($focusToday['today'] ?? 0);
?>
<section class="today-summary" aria-label="Today summary">
  <div class="today-summary-grid">
    <?php if ($ts_focusMin > 0): ?>
      <div class="today-summary-item">
        <div class="today-summary-icon"><i class="fas fa-stopwatch"></i></div>
        <div>
          <div class="today-summary-value"><?= $ts_focusMin ?> min</div>
          <div class="today-summary-label">Focus today</div>
        </div>
      </div>
    <?php endif; ?>

    <div class="today-summary-item">
      <div class="today-summary-icon"><i class="fas fa-check-square"></i></div>
      <div>
        <div class="today-summary-value"><?= (int)$todoStats['done'] ?> done · <?= (int)$todoStats['pending'] ?> left</div>
        <div class="today-summary-label">Tasks</div>
      </div>
    </div>

    <div class="today-summary-item">
      <div class="today-summary-icon"><i class="fas fa-fire" style="color:#f59e0b"></i></div>
      <div>
        <div class="today-summary-value"><?= (int)$streakData['current'] ?> day<?= $streakData['current'] === 1 ? '' : 's' ?> of showing up</div>
        <div class="today-summary-label">Habits</div>
      </div>
    </div>

    <div class="today-summary-item">
      <div class="today-summary-icon"><i class="fas fa-seedling" style="color:var(--ok)"></i></div>
      <div>
        <div class="today-summary-value">+<?= $xpToday ?> XP <span style="font-weight:400;color:var(--muted)">· Level <?= (int)$xpData['level'] ?></span></div>
        <div class="today-summary-label">Progress made today</div>
      </div>
    </div>
  </div>
</section>
