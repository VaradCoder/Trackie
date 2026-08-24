<?php
/**
 * Dashboard — Mini calendar card (column 1)
 * Expects: $calMonth (int), $calYear (int), $calTaskDates (array of YYYY-MM-DD)
 */
$calMonthName  = date('F, Y', mktime(0,0,0,$calMonth,1,$calYear));
$firstDay      = mktime(0,0,0,$calMonth,1,$calYear);
$daysInMonth   = (int)date('t', $firstDay);
$startWeekday  = (int)date('w', $firstDay);  // 0=Sun
$todayNum      = (date('n') == $calMonth && date('Y') == $calYear) ? (int)date('j') : -1;

$prevMonth = $calMonth === 1  ? 12 : $calMonth - 1;
$prevYear  = $calMonth === 1  ? $calYear - 1 : $calYear;
$nextMonth = $calMonth === 12 ? 1  : $calMonth + 1;
$nextYear  = $calMonth === 12 ? $calYear + 1 : $calYear;

$taskDateSet = array_flip($calTaskDates);  // O(1) lookup
?>
<div class="d-card" aria-label="Mini calendar for <?= h($calMonthName) ?>">
  <div class="d-card-body">
    <!-- Header -->
    <div class="mini-cal-nav-row">
      <span class="mini-cal-label"><?= h($calMonthName) ?></span>
      <div class="mini-cal-btn-group">
        <a href="?cal_month=<?= $prevMonth ?>&cal_year=<?= $prevYear ?>"
           class="mini-cal-nav-btn" aria-label="Previous month">
          <i class="fas fa-chevron-left" aria-hidden="true"></i>
        </a>
        <a href="?cal_month=<?= $nextMonth ?>&cal_year=<?= $nextYear ?>"
           class="mini-cal-nav-btn" aria-label="Next month">
          <i class="fas fa-chevron-right" aria-hidden="true"></i>
        </a>
      </div>
    </div>

    <!-- Day-of-week headers -->
    <div class="mini-cal-grid" role="grid" aria-label="Calendar grid">
      <?php foreach (['S','M','T','W','T','F','S'] as $d): ?>
        <div class="mini-cal-dow" role="columnheader"><?= $d ?></div>
      <?php endforeach; ?>

      <!-- Empty cells before month start -->
      <?php for ($i = 0; $i < $startWeekday; $i++): ?>
        <div role="gridcell"></div>
      <?php endfor; ?>

      <!-- Day cells -->
      <?php for ($day = 1; $day <= $daysInMonth; $day++):
        $dateStr  = date('Y-m-d', mktime(0,0,0,$calMonth,$day,$calYear));
        $isToday  = ($day === $todayNum);
        $hasTask  = isset($taskDateSet[$dateStr]);
        $classes  = 'mini-cal-day';
        if ($isToday)  $classes .= ' today';
        if ($hasTask)  $classes .= ' has-task';
        $ariaLabel = date('l M j', mktime(0,0,0,$calMonth,$day,$calYear))
                   . ($hasTask ? ' — has tasks' : '');
      ?>
        <a href="<?= APP_BASE ?>/pages/todos.php?filter=today&date=<?= $dateStr ?>"
           class="<?= $classes ?>"
           role="gridcell"
           aria-label="<?= h($ariaLabel) ?>"
           <?= $isToday ? 'aria-current="date"' : '' ?>
           style="text-decoration:none;display:block">
          <?= $day ?>
        </a>
      <?php endfor; ?>
    </div>
  </div>
</div>
