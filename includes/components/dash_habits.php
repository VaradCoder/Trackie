<?php
/**
 * Dashboard — "Should Do!" / recommended habits (column 2, middle)
 * Expects: $todayHabits (habits with logged_today flag, sorted by total_logs desc)
 * Shows habits not yet logged today as recommendations.
 */
$pendingHabits = array_filter($todayHabits, fn($h) => !$h['logged_today']);
$doneHabits    = array_filter($todayHabits, fn($h) =>  $h['logged_today']);
$today         = date('Y-m-d');

$habitEmojis = ['💪','🧘','📖','🏃','🥗','💧','🌿','⚡','🎯','🏋️','🚴','🧠'];
?>
<div class="d-card" aria-label="Habit recommendations">
  <div class="d-card-header">
    <span class="d-card-title">Should Do!</span>
    <a href="<?= APP_BASE ?>/pages/habits.php" class="d-card-link" aria-label="Manage habits">
      View Details
    </a>
  </div>

  <div class="d-card-body">

    <?php if (empty($todayHabits)): ?>
      <div class="empty-dash">
        <div class="empty-dash-icon" aria-hidden="true"><i class="fas fa-heart"></i></div>
        <p>No habits yet.<br>
          <a href="<?= APP_BASE ?>/pages/habits.php">Add your first habit →</a>
        </p>
      </div>

    <?php elseif (empty($pendingHabits)): ?>
      <div class="all-done-msg" role="status" aria-live="polite">
        <i class="fas fa-trophy" aria-hidden="true"></i>
        All habits completed today!
      </div>

    <?php else: ?>
      <?php
      $shown = 0;
      foreach ($pendingHabits as $h):
        if ($shown >= 3) break;
        $emoji = $habitEmojis[$h['id'] % count($habitEmojis)];
        $shown++;
      ?>
        <div class="hab-rec-item" id="hab-dash-<?= $h['id'] ?>">
          <div class="hab-rec-icon" style="background:<?= h($h['color']) ?>20;color:<?= h($h['color']) ?>"
               aria-hidden="true">
            <?= $emoji ?>
          </div>
          <div>
            <div class="hab-rec-name"><?= h($h['name']) ?></div>
            <div class="hab-rec-count">
              <?= $h['total_logs'] ?> log<?= $h['total_logs'] !== 1 ? 's' : '' ?> total
            </div>
          </div>
          <button class="hab-rec-log-btn"
                  onclick="dashHabitLog(<?= $h['id'] ?>, this)"
                  aria-label="Log habit: <?= h($h['name']) ?>">
            Log it
          </button>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <!-- Completed ones as a subtle count -->
    <?php if (!empty($doneHabits)): ?>
      <div style="margin-top:.625rem;font-size:.8125rem;color:var(--muted);text-align:center">
        <i class="fas fa-check-circle" style="color:var(--ok)" aria-hidden="true"></i>
        <?= count($doneHabits) ?> habit<?= count($doneHabits) > 1 ? 's' : '' ?> done today
      </div>
    <?php endif; ?>

  </div>
</div>
