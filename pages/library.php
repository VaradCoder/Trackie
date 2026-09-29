<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Reading';
$currentPage = 'library';

if (!tableExists('books')) renderSetupNeeded('Library');
if (!tableExists('reading_sessions')) renderSetupNeeded('Reading');

require_once '../app/Modules/Reading/ReadingService.php';
$svc  = new ReadingService($uid);
$year = (int)date('Y');

$statusMeta = [
    'reading'  => ['label' => 'Reading',      'icon' => 'fa-book-open'],
    'want'     => ['label' => 'Want to read', 'icon' => 'fa-bookmark'],
    'paused'   => ['label' => 'Paused',       'icon' => 'fa-pause'],
    'finished' => ['label' => 'Finished',     'icon' => 'fa-check'],
];

$tabs = ['overview' => 'Overview', 'library' => 'Library', 'sessions' => 'Sessions',
         'notes' => 'Notes & Quotes', 'goals' => 'Goals', 'stats' => 'Statistics'];
// Old links (?shelf=reading) still land on the Library tab with that filter.
$shelf = isset($statusMeta[$_GET['shelf'] ?? '']) ? $_GET['shelf'] : 'all';
$tab   = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : (isset($_GET['shelf']) ? 'library' : 'overview');

$books = array_map([ReadingService::class, 'present'],
    fetchAll("SELECT * FROM books WHERE user_id=? ORDER BY FIELD(status,'reading','paused','want','finished'), created_at DESC", [$uid]));
$counts = ['all' => count($books)];
foreach ($statusMeta as $k => $_) $counts[$k] = count(array_filter($books, static fn($b) => $b['status'] === $k));

$reading  = $svc->currentlyReading();
$totals   = $svc->yearTotals($year);
$goal     = $svc->goal($year);
$streak   = $svc->streak();
$days     = $svc->recentDays(7);
$sessions = $svc->recentSessions(40);
$notes    = $svc->notes();

function rdMins(int $m): string {
    if ($m < 60) return "{$m}m";
    return intdiv($m, 60) . 'h' . ($m % 60 ? ' ' . ($m % 60) . 'm' : '');
}
function rdCover(array $b, string $cls = 'rd-cover'): string {
    if (!empty($b['cover_url'])) {
        return '<img class="' . $cls . '" src="' . h($b['cover_url']) . '" alt="" loading="lazy" onerror="this.style.visibility=\'hidden\'">';
    }
    return '<div class="' . $cls . ' rd-cover-empty">' . h(mb_strtoupper(mb_substr($b['title'], 0, 1))) . '</div>';
}
function rdDay(string $d): string {
    if ($d === date('Y-m-d')) return 'Today';
    if ($d === date('Y-m-d', strtotime('-1 day'))) return 'Yesterday';
    return formatDate($d, substr($d, 0, 4) === date('Y') ? 'D, M j' : 'M j, Y');
}

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<div class="rd-head">
  <h1><i class="fas fa-book" style="color:var(--accent)"></i> Reading</h1>
  <div class="rd-head-actions">
    <button class="btn btn-secondary btn-sm" onclick="openSession()" <?= $books ? '' : 'disabled' ?>><i class="fas fa-stopwatch"></i> Log session</button>
    <button class="btn btn-primary btn-sm" onclick="openAddBook()"><i class="fas fa-plus"></i> Add book</button>
  </div>
</div>

<div id="rdTimerBanner" class="rd-timer-banner hidden" role="status">
  <i class="fas fa-stopwatch"></i> <span>Reading session running · <b id="rdBannerTime">0:00</b> · <span id="rdBannerBook"></span></span>
  <button class="btn btn-primary btn-sm" onclick="openSession()">Open</button>
</div>

<div class="filter-tabs" style="margin-bottom:1.25rem" id="rdTabs" role="tablist" aria-label="Reading sections">
  <?php foreach ($tabs as $k => $label): ?>
    <button class="filter-tab<?= $tab === $k ? ' active' : '' ?>" data-tab="<?= $k ?>" role="tab" id="rdtab-<?= $k ?>"
            aria-controls="rd-<?= $k ?>" aria-selected="<?= $tab === $k ? 'true' : 'false' ?>" <?= $tab === $k ? '' : 'tabindex="-1"' ?>><?= $label ?></button>
  <?php endforeach; ?>
</div>

<!-- ── Overview ─────────────────────────────────────────────── -->
<div id="rd-overview" class="rd-panel<?= $tab === 'overview' ? '' : ' hidden' ?>" role="tabpanel" aria-labelledby="rdtab-overview">
<div id="rdOverviewWrap">
  <div class="grid-stats" style="margin-bottom:1.25rem">
    <div class="stat-card">
      <div class="stat-val">🔥 <?= (int)$streak['current'] ?></div>
      <div class="stat-label">Day reading streak<?= $streak['best'] > $streak['current'] ? ' · best ' . (int)$streak['best'] : '' ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-val"><?= $totals['books'] ?><?= $goal['books_target'] ? '<small> / ' . (int)$goal['books_target'] . '</small>' : '' ?></div>
      <div class="stat-label">Books finished in <?= $year ?></div>
    </div>
    <div class="stat-card"><div class="stat-val"><?= number_format($totals['pages']) ?></div><div class="stat-label">Pages logged in <?= $year ?></div></div>
    <div class="stat-card"><div class="stat-val"><?= rdMins($totals['minutes']) ?></div><div class="stat-label">Reading time in <?= $year ?></div></div>
  </div>

  <div class="fit-section-head"><h2 class="hb-h2">Currently reading</h2></div>
  <?php if (!$reading): ?>
    <div class="card"><div class="empty-state">
      <div class="empty-state-icon"><i class="fas fa-book-open"></i></div>
      <div class="empty-state-title">Nothing on the Reading shelf</div>
      <p><?= $books ? 'Pick a book from your Library and log a session to start.' : 'Add a book, then log your first reading session.' ?></p>
      <button class="btn btn-primary" style="margin-top:.75rem" onclick="<?= $books ? "switchRdTab('library')" : 'openAddBook()' ?>"><?= $books ? 'Open Library' : '<i class="fas fa-plus"></i> Add a book' ?></button>
    </div></div>
  <?php else: ?>
    <div class="rd-current-list">
    <?php foreach ($reading as $b): ?>
      <div class="card rd-current">
        <?= rdCover($b, 'rd-cover-lg') ?>
        <div class="rd-current-body">
          <div class="rd-title"><?= h($b['title']) ?></div>
          <?php if ($b['author']): ?><div class="rd-author"><?= h($b['author']) ?></div><?php endif; ?>
          <?php if ($b['progress'] !== null): ?>
            <div class="rd-pages">Page <?= (int)$b['current_page'] ?> / <?= (int)$b['pages_total'] ?> · <b><?= $b['progress'] ?>%</b></div>
            <div class="hb-progress rd-progress-lg"><span style="width:<?= $b['progress'] ?>%"></span></div>
          <?php else: ?>
            <div class="rd-pages"><?= $b['current_page'] ? 'Page ' . (int)$b['current_page'] . ' · ' : '' ?><a href="#" onclick="openEditBook(<?= (int)$b['id'] ?>);return false">Add page count</a> to see progress</div>
          <?php endif; ?>
          <div class="rd-last">
            <?= $b['last_session'] ? 'Last session: ' . rdDay($b['last_session']) . ' · ' . rdMins((int)$b['last_minutes']) : 'No sessions logged yet' ?>
          </div>
          <div class="rd-current-actions">
            <button class="btn btn-primary btn-sm" onclick="openSession(<?= (int)$b['id'] ?>, true)"><i class="fas fa-play"></i> Continue reading</button>
            <button class="btn btn-secondary btn-sm" onclick="openBook(<?= (int)$b['id'] ?>)">Details</button>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="card card-body" style="margin-top:1.25rem">
    <div class="fit-card-label">Reading activity · last 7 days</div>
    <?php $max = max(1, ...array_column($days, 'minutes')); ?>
    <?php if (!array_sum(array_column($days, 'minutes'))): ?>
      <p class="hb-empty-line">No sessions in the last 7 days.</p>
    <?php endif; ?>
    <div class="hb-bars">
      <?php foreach ($days as $d): ?>
        <div class="hb-bar" title="<?= h(formatDate($d['date'], 'D M j')) ?>: <?= rdMins($d['minutes']) ?>">
          <span style="height:<?= round($d['minutes'] / $max * 100) ?>%"></span><em><?= date('D', strtotime($d['date'])) ?></em>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
</div>

<!-- ── Library ──────────────────────────────────────────────── -->
<div id="rd-library" class="rd-panel<?= $tab === 'library' ? '' : ' hidden' ?>" role="tabpanel" aria-labelledby="rdtab-library">
  <div class="rd-toolbar">
    <div class="rd-search"><i class="fas fa-search"></i><input id="rdSearch" class="form-input" placeholder="Search your books…" aria-label="Search your books"></div>
    <div class="rd-view" role="group" aria-label="Layout">
      <button class="btn btn-icon btn-ghost btn-sm" data-view="grid" aria-label="Grid view" title="Grid"><i class="fas fa-grip"></i></button>
      <button class="btn btn-icon btn-ghost btn-sm" data-view="list" aria-label="List view" title="List"><i class="fas fa-list"></i></button>
    </div>
  </div>
  <div id="libraryListWrap">
  <div class="filter-tabs" style="margin-bottom:1rem" id="rdShelves">
    <button class="filter-tab<?= $shelf === 'all' ? ' active' : '' ?>" data-shelf="all">All <small><?= $counts['all'] ?></small></button>
    <?php foreach ($statusMeta as $k => $m): ?>
      <button class="filter-tab<?= $shelf === $k ? ' active' : '' ?>" data-shelf="<?= $k ?>"><?= $m['label'] ?> <small><?= $counts[$k] ?></small></button>
    <?php endforeach; ?>
  </div>
  <?php if (!$books): ?>
    <div class="card"><div class="empty-state">
      <div class="empty-state-icon"><i class="fas fa-book"></i></div>
      <div class="empty-state-title">Your library is empty</div>
      <p>Search Open Library or add a book by hand.</p>
      <button class="btn btn-primary" style="margin-top:.75rem" onclick="openAddBook()"><i class="fas fa-plus"></i> Add your first book</button>
    </div></div>
  <?php else: ?>
    <div class="rd-books" id="rdBooks">
      <?php foreach ($books as $b): ?>
        <div class="rd-book" id="book-<?= (int)$b['id'] ?>" data-status="<?= h($b['status']) ?>"
             data-search="<?= h(mb_strtolower($b['title'] . ' ' . $b['author'])) ?>">
          <button class="rd-book-cover" onclick="openBook(<?= (int)$b['id'] ?>)" aria-label="Open <?= h($b['title']) ?>"><?= rdCover($b) ?></button>
          <div class="rd-book-body">
            <button class="rd-title rd-link" onclick="openBook(<?= (int)$b['id'] ?>)"><?= h($b['title']) ?></button>
            <?php if ($b['author']): ?><div class="rd-author"><?= h($b['author']) ?></div><?php endif; ?>
            <div class="rd-book-meta">
              <?php if ($b['status'] === 'finished'): ?>
                <span class="rd-badge rd-badge-done"><i class="fas fa-check"></i> Finished<?= $b['finished_at'] ? ' ' . h(formatDate($b['finished_at'], 'M Y')) : '' ?></span>
              <?php elseif ($b['progress'] !== null && $b['current_page'] > 0): ?>
                <span class="rd-pct"><?= $b['progress'] ?>% complete</span>
              <?php endif; ?>
            </div>
            <?php if ($b['progress'] !== null && $b['status'] !== 'finished' && $b['current_page'] > 0): ?>
              <div class="hb-progress"><span style="width:<?= $b['progress'] ?>%"></span></div>
            <?php endif; ?>
            <div class="rd-stars" id="stars-<?= (int)$b['id'] ?>" aria-label="Rating">
              <?php for ($i = 1; $i <= 5; $i++): ?>
                <button class="rd-star" onclick="rateBook(<?= (int)$b['id'] ?>, <?= $i ?>)" aria-label="Rate <?= $i ?> star<?= $i > 1 ? 's' : '' ?>"><i class="<?= $b['rating'] >= $i ? 'fas' : 'far' ?> fa-star"></i></button>
              <?php endfor; ?>
            </div>
            <div class="rd-book-actions">
              <select class="form-input" aria-label="Shelf" onchange="setBookStatus(<?= (int)$b['id'] ?>, this.value)">
                <?php foreach ($statusMeta as $k => $m): ?>
                  <option value="<?= $k ?>" <?= $b['status'] === $k ? 'selected' : '' ?>><?= $m['label'] ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-icon btn-ghost btn-sm" onclick="openEditBook(<?= (int)$b['id'] ?>)" aria-label="Edit book" title="Edit"><i class="fas fa-pen"></i></button>
              <button class="btn btn-icon btn-ghost btn-sm" style="color:var(--accent)" onclick="deleteBook(<?= (int)$b['id'] ?>)" aria-label="Delete book" title="Delete"><i class="fas fa-trash"></i></button>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="card card-body hb-empty-line hidden" id="rdNoMatch">No books match.</div>
  <?php endif; ?>
  </div>
</div>

<!-- ── Sessions ─────────────────────────────────────────────── -->
<div id="rd-sessions" class="rd-panel<?= $tab === 'sessions' ? '' : ' hidden' ?>" role="tabpanel" aria-labelledby="rdtab-sessions">
<div id="rdSessionsWrap">
  <?php if (!$sessions): ?>
    <div class="card"><div class="empty-state">
      <div class="empty-state-icon"><i class="fas fa-stopwatch"></i></div>
      <div class="empty-state-title">No reading sessions yet</div>
      <p>Time a session with the timer, or log one you already did.</p>
      <?php if ($books): ?><button class="btn btn-primary" style="margin-top:.75rem" onclick="openSession()"><i class="fas fa-stopwatch"></i> Log a session</button><?php endif; ?>
    </div></div>
  <?php else: ?>
    <div class="card rd-session-list">
      <?php $lastDay = null; foreach ($sessions as $s): ?>
        <?php if ($s['session_date'] !== $lastDay): $lastDay = $s['session_date']; ?>
          <div class="rd-day"><?= rdDay($s['session_date']) ?></div>
        <?php endif; ?>
        <div class="rd-session" id="session-<?= (int)$s['id'] ?>">
          <?= rdCover($s, 'rd-cover-xs') ?>
          <div class="rd-session-body">
            <button class="rd-title rd-link" onclick="openBook(<?= (int)$s['book_id'] ?>)"><?= h($s['title']) ?></button>
            <div class="rd-author">
              <?= rdMins((int)$s['minutes']) ?>
              <?php if ($s['end_page'] !== null): ?> · <?= (int)$s['pages'] ?> page<?= (int)$s['pages'] === 1 ? '' : 's' ?> (p. <?= (int)$s['start_page'] ?>→<?= (int)$s['end_page'] ?>)<?php endif; ?>
            </div>
            <?php if ($s['note']): ?><div class="rd-session-note"><?= h($s['note']) ?></div><?php endif; ?>
          </div>
          <button class="btn btn-icon btn-ghost btn-sm" onclick="deleteSession(<?= (int)$s['id'] ?>)" aria-label="Delete session" title="Delete"><i class="fas fa-trash"></i></button>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="hb-foot">Deleting a session removes it from your stats; it doesn't move the book's current page back.</p>
  <?php endif; ?>
</div>
</div>

<!-- ── Notes & quotes ───────────────────────────────────────── -->
<div id="rd-notes" class="rd-panel<?= $tab === 'notes' ? '' : ' hidden' ?>" role="tabpanel" aria-labelledby="rdtab-notes">
<div id="rdNotesWrap">
  <div class="rd-toolbar">
    <div class="filter-tabs" id="rdNoteFilter">
      <button class="filter-tab active" data-kind="all">All</button>
      <button class="filter-tab" data-kind="note">Notes</button>
      <button class="filter-tab" data-kind="quote">Quotes</button>
    </div>
    <?php if ($books): ?><button class="btn btn-primary btn-sm" onclick="openNote()"><i class="fas fa-plus"></i> Add</button><?php endif; ?>
  </div>
  <?php if (!$notes): ?>
    <div class="card"><div class="empty-state">
      <div class="empty-state-icon"><i class="fas fa-quote-left"></i></div>
      <div class="empty-state-title">No notes or quotes yet</div>
      <p>Save the lines worth remembering and your thoughts as you read.</p>
    </div></div>
  <?php else: ?>
    <div class="rd-notes" id="rdNotes">
      <?php foreach ($notes as $n): ?>
        <div class="card card-body rd-note rd-note-<?= h($n['kind']) ?>" data-kind="<?= h($n['kind']) ?>" id="note-<?= (int)$n['id'] ?>">
          <div class="rd-note-body"><?= nl2br(h($n['body'])) ?></div>
          <div class="rd-note-foot">
            <span><i class="fas <?= $n['kind'] === 'quote' ? 'fa-quote-left' : 'fa-note-sticky' ?>"></i>
              <button class="rd-link" onclick="openBook(<?= (int)$n['book_id'] ?>)"><?= h($n['title']) ?></button><?= $n['page'] ? ' · p. ' . (int)$n['page'] : '' ?> · <?= h(formatDate($n['created_at'], 'M j, Y')) ?></span>
            <button class="btn btn-icon btn-ghost btn-sm" onclick="deleteNote(<?= (int)$n['id'] ?>)" aria-label="Delete" title="Delete"><i class="fas fa-trash"></i></button>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
</div>

<!-- ── Goals ────────────────────────────────────────────────── -->
<div id="rd-goals" class="rd-panel<?= $tab === 'goals' ? '' : ' hidden' ?>" role="tabpanel" aria-labelledby="rdtab-goals">
<div id="rdGoalsWrap">
  <?php
    $dayOfYear = (int)date('z') + 1;
    $daysInYear = (int)date('L') ? 366 : 365;
    $goalRows = [
        ['label' => 'Books finished', 'done' => $totals['books'], 'target' => $goal['books_target'], 'fmt' => 'number_format'],
        ['label' => 'Pages logged',   'done' => $totals['pages'], 'target' => $goal['pages_target'], 'fmt' => 'number_format'],
    ];
  ?>
  <div class="hb-grid2">
    <?php foreach ($goalRows as $g): $pct = $g['target'] ? min(100, (int)round($g['done'] / $g['target'] * 100)) : null; ?>
      <div class="card card-body">
        <div class="fit-card-label"><?= $g['label'] ?> · <?= $year ?></div>
        <div class="rd-goal-num"><?= number_format($g['done']) ?><?php if ($g['target']): ?><small> / <?= number_format($g['target']) ?></small><?php endif; ?></div>
        <?php if ($g['target']): ?>
          <div class="hb-progress rd-progress-lg"><span style="width:<?= $pct ?>%"></span></div>
          <?php
            $expected = $g['target'] * $dayOfYear / $daysInYear;
            $diff = $g['done'] - $expected;
          ?>
          <p class="hb-foot"><?= $pct ?>% ·
            <?php if ($g['done'] >= $g['target']): ?>Goal reached 🎉
            <?php elseif ($diff >= 0): ?>On pace (<?= number_format(round($expected)) ?> expected by today)
            <?php else: ?><?= number_format(ceil(-$diff)) ?> behind the even pace for today<?php endif; ?>
          </p>
        <?php else: ?>
          <p class="hb-empty-line">No target set.</p>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="card card-body" style="margin-top:1rem">
    <div class="fit-card-label">Set your <?= $year ?> goal</div>
    <div class="rd-goal-form">
      <div class="form-group"><label class="form-label" for="goalBooks">Books</label><input id="goalBooks" type="number" min="1" max="1000" class="form-input" value="<?= h((string)($goal['books_target'] ?? '')) ?>" placeholder="e.g. 24"></div>
      <div class="form-group"><label class="form-label" for="goalPages">Pages</label><input id="goalPages" type="number" min="1" max="1000000" class="form-input" value="<?= h((string)($goal['pages_target'] ?? '')) ?>" placeholder="Optional"></div>
      <button class="btn btn-primary btn-sm" onclick="saveGoal()"><i class="fas fa-save"></i> Save goal</button>
    </div>
    <p class="hb-foot">Books count when you move them to Finished. Pages count only from logged sessions. Leave a field empty to clear that target.</p>
  </div>
</div>
</div>

<!-- ── Statistics (loaded on demand) ───────────────────────── -->
<div id="rd-stats" class="rd-panel<?= $tab === 'stats' ? '' : ' hidden' ?>" role="tabpanel" aria-labelledby="rdtab-stats">
  <div id="rdStats"><div class="hb-loading"><i class="fas fa-spinner fa-spin"></i> Crunching your reading…</div></div>
</div>

<!-- ── Add book ─────────────────────────────────────────────── -->
<div id="addBookModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header"><span class="modal-title">Add Book</span><button class="btn btn-icon btn-ghost btn-sm" data-close-modal="addBookModal" aria-label="Close dialog">&times;</button></div>
    <div class="modal-body">
      <div class="form-group">
        <label for="bookSearch" class="form-label">Search Open Library</label>
        <div class="rd-search"><i class="fas fa-search"></i><input id="bookSearch" class="form-input" placeholder="Title, author or ISBN" autocomplete="off"></div>
        <div id="bookResults" class="rd-results" role="listbox" aria-label="Search results"></div>
      </div>
      <div id="bookPicked" class="rd-picked hidden"></div>
      <div class="form-group"><label for="bookTitle" class="form-label">Title <span style="color:var(--accent)">*</span></label><input id="bookTitle" class="form-input" placeholder="e.g. Atomic Habits"></div>
      <div class="rd-form-row">
        <div class="form-group"><label for="bookAuthor" class="form-label">Author</label><input id="bookAuthor" class="form-input" placeholder="Optional"></div>
        <div class="form-group"><label for="bookPages" class="form-label">Pages</label><input id="bookPages" type="number" min="1" max="20000" class="form-input" placeholder="Optional"></div>
      </div>
      <div class="form-group">
        <label for="bookStatus" class="form-label">Shelf</label>
        <select id="bookStatus" class="form-input">
          <?php foreach ($statusMeta as $k => $m): ?><option value="<?= $k ?>" <?= $k === 'want' ? 'selected' : '' ?>><?= $m['label'] ?></option><?php endforeach; ?>
        </select>
      </div>
      <p class="hb-foot" style="margin:0">Book details and covers from <a href="https://openlibrary.org" target="_blank" rel="noopener">Open Library</a>.</p>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary btn-sm" data-close-modal="addBookModal">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="saveBook()" id="saveBookBtn"><i class="fas fa-save"></i> Add</button>
    </div>
  </div>
</div>

<!-- ── Edit book ────────────────────────────────────────────── -->
<div id="editBookModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header"><span class="modal-title">Edit Book</span><button class="btn btn-icon btn-ghost btn-sm" data-close-modal="editBookModal" aria-label="Close dialog">&times;</button></div>
    <div class="modal-body">
      <input type="hidden" id="editId">
      <div class="form-group"><label for="editTitle" class="form-label">Title <span style="color:var(--accent)">*</span></label><input id="editTitle" class="form-input"></div>
      <div class="form-group"><label for="editAuthor" class="form-label">Author</label><input id="editAuthor" class="form-input"></div>
      <div class="rd-form-row">
        <div class="form-group"><label for="editPages" class="form-label">Total pages</label><input id="editPages" type="number" min="1" max="20000" class="form-input"></div>
        <div class="form-group"><label for="editCurrent" class="form-label">Current page</label><input id="editCurrent" type="number" min="0" class="form-input"></div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary btn-sm" data-close-modal="editBookModal">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="saveEditBook()"><i class="fas fa-save"></i> Save</button>
    </div>
  </div>
</div>

<!-- ── Reading session ──────────────────────────────────────── -->
<div id="sessionModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header"><span class="modal-title"><i class="fas fa-stopwatch"></i> Reading session</span><button class="btn btn-icon btn-ghost btn-sm" data-close-modal="sessionModal" aria-label="Close dialog">&times;</button></div>
    <div id="rsForm">
      <div class="modal-body">
        <div class="form-group">
          <label for="rsBook" class="form-label">Book</label>
          <select id="rsBook" class="form-input">
            <?php foreach ($books as $b): if ($b['status'] === 'finished') continue; ?>
              <option value="<?= (int)$b['id'] ?>" data-page="<?= (int)$b['current_page'] ?>" data-total="<?= (int)$b['pages_total'] ?>"><?= h($b['title']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="rd-timer">
          <div class="rd-timer-time" id="rsTime" aria-live="off">00:00:00</div>
          <div class="rd-timer-btns">
            <button class="btn btn-primary btn-sm" id="rsToggle" onclick="rsToggle()"><i class="fas fa-play"></i> Start</button>
            <button class="btn btn-secondary btn-sm" onclick="rsReset()">Reset</button>
          </div>
        </div>
        <div class="rd-form-row">
          <div class="form-group"><label for="rsMinutes" class="form-label">Minutes</label><input id="rsMinutes" type="number" min="1" max="720" class="form-input"></div>
          <div class="form-group"><label for="rsEnd" class="form-label">Stopped at page</label><input id="rsEnd" type="number" min="0" class="form-input" placeholder="Optional"></div>
        </div>
        <p class="hb-foot" id="rsHint" style="margin:-.5rem 0 .75rem"></p>
        <div class="rd-form-row">
          <div class="form-group"><label for="rsDate" class="form-label">Date</label><input id="rsDate" type="date" class="form-input" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>"></div>
        </div>
        <div class="form-group"><label for="rsNote" class="form-label">Note</label><textarea id="rsNote" class="form-input" rows="2" maxlength="500" placeholder="Optional — a thought from this session"></textarea></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-sm" data-close-modal="sessionModal">Close</button>
        <button class="btn btn-primary btn-sm" id="rsSave" onclick="saveSession()"><i class="fas fa-check"></i> Finish session</button>
      </div>
    </div>
    <div id="rsDone" class="hidden"></div>
  </div>
</div>

<!-- ── Book detail ──────────────────────────────────────────── -->
<div id="bookModal" class="modal-backdrop hidden">
  <div class="modal-box rd-detail-box">
    <div class="modal-header"><span class="modal-title">Book</span><button class="btn btn-icon btn-ghost btn-sm" data-close-modal="bookModal" aria-label="Close dialog">&times;</button></div>
    <div class="modal-body" id="bookDetail"></div>
  </div>
</div>

<!-- ── Note / quote ─────────────────────────────────────────── -->
<div id="noteModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header"><span class="modal-title">Add note or quote</span><button class="btn btn-icon btn-ghost btn-sm" data-close-modal="noteModal" aria-label="Close dialog">&times;</button></div>
    <div class="modal-body">
      <div class="form-group">
        <label for="noteBook" class="form-label">Book</label>
        <select id="noteBook" class="form-input">
          <?php foreach ($books as $b): ?><option value="<?= (int)$b['id'] ?>"><?= h($b['title']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="rd-form-row">
        <div class="form-group"><label for="noteKind" class="form-label">Type</label>
          <select id="noteKind" class="form-input"><option value="note">Note</option><option value="quote">Quote</option></select></div>
        <div class="form-group"><label for="notePage" class="form-label">Page</label><input id="notePage" type="number" min="1" class="form-input" placeholder="Optional"></div>
      </div>
      <div class="form-group"><label for="noteBody" class="form-label">Text <span style="color:var(--accent)">*</span></label><textarea id="noteBody" class="form-input" rows="4" maxlength="5000"></textarea></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary btn-sm" data-close-modal="noteModal">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="saveNote()"><i class="fas fa-save"></i> Save</button>
    </div>
  </div>
</div>

</div>
<?php include '../includes/footer.php'; ?>

<script>
const API_BASE = '<?= APP_BASE ?>/api';
// rsBook / noteBook are refreshed too so newly added books appear in the pickers.
const RD_FRAGS = ['rdOverviewWrap', 'libraryListWrap', 'rdSessionsWrap', 'rdNotesWrap', 'rdGoalsWrap', 'rsBook', 'noteBook'];
const rdPost = data => Trackie.API.post(`${API_BASE}/library.php`, data);
function rdMins(m) { m = Math.round(m || 0); if (m < 60) return `${m}m`; const r = m % 60; return `${Math.floor(m / 60)}h${r ? ' ' + r + 'm' : ''}`; }
function rdCover(url, title, cls = 'rd-cover') {
  return /^https:\/\/covers\.openlibrary\.org\//.test(url || '')
    ? `<img class="${cls}" src="${escHtml(url)}" alt="" loading="lazy">`
    : `<div class="${cls} rd-cover-empty">${escHtml((title || '?').charAt(0).toUpperCase())}</div>`;
}
async function rdRefresh() {
  await Trackie.refreshFragments(RD_FRAGS);
  rdApplyFilters();
  rdStatsLoaded = false;
  if (rdCurrentTab() === 'stats') loadStats();
}

/* ── Tabs ─────────────────────────────────────────────────────── */
const RD_TABS = [...document.querySelectorAll('#rdTabs [data-tab]')].map(b => b.dataset.tab);
let rdStatsLoaded = false;
function rdCurrentTab() { return document.querySelector('#rdTabs [aria-selected="true"]')?.dataset.tab; }
function switchRdTab(tab) {
  if (!RD_TABS.includes(tab)) tab = 'overview';
  document.querySelectorAll('#rdTabs [data-tab]').forEach(b => {
    const on = b.dataset.tab === tab;
    b.classList.toggle('active', on); b.setAttribute('aria-selected', on ? 'true' : 'false'); b.tabIndex = on ? 0 : -1;
  });
  RD_TABS.forEach(t => document.getElementById(`rd-${t}`).classList.toggle('hidden', t !== tab));
  const url = new URL(location.href);
  url.searchParams.set('tab', tab); url.searchParams.delete('shelf');
  history.replaceState(history.state, '', url);
  if (tab === 'stats' && !rdStatsLoaded) loadStats();
}
document.getElementById('rdTabs').addEventListener('click', e => { const b = e.target.closest('[data-tab]'); if (b) switchRdTab(b.dataset.tab); });
document.getElementById('rdTabs').addEventListener('keydown', e => {
  if (!['ArrowLeft', 'ArrowRight'].includes(e.key)) return;
  const i = RD_TABS.indexOf(rdCurrentTab());
  const next = RD_TABS[(i + (e.key === 'ArrowRight' ? 1 : RD_TABS.length - 1)) % RD_TABS.length];
  switchRdTab(next); document.getElementById(`rdtab-${next}`).focus();
});
if (rdCurrentTab() === 'stats') loadStats();

/* ── Library: search, shelves, grid/list ───────────────────────── */
let rdShelf = '<?= $shelf ?>';
function rdApplyFilters() {
  const q = (document.getElementById('rdSearch')?.value || '').trim().toLowerCase();
  let shown = 0;
  document.querySelectorAll('#rdBooks .rd-book').forEach(el => {
    const ok = (rdShelf === 'all' || el.dataset.status === rdShelf) && (!q || el.dataset.search.includes(q));
    el.classList.toggle('hidden', !ok); if (ok) shown++;
  });
  document.querySelectorAll('#rdShelves [data-shelf]').forEach(b => b.classList.toggle('active', b.dataset.shelf === rdShelf));
  document.getElementById('rdNoMatch')?.classList.toggle('hidden', shown > 0);
  let view = 'grid';
  try { view = localStorage.getItem('trackie.readingView') || 'grid'; } catch {}
  document.getElementById('rdBooks')?.classList.toggle('rd-books-list', view === 'list');
  document.querySelectorAll('[data-view]').forEach(b => b.classList.toggle('active', b.dataset.view === view));
}
document.getElementById('rdSearch').addEventListener('input', rdApplyFilters);
document.getElementById('rd-library').addEventListener('click', e => {
  const s = e.target.closest('[data-shelf]');
  if (s) { rdShelf = s.dataset.shelf; rdApplyFilters(); return; }
  const v = e.target.closest('[data-view]');
  if (v) { try { localStorage.setItem('trackie.readingView', v.dataset.view); } catch {} rdApplyFilters(); }
});
rdApplyFilters();

/* ── Add book (Open Library search) ────────────────────────────── */
let rdPicked = null, rdSearchTimer = null, rdSearchSeq = 0, rdResults = [];
function openAddBook() {
  ['bookSearch', 'bookTitle', 'bookAuthor', 'bookPages'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('bookStatus').value = 'want';
  document.getElementById('bookResults').innerHTML = '';
  rdPicked = null; renderPicked();
  Trackie.openModal('addBookModal');
  setTimeout(() => document.getElementById('bookSearch').focus(), 50);
}
document.getElementById('bookSearch').addEventListener('input', e => {
  clearTimeout(rdSearchTimer);
  const q = e.target.value.trim();
  const box = document.getElementById('bookResults');
  if (q.length < 2) { box.innerHTML = ''; return; }
  rdSearchTimer = setTimeout(async () => {
    const seq = ++rdSearchSeq;
    box.innerHTML = '<div class="hb-loading" style="padding:.75rem 0"><i class="fas fa-spinner fa-spin"></i> Searching…</div>';
    try {
      const res = await rdPost({ action: 'search', q });
      if (seq !== rdSearchSeq) return;
      if (!res.success) { box.innerHTML = `<p class="hb-foot">${escHtml(res.error || 'Search failed.')}</p>`; return; }
      rdResults = res.results;
      box.innerHTML = rdResults.length ? rdResults.map((r, i) => `<button class="rd-result" data-i="${i}" role="option">
        ${rdCover(r.cover_url, r.title, 'rd-cover-xs')}
        <span><b>${escHtml(r.title)}</b><small>${escHtml([r.author, r.publish_year, r.pages_total ? r.pages_total + ' pages' : ''].filter(Boolean).join(' · '))}</small></span></button>`).join('')
        : '<p class="hb-foot">No matches — fill in the details below.</p>';
    } catch { if (seq === rdSearchSeq) box.innerHTML = '<p class="hb-foot">Network error — fill in the details below.</p>'; }
  }, 350);
});
document.getElementById('bookResults').addEventListener('click', e => {
  const b = e.target.closest('[data-i]');
  if (!b) return;
  rdPicked = rdResults[+b.dataset.i];
  document.getElementById('bookTitle').value = rdPicked.title || '';
  document.getElementById('bookAuthor').value = rdPicked.author || '';
  document.getElementById('bookPages').value = rdPicked.pages_total || '';
  document.getElementById('bookResults').innerHTML = '';
  renderPicked();
});
function clearPicked() { rdPicked = null; renderPicked(); }
function renderPicked() {
  const el = document.getElementById('bookPicked');
  el.classList.toggle('hidden', !rdPicked);
  if (rdPicked) el.innerHTML = `${rdCover(rdPicked.cover_url, rdPicked.title, 'rd-cover-sm')}
    <div><b>${escHtml(rdPicked.title)}</b><small>${escHtml([rdPicked.author, rdPicked.publish_year].filter(Boolean).join(' · '))}</small>
    ${rdPicked.subjects ? `<small>${escHtml(rdPicked.subjects)}</small>` : ''}</div>
    <button class="btn btn-icon btn-ghost btn-sm" onclick="clearPicked()" aria-label="Clear selection">&times;</button>`;
}
async function saveBook() {
  const title = document.getElementById('bookTitle').value.trim();
  if (!title) { Trackie.Toast.warning('Title is required.'); return; }
  const btn = document.getElementById('saveBookBtn'); btn.disabled = true;
  const data = { action: 'add', title, author: document.getElementById('bookAuthor').value.trim(),
    pages_total: document.getElementById('bookPages').value, status: document.getElementById('bookStatus').value };
  // Only keep Open Library metadata if the title still matches what was picked.
  if (rdPicked && rdPicked.title === title) Object.assign(data, {
    cover_url: rdPicked.cover_url || '', isbn: rdPicked.isbn || '', ol_key: rdPicked.ol_key || '',
    publish_year: rdPicked.publish_year || '', subjects: rdPicked.subjects || '' });
  try {
    const res = await rdPost(data);
    if (res.success) { Trackie.Toast.success('Book added!'); Trackie.closeModal('addBookModal'); await rdRefresh(); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
  finally { btn.disabled = false; }
}

/* ── Edit / status / rate / delete ─────────────────────────────── */
async function openEditBook(id) {
  try {
    const res = await rdPost({ action: 'detail', book_id: id });
    if (!res.success) { Trackie.Toast.error(res.error || 'Not found.'); return; }
    const b = res.book;
    document.getElementById('editId').value = b.id;
    document.getElementById('editTitle').value = b.title;
    document.getElementById('editAuthor').value = b.author || '';
    document.getElementById('editPages').value = b.pages_total || '';
    document.getElementById('editCurrent').value = b.current_page || 0;
    Trackie.closeModal('bookModal');
    Trackie.openModal('editBookModal');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function saveEditBook() {
  try {
    const res = await rdPost({ action: 'edit', book_id: document.getElementById('editId').value,
      title: document.getElementById('editTitle').value.trim(), author: document.getElementById('editAuthor').value.trim(),
      pages_total: document.getElementById('editPages').value, current_page: document.getElementById('editCurrent').value });
    if (res.success) { Trackie.Toast.success('Saved.'); Trackie.closeModal('editBookModal'); await rdRefresh(); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function setBookStatus(id, status) {
  try {
    const res = await rdPost({ action: 'update_status', book_id: id, status });
    if (res.success) {
      if (res.xp?.leveledUp) Trackie.Toast.success(`⚡ Level up! Level ${res.xp.level} — ${res.xp.title}`, 5000);
      else if (res.xp?.ok) Trackie.Toast.success(`📚 Finished! +${res.xp.gained} XP`);
      else Trackie.Toast.success(status === 'finished' ? 'Marked finished.' : 'Shelf updated.');
      await rdRefresh();
    } else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function rateBook(id, rating) {
  document.querySelectorAll(`#stars-${id} i`).forEach((s, i) => s.className = (i < rating ? 'fas' : 'far') + ' fa-star');
  try { const res = await rdPost({ action: 'rate', book_id: id, rating }); if (!res.success) Trackie.Toast.error(res.error || 'Failed.'); }
  catch { Trackie.Toast.error('Network error.'); }
}
async function deleteBook(id) {
  const ok = await Trackie.confirmDialog('Delete this book? Its sessions, notes and quotes are deleted too.', { confirmText: 'Delete', danger: true });
  if (!ok) return;
  try {
    const res = await rdPost({ action: 'delete', book_id: id });
    if (res.success) { Trackie.Toast.success('Book deleted.'); Trackie.closeModal('bookModal'); await rdRefresh(); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}

/* ── Book detail ───────────────────────────────────────────────── */
async function openBook(id) {
  const el = document.getElementById('bookDetail');
  el.innerHTML = '<div class="hb-loading"><i class="fas fa-spinner fa-spin"></i></div>';
  Trackie.openModal('bookModal');
  try {
    const res = await rdPost({ action: 'detail', book_id: id });
    if (!res.success) { el.innerHTML = `<p class="hb-error">${escHtml(res.error || 'Not found.')}</p>`; return; }
    const b = res.book, t = res.totals;
    const labels = { reading: 'Reading', want: 'Want to read', paused: 'Paused', finished: 'Finished' };
    el.innerHTML = `<div class="rd-detail-head">${rdCover(b.cover_url, b.title, 'rd-cover-lg')}
      <div class="rd-detail-info">
        <div class="rd-title" style="font-size:1.125rem">${escHtml(b.title)}</div>
        ${b.author ? `<div class="rd-author">${escHtml(b.author)}</div>` : ''}
        <div class="rd-author">${escHtml([b.publish_year, b.isbn ? 'ISBN ' + b.isbn : ''].filter(Boolean).join(' · '))}</div>
        <span class="rd-badge">${escHtml(labels[b.status] || b.status)}</span>
        ${b.progress !== null ? `<div class="rd-pages" style="margin-top:.5rem">${+b.current_page} / ${+b.pages_total} pages · <b>${+b.progress}%</b></div>
          <div class="hb-progress"><span style="width:${+b.progress}%"></span></div>` : ''}
        <div class="rd-current-actions">
          ${b.status !== 'finished' ? `<button class="btn btn-primary btn-sm" onclick="Trackie.closeModal('bookModal');openSession(${+b.id}, true)"><i class="fas fa-play"></i> Continue reading</button>` : ''}
          <button class="btn btn-secondary btn-sm" onclick="openEditBook(${+b.id})"><i class="fas fa-pen"></i> Edit</button>
          ${b.ol_key ? `<a class="btn btn-ghost btn-sm" href="https://openlibrary.org/works/${escHtml(b.ol_key)}" target="_blank" rel="noopener">Open Library <i class="fas fa-arrow-up-right-from-square"></i></a>` : ''}
        </div>
      </div></div>
      ${b.subjects ? `<div class="hb-chips">${b.subjects.split(',').map(s => `<span class="hb-chip">${escHtml(s.trim())}</span>`).join('')}</div>` : ''}
      <div class="grid-stats rd-mini-stats">
        <div class="stat-card"><div class="stat-val">${t.sessions}</div><div class="stat-label">Sessions</div></div>
        <div class="stat-card"><div class="stat-val">${rdMins(t.minutes)}</div><div class="stat-label">Time</div></div>
        <div class="stat-card"><div class="stat-val">${t.pages}</div><div class="stat-label">Pages logged</div></div>
      </div>
      <div class="fit-card-label" style="margin-top:1rem">Notes & quotes</div>
      <div class="rd-inline-note">
        <select id="bdKind" class="form-input" aria-label="Type"><option value="note">Note</option><option value="quote">Quote</option></select>
        <input id="bdPage" type="number" min="1" class="form-input" placeholder="Page" aria-label="Page">
        <textarea id="bdBody" class="form-input" rows="2" placeholder="Add a note or quote…" aria-label="Note text"></textarea>
        <button class="btn btn-primary btn-sm" onclick="saveNote(${+b.id})">Save</button>
      </div>
      ${res.notes.length ? res.notes.map(n => `<div class="rd-note rd-note-${n.kind === 'quote' ? 'quote' : 'note'} rd-note-sm">
        <div class="rd-note-body">${escHtml(n.body).replace(/\n/g, '<br>')}</div>
        <div class="rd-note-foot"><span>${n.kind === 'quote' ? 'Quote' : 'Note'}${n.page ? ' · p. ' + +n.page : ''}</span></div></div>`).join('')
        : '<p class="hb-empty-line">None yet.</p>'}
      <div class="fit-card-label" style="margin-top:1rem">Sessions</div>
      ${res.sessions.length ? res.sessions.map(s => `<div class="hb-row"><span>${escHtml(s.session_date)}${s.note ? ' — ' + escHtml(s.note) : ''}</span>
        <b>${rdMins(s.minutes)}${s.end_page !== null ? ' · ' + +s.pages + ' p' : ''}</b></div>`).join('')
        : '<p class="hb-empty-line">No sessions yet.</p>'}
      <div style="margin-top:1rem;text-align:right"><button class="btn btn-ghost btn-sm" style="color:var(--accent)" onclick="deleteBook(${+b.id})"><i class="fas fa-trash"></i> Delete book</button></div>`;
  } catch { el.innerHTML = '<p class="hb-error">Network error.</p>'; }
}

/* ── Session timer ─────────────────────────────────────────────
   Timer state lives in localStorage so a running session survives
   navigation and reloads: {bookId, start (ms, when running), acc (ms)}. */
const RS_KEY = 'trackie.readingTimer';
function rsState() { try { return JSON.parse(localStorage.getItem(RS_KEY)) || null; } catch { return null; } }
function rsSave(s) { try { s ? localStorage.setItem(RS_KEY, JSON.stringify(s)) : localStorage.removeItem(RS_KEY); } catch {} }
function rsElapsed(s) { return s ? (s.acc || 0) + (s.start ? Date.now() - s.start : 0) : 0; }
function rsFmt(ms) { const t = Math.floor(ms / 1000); return [Math.floor(t / 3600), Math.floor(t / 60) % 60, t % 60].map(n => String(n).padStart(2, '0')).join(':'); }
let rsTick = null;
function rsRender() {
  const s = rsState();
  const ms = rsElapsed(s);
  const time = document.getElementById('rsTime');
  if (time) time.textContent = rsFmt(ms);
  const tog = document.getElementById('rsToggle');
  if (tog) tog.innerHTML = s?.start ? '<i class="fas fa-pause"></i> Pause' : `<i class="fas fa-play"></i> ${ms ? 'Resume' : 'Start'}`;
  const banner = document.getElementById('rdTimerBanner');
  if (banner) {
    banner.classList.toggle('hidden', !s?.start);
    if (s?.start) {
      document.getElementById('rdBannerTime').textContent = rsFmt(ms);
      const opt = document.querySelector(`#rsBook option[value="${s.bookId}"]`);
      document.getElementById('rdBannerBook').textContent = opt ? opt.textContent : '';
    }
  }
  if (s?.start && document.getElementById('rsMinutes') && document.activeElement?.id !== 'rsMinutes') {
    document.getElementById('rsMinutes').value = Math.max(1, Math.round(ms / 60000));
  }
}
function rsEnsureTick() { clearInterval(rsTick); if (rsState()?.start) rsTick = setInterval(rsRender, 1000); }
function rsToggle() {
  const bookId = +document.getElementById('rsBook').value;
  let s = rsState() || { bookId, acc: 0, start: null };
  if (s.bookId !== bookId) s = { bookId, acc: 0, start: null };
  if (s.start) { s.acc += Date.now() - s.start; s.start = null; } else s.start = Date.now();
  rsSave(s); rsRender(); rsEnsureTick();
  if (!s.start) document.getElementById('rsMinutes').value = Math.max(1, Math.round(rsElapsed(s) / 60000));
}
function rsReset() { rsSave(null); document.getElementById('rsMinutes').value = ''; rsRender(); rsEnsureTick(); }
function rsHint() {
  const opt = document.getElementById('rsBook').selectedOptions[0];
  if (!opt) return;
  const page = +opt.dataset.page, total = +opt.dataset.total;
  document.getElementById('rsEnd').min = page;
  if (total) document.getElementById('rsEnd').max = total;
  document.getElementById('rsHint').textContent = `You're on page ${page}${total ? ' of ' + total : ''}. Enter where you stopped to count pages read.`;
}
function openSession(bookId, autostart = false) {
  const sel = document.getElementById('rsBook');
  if (!sel.options.length) { Trackie.Toast.warning('Add a book you haven\'t finished first.'); return; }
  const s = rsState();
  if (s && sel.querySelector(`option[value="${s.bookId}"]`) && (!bookId || s.bookId === bookId)) sel.value = s.bookId;
  else if (bookId && sel.querySelector(`option[value="${bookId}"]`)) sel.value = bookId;
  document.getElementById('rsForm').classList.remove('hidden');
  document.getElementById('rsDone').classList.add('hidden');
  document.getElementById('rsEnd').value = '';
  document.getElementById('rsNote').value = '';
  document.getElementById('rsDate').value = '<?= date('Y-m-d') ?>';
  const st = rsState();
  document.getElementById('rsMinutes').value = st && st.bookId === +sel.value && rsElapsed(st) ? Math.max(1, Math.round(rsElapsed(st) / 60000)) : '';
  rsHint(); rsRender();
  Trackie.openModal('sessionModal');
  if (autostart && !(rsState()?.start)) rsToggle();
}
document.getElementById('sessionModal').addEventListener('change', e => { if (e.target.id === 'rsBook') rsHint(); });
async function saveSession() {
  const minutes = +document.getElementById('rsMinutes').value;
  if (!minutes || minutes < 1) { Trackie.Toast.warning('How many minutes did you read?'); return; }
  const btn = document.getElementById('rsSave'); btn.disabled = true;
  const bookId = +document.getElementById('rsBook').value;
  try {
    const res = await rdPost({ action: 'session_log', book_id: bookId, minutes,
      end_page: document.getElementById('rsEnd').value, note: document.getElementById('rsNote').value,
      date: document.getElementById('rsDate').value });
    if (!res.success) { Trackie.Toast.error(res.error || 'Failed.'); return; }
    if (rsState()?.bookId === bookId) rsSave(null);
    rsRender(); rsEnsureTick();
    const title = document.getElementById('rsBook').selectedOptions[0]?.textContent || '';
    document.getElementById('rsForm').classList.add('hidden');
    const done = document.getElementById('rsDone');
    done.classList.remove('hidden');
    done.innerHTML = `<div class="modal-body rd-done">
      <div class="rd-done-icon">🎉</div>
      <div class="rd-done-title">Session complete</div>
      <div class="rd-author">${escHtml(title)}</div>
      <div class="rd-done-stats"><span><b>${rdMins(res.minutes)}</b> read</span>${res.pages ? `<span><b>${res.pages}</b> page${res.pages === 1 ? '' : 's'}</span>` : ''}</div>
      <div class="rd-done-line">${res.xp?.ok ? `+${res.xp.gained} XP` : 'Today\'s reading XP already earned'} · 🔥 ${res.streak.current}-day streak</div>
      ${res.reached_end ? `<button class="btn btn-primary btn-sm" onclick="Trackie.closeModal('sessionModal');setBookStatus(${bookId}, 'finished')"><i class="fas fa-check"></i> You reached the last page — mark finished?</button>` : ''}
    </div><div class="modal-footer"><button class="btn btn-secondary btn-sm" data-close-modal="sessionModal">Done</button></div>`;
    if (res.xp?.leveledUp) Trackie.Toast.success(`⚡ Level up! Level ${res.xp.level} — ${res.xp.title}`, 5000);
    await rdRefresh();
  } catch { Trackie.Toast.error('Network error.'); }
  finally { btn.disabled = false; }
}
async function deleteSession(id) {
  const ok = await Trackie.confirmDialog('Delete this session?', { confirmText: 'Delete', danger: true });
  if (!ok) return;
  try { const res = await rdPost({ action: 'session_delete', session_id: id });
    if (res.success) { Trackie.Toast.success('Session deleted.'); await rdRefresh(); } else Trackie.Toast.error(res.error || 'Failed.'); }
  catch { Trackie.Toast.error('Network error.'); }
}
rsRender(); rsEnsureTick();

/* ── Notes ─────────────────────────────────────────────────────── */
function openNote() {
  document.getElementById('noteBody').value = ''; document.getElementById('notePage').value = '';
  Trackie.openModal('noteModal');
}
async function saveNote(inlineBookId) {
  const inline = !!inlineBookId;
  const body = document.getElementById(inline ? 'bdBody' : 'noteBody').value.trim();
  if (!body) { Trackie.Toast.warning('Write something first.'); return; }
  try {
    const res = await rdPost({ action: 'note_add', body,
      book_id: inline ? inlineBookId : document.getElementById('noteBook').value,
      kind: document.getElementById(inline ? 'bdKind' : 'noteKind').value,
      page: document.getElementById(inline ? 'bdPage' : 'notePage').value });
    if (!res.success) { Trackie.Toast.error(res.error || 'Failed.'); return; }
    Trackie.Toast.success('Saved.');
    if (inline) openBook(inlineBookId); else Trackie.closeModal('noteModal');
    await rdRefresh();
  } catch { Trackie.Toast.error('Network error.'); }
}
async function deleteNote(id) {
  const ok = await Trackie.confirmDialog('Delete this?', { confirmText: 'Delete', danger: true });
  if (!ok) return;
  try { const res = await rdPost({ action: 'note_delete', note_id: id });
    if (res.success) { document.getElementById(`note-${id}`)?.remove(); Trackie.Toast.success('Deleted.'); } else Trackie.Toast.error(res.error || 'Failed.'); }
  catch { Trackie.Toast.error('Network error.'); }
}
document.getElementById('rd-notes').addEventListener('click', e => {
  const f = e.target.closest('[data-kind]');
  if (!f || !f.closest('#rdNoteFilter')) return;
  document.querySelectorAll('#rdNoteFilter [data-kind]').forEach(b => b.classList.toggle('active', b === f));
  document.querySelectorAll('#rdNotes .rd-note').forEach(n => n.classList.toggle('hidden', f.dataset.kind !== 'all' && n.dataset.kind !== f.dataset.kind));
});

/* ── Goal ──────────────────────────────────────────────────────── */
async function saveGoal() {
  try {
    const res = await rdPost({ action: 'goal_save', books_target: document.getElementById('goalBooks').value, pages_target: document.getElementById('goalPages').value });
    if (res.success) { Trackie.Toast.success('Goal saved.'); await rdRefresh(); } else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}

/* ── Statistics ────────────────────────────────────────────────── */
async function loadStats(year) {
  rdStatsLoaded = true;
  const el = document.getElementById('rdStats');
  try {
    const res = await rdPost({ action: 'stats', year: year || '' });
    if (!res.success) { el.innerHTML = `<div class="card card-body hb-error">${escHtml(res.error || 'Failed.')}</div>`; return; }
    const s = res.stats, t = s.totals;
    const mo = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    const bars = (key, fmt) => { const v = s.months.map(m => m[key]); const max = Math.max(1, ...v);
      return `<div class="hb-bars">${v.map((x, i) => `<div class="hb-bar" title="${mo[i]}: ${fmt(x)}"><span style="height:${Math.round(x / max * 100)}%"></span><em>${mo[i][0]}</em></div>`).join('')}</div>`; };
    el.innerHTML = `<div class="rd-stats-head"><h2 class="hb-h2">Reading in ${+s.year}</h2>
      ${s.years.length > 1 ? `<select id="rdYear" class="form-input" style="width:auto" aria-label="Year">${s.years.map(y => `<option${y === s.year ? ' selected' : ''}>${+y}</option>`).join('')}</select>` : ''}</div>
      <div class="grid-stats" style="margin-bottom:1rem">
        <div class="stat-card"><div class="stat-val">${t.books}${s.goal.books_target ? `<small> / ${+s.goal.books_target}</small>` : ''}</div><div class="stat-label">Books finished</div></div>
        <div class="stat-card"><div class="stat-val">${t.pages.toLocaleString()}</div><div class="stat-label">Pages logged</div></div>
        <div class="stat-card"><div class="stat-val">${rdMins(t.minutes)}</div><div class="stat-label">Reading time · ${t.sessions} sessions</div></div>
        <div class="stat-card"><div class="stat-val">${s.streak.best}</div><div class="stat-label">Best streak (days) · now ${s.streak.current}</div></div>
      </div>
      ${!t.sessions && !t.books ? '<div class="card card-body hb-empty-line">Nothing logged for this year yet.</div>' : `
      <div class="hb-grid2">
        <div class="card card-body"><div class="fit-card-label">Books finished per month</div>${bars('books', x => x + ' books')}</div>
        <div class="card card-body"><div class="fit-card-label">Pages per month</div>${bars('pages', x => x + ' pages')}</div>
        <div class="card card-body"><div class="fit-card-label">Reading time per month</div>${bars('minutes', rdMins)}
          ${s.avg_session ? `<p class="hb-foot">Average session: ${rdMins(s.avg_session)}</p>` : ''}</div>
        <div class="card card-body"><div class="fit-card-label">Most-read authors · all time</div>
          ${s.authors.length ? s.authors.map(a => `<div class="hb-row"><span>${escHtml(a.author)}</span><b>${+a.n} book${+a.n === 1 ? '' : 's'}</b></div>`).join('') : '<p class="hb-empty-line">Finish a book to see authors here.</p>'}</div>
      </div>
      <div class="card card-body" style="margin-top:1rem"><div class="fit-card-label">Subjects you read</div>
        ${s.subjects.length ? `<div class="hb-chips">${s.subjects.map(x => `<span class="hb-chip">${escHtml(x.subject)} · ${+x.books}</span>`).join('')}</div>
          <p class="hb-foot">From Open Library subjects of books you're reading or finished. Books added by hand have none.</p>` : '<p class="hb-empty-line">Add books through search to see subjects.</p>'}</div>`}
      <p class="hb-foot">Pages and time count only from logged sessions; books count when moved to Finished.</p>`;
    document.getElementById('rdYear')?.addEventListener('change', e => loadStats(e.target.value));
  } catch { el.innerHTML = '<div class="card card-body hb-error">Network error.</div>'; }
}
</script>
