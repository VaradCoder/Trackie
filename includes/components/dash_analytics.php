<?php
/**
 * Dashboard — Analytics bottom section (full width below the grid)
 * Expects:
 *   $weeklyHabitRate  (int 0-100)  — this week's habit completion %
 *   $streakData       (array)       — ['current','best']
 *   $chartLabels      (array)       — last 7 day labels ['Mon 2', ...]
 *   $chartData        (array)       — completions per day for last 7 days
 *   $topHabitsData    (array)       — [['name'=>'...','count'=>N,'color'=>'#hex'], ...]
 */

$bestStreak = $streakData['best'] ?? 0;
$curStreak  = $streakData['current'] ?? 0;
?>
<div class="dash-analytics" aria-label="Analytics overview">

  <!-- Card 1: Habit rate (dark green, like reference "Positive Habits") -->
  <div class="analytics-stat-card green"
       role="region" aria-label="Weekly habit completion rate">
    <div class="as-emoji" aria-hidden="true">😎</div>
    <div class="as-label">Positive Habits</div>
    <div class="as-value" id="dash-habit-rate">
      +<?= $weeklyHabitRate ?>%
    </div>
    <div style="font-size:.8125rem;opacity:.7;margin-top:.375rem">this week</div>
  </div>

  <!-- Card 2: Best streak (dark, like reference "Habits Wrapped") -->
  <div class="analytics-stat-card dark"
       role="region" aria-label="Best habit streak">
    <div class="as-emoji" aria-hidden="true">🎁</div>
    <div class="as-label">Best Streak</div>
    <div class="as-year"><?= $bestStreak ?></div>
    <div style="font-size:.8125rem;opacity:.7">days</div>
    <a href="<?= APP_BASE ?>/pages/analytics.php"
       class="btn-view-white"
       aria-label="View full analytics">
      View
    </a>
  </div>

  <!-- Card 3: Habit chart + top habits (wide) -->
  <div class="d-card dash-analytics-chart" role="region" aria-label="Habit consistency chart">
    <div class="d-card-body">
      <div class="chart-card-header">
        <span class="d-card-title">Favourite Habits</span>
        <a href="<?= APP_BASE ?>/pages/analytics.php" class="d-card-link" aria-label="View full analytics">
          View Details
        </a>
      </div>

      <!-- Legend -->
      <?php if (!empty($topHabitsData)): ?>
        <div class="chart-legend" role="list" aria-label="Habit legend">
          <?php foreach ($topHabitsData as $h): ?>
            <div class="chart-legend-item" role="listitem">
              <div class="chart-legend-dot" style="background:<?= h($h['color']) ?>" aria-hidden="true"></div>
              <?= h($h['name']) ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <!-- Chart / skeleton -->
      <div id="habit-chart-skeleton" class="skel-pulse skel-h-chart skel-w-full" aria-hidden="true"></div>
      <div id="habit-chart-content" class="content-block hidden" aria-label="Habit completion chart">
        <canvas id="dashHabitChart" height="170" aria-label="Bar chart of habit completions over the last 7 days"></canvas>
      </div>

    </div>
  </div>

</div>
