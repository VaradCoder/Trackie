<?php
/**
 * Topbar — include after sidebar.php.
 * Requires $pageTitle + DB connection available (for notification count).
 */
$profilePic = $_SESSION['profile_pic'] ?? '';
$picSrc = $profilePic && file_exists(ROOT_PATH . '/' . $profilePic)
    ? APP_BASE . '/' . $profilePic
    : APP_BASE . '/assets/images/default-user.png';

// Safe — returns 0 if notifications table doesn't exist yet
try {
    $unreadCount = isLoggedIn() ? unreadNotificationCount(currentUserId()) : 0;
} catch (Throwable $e) {
    $unreadCount = 0;
}
?>

<header class="topbar">
  <!-- Hamburger (all screen sizes) -->
  <button class="topbar-hamburger" data-toggle-sidebar aria-label="Toggle menu" aria-expanded="false">
    <span class="hb-line"></span><span class="hb-line"></span><span class="hb-line"></span>
  </button>

  <!-- Brand logo (links home; brand is hidden while sidebar is closed) -->
  <a href="<?= APP_BASE ?>/pages/dashboard.php" class="topbar-brand" aria-label="Trackie home">
    <img src="<?= APP_BASE ?>/assets/images/logo.png" alt="Trackie logo"
         width="32" height="32" decoding="async">
  </a>

  <!-- Page identity: icon + title, so the header states where you are -->
  <?php $tbIcon = navIconFor($currentPage ?? ''); ?>
  <span class="topbar-title">
    <?php if ($tbIcon): ?>
      <i class="fas <?= h($tbIcon) ?> topbar-title-icon" aria-hidden="true"></i>
    <?php endif; ?>
    <span class="topbar-title-text"><?= h($pageTitle ?? 'Dashboard') ?></span>
  </span>

  <!-- Global search trigger (opens search overlay) -->
  <button class="topbar-btn search-trigger" id="searchTrigger"
          aria-label="Search (Ctrl+K)" title="Search — Ctrl+K">
    <i class="fas fa-search" aria-hidden="true"></i>
    <span class="search-trigger-hint">Search…</span>
    <kbd class="search-trigger-kbd">Ctrl K</kbd>
  </button>

  <!-- Actions -->
  <div class="topbar-actions">
    <!-- Quick add todo (global) -->
    <button class="topbar-btn quick-add-btn" id="quickAddBtn"
            aria-label="Quick add todo" title="Quick add todo (N)">
      <i class="fas fa-plus"></i>
    </button>

    <!-- Notification bell -->
    <div class="notif-wrap" id="notifWrap">
      <button class="topbar-btn notif-btn" id="notifBtn"
              aria-label="Notifications" aria-expanded="false" aria-haspopup="true">
        <i class="fas fa-bell"></i>
        <?php if ($unreadCount > 0): ?>
          <span class="notif-badge" id="notifBadge"><?= min($unreadCount, 99) ?></span>
        <?php else: ?>
          <span class="notif-badge hidden" id="notifBadge"></span>
        <?php endif; ?>
      </button>

      <!-- Notification dropdown -->
      <div class="notif-dropdown hidden" id="notifDropdown" role="dialog" aria-label="Notifications">
        <div class="notif-header">
          <span class="notif-header-title">Notifications</span>
          <button class="notif-mark-all" id="notifMarkAll" title="Mark all as read">
            <i class="fas fa-check-double"></i> All read
          </button>
        </div>
        <div class="notif-list" id="notifList">
          <div class="notif-loading">
            <i class="fas fa-spinner fa-spin"></i> Loading…
          </div>
        </div>
      </div>
    </div>

    <!-- Theme toggle -->
    <button class="topbar-btn" data-toggle-theme aria-label="Toggle dark mode" title="Toggle dark mode">
      <i class="fas fa-moon" data-theme-icon></i>
    </button>

    <!-- User chip -->
    <a href="<?= APP_BASE ?>/pages/profile.php" class="user-chip" id="topbarUserChip">
      <img src="<?= h($picSrc) ?>" alt="<?= h($_SESSION['user_name'] ?? '') ?>"
           width="28" height="28" loading="lazy" decoding="async">
      <span><?= h($_SESSION['user_name'] ?? 'User') ?></span>
    </a>
  </div>
</header>

<!-- Global search overlay -->
<div class="search-overlay hidden" id="searchOverlay" role="search">
  <div class="search-box">
    <div class="search-input-wrap">
      <i class="fas fa-search search-icon" aria-hidden="true"></i>
      <?php /* combobox + aria-activedescendant: the input keeps DOM focus
               while arrow keys move a virtual cursor through the listbox.
               Without activedescendant a screen reader announced nothing as
               the user arrowed through results. */ ?>
      <input type="text" class="search-input" id="searchInput"
             placeholder="Search or jump to… (type a command)"
             autocomplete="off" aria-label="Command palette and global search"
             role="combobox" aria-expanded="false" aria-autocomplete="list"
             aria-controls="searchResults" aria-haspopup="listbox">
      <kbd class="search-esc-hint">ESC</kbd>
    </div>
    <div class="search-results" id="searchResults" role="listbox"
         aria-label="Search results"></div>
  </div>
  <div class="search-backdrop" id="searchBackdrop"></div>
</div>

<!-- Mobile bottom navigation (hidden on desktop).
     Rendered from navPrimary() so it can never drift from the sidebar — this
     used to be four hardcoded links that had to be hand-synced. The FAB is
     inserted at the midpoint so the bar stays visually balanced regardless of
     how many primary destinations navTree() declares. -->
<?php
$bnCur   = $currentPage ?? '';
$bnItems = navPrimary();
$bnMid   = (int)ceil(count($bnItems) / 2);
?>
<nav class="bottom-nav" aria-label="Primary">
  <?php foreach ($bnItems as $bnIdx => $bnItem): ?>
    <?php if ($bnIdx === $bnMid): ?>
      <button class="bottom-nav-fab" id="bottomNavFab" aria-label="Quick add todo">
        <i class="fas fa-plus" aria-hidden="true"></i>
      </button>
    <?php endif; ?>
    <?php $bnActive = $bnCur === $bnItem['page']; ?>
    <a href="<?= APP_BASE ?>/pages/<?= h($bnItem['href']) ?>"
       class="bottom-nav-item <?= $bnActive ? 'active' : '' ?>"
       <?= $bnActive ? 'aria-current="page"' : '' ?>>
      <i class="fas <?= h($bnItem['icon']) ?>" aria-hidden="true"></i>
      <span><?= h($bnItem['short'] ?? $bnItem['label']) ?></span>
    </a>
  <?php endforeach; ?>
</nav>

<!-- Global quick-add todo modal -->
<div id="quickAddModal" class="modal-backdrop hidden" role="dialog" aria-label="Quick add todo">
  <div class="modal-box" style="max-width:420px">
    <div class="modal-header">
      <span class="modal-title"><i class="fas fa-bolt" style="color:var(--accent);margin-right:.375rem"></i>Quick Add Todo</span>
      <button class="btn btn-icon btn-ghost btn-sm" data-close-modal="quickAddModal" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <div style="position:relative">
          <input id="quickAddTitle" class="form-input" placeholder="What needs to be done?"
                 autocomplete="off" maxlength="255" style="padding-right:2.75rem">
          <button type="button" id="quickAddMic" class="quick-add-mic" title="Dictate task (voice)" aria-label="Dictate task with voice">
            <i class="fas fa-microphone"></i>
          </button>
        </div>
      </div>
      <div class="form-grid-2">
        <div class="form-group" style="margin-bottom:0">
          <label for="quickAddDue" class="form-label">Due</label>
          <input id="quickAddDue" class="form-input" type="date">
        </div>
        <div class="form-group" style="margin-bottom:0">
          <label for="quickAddPriority" class="form-label">Priority</label>
          <select id="quickAddPriority" class="form-input">
            <option value="low">Low</option>
            <option value="medium" selected>Medium</option>
            <option value="high">High</option>
          </select>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary btn-sm" data-close-modal="quickAddModal">Cancel</button>
      <button class="btn btn-primary btn-sm" id="quickAddSave">
        <i class="fas fa-plus"></i> Add
      </button>
    </div>
  </div>
</div>
