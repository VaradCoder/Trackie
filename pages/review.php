<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/insights.php';
require_once '../includes/habit_schedule.php';

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Weekly Review';
$currentPage = 'review';

if (!activityReady()) renderSetupNeeded('Weekly Review');

// Default: the last complete week. ?w=YYYY-MM-DD picks any week (snapped to its start).
$ws        = userSetting($uid, 'week_start');
$thisWeek  = weekStartOf(date('Y-m-d'), $ws);
$req       = (string)($_GET['w'] ?? '');
$weekFrom  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $req) && strtotime($req)
    ? weekStartOf($req, $ws)
    : date('Y-m-d', strtotime('-7 day', strtotime($thisWeek)));
if ($weekFrom > $thisWeek) $weekFrom = $thisWeek;
$isCurrent = $weekFrom === $thisWeek;

$rv   = weeklyReview($uid, $weekFrom);
$prev = date('Y-m-d', strtotime('-7 day', strtotime($weekFrom)));
$next = date('Y-m-d', strtotime('+7 day', strtotime($weekFrom)));

function rvDelta(int $now, int $prev): string {
    if ($now === $prev) return '<span class="rv-delta same">±0</span>';
    $d = $now - $prev;
    return '<span class="rv-delta ' . ($d > 0 ? 'up' : 'down') . '">' . ($d > 0 ? '▲' : '▼') . ' ' . number_format(abs($d)) . '</span>';
}

require_once '../includes/head.php';
?>
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<?= renderPageHeader('Weekly Review', [
  'icon' => 'fa-calendar-week',
  'sub'  => 'How your week went across Trackie, compared with the week before.',
]) ?>

<div class="rv-nav">
  <a class="btn btn-secondary btn-sm" href="?w=<?= $prev ?>" aria-label="Previous week"><i class="fas fa-chevron-left"></i></a>
  <span class="rv-range">
    <?= h(date('j M', strtotime($rv['from']))) ?> – <?= h(date('j M Y', strtotime($rv['to']))) ?>
    <?= $isCurrent ? '<span class="text-muted" style="font-weight:400"> · this week so far</span>' : '' ?>
  </span>
  <?php if (!$isCurrent): ?>
    <a class="btn btn-secondary btn-sm" href="?w=<?= $next ?>" aria-label="Next week"><i class="fas fa-chevron-right"></i></a>
  <?php endif; ?>
</div>

<?php if ($rv['active_days'] === 0 && $rv['prev_active_days'] === 0): ?>
  <div class="card card-body">
    <p class="text-muted" style="margin:0">Nothing was logged this week or the week before. Your review fills in as you tick habits, finish todos and log hobbies.</p>
  </div>
<?php else: ?>

<div class="rv-grid">
  <div class="stat-card">
    <span class="stat-label">Active days</span>
    <div class="stat-val"><?= $rv['active_days'] ?><small class="text-muted">/7</small><?= rvDelta($rv['active_days'], $rv['prev_active_days']) ?></div>
  </div>
  <div class="stat-card">
    <span class="stat-label">XP earned</span>
    <div class="stat-val"><?= number_format($rv['xp']) ?><?= rvDelta($rv['xp'], $rv['prev_xp']) ?></div>
  </div>
  <?php foreach ($rv['metrics'] as $label => $m): ?>
    <div class="stat-card">
      <span class="stat-label"><?= h($label) ?></span>
      <div class="stat-val"><?= number_format($m['now']) ?><?= rvDelta($m['now'], $m['prev']) ?></div>
    </div>
  <?php endforeach; ?>
</div>

<div class="grid-2" style="margin-bottom:1.5rem">
  <div class="card card-body">
    <div style="font-size:.9375rem;font-weight:600;margin-bottom:.75rem">By area</div>
    <?php if ($rv['modules']): ?>
    <table class="rv-table">
      <thead><tr><th>Area</th><th class="num">This week</th><th class="num">Week before</th></tr></thead>
      <tbody>
      <?php foreach ($rv['modules'] as $m): ?>
        <tr><td><?= h($m['label']) ?></td><td class="num"><?= $m['now'] ?><?= rvDelta($m['now'], $m['prev']) ?></td><td class="num text-muted"><?= $m['prev'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
      <p class="text-muted" style="margin:0;font-size:.875rem">No activity yet.</p>
    <?php endif; ?>
  </div>

  <div class="card card-body">
    <div style="font-size:.9375rem;font-weight:600;margin-bottom:.75rem">Highlights</div>
    <?php if ($rv['new']): ?>
      <div style="font-size:.8125rem;color:var(--muted)">Picked up this week</div>
      <div class="rv-chips"><?php foreach ($rv['new'] as $m): ?><span class="rv-chip"><?= h($m['label']) ?></span><?php endforeach; ?></div>
    <?php endif; ?>
    <?php if ($rv['dropped']): ?>
      <div style="font-size:.8125rem;color:var(--muted);margin-top:.875rem">Active the week before, quiet this week</div>
      <div class="rv-chips"><?php foreach ($rv['dropped'] as $m): ?><span class="rv-chip"><?= h($m['label']) ?></span><?php endforeach; ?></div>
    <?php endif; ?>
    <?php if (!$rv['new'] && !$rv['dropped']): ?>
      <p class="text-muted" style="margin:0;font-size:.875rem">Same areas as the week before — steady.</p>
    <?php endif; ?>
    <a href="<?= APP_BASE ?>/pages/analytics.php" class="an-review-link">See full analytics <i class="fas fa-arrow-right"></i></a>
  </div>
</div>
<?php endif; ?>

</div>
<?php include '../includes/footer.php'; ?>
