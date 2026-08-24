<?php
/**
 * Dashboard — Hero band (Phase 2 / 6)
 * Greeting + Level/XP + Current Streak + Today's Priority.
 * Expects: $greeting, $userName, $xpData, $streakData, $todayTodos, $todayPriority
 */
$lvl     = $xpData['level']   ?? 1;
$lvlPct  = $xpData['pct']     ?? 0;
$lvlTtl  = $xpData['title']   ?? 'Beginner';
$xpTotal = $xpData['total']   ?? 0;
$xpInto  = $xpData['into']    ?? 0;
$xpSpan  = $xpData['span']    ?? 1;
$cur     = (int)($streakData['current'] ?? 0);
$pending = count(array_filter($todayTodos, fn($t) => !$t['completed']));

// Daily-rotating motivational quote (stable for the whole day)
$quotes = [
    "Small steps every day add up to big results.",
    "Discipline is choosing what you want most over what you want now.",
    "Focus on progress, not perfection.",
    "The secret of getting ahead is getting started.",
    "You don't have to be extreme, just consistent.",
    "What you do today can improve all your tomorrows.",
    "Done is better than perfect.",
    "Consistency compounds — keep showing up.",
    "One task at a time. One day at a time.",
    "Your future is created by what you do today.",
    "Win the morning, win the day.",
    "Motivation gets you going; habit keeps you growing.",
    "Energy and persistence conquer all things.",
    "Make today count — your streak is watching. 🔥",
];
$quote = $quotes[(int)date('z') % count($quotes)];
$doneToday = count(array_filter($todayTodos, fn($t) => $t['completed']));
$habitsDoneToday  = count(array_filter($todayHabits ?? [], fn($h) => $h['logged_today']));
$habitsTotalToday = count($todayHabits ?? []);
$sc    = $scoreData ?? ['score'=>0,'band'=>'','has_data'=>false];
$scCol = $sc['score'] >= 75 ? 'var(--ok)' : ($sc['score'] >= 50 ? '#f59e0b' : 'var(--accent)');
?>
<!-- ── Greeting banner ─────────────────────────────────────────── -->
<div class="hero-banner" aria-label="Your day at a glance">
  <div class="hero-banner-scrim">
    <div class="hero-banner-top">
      <?php /* The greeting IS the dashboard's page title, so it carries the
               h1. The dashboard previously rendered no heading at any level,
               leaving the app's main screen with no document outline. */ ?>
      <h1 class="hero-greeting">
        Happy<br><?= h($dayName) ?> <span aria-hidden="true">👋</span>
      </h1>
      <?php /* The focus the user named during onboarding, echoed back so the
               answer is visibly in use rather than silently stored. Absent
               entirely when they skipped the question. */
      if (!empty($dashFocus)): ?>
        <div class="hero-focus">
          <i class="fas fa-compass" aria-hidden="true"></i> Focus: <?= h($dashFocus) ?>
        </div>
      <?php endif; ?>
      <div class="hero-quote">Welcome back, <?= h($userName) ?>. <?= h($quote) ?></div>
      <div class="hero-date" id="hero-date"><?= date('l, j M Y') ?></div>

      <div class="hero-cta-row">
        <a href="<?= APP_BASE ?>/pages/habits.php" class="btn-dash-primary hero-cta-btn">
          <i class="fas fa-plus" aria-hidden="true"></i> New Habits
        </a>
        <a href="<?= APP_BASE ?>/pages/habits.php" class="btn-dash-secondary hero-cta-btn">
          Browse Popular Habits
        </a>
      </div>
    </div>
  </div>
</div>

<!-- ── Stat pill row ────────────────────────────────────────────── -->
<div class="stat-row" role="list" aria-label="Today's stats at a glance">

  <div class="stat-pill stat-pill-level" role="listitem">
    <div class="stat-pill-level-icon" style="color:#f59e0b"><i class="fas fa-bolt"></i></div>
    <div class="stat-pill-level-num">LEVEL <?= $lvl ?></div>
    <div class="stat-pill-level-title"><?= h($lvlTtl) ?></div>
    <div class="hero-xp-track" role="progressbar" aria-valuenow="<?= $lvlPct ?>" aria-valuemin="0" aria-valuemax="100"
         title="<?= number_format($xpInto) ?> / <?= number_format($xpSpan) ?> XP to next level">
      <div class="hero-xp-fill" style="width:<?= $lvlPct ?>%"></div>
    </div>
    <div class="stat-pill-label"><?= number_format($xpTotal) ?> / <?= number_format($xpTotal + ($xpData['to_next'] ?? 0)) ?> XP</div>
  </div>

  <div class="stat-pill" role="listitem">
    <div class="stat-pill-top">
      <span class="stat-pill-icon" style="color:#f59e0b"><i class="fas fa-fire"></i></span>
      <span class="stat-pill-num"><?= $cur ?></span>
    </div>
    <div class="stat-pill-label">Day streak<br><span class="stat-pill-sub">Keep it up! 🔥</span></div>
  </div>

  <div class="stat-pill" role="listitem">
    <div class="stat-pill-top">
      <span class="stat-pill-icon" style="color:var(--ok)"><i class="fas fa-circle-check"></i></span>
      <span class="stat-pill-num"><?= $habitsDoneToday ?> / <?= $habitsTotalToday ?></span>
    </div>
    <div class="stat-pill-label">Habits today<br><span class="stat-pill-sub">Great progress!</span></div>
  </div>

  <div class="stat-pill" role="listitem">
    <div class="stat-pill-top">
      <span class="stat-pill-icon" style="color:var(--accent)"><i class="fas fa-list-check"></i></span>
      <span class="stat-pill-num"><?= $pending ?></span>
    </div>
    <div class="stat-pill-label">Tasks left<br><span class="stat-pill-sub">Stay focused!</span></div>
  </div>

  <a href="<?= APP_BASE ?>/pages/analytics.php" class="stat-pill" role="listitem" style="text-decoration:none"
     title="Trackie Score — your daily productivity index">
    <div class="stat-pill-top">
      <span class="stat-pill-icon" style="color:<?= $sc['has_data'] ? $scCol : 'var(--muted)' ?>"><i class="fas fa-trophy"></i></span>
      <span class="stat-pill-num"><?= $sc['has_data'] ? (int)$sc['score'] : '—' ?></span>
    </div>
    <div class="stat-pill-label">Trackie Score<br><span class="stat-pill-sub">
      <?php
      /* Week-over-week movement, the whole reason daily_snapshots exists.
         Shown ONLY when both weeks actually have recorded days — with less
         history than that the honest thing is to fall back to the band rather
         than imply a trend from one data point. */
      $trend = $scoreTrendData ?? ['delta' => null];
      if ($sc['has_data'] && $trend['delta'] !== null && $trend['delta'] !== 0):
          $up = $trend['delta'] > 0;
      ?>
        <span style="color:<?= $up ? 'var(--ok)' : 'var(--accent)' ?>">
          <i class="fas fa-arrow-<?= $up ? 'up' : 'down' ?>" aria-hidden="true"></i>
          <?= abs((int)$trend['delta']) ?> vs last week
        </span>
      <?php else: ?>
        <?= $sc['has_data'] ? h($sc['band']) : 'Log activity to unlock' ?>
      <?php endif; ?>
    </span></div>
  </a>

  <div class="stat-pill stat-pill-priority" role="listitem">
    <div class="stat-pill-label" style="text-transform:uppercase;letter-spacing:.06em;font-weight:700;font-size:.6875rem;color:var(--accent)">
      Today's Priority
    </div>
    <?php if ($todayPriority): ?>
      <div class="hero-priority-task">
        <span class="priority-pip <?= h($todayPriority['priority']) ?>" aria-hidden="true"></span>
        <?= h($todayPriority['title']) ?>
      </div>
      <a href="<?= APP_BASE ?>/pages/todos.php" class="btn btn-primary btn-sm" style="margin-top:.5rem">
        <i class="fas fa-arrow-right"></i> View Tasks
      </a>
    <?php else: ?>
      <div class="hero-priority-task hero-priority-done">🎉 All caught up.</div>
      <a href="<?= APP_BASE ?>/pages/todos.php" class="btn btn-secondary btn-sm" style="margin-top:.5rem">
        <i class="fas fa-plus"></i> Plan a task
      </a>
    <?php endif; ?>
  </div>
</div>
