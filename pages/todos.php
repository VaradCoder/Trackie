<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Todos';
$currentPage = 'todos';

// Roll over any recurring todos whose completion is from a previous day/week/month
resetRecurringTodos($uid);

$today    = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$weekEnd  = date('Y-m-d', strtotime('+6 day'));

/* ════════════════════════════════════════════════════════════════
   One page, grouped by when things are due. Filters and search run in the
   browser, so switching never reloads. (Replaces the List / Timeline split
   and the five reload-per-click filter tabs.)
   ════════════════════════════════════════════════════════════════ */
$open = fetchAll(
    "SELECT * FROM todos WHERE user_id=? AND deleted_at IS NULL AND parent_id IS NULL AND completed=0
      ORDER BY FIELD(priority,'high','medium','low'), due_date IS NULL, due_date, created_at DESC", [$uid]);
// Done: the most recent ones; the full archive isn't needed to plan a day.
$done = fetchAll(
    "SELECT * FROM todos WHERE user_id=? AND deleted_at IS NULL AND parent_id IS NULL AND completed=1
      ORDER BY completed_at DESC LIMIT 40", [$uid]);
$doneTotal = (int)(fetchOne("SELECT COUNT(*) c FROM todos WHERE user_id=? AND deleted_at IS NULL AND parent_id IS NULL AND completed=1", [$uid])['c'] ?? 0);

$subs = [];
foreach (fetchAll("SELECT parent_id, COUNT(*) total, SUM(completed) done FROM todos
                    WHERE user_id=? AND parent_id IS NOT NULL AND deleted_at IS NULL GROUP BY parent_id", [$uid]) as $r) {
    $subs[$r['parent_id']] = ['total' => (int)$r['total'], 'done' => (int)$r['done']];
}

/** Which group an OPEN todo belongs in (also used for a done todo's "home" if re-opened). */
function todoGroup(array $t, string $today, string $tomorrow, string $weekEnd): string {
    if (($t['recurring'] ?? 'none') !== 'none' && (!$t['due_date'] || $t['due_date'] <= $today)) return 'today';
    if (!$t['due_date'])              return 'nodate';
    if ($t['due_date'] < $today)      return 'overdue';
    if ($t['due_date'] === $today)    return 'today';
    if ($t['due_date'] === $tomorrow) return 'tomorrow';
    if ($t['due_date'] <= $weekEnd)   return 'week';
    return 'later';
}

$groups = ['overdue' => [], 'today' => [], 'tomorrow' => [], 'week' => [], 'later' => [], 'nodate' => []];
foreach ($open as $t) $groups[todoGroup($t, $today, $tomorrow, $weekEnd)][] = $t;
$groupMeta = [
    'overdue'  => ['Overdue',     'fa-triangle-exclamation'],
    'today'    => ['Today',       'fa-sun'],
    'tomorrow' => ['Tomorrow',    'fa-cloud-sun'],
    'week'     => ['This week',   'fa-calendar-week'],
    'later'    => ['Later',       'fa-calendar'],
    'nodate'   => ['No date',     'fa-inbox'],
];

// Top tags among open todos → one-tap filters.
$tagCount = [];
foreach ($open as $t) foreach (array_filter(array_map('trim', explode(',', (string)$t['tags']))) as $tg) $tagCount[strtolower($tg)] = ($tagCount[strtolower($tg)] ?? 0) + 1;
arsort($tagCount);
$topTags = array_slice(array_keys($tagCount), 0, 6);

$overdueN  = count($groups['overdue']);
$weekDone  = (int)(fetchOne("SELECT COUNT(*) c FROM activity_log WHERE user_id=? AND action='todo' AND occurred_on >= ?",
                 [$uid, date('Y-m-d', strtotime('-6 day'))])['c'] ?? 0);
$initialFilter = in_array($_GET['filter'] ?? '', ['today', 'overdue', 'high'], true) ? $_GET['filter'] : 'all';
if (($_GET['filter'] ?? '') === 'completed') $initialFilter = 'done';
$initialDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : '';

/** Relative due label: Today · Tomorrow · Fri 24 Oct · 3 days late */
function todoDueLabel(?string $due, string $today, bool $done): array {
    if (!$due) return ['', ''];
    $diff = (int)round((strtotime($due) - strtotime($today)) / 86400);
    if (!$done && $diff < 0) return [$diff === -1 ? 'Yesterday' : (-$diff) . ' days late', 'is-late'];
    if ($diff === 0) return ['Today', 'is-today'];
    if ($diff === 1) return ['Tomorrow', ''];
    if ($diff > 1 && $diff < 7) return [date('l', strtotime($due)), ''];
    return [date('j M', strtotime($due)) . (date('Y', strtotime($due)) !== date('Y') ? ' ' . date('Y', strtotime($due)) : ''), ''];
}

function renderTodoRow(array $t, string $group, array $subs, string $today): string {
    $done = (bool)$t['completed'];
    [$dueTxt, $dueCls] = todoDueLabel($t['due_date'], $today, $done);
    $tags = array_values(array_filter(array_map(static fn($x) => strtolower(trim($x)), explode(',', (string)$t['tags']))));
    $s = $subs[$t['id']] ?? null;
    $title = h($t['title']);
    $pri = in_array($t['priority'], ['low', 'medium', 'high'], true) ? $t['priority'] : 'medium';
    $search = strtolower($t['title'] . ' ' . $t['description'] . ' ' . $t['category'] . ' ' . implode(' ', $tags) . ' ' . $t['location']);

    $meta = '';
    if ($dueTxt) $meta .= '<span class="tdo-due ' . $dueCls . '"><i class="fas fa-calendar-day" aria-hidden="true"></i> ' . h($dueTxt) . '</span>';
    if (($t['recurring'] ?? 'none') !== 'none') $meta .= '<span title="Repeats ' . h($t['recurring']) . '"><i class="fas fa-repeat" aria-hidden="true"></i> ' . h(ucfirst($t['recurring'])) . '</span>';
    if ($s) $meta .= '<span class="tdo-subcount" title="Subtasks done"><i class="fas fa-list-check" aria-hidden="true"></i> <b>' . $s['done'] . '</b>/' . $s['total'] . '</span>';
    if ($t['category']) $meta .= '<span class="category-badge">' . h($t['category']) . '</span>';
    foreach ($tags as $tg) $meta .= '<button type="button" class="tdo-tag" data-tag="' . h($tg) . '">#' . h($tg) . '</button>';
    if ($t['location']) $meta .= '<span><i class="fas fa-location-dot" aria-hidden="true"></i> ' . h($t['location']) . '</span>';
    if ($t['description']) $meta .= '<span class="tdo-desc">' . h(mb_strimwidth($t['description'], 0, 90, '…')) . '</span>';

    $canSnooze = !$done && ($t['recurring'] ?? 'none') === 'none' && in_array($group, ['overdue', 'today'], true);
    return '<li class="td-item tdo-row' . ($done ? ' is-done' : '') . '" id="todo-' . (int)$t['id'] . '"'
        . ' data-id="' . (int)$t['id'] . '" data-home="' . h($group) . '" data-pri="' . $pri . '"'
        . ' data-due="' . h((string)$t['due_date']) . '" data-tags="' . h(implode(',', $tags)) . '" data-search="' . h($search) . '"'
        . ' style="--item-c:var(--ok)">'
        . '<button type="button" class="td-check" role="checkbox" aria-checked="' . ($done ? 'true' : 'false') . '" aria-label="' . $title . '">'
        . '<svg viewBox="0 0 26 26" aria-hidden="true"><circle class="td-check-ring" cx="13" cy="13" r="11.5"/><path class="td-check-tick" d="M8 13.4l3.2 3.2L18 9.8"/></svg></button>'
        . '<div class="td-body">'
        .   '<button type="button" class="tdo-open" data-act="edit">' . '<span class="td-title">' . $title . '</span></button>'
        .   '<div class="td-meta">'
        .     '<button type="button" class="tdo-pri ' . $pri . '" data-act="pri" title="Priority: ' . $pri . ' — tap to change" aria-label="Priority ' . $pri . ', change">'
        .       '<i class="fas fa-flag" aria-hidden="true"></i> ' . ucfirst($pri) . '</button>'
        .     $meta
        .   '</div>'
        .   '<div class="tdo-subs" id="subtasks-' . (int)$t['id'] . '" hidden></div>'
        . '</div>'
        . '<div class="tdo-actions">'
        .   ($canSnooze ? '<button type="button" class="tdo-act tdo-snooze" data-act="snooze" title="Move to tomorrow" aria-label="Move ' . $title . ' to tomorrow"><i class="fas fa-forward" aria-hidden="true"></i><span>Tomorrow</span></button>' : '')
        .   '<button type="button" class="tdo-act" data-act="subs" title="Subtasks" aria-label="Subtasks for ' . $title . '" aria-expanded="false"><i class="fas fa-list-ul" aria-hidden="true"></i></button>'
        .   (!$done ? '<a class="tdo-act" href="' . APP_BASE . '/pages/focus.php?todo=' . (int)$t['id'] . '" title="Focus on this" aria-label="Start a focus session on ' . $title . '"><i class="fas fa-stopwatch" aria-hidden="true"></i></a>' : '')
        .   '<button type="button" class="tdo-act tdo-del" data-act="delete" title="Delete" aria-label="Delete ' . $title . '"><i class="fas fa-trash" aria-hidden="true"></i></button>'
        . '</div>'
        . '</li>';
}

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<?= renderPageHeader('Todos', [
  'icon'    => 'fa-check-square',
  'sub'     => 'Capture it in a sentence, finish it in a tap.',
  'actions' => '<button type="button" class="btn btn-secondary btn-sm" onclick="openAddTodo()"><i class="fas fa-sliders" aria-hidden="true"></i> Detailed todo</button>',
]) ?>

<div class="tdo-strip" id="todoStatsWrap">
  <span><strong><?= count($open) ?></strong> open</span>
  <?php if ($overdueN): ?><span class="is-late"><strong><?= $overdueN ?></strong> overdue</span><?php endif; ?>
  <span><strong><?= $weekDone ?></strong> done in the last 7 days</span>
</div>

<form class="td-add" id="tdoAdd" autocomplete="off">
  <label class="td-add-row">
    <i class="fas fa-plus" aria-hidden="true"></i>
    <span class="sr-only">Add a todo</span>
    <input class="td-add-input" id="tdoAddInput" maxlength="150" enterkeyhint="done"
           placeholder="Add a todo, e.g. “report friday !high #work”"
           title="Plain words for dates (tomorrow, next Fri 5pm), !high or !low for priority, #tag for tags">
    <button type="submit" class="btn btn-primary btn-sm td-add-btn">Add</button>
  </label>
  <div class="td-add-hint" id="tdoAddHint" aria-live="polite"></div>
</form>

<div class="tdo-filters" role="toolbar" aria-label="Filter todos">
  <div class="tdo-chips" id="tdoChips">
    <button type="button" class="tdo-chip" data-filter="all">All</button>
    <button type="button" class="tdo-chip" data-filter="today">Today</button>
    <button type="button" class="tdo-chip" data-filter="overdue">Overdue<?= $overdueN ? ' <b>' . $overdueN . '</b>' : '' ?></button>
    <button type="button" class="tdo-chip" data-filter="high"><i class="fas fa-flag" aria-hidden="true"></i> High</button>
    <?php foreach ($topTags as $tg): ?>
      <button type="button" class="tdo-chip" data-filter="tag:<?= h($tg) ?>">#<?= h($tg) ?></button>
    <?php endforeach; ?>
  </div>
  <label class="tdo-search">
    <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
    <span class="sr-only">Search todos</span>
    <input type="search" id="tdoSearch" placeholder="Search" autocomplete="off">
  </label>
</div>

<div id="todoList" data-today="<?= h($today) ?>" data-tomorrow="<?= h($tomorrow) ?>" data-week-end="<?= h($weekEnd) ?>"
     data-initial-filter="<?= h($initialFilter) ?>" data-date="<?= h($initialDate) ?>">
  <?php if (!$open && !$done): ?>
    <div class="card"><?= renderEmptyState('fa-clipboard-list', 'Nothing to do — yet',
      'Type a task above in plain words: “call the bank tomorrow 10am”. It lands in the right day automatically.') ?></div>
  <?php endif; ?>

  <?php foreach ($groupMeta as $g => [$label, $icon]): ?>
    <section class="td-section tdo-group<?= $g === 'overdue' ? ' is-overdue' : '' ?>" data-group="<?= $g ?>"<?= $groups[$g] ? '' : ' hidden' ?>>
      <h2 class="td-section-head"><span><i class="fas <?= $icon ?>" aria-hidden="true"></i> <?= $label ?></span> <span class="td-count"><?= count($groups[$g]) ?></span></h2>
      <ul class="td-list" data-list="<?= $g ?>"><?php foreach ($groups[$g] as $t) echo renderTodoRow($t, $g, $subs, $today); ?></ul>
    </section>
  <?php endforeach; ?>

  <details class="td-section td-done tdo-group" data-group="done"<?= $done ? '' : ' hidden' ?>>
    <summary><i class="fas fa-chevron-right" aria-hidden="true"></i> Done <span class="td-count"><?= $doneTotal ?></span></summary>
    <ul class="td-list" data-list="done"><?php foreach ($done as $t) echo renderTodoRow($t, todoGroup($t, $today, $tomorrow, $weekEnd), $subs, $today); ?></ul>
    <?php if ($doneTotal > count($done)): ?><p class="form-hint u-mt-2">Showing the latest <?= count($done) ?> of <?= $doneTotal ?> completed.</p><?php endif; ?>
  </details>

  <div class="card tdo-nomatch" hidden><?= renderEmptyState('fa-magnifying-glass', 'No todos match', 'Try another word, or clear the filter.', '<button type="button" class="btn btn-secondary btn-sm" data-filter="all">Show all</button>', true) ?></div>
</div>

<!-- Add / Edit Modal -->
<div id="todoModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header">
      <span class="modal-title" id="todoModalTitle">New Todo</span>
      <button class="btn btn-icon btn-ghost btn-sm" data-close-modal="todoModal" aria-label="Close dialog">&times;</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="todoId">
      <div class="form-group">
        <label for="todoTitle" class="form-label">Title <span class="u-c-accent">*</span></label>
        <input id="todoTitle" class="form-input" placeholder="Todo title" required>
      </div>
      <div class="form-group">
        <label for="todoDesc" class="form-label">Description</label>
        <textarea id="todoDesc" class="form-input" rows="2" placeholder="Optional details"></textarea>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label for="todoDue" class="form-label">Due date</label>
          <input id="todoDue" class="form-input" type="date">
        </div>
        <div class="form-group">
          <label for="todoPriority" class="form-label">Priority</label>
          <select id="todoPriority" class="form-input">
            <option value="low">Low</option>
            <option value="medium" selected>Medium</option>
            <option value="high">High</option>
          </select>
        </div>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label for="todoLocation" class="form-label">Location</label>
          <input id="todoLocation" class="form-input" placeholder="Optional">
        </div>
        <div class="form-group">
          <label for="todoRecurring" class="form-label">Repeat</label>
          <select id="todoRecurring" class="form-input">
            <option value="none">None</option>
            <option value="daily">Daily</option>
            <option value="weekly">Weekly</option>
            <option value="monthly">Monthly</option>
          </select>
        </div>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label for="todoCategory" class="form-label">Category</label>
          <select id="todoCategory" class="form-input">
            <option value="">No category</option>
            <option value="Work">Work</option>
            <option value="Study">Study</option>
            <option value="Health">Health</option>
            <option value="Personal">Personal</option>
            <option value="Finance">Finance</option>
            <option value="Shopping">Shopping</option>
            <option value="Fitness">Fitness</option>
          </select>
        </div>
        <div class="form-group">
          <label for="todoTags" class="form-label">Tags <span class="form-hint u-m-0">(comma-separated)</span></label>
          <input id="todoTags" class="form-input" placeholder="e.g. urgent, physics">
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-ghost btn-sm u-c-accent u-mr-auto" id="todoDeleteBtn" hidden
              onclick="Trackie.closeModal('todoModal'); deleteTodo(+document.getElementById('todoId').value)">
        <i class="fas fa-trash" aria-hidden="true"></i> Delete
      </button>
      <button class="btn btn-secondary btn-sm" data-close-modal="todoModal">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="saveTodo()">
        <i class="fas fa-save"></i> Save
      </button>
    </div>
  </div>
</div>

</div>
<?php include '../includes/footer.php'; ?>

<script>
const API_BASE = '<?= APP_BASE ?>/api';
(() => {
  const main = document.getElementById('page-main');
  if (!main || !document.getElementById('todoList')) return;
  const $ = id => document.getElementById(id);
  const root = () => $('todoList');
  const NL = Trackie.NL;
  const D = () => root().dataset;
  const post = body => Trackie.API.post(`${API_BASE}/todos.php`, body);
  const reduce = () => matchMedia('(prefers-reduced-motion: reduce)').matches;
  const PRI_RANK = { high: 0, medium: 1, low: 2 };
  const PRI_NEXT = { low: 'medium', medium: 'high', high: 'low' };
  let filter = D().date ? 'date:' + D().date : (D().initialFilter || 'all');
  let query = '';

  const list = g => root().querySelector(`[data-list="${g}"]`);
  const rows = () => [...root().querySelectorAll('.tdo-row')];
  // Open rows keep server order: priority, then due date (no date last), then newest.
  const order = (a, b) => (PRI_RANK[a.dataset.pri] - PRI_RANK[b.dataset.pri])
    || ((a.dataset.due || '9999') < (b.dataset.due || '9999') ? -1 : (a.dataset.due || '9999') > (b.dataset.due || '9999') ? 1 : 0)
    || (+b.dataset.id - +a.dataset.id);
  function place(li, group) {
    const ul = list(group);
    if (group === 'done') { ul.prepend(li); return; }
    ul.insertBefore(li, [...ul.children].find(x => x !== li && order(li, x) < 0) || null);
  }
  function groupFor(due) {
    const d = D();
    if (!due) return 'nodate';
    if (due < d.today) return 'overdue';
    if (due === d.today) return 'today';
    if (due === d.tomorrow) return 'tomorrow';
    return due <= d.weekEnd ? 'week' : 'later';
  }

  // ── Filters + search: client-side, instant, no reload ─────────────
  function matches(li) {
    const done = li.classList.contains('is-done');
    if (query && !li.dataset.search.includes(query)) return false;
    if (filter === 'all') return true;
    if (filter === 'today') return !done && li.dataset.home === 'today';
    if (filter === 'overdue') return !done && li.dataset.home === 'overdue';
    if (filter === 'high') return !done && li.dataset.pri === 'high';
    if (filter.startsWith('tag:')) return li.dataset.tags.split(',').includes(filter.slice(4));
    if (filter.startsWith('date:')) return li.dataset.due === filter.slice(5);
    return true;
  }
  function apply() {
    let shown = 0;
    rows().forEach(li => { const m = matches(li); li.hidden = !m; if (m) shown++; });
    root().querySelectorAll('.tdo-group').forEach(sec => {
      const visible = [...sec.querySelectorAll('.tdo-row')].filter(r => !r.hidden).length;
      const total = sec.querySelectorAll('.tdo-row').length;
      sec.hidden = !visible;
      const c = sec.querySelector('.td-count');
      if (c && sec.dataset.group !== 'done') c.textContent = (filter !== 'all' || query) ? `${visible} of ${total}` : total;
      if (sec.dataset.group === 'done' && (filter !== 'all' || query) && visible) sec.open = true;
    });
    const nm = root().querySelector('.tdo-nomatch');
    if (nm) nm.hidden = shown > 0 || !rows().length;
    document.querySelectorAll('#tdoChips .tdo-chip').forEach(c => c.setAttribute('aria-pressed', String(c.dataset.filter === filter)));
    const url = new URL(location.href);
    url.searchParams.delete('date'); url.searchParams.delete('view');
    if (filter === 'all' || filter.startsWith('date:') || filter.startsWith('tag:')) url.searchParams.delete('filter'); else url.searchParams.set('filter', filter);
    history.replaceState(history.state, '', url);
  }
  function setFilter(f) { filter = f; apply(); }

  // Counts in the strip and group headings after a move.
  function recount() {
    const open = rows().filter(r => !r.classList.contains('is-done'));
    const strip = $('todoStatsWrap')?.querySelector('strong');
    if (strip) strip.textContent = open.length;
    apply();
  }

  // ── Row actions (delegated: refreshes replace #todoList) ──────────
  main.addEventListener('click', async e => {
    const chip = e.target.closest('button[data-filter]');
    if (chip && main.contains(chip)) { setFilter(chip.dataset.filter === filter && filter !== 'all' ? 'all' : chip.dataset.filter); return; }
    const tagBtn = e.target.closest('.tdo-tag');
    if (tagBtn) { setFilter('tag:' + tagBtn.dataset.tag); return; }

    const check = e.target.closest('#todoList button.td-check');
    if (check) return toggle(check);
    const actBtn = e.target.closest('#todoList [data-act]');
    if (!actBtn) return;
    const li = actBtn.closest('.tdo-row');
    const id = +li.dataset.id;
    switch (actBtn.dataset.act) {
      case 'edit':   return editTodo(id);
      case 'delete': return deleteTodo(id);
      case 'subs':   return toggleSubtasks(id, actBtn);
      case 'pri':    return cyclePriority(li, actBtn);
      case 'snooze': return snooze(li);
    }
  });

  async function toggle(btn) {
    if (btn.getAttribute('aria-busy') === 'true') return;
    const li = btn.closest('.tdo-row');
    const done = btn.getAttribute('aria-checked') !== 'true';
    btn.setAttribute('aria-checked', String(done)); btn.setAttribute('aria-busy', 'true');
    li.classList.toggle('is-done', done);
    if (done) Trackie.Delight?.tap();
    try {
      const res = await post({ action: 'toggle', todo_id: li.dataset.id, completed: done ? 1 : 0 });
      if (!res?.success) throw res;
      if (res.achievements?.length) Trackie.showAchievementToasts?.(res.achievements);
      await new Promise(r => setTimeout(r, done && !reduce() ? 300 : 0));
      await Trackie.UI.transition(() => { place(li, done ? 'done' : li.dataset.home); recount(); }, rows().filter(r => !r.hidden));
      const left = rows().filter(r => !r.classList.contains('is-done') && !r.hidden).length;
      if (done && left === 0 && filter === 'all' && !query) {
        Trackie.Delight?.burst(btn);
        Trackie.Toast.success(`🎉 All clear${res.xp?.gained ? ` · +${res.xp.gained} XP` : ''}`, 3500);
      } else if (done && res.xp?.gained) {
        Trackie.Toast.success(`+${res.xp.gained} XP · ${left} left`, 1800);
      }
    } catch (err) {
      btn.setAttribute('aria-checked', String(!done)); li.classList.toggle('is-done', !done);
      Trackie.UI.toastError(err, "Couldn't update that todo.");
    } finally { btn.removeAttribute('aria-busy'); }
  }

  async function cyclePriority(li, btn) {
    const from = li.dataset.pri, to = PRI_NEXT[from];
    const setPri = p => {
      li.dataset.pri = p;
      btn.className = `tdo-pri ${p}`;
      btn.innerHTML = `<i class="fas fa-flag" aria-hidden="true"></i> ${p[0].toUpperCase() + p.slice(1)}`;
      btn.setAttribute('aria-label', `Priority ${p}, change`); btn.title = `Priority: ${p} — tap to change`;
    };
    setPri(to);
    try {
      const res = await post({ action: 'patch', todo_id: li.dataset.id, priority: to });
      if (!res?.success) throw res;
      if (!li.classList.contains('is-done')) await Trackie.UI.transition(() => place(li, li.dataset.home), [...li.parentElement.children]);
      apply();
    } catch (err) { setPri(from); Trackie.UI.toastError(err, "Couldn't change the priority."); }
  }

  // Same wording as the server (todoDueLabel): "3 days late", "Today", "Tomorrow", weekday, date.
  function dueLabel(due) {
    const d = D(), at = x => new Date(x + 'T12:00:00');
    const diff = Math.round((at(due) - at(d.today)) / 864e5);
    if (diff < 0) return diff === -1 ? 'Yesterday' : `${-diff} days late`;
    if (diff < 2) return NL.describe(due, d.today);
    if (diff < 7) return at(due).toLocaleDateString(undefined, { weekday: 'long' });
    return at(due).toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
  }

  async function snooze(li) {
    const prevDue = li.dataset.due, prevHome = li.dataset.home, d = D();
    const moveTo = async (due, home) => {
      li.dataset.due = due; li.dataset.home = home;
      const label = li.querySelector('.tdo-due');
      if (label) { label.className = 'tdo-due' + (home === 'overdue' ? ' is-late' : home === 'today' ? ' is-today' : ''); label.lastChild.textContent = ' ' + dueLabel(due); }
      li.querySelector('.tdo-snooze')?.toggleAttribute('hidden', !['overdue', 'today'].includes(home));
      await Trackie.UI.transition(() => { place(li, home); recount(); }, rows().filter(r => !r.hidden));
    };
    try {
      const res = await post({ action: 'patch', todo_id: li.dataset.id, due_date: d.tomorrow });
      if (!res?.success) throw res;
      await moveTo(d.tomorrow, 'tomorrow');
      Trackie.Toast.action('Moved to tomorrow.', 'Undo', async () => {
        const r = await post({ action: 'patch', todo_id: li.dataset.id, due_date: prevDue });
        if (r?.success) await moveTo(prevDue, prevHome); else Trackie.UI.toastError(r, "Couldn't undo.");
      });
    } catch (err) { Trackie.UI.toastError(err, "Couldn't move that todo."); }
  }

  // ── Quick add ─────────────────────────────────────────────────────
  main.addEventListener('focusin', e => { if (e.target.id === 'tdoAddInput') NL.load(); });
  main.addEventListener('input', e => {
    if (e.target.id === 'tdoAddInput') NL.hint($('tdoAddHint'), e.target.value, D().today, 'No date');
    if (e.target.id === 'tdoSearch') { query = e.target.value.trim().toLowerCase(); apply(); }
  });
  main.addEventListener('submit', async e => {
    if (e.target.id !== 'tdoAdd') return;
    e.preventDefault();
    await NL.load();
    const { title, priority, due, tags } = NL.parse($('tdoAddInput').value);
    if (!title) { $('tdoAddInput').focus(); return; }
    const btn = e.target.querySelector('button[type="submit"]'); btn.disabled = true;
    try {
      const res = await post({ action: 'add', title, priority, due_date: due || '', tags: tags.join(',') });
      if (!res?.success) throw res;
      $('tdoAddInput').value = ''; NL.hint($('tdoAddHint'), '', D().today, 'No date');
      await refresh();
      const li = $('todo-' + res.id);
      if (li) {
        li.classList.add('is-new');
        if (li.hidden) { setFilter('all'); }
        li.scrollIntoView({ block: 'nearest', behavior: reduce() ? 'auto' : 'smooth' });
      }
      Trackie.Toast.success(due ? `Added for ${NL.describe(due, D().today)}` : 'Added', 1800);
    } catch (err) { Trackie.UI.toastError(err, "Couldn't add that todo."); }
    finally { btn.disabled = false; $('tdoAddInput').focus(); }
  });

  async function refresh() { await Trackie.refreshFragments(['todoStatsWrap', 'todoList', 'tdoChips']); apply(); }
  window.todosRefresh = refresh;
  apply();
})();

async function deleteTodo(id) {
  const ok = await Trackie.confirmDialog('Delete this todo?', { confirmText: 'Delete', danger: true });
  if (!ok) return;
  try {
    const res = await Trackie.API.post(`${API_BASE}/todos.php`, { action: 'delete', todo_id: id });
    if (!res.success) throw res;
    const row = document.getElementById(`todo-${id}`);
    await Trackie.UI.transition(() => row?.remove(), [...document.querySelectorAll('.tdo-row:not([hidden])')]);
    window.todosRefresh?.();
    Trackie.Toast.action('Todo deleted.', 'Undo', async () => {
      const r = await Trackie.API.post(`${API_BASE}/todos.php`, { action: 'restore', todo_id: id });
      if (r.success) { Trackie.Toast.success('Restored.'); await window.todosRefresh?.(); }
      else Trackie.UI.toastError(r, 'Could not restore.');
    });
  } catch (e) { Trackie.UI.toastError(e, 'Delete failed.'); }
}

async function toggleSubtasks(id, btn) {
  const panel = document.getElementById('subtasks-' + id);
  if (!panel) return;
  const open = panel.hidden;
  panel.hidden = !open;
  btn?.setAttribute('aria-expanded', String(open));
  if (open) await loadSubtasks(id);
}

async function loadSubtasks(id) {
  const panel = document.getElementById('subtasks-' + id);
  if (!panel) return;
  await Trackie.UI.load(panel, () => Trackie.API.post(API_BASE + '/todos.php', { action: 'subtasks', parent_id: id }), res => {
    const items = res.subtasks.map(s => `
      <li class="tdo-sub${s.completed ? ' done' : ''}">
        <input type="checkbox" class="todo-check" ${s.completed ? 'checked' : ''} aria-label="${escHtml(s.title)}"
               onchange="toggleSubtask(${+s.id}, this.checked, ${+id})">
        <span class="todo-title">${escHtml(s.title)}</span>
        <button type="button" class="tdo-act" aria-label="Delete subtask ${escHtml(s.title)}" onclick="deleteSubtask(${+s.id}, ${+id})"><i class="fas fa-xmark" aria-hidden="true"></i></button>
      </li>`).join('');
    panel.innerHTML = `<ul class="tdo-sublist">${items}</ul>
      <form class="tdo-subadd" onsubmit="event.preventDefault(); addSubtask(${+id}, this.elements.t)">
        <input name="t" class="form-input" placeholder="Add a step…" aria-label="New subtask" maxlength="150">
        <button type="submit" class="btn btn-secondary btn-sm" aria-label="Add subtask"><i class="fas fa-plus" aria-hidden="true"></i></button>
      </form>`;
    // Keep the "2/5" on the row in step with what's actually in the panel.
    const total = res.subtasks.length, done = res.subtasks.filter(s => +s.completed).length;
    const row = document.getElementById('todo-' + id);
    let pill = row?.querySelector('.tdo-subcount');
    if (total && !pill && row) {
      pill = Object.assign(document.createElement('span'), { className: 'tdo-subcount', title: 'Subtasks done' });
      row.querySelector('.td-meta')?.append(pill);
    }
    if (pill) { if (total) pill.innerHTML = `<i class="fas fa-list-check" aria-hidden="true"></i> <b>${done}</b>/${total}`; else pill.remove(); }
  }, { label: 'Loading steps…', rows: 2, fallback: 'Could not load subtasks.' });
}

async function addSubtask(parentId, inputEl) {
  const title = inputEl.value.trim();
  if (!title) return;
  inputEl.disabled = true;
  try {
    const res = await Trackie.API.post(API_BASE + '/todos.php', { action: 'add_subtask', parent_id: parentId, title });
    if (!res.success) throw res;
    await loadSubtasks(parentId);
    document.querySelector(`#subtasks-${parentId} input[name="t"]`)?.focus();
  } catch (e) { Trackie.UI.toastError(e, 'Could not add subtask.'); inputEl.disabled = false; }
}

async function toggleSubtask(id, checked, parentId) {
  try {
    const res = await Trackie.API.post(API_BASE + '/todos.php', { action: 'toggle', todo_id: id, completed: checked ? 1 : 0 });
    if (!res.success) throw res;
  } catch (e) { Trackie.UI.toastError(e, 'Could not update subtask.'); }
  await loadSubtasks(parentId);
}

async function deleteSubtask(id, parentId) {
  try {
    const res = await Trackie.API.post(API_BASE + '/todos.php', { action: 'delete', todo_id: id });
    if (!res.success) throw res;
    await loadSubtasks(parentId);
  } catch (e) { Trackie.UI.toastError(e, 'Delete failed.'); }
}

function openAddTodo() {
  for (const [k, v] of Object.entries({ todoId: '', todoTitle: '', todoDesc: '', todoDue: '', todoPriority: 'medium', todoLocation: '', todoRecurring: 'none', todoCategory: '', todoTags: '' })) {
    document.getElementById(k).value = v;
  }
  document.getElementById('todoModalTitle').textContent = 'New Todo';
  document.getElementById('todoDeleteBtn').hidden = true;
  Trackie.openModal('todoModal');
}

async function editTodo(id) {
  try {
    const res = await Trackie.API.post(`${API_BASE}/todos.php`, { action: 'get', todo_id: id });
    if (!res.success) throw res;
    const t = res.todo;
    document.getElementById('todoId').value        = t.id;
    document.getElementById('todoTitle').value     = t.title;
    document.getElementById('todoDesc').value      = t.description || '';
    document.getElementById('todoDue').value       = t.due_date || '';
    document.getElementById('todoPriority').value  = t.priority;
    document.getElementById('todoLocation').value  = t.location || '';
    document.getElementById('todoRecurring').value = t.recurring || 'none';
    document.getElementById('todoCategory').value  = t.category || '';
    document.getElementById('todoTags').value      = t.tags || '';
    document.getElementById('todoModalTitle').textContent = 'Edit Todo';
    document.getElementById('todoDeleteBtn').hidden = false;   // on phones this is the way to delete
    Trackie.openModal('todoModal');
  } catch (e) { Trackie.UI.toastError(e, 'Could not load that todo.'); }
}

async function saveTodo() {
  const id    = document.getElementById('todoId').value;
  const title = document.getElementById('todoTitle').value.trim();
  if (!title) { Trackie.Toast.warning('Title is required.'); return; }
  const v = k => document.getElementById(k).value;
  try {
    const res = await Trackie.API.post(`${API_BASE}/todos.php`, {
      action: id ? 'edit' : 'add', todo_id: id || '', title,
      description: v('todoDesc'), due_date: v('todoDue'), priority: v('todoPriority'), location: v('todoLocation'),
      recurring: v('todoRecurring'), category: v('todoCategory'), tags: v('todoTags'),
    });
    if (!res.success) throw res;
    Trackie.Toast.success(id ? 'Todo updated.' : 'Todo added.');
    Trackie.closeModal('todoModal');
    await window.todosRefresh?.();
    if (!id && res.id) document.getElementById('todo-' + res.id)?.classList.add('is-new');
  } catch (e) { Trackie.UI.toastError(e, 'Save failed.'); }
}
</script>
