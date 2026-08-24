<?php
/**
 * Sidebar navigation — renders navTree() (includes/functions.php).
 * Requires $currentPage (string) to be set by the including page.
 *
 * This file is now purely a renderer: the nav DATA lives in navTree() so the
 * bottom bar and command palette stay in sync automatically. To add or move a
 * destination, edit navTree() — never this file.
 */
$currentPage = $currentPage ?? '';
$sb_tree     = navTree();

// ── Identity + workspace status for the sidebar header ──────────────
// xpSummary() lives in gamification.php, which only ~5 pages require — so it
// is loaded here on demand rather than being silently absent on the other 23.
if (isLoggedIn() && !function_exists('xpSummary')
    && is_file(__DIR__ . '/gamification.php')) {
    require_once __DIR__ . '/gamification.php';
}
$sb_xp = (isLoggedIn() && function_exists('xpSummary')) ? xpSummary(currentUserId()) : null;

/* Daily score snapshot.
   Hooked here because the sidebar is the one include EVERY authenticated page
   renders, and shared hosting gives us no cron. maybeRecordSnapshot() throttles
   itself to once per 10 minutes per session, always writes once on the first
   request of a new day, and swallows its own errors — telemetry must never be
   able to break the page it measures. */
if (isLoggedIn() && function_exists('maybeRecordSnapshot')) {
    maybeRecordSnapshot(currentUserId());
}

$sb_streak = null;
if (isLoggedIn()) {
    try {
        // habitStreaks() is the single definition of a habit streak, so the
        // number in the sidebar can never disagree with the dashboard's.
        $sb_streak = habitStreaks(currentUserId())['current'] ?? null;
    } catch (Throwable $e) { $sb_streak = null; }
}

$sb_pic = $_SESSION['profile_pic'] ?? '';
$sb_pic = ($sb_pic && file_exists(ROOT_PATH . '/' . $sb_pic))
    ? APP_BASE . '/' . $sb_pic
    : APP_BASE . '/assets/images/default-user.png';
?>

<div id="sidebar-overlay" class="sidebar-overlay"></div>

<aside id="sidebar" class="sidebar">
  <!-- Brand -->
  <a href="<?= APP_BASE ?>/pages/dashboard.php" class="sidebar-brand">
    <img src="<?= APP_BASE ?>/assets/images/logo.png" alt="" class="sidebar-brand-logo"
         width="28" height="28" decoding="async">
    <span class="sidebar-brand-name">Trackie</span>
  </a>

  <!-- Identity: whose workspace this is, plus level/streak at a glance -->
  <?php if (isLoggedIn()): ?>
    <a href="<?= APP_BASE ?>/pages/profile.php" class="sidebar-identity">
      <img src="<?= h($sb_pic) ?>" alt="" width="36" height="36" loading="lazy" decoding="async">
      <div style="min-width:0">
        <div class="sidebar-identity-name"><?= h($_SESSION['user_name'] ?? 'You') ?></div>
        <div class="sidebar-identity-meta">
          <?php if ($sb_xp): ?>
            <span><i class="fas fa-bolt" style="color:var(--accent)" aria-hidden="true"></i>Level <?= (int)$sb_xp['level'] ?></span>
          <?php endif; ?>
          <?php if ($sb_streak !== null): ?>
            <span><i class="fas fa-fire" style="color:#f59e0b" aria-hidden="true"></i><?= (int)$sb_streak ?>d</span>
          <?php endif; ?>
        </div>
      </div>
    </a>
  <?php endif; ?>

  <!-- Nav -->
  <nav class="sidebar-nav" id="sidebarNav" aria-label="Main navigation">
    <?php foreach ($sb_tree as $sb_group):
      if (empty($sb_group['items'])) continue;              // never render an empty section
      $sb_hasHeading = $sb_group['heading'] !== null;
      // A group containing the current page always starts expanded, whatever
      // the user's stored preference — never hide the page you're looking at.
      $sb_holdsCurrent = false;
      foreach ($sb_group['items'] as $sb_i) {
        if ($currentPage === $sb_i['page']) { $sb_holdsCurrent = true; break; }
      }
    ?>
      <?php if ($sb_hasHeading): ?>
        <button type="button"
                class="sidebar-group-toggle"
                data-nav-group="<?= h($sb_group['key']) ?>"
                data-holds-current="<?= $sb_holdsCurrent ? '1' : '0' ?>"
                aria-expanded="true"
                aria-controls="navgroup-<?= h($sb_group['key']) ?>">
          <span class="sidebar-label"><?= h($sb_group['heading']) ?></span>
          <i class="fas fa-chevron-down sidebar-group-chevron" aria-hidden="true"></i>
        </button>
      <?php endif; ?>

      <div class="sidebar-group-items"
           id="navgroup-<?= h($sb_group['key']) ?>"
           <?= $sb_hasHeading ? '' : 'data-no-heading="1"' ?>>
        <?php foreach ($sb_group['items'] as $sb_item):
          $sb_active = $currentPage === $sb_item['page'];
        ?>
          <a href="<?= APP_BASE ?>/pages/<?= h($sb_item['href']) ?>"
             class="nav-item <?= $sb_active ? 'active' : '' ?>"
             <?= $sb_active ? 'aria-current="page"' : '' ?>
             data-nav-label="<?= h($sb_item['label']) ?>">
            <i class="fas <?= h($sb_item['icon']) ?>" aria-hidden="true"></i>
            <span class="nav-item-text"><?= h($sb_item['label']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </nav>

  <!-- Sidebar footer: quick add + workspace status + log out.
       Identity (avatar/name) deliberately stays in the topbar user chip —
       duplicating it here would cost vertical space for no new information. -->
  <div class="sidebar-footer">
    <button type="button" class="sidebar-quickadd" id="sidebarQuickAdd">
      <i class="fas fa-plus" aria-hidden="true"></i>
      <span class="nav-item-text">Quick add</span>
      <kbd class="sidebar-kbd">N</kbd>
    </button>

    <?php // Level/streak live in the identity header above — not duplicated here.
          // Theme toggle appears here only on mobile, where it's hidden from the
          // topbar to de-cramp the action row. Multiple [data-toggle-theme]
          // elements are supported — app.js binds all of them. ?>
    <button type="button" class="nav-item sidebar-theme" data-toggle-theme
            aria-label="Toggle dark mode">
      <i class="fas fa-moon" data-theme-icon aria-hidden="true"></i>
      <span class="nav-item-text">Theme</span>
    </button>

    <a href="<?= APP_BASE ?>/pages/logout.php" class="nav-item sidebar-logout" data-no-spa>
      <i class="fas fa-sign-out-alt" aria-hidden="true"></i>
      <span class="nav-item-text">Log out</span>
    </a>
  </div>
</aside>
