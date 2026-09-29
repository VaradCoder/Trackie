<?php
require_once 'config/app.php';
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

// Signed in (or restorable from remember-me) → straight to the app.
if (tryRememberLogin()) {
    redirect(APP_BASE . '/pages/dashboard.php');
}

// Everyone else gets the public landing page. Everything listed here exists
// in the app today — no invented numbers, testimonials or "coming soon".
$pageTitle       = 'Track your habits, goals & hobbies in one place';
$metaDescription = 'Trackie is a free life tracker: habits, todos, goals, focus timer, fitness, finance, reading, gaming and more — with streaks, XP, a calendar and a weekly review. Works as an installable app.';
$metaIndex       = true;
$bodyClass       = 'lp-body';

$areas = [
    ['Plan',     'fa-list-check', '#ef4444', 'Today view, todos with subtasks, habits on your own schedule, goals, routines, a study plan, a focus timer, reminders and one calendar that shows all of it.'],
    ['Life',     'fa-heart-pulse', '#22c55e', 'Workout logging with personal records and body-weight trend, sports sessions with your win–loss record, guided meditation, and finance with budgets, savings goals and CSV export.'],
    ['Hobbies',  'fa-palette', '#8b5cf6', 'Reading, gaming, photography, coding (with GitHub), cooking and meal plans, writing with daily word goals, art practice, gardening care reminders and Spotify listening.'],
    ['Progress', 'fa-chart-line', '#f59e0b', 'Everything you log feeds one activity history: streaks per area, XP and levels, achievements, 26-week heatmap, cross-area insights and a weekly review.'],
];
$points = [
    ['fa-lock', 'Private by default', 'No ads, no third-party analytics, no selling data. Photos and art are stored privately and only shown to you.'],
    ['fa-mobile-screen', 'Install it', 'Add Trackie to your home screen — it works as an app on phone and desktop, with reminders.'],
    ['fa-plug', 'Connect what you use', 'Optional GitHub, Spotify and Google sign-in and sync. Nothing is connected unless you choose to.'],
];

require_once 'includes/head.php';
?>
<main class="lp" id="page-main">
  <header class="lp-nav">
    <a class="lp-brand" href="<?= APP_BASE ?>/"><img src="<?= APP_BASE ?>/assets/images/icon-192.png" alt="" width="32" height="32"> Trackie</a>
    <nav class="lp-nav-links" aria-label="Account">
      <a href="<?= APP_BASE ?>/pages/auth.php?tab=login" class="lp-link">Sign in</a>
      <a href="<?= APP_BASE ?>/pages/auth.php?tab=register" class="btn btn-primary btn-sm">Get started</a>
    </nav>
  </header>

  <section class="lp-hero">
    <h1>Your whole life,<br><span>tracked in one place.</span></h1>
    <p class="lp-lead">Habits, todos, goals, workouts, money and every hobby you care about — with streaks and a weekly review that show you how it's actually going.</p>
    <div class="lp-cta">
      <a href="<?= APP_BASE ?>/pages/auth.php?tab=register" class="btn btn-primary">Create a free account</a>
      <a href="<?= APP_BASE ?>/pages/auth.php?tab=login" class="btn btn-secondary">I already have one</a>
    </div>
    <p class="lp-note">Free. No credit card. Delete your account and data any time.</p>
  </section>

  <section class="lp-areas" aria-label="What you can track">
    <?php foreach ($areas as [$name, $icon, $col, $text]): ?>
      <article class="lp-area">
        <div class="lp-area-icon" style="--c:<?= $col ?>"><i class="fas <?= $icon ?>"></i></div>
        <h2><?= $name ?></h2>
        <p><?= $text ?></p>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="lp-points">
    <?php foreach ($points as [$icon, $title, $text]): ?>
      <div class="lp-point"><i class="fas <?= $icon ?>"></i><div><h3><?= $title ?></h3><p><?= $text ?></p></div></div>
    <?php endforeach; ?>
  </section>

  <section class="lp-final">
    <h2>Start with one habit. Grow from there.</h2>
    <a href="<?= APP_BASE ?>/pages/auth.php?tab=register" class="btn btn-primary">Get started — it's free</a>
  </section>

  <footer class="lp-foot">
    <span>© <?= date('Y') ?> Trackie</span>
    <a href="<?= APP_BASE ?>/pages/privacy.php">Privacy</a>
    <a href="<?= APP_BASE ?>/pages/terms.php">Terms</a>
  </footer>
</main>
</body>
</html>
