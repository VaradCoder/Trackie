<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/gamification.php';

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Progress';
$currentPage = 'progress';

// Gamification tables may not exist on an un-migrated DB.
if (!tableExists('user_xp')) renderSetupNeeded('Progress & XP');

$xp        = xpSummary($uid);
$defs      = achievementDefs();
$unlocked  = unlockedAchievements($uid);
$events    = recentXpEvents($uid, 20);
require_once '../includes/activity.php';
$overallStreak = activityReady() ? activityStreak($uid) : ['current' => 0, 'best' => 0];
$modStreaks    = activityReady() ? moduleStreaks($uid) : [];

// Friendly labels for xp_event actions
$actionLabel = [
    'todo'        => '✅ Completed a todo',
    'habit'       => '🔥 Logged a habit',
    'goal'        => '🎯 Completed a goal',
    'study'       => '📚 Completed a study task',
    'focus'       => '⏱ Focus session',
    'streak_7'    => '🔥 7-day streak bonus',
    'streak_30'   => '🔥 30-day streak bonus',
    'achievement' => '🏆 Achievement unlocked',
    'routine'            => '🕒 Completed a routine',
    'workout'            => '🏋️ Logged an exercise',
    'workout_session'    => '🏋️ Finished a workout',
    'fitness_goal'       => '🎯 Reached a fitness goal',
    'meditation_session' => '🧘 Meditated',
    'sports_session'     => '⚽ Sports session',
    'reading_session'    => '📖 Read today',
    'book_finished'      => '📚 Finished a book',
    'game_completed'     => '🎮 Completed a game',
    'photo_shoot'        => '📷 Logged a shoot',
    'photo_upload_day'   => '🖼️ Added photos',
    'xp'                 => '⚡ XP',
];

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<?= renderPageHeader('Progress & Achievements', [
  'icon' => 'fa-trophy',
  'sub'  => 'Your XP, levels, streaks, and unlocked badges.',
]) ?>

<!-- Streaks (activity engine) -->
<div class="card card-body u-mb-5" id="streakBoard">
  <div class="fit-section-head"><h2 class="hb-h2"><i class="fas fa-fire u-c-warn"></i> Streaks</h2>
    <span class="rd-author">A day counts when you complete at least one real activity.</span></div>
  <div class="streak-board">
    <div class="streak-tile streak-tile-main">
      <div class="streak-num"><?= (int)$overallStreak['current'] ?></div>
      <div class="streak-label">Trackie streak</div>
      <div class="streak-best">Best <?= (int)$overallStreak['best'] ?> days</div>
    </div>
    <?php foreach ($modStreaks as $st): ?>
      <div class="streak-tile<?= $st['current'] > 0 ? ' is-on' : '' ?>">
        <div class="streak-num"><?= (int)$st['current'] ?></div>
        <div class="streak-label"><?= h($st['label']) ?></div>
        <div class="streak-best">Best <?= (int)$st['best'] ?> · last <?= h(formatDate($st['last'], 'M j')) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if (!$modStreaks): ?><p class="hb-empty-line">Complete a habit, todo, workout or reading session to start your first streak.</p><?php endif; ?>
</div>

<!-- Level card -->
<div class="card card-body u-mb-5">
  <div class="u-flex u-items-center u-gap-4 u-flex-wrap">
    <div style="width:64px;height:64px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;
                background:linear-gradient(135deg,#f59e0b,#ef4444);color:#fff;font-size:1.5rem;font-weight:800">
      <?= $xp['level'] ?>
    </div>
    <div style="flex:1;min-width:200px">
      <div class="u-fs-18 u-fw-8 u-c-text">Level <?= $xp['level'] ?> · <?= h($xp['title']) ?></div>
      <div style="font-size:.8125rem;color:var(--muted);margin:.25rem 0 .5rem">
        <?= number_format($xp['total']) ?> XP total · <?= number_format($xp['to_next']) ?> XP to level <?= $xp['level'] + 1 ?>
      </div>
      <div class="progress-track" role="progressbar" aria-valuenow="<?= $xp['pct'] ?>" aria-valuemin="0" aria-valuemax="100">
        <div class="progress-fill" style="width:<?= $xp['pct'] ?>%;background:linear-gradient(90deg,#f59e0b,#ef4444)"></div>
      </div>
    </div>
  </div>
</div>

<!-- Achievement gallery -->
<div style="font-size:.9375rem;font-weight:600;margin:0 0 .75rem;color:var(--text)">
  Achievements <span class="badge badge-gray"><?= count($unlocked) ?>/<?= count($defs) ?></span>
</div>
<div class="grid-cards u-mb-6">
  <?php foreach ($defs as $key => $a): $got = in_array($key, $unlocked, true); ?>
    <div class="card card-body achievement <?= $got ? 'achievement-on' : 'achievement-off' ?> u-flex u-items-center u-gap-3h"
        >
      <div style="font-size:2rem;line-height:1;<?= $got ? '' : 'filter:grayscale(1);opacity:.4' ?>"><?= $a['emoji'] ?></div>
      <div class="u-flex-1 u-min-w-0">
        <div class="u-fw-7 u-c-text"><?= h($a['name']) ?></div>
        <div class="u-fs-13 u-c-muted"><?= h($a['desc']) ?></div>
      </div>
      <?php if ($got): ?>
        <i class="fas fa-circle-check u-c-ok" title="Unlocked"></i>
      <?php else: ?>
        <i class="fas fa-lock u-c-subtle" title="Locked"></i>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<!-- Recent XP events -->
<div style="font-size:.9375rem;font-weight:600;margin:0 0 .75rem;color:var(--text)">Recent XP</div>
<div class="card">
  <?php if (empty($events)): ?>
    <div class="empty-state">
      <div class="empty-state-icon"><i class="fas fa-bolt"></i></div>
      <div class="empty-state-title">Every journey starts at zero</div>
      <p>Complete a todo, log a habit, or finish a study task to start earning XP.</p>
    </div>
  <?php else: foreach ($events as $e): ?>
    <div class="todo-row">
      <div class="u-flex-1 u-min-w-0">
        <span class="todo-title"><?= h($actionLabel[$e['action']] ?? ucfirst($e['action'])) ?></span>
        <div class="todo-meta u-mt-0h"><?= timeAgo($e['created_at']) ?></div>
      </div>
      <strong class="u-c-warn u-nowrap">+<?= (int)$e['xp'] ?> XP</strong>
    </div>
  <?php endforeach; endif; ?>
</div>

</div>
<?php include '../includes/footer.php'; ?>
