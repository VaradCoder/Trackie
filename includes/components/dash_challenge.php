<?php
/**
 * Dashboard — Featured Goal / Challenge card (column 2, bottom)
 * Replaces "Running Competition" from the reference with the user's
 * nearest-deadline active goal.
 * Expects: $featuredGoal (array|null)
 */
if ($featuredGoal) {
    $target  = (int)($featuredGoal['target_value'] ?? 100);
    $prog    = (int)($featuredGoal['progress']     ?? 0);
    $pct     = $target > 0 ? min(100, round($prog / $target * 100)) : 0;
    $done    = $pct >= 100;

    $deadline = $featuredGoal['deadline'] ?? null;
    $daysLeft = null;
    if ($deadline) {
        $daysLeft = (int)ceil((strtotime($deadline) - time()) / 86400);
    }
}
?>
<div class="d-card" aria-label="Featured goal">
  <div class="d-card-header">
    <span class="d-card-title">
      <?= $featuredGoal ? 'Featured Goal' : 'Goals' ?>
    </span>
    <a href="<?= APP_BASE ?>/pages/goals.php" class="d-card-link" aria-label="View all goals">
      View Details
    </a>
  </div>

  <div class="d-card-body">
    <?php if (!$featuredGoal): ?>
      <div class="no-goal-cta">
        <div class="empty-dash-icon" aria-hidden="true"><i class="fas fa-bullseye"></i></div>
        <p>No active goals.</p>
        <a href="<?= APP_BASE ?>/pages/goals.php" class="btn-dash-primary" style="margin-top:.625rem;display:inline-flex;width:auto;padding:.5rem 1.25rem">
          <i class="fas fa-plus" aria-hidden="true"></i> Set a Goal
        </a>
      </div>

    <?php else: ?>

      <!-- Meta row (mirrors running competition layout) -->
      <div class="challenge-meta-row">
        <?php if ($deadline): ?>
          <span>
            <i class="fas fa-calendar-alt" aria-hidden="true"></i>
            <?= date('j M', strtotime($deadline)) ?>
          </span>
        <?php endif; ?>
        <?php if ($daysLeft !== null): ?>
          <span>
            <i class="fas fa-clock" aria-hidden="true"></i>
            <?= $daysLeft > 0 ? $daysLeft . ' day' . ($daysLeft !== 1 ? 's' : '') . ' left' : 'Due today!' ?>
          </span>
        <?php endif; ?>
        <span>
          <i class="fas fa-chart-line" aria-hidden="true"></i>
          <?= $pct ?>% done
        </span>
      </div>

      <!-- Goal name -->
      <div class="challenge-goal-name"><?= h($featuredGoal['goal_name']) ?></div>

      <?php if ($featuredGoal['description']): ?>
        <div style="font-size:.8125rem;color:var(--muted);margin-bottom:.75rem">
          <?= h($featuredGoal['description']) ?>
        </div>
      <?php endif; ?>

      <!-- Progress -->
      <div class="challenge-progress-area">
        <div class="challenge-progress-numbers">
          <span><?= $prog ?> / <?= $target ?></span>
          <strong><?= $pct ?>%</strong>
        </div>
        <div class="progress-track" role="progressbar"
             aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"
             aria-label="Goal progress">
          <div class="progress-fill <?= $done ? 'green' : '' ?>" style="width:<?= $pct ?>%"></div>
        </div>
      </div>

      <?php if (!$done): ?>
        <a href="<?= APP_BASE ?>/pages/goals.php" class="btn-dash-secondary"
           style="display:inline-flex;width:auto;padding:.4375rem 1rem;font-size:.8125rem">
          <i class="fas fa-edit" aria-hidden="true"></i> Update Progress
        </a>
      <?php else: ?>
        <div class="all-done-msg">
          <i class="fas fa-trophy" aria-hidden="true"></i> Goal achieved!
        </div>
      <?php endif; ?>

    <?php endif; ?>
  </div>
</div>
