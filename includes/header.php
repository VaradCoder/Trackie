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

<!-- Global universal quick-add modal — one title field + a type switcher that
     swaps the field set below it. Each type posts straight to its own
     module's existing add/log action (Trackie.QuickAdd in app.js). -->
<div id="quickAddModal" class="modal-backdrop hidden" role="dialog" aria-label="Quick add">
  <div class="modal-box" style="max-width:440px">
    <div class="modal-header">
      <span class="modal-title" id="quickAddTitleBar"><i class="fas fa-bolt" style="color:var(--accent);margin-right:.375rem"></i>Quick Add</span>
      <button class="btn btn-icon btn-ghost btn-sm" data-close-modal="quickAddModal" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <div class="filter-tabs" id="quickAddTypeTabs" role="tablist" style="margin-bottom:var(--sp-3)">
        <button type="button" class="filter-tab active" data-qa-type="todo"    role="tab" aria-selected="true"><i class="fas fa-check-square"></i> Todo</button>
        <button type="button" class="filter-tab"        data-qa-type="habit"  role="tab" aria-selected="false"><i class="fas fa-heart"></i> Habit</button>
        <button type="button" class="filter-tab"        data-qa-type="goal"   role="tab" aria-selected="false"><i class="fas fa-bullseye"></i> Goal</button>
        <button type="button" class="filter-tab"        data-qa-type="study"  role="tab" aria-selected="false"><i class="fas fa-book-open"></i> Study</button>
        <button type="button" class="filter-tab"        data-qa-type="workout" role="tab" aria-selected="false"><i class="fas fa-dumbbell"></i> Workout</button>
      </div>

      <div class="form-group">
        <div style="position:relative">
          <input id="quickAddTitle" class="form-input" placeholder="What needs to be done?"
                 autocomplete="off" maxlength="255" style="padding-right:2.75rem">
          <button type="button" id="quickAddMic" class="quick-add-mic" title="Dictate (voice)" aria-label="Dictate with voice">
            <i class="fas fa-microphone"></i>
          </button>
        </div>
      </div>

      <!-- Todo fields -->
      <div class="quick-add-fields form-grid-2" data-qa-fields="todo">
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

      <!-- Habit fields -->
      <div class="quick-add-fields form-grid-2 hidden" data-qa-fields="habit">
        <div class="form-group" style="margin-bottom:0">
          <label for="quickAddHabitFreq" class="form-label">Frequency</label>
          <select id="quickAddHabitFreq" class="form-input">
            <option value="daily" selected>Daily</option>
            <option value="weekly">Weekly</option>
          </select>
        </div>
        <div class="form-group" style="margin-bottom:0">
          <label for="quickAddHabitColor" class="form-label">Color</label>
          <input id="quickAddHabitColor" class="form-input" type="color" value="#ef4444" style="height:2.5rem;padding:.25rem">
        </div>
      </div>

      <!-- Goal fields -->
      <div class="quick-add-fields form-grid-2 hidden" data-qa-fields="goal">
        <div class="form-group" style="margin-bottom:0">
          <label for="quickAddGoalTarget" class="form-label">Target value</label>
          <input id="quickAddGoalTarget" class="form-input" type="number" min="1" placeholder="e.g. 10">
        </div>
        <div class="form-group" style="margin-bottom:0">
          <label for="quickAddGoalDeadline" class="form-label">Deadline</label>
          <input id="quickAddGoalDeadline" class="form-input" type="date">
        </div>
      </div>

      <!-- Study fields -->
      <div class="quick-add-fields form-grid-2 hidden" data-qa-fields="study">
        <div class="form-group" style="margin-bottom:0">
          <label for="quickAddStudySubject" class="form-label">Subject</label>
          <input id="quickAddStudySubject" class="form-input" placeholder="e.g. Physics">
        </div>
        <div class="form-group" style="margin-bottom:0">
          <label for="quickAddStudyDue" class="form-label">Due</label>
          <input id="quickAddStudyDue" class="form-input" type="date">
        </div>
      </div>

      <!-- Workout fields -->
      <div class="quick-add-fields hidden" data-qa-fields="workout">
        <div class="form-grid-3">
          <div class="form-group" style="margin-bottom:0">
            <label for="quickAddWoSets" class="form-label">Sets</label>
            <input id="quickAddWoSets" class="form-input" type="number" min="1" value="3">
          </div>
          <div class="form-group" style="margin-bottom:0">
            <label for="quickAddWoReps" class="form-label">Reps</label>
            <input id="quickAddWoReps" class="form-input" type="number" min="1" value="10">
          </div>
          <div class="form-group" style="margin-bottom:0">
            <label for="quickAddWoWeight" class="form-label">Weight</label>
            <input id="quickAddWoWeight" class="form-input" type="number" min="0" step="0.5" placeholder="kg">
          </div>
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
