<?php
/**
 * Dashboard — Welcome card (column 1, top)
 * Expects: $greeting, $dayName, $userName, $picSrc, $habitDelta
 */
?>
<div class="d-card" aria-label="Welcome card">
  <div class="d-card-body">
    <!-- Quick actions (greeting now lives in the hero band) -->
    <div class="section-label" style="margin-bottom:.75rem">Quick actions</div>
    <div class="welcome-actions">
      <a href="<?= APP_BASE ?>/pages/habits.php"
         class="btn-dash-primary"
         aria-label="Add a new habit">
        <i class="fas fa-plus" aria-hidden="true"></i>
        New Habit
      </a>
      <a href="<?= APP_BASE ?>/pages/todos.php"
         class="btn-dash-secondary"
         aria-label="View all todos">
        <i class="fas fa-list-check" aria-hidden="true"></i>
        View All Todos
      </a>
    </div>

    <!-- Habit delta -->
    <?php if ($habitDelta !== null): ?>
      <div class="delta-pill <?= $habitDelta >= 0 ? 'delta-pill-pos' : 'delta-pill-neg' ?>"
           aria-label="<?= $habitDelta >= 0 ? 'Up' : 'Down' ?> <?= abs($habitDelta) ?>% vs last month">
        <i class="fas <?= $habitDelta >= 0 ? 'fa-arrow-up' : 'fa-arrow-down' ?>" aria-hidden="true"></i>
        <?= ($habitDelta >= 0 ? '+' : '') . $habitDelta ?>% vs last month
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
/* Live clock in the hero date line */
(function () {
  function tick() {
    const el = document.getElementById('hero-date');
    if (!el) return;
    const now = new Date();
    el.textContent = now.toLocaleString('en-US', {
      weekday: 'long', day: 'numeric', month: 'short', year: 'numeric',
      hour: 'numeric', minute: '2-digit', hour12: true
    });
  }
  tick();
  setInterval(tick, 30000);
})();
</script>
