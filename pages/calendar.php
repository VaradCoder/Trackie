<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Calendar';
$currentPage = 'calendar';

// Validate month/year
$month = max(1, min(12, (int)($_GET['month'] ?? date('n'))));
$year  = max(2000, min(2100, (int)($_GET['year'] ?? date('Y'))));

$firstDay     = mktime(0, 0, 0, $month, 1, $year);
$daysInMonth  = (int)date('t', $firstDay);
require_once '../includes/settings.php';
$weekStart    = userSetting($uid, 'week_start');            // 0 = Sunday, 1 = Monday
// Blank cells before the 1st, counted from the user's first weekday.
$startWeekday = ((int)date('w', $firstDay) - $weekStart + 7) % 7;
$dayNames     = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
if ($weekStart === 1) $dayNames = array_merge(array_slice($dayNames, 1), ['Sun']);
$startDate    = date('Y-m-01', $firstDay);
$endDate      = date('Y-m-t',  $firstDay);
$todayStr     = date('Y-m-d');

// Every dated thing in Trackie for this month, one event shape (includes/calendar.php).
require_once '../includes/calendar.php';
$events = calendarEvents($uid, $startDate, $endDate);
$byDate = [];
foreach ($events as $e) $byDate[$e['date']][] = $e;
ksort($byDate);

$tasks     = array_filter($events, static fn($e) => $e['actionable']);
$monthDone = count(array_filter($tasks, static fn($e) => $e['done']));
$monthLate = count(array_filter($tasks, static fn($e) => !$e['done'] && $e['date'] < $todayStr));

// The day the agenda opens on: ?day=, else today if it's in this month, else the 1st.
$selected = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['day'] ?? '') && substr($_GET['day'], 0, 7) === date('Y-m', $firstDay)
    ? $_GET['day'] : (date('Y-m', $firstDay) === date('Y-m') ? $todayStr : $startDate);

$prev = strtotime('-1 month', $firstDay);
$next = strtotime('+1 month', $firstDay);

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<?= renderPageHeader(date('F Y', $firstDay), [
  'icon'    => 'fa-calendar',
  'sub'     => 'Everything dated in Trackie — tasks, reminders, deadlines, workouts and more.',
  'actions' =>
      '<a href="?month=' . date('n', $prev) . '&year=' . date('Y', $prev) . '" class="btn btn-icon btn-secondary btn-sm" aria-label="Previous month"><i class="fas fa-chevron-left" aria-hidden="true"></i></a>'
    . '<a href="?month=' . date('n') . '&year=' . date('Y') . '" class="btn btn-secondary btn-sm">Today</a>'
    . '<a href="?month=' . date('n', $next) . '&year=' . date('Y', $next) . '" class="btn btn-icon btn-secondary btn-sm" aria-label="Next month"><i class="fas fa-chevron-right" aria-hidden="true"></i></a>',
]) ?>

<div class="pg-strip" id="calStrip">
  <span><strong><?= count($tasks) ?></strong> task<?= count($tasks) === 1 ? '' : 's' ?> this month</span>
  <span><strong><?= $monthDone ?></strong> done</span>
  <?php if ($monthLate): ?><span class="is-late"><strong><?= $monthLate ?></strong> overdue</span><?php endif; ?>
</div>

<div class="pg-chips u-mb-3" id="calSources" role="toolbar" aria-label="Show on the calendar">
  <?php foreach (CALENDAR_SOURCES as $k => [$label, $icon, $color]): ?>
    <button type="button" class="pg-chip cal-src" data-src="<?= $k ?>" aria-pressed="true" style="--c:<?= $color ?>">
      <span class="cal-src-dot" aria-hidden="true"></span> <?= h($label) ?>
    </button>
  <?php endforeach; ?>
</div>

<div class="cal-layout">
  <div class="card cal-month">
    <div class="cal-grid2" role="grid" aria-label="<?= h(date('F Y', $firstDay)) ?>" id="calGrid">
      <?php foreach ($dayNames as $d): ?><div class="cal-dow" role="columnheader"><?= $d ?></div><?php endforeach; ?>
      <?php for ($i = 0; $i < $startWeekday; $i++): ?><div class="cal-pad" aria-hidden="true"></div><?php endfor; ?>
      <?php for ($day = 1; $day <= $daysInMonth; $day++):
        $dateStr = date('Y-m-d', mktime(0, 0, 0, $month, $day, $year)); ?>
        <button type="button" class="cal-cell<?= $dateStr === $todayStr ? ' is-today' : '' ?><?= $dateStr < $todayStr ? ' is-past' : '' ?>"
                data-date="<?= $dateStr ?>" role="gridcell" aria-selected="<?= $dateStr === $selected ? 'true' : 'false' ?>"
                tabindex="<?= $dateStr === $selected ? '0' : '-1' ?>"
                aria-label="<?= h(date('l j F', strtotime($dateStr))) ?>">
          <span class="cal-num"><?= $day ?></span>
          <span class="cal-marks" aria-hidden="true"></span>
        </button>
      <?php endfor; ?>
    </div>
  </div>

  <aside class="card cal-agenda" aria-labelledby="calAgendaTitle">
    <header class="cal-agenda-head">
      <div>
        <h2 class="cal-agenda-title" id="calAgendaTitle"></h2>
        <p class="cal-agenda-sub" id="calAgendaSub"></p>
      </div>
    </header>
    <ul class="td-list cal-agenda-list" id="calAgendaList" aria-live="polite"></ul>
    <div class="cal-agenda-empty" id="calAgendaEmpty" hidden>
      <i class="fas fa-mug-hot" aria-hidden="true"></i> Nothing on this day.
    </div>
    <form class="td-add cal-add" id="calAdd" autocomplete="off">
      <label class="td-add-row">
        <i class="fas fa-plus" aria-hidden="true"></i>
        <span class="sr-only">Add a todo for this day</span>
        <input class="td-add-input" id="calAddInput" maxlength="150" enterkeyhint="done" placeholder="Add a todo for this day…">
        <button type="submit" class="btn btn-primary btn-sm td-add-btn">Add</button>
      </label>
      <div class="td-add-hint" id="calAddHint" aria-live="polite"></div>
    </form>
  </aside>
</div>


</div>
<?php include '../includes/footer.php'; ?>

<script>
(() => {
  const main = document.getElementById('page-main');
  const grid = document.getElementById('calGrid');
  if (!main || !grid) return;
  const base = document.querySelector('meta[name="app-base"]')?.content || '';
  const TODAY = <?= json_encode($todayStr) ?>;
  const byDate = <?= json_encode($byDate, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const SRC = <?= json_encode(array_map(fn($v) => ['label' => $v[0], 'icon' => $v[1], 'color' => $v[2]], CALENDAR_SOURCES)) ?>;
  const $ = id => document.getElementById(id);
  let selected = <?= json_encode($selected) ?>;
  let hidden = new Set();
  try { hidden = new Set(JSON.parse(localStorage.getItem('trackie.calHidden') || '["routine"]')); } catch { /* defaults */ }

  const visible = d => (byDate[d] || []).filter(e => !hidden.has(e.source));
  /** Only in-app links, or Google Calendar's own event pages. */
  const safeLink = u => typeof u === 'string' && (u.startsWith('/') || u.startsWith('https://www.google.com/calendar') || u.startsWith('https://calendar.google.com/')) ? u : null;

  // ── Month cells: a dot per source (up to 4), open tasks counted ─────
  function paintCell(cell) {
    const items = visible(cell.dataset.date);
    const marks = cell.querySelector('.cal-marks');
    const srcs = [...new Set(items.map(e => e.source))].slice(0, 4);
    marks.innerHTML = srcs.map(s => `<i style="background:${escHtml(SRC[s]?.color || '#64748b')}"></i>`).join('');
    const open = items.filter(e => e.actionable && !e.done).length;
    cell.classList.toggle('has-items', items.length > 0);
    cell.classList.toggle('has-late', open > 0 && cell.dataset.date < TODAY);
    const label = new Date(cell.dataset.date + 'T12:00:00').toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' });
    cell.setAttribute('aria-label', `${label}: ${items.length ? items.length + ' item' + (items.length === 1 ? '' : 's') + (open ? `, ${open} open` : '') : 'nothing'}`);
  }
  const paintAll = () => grid.querySelectorAll('.cal-cell').forEach(paintCell);

  // ── Agenda for the selected day ─────────────────────────────────────
  const tick = '<svg viewBox="0 0 26 26" aria-hidden="true"><circle class="td-check-ring" cx="13" cy="13" r="11.5"/><path class="td-check-tick" d="M8 13.4l3.2 3.2L18 9.8"/></svg>';
  function paintAgenda() {
    const d = new Date(selected + 'T12:00:00');
    $('calAgendaTitle').textContent = selected === TODAY ? 'Today' : d.toLocaleDateString(undefined, { weekday: 'long' });
    $('calAgendaSub').textContent = d.toLocaleDateString(undefined, { day: 'numeric', month: 'long', year: 'numeric' });
    const items = visible(selected).slice().sort((a, b) => (a.time || '99') < (b.time || '99') ? -1 : (a.time || '99') > (b.time || '99') ? 1 : (a.done - b.done));
    $('calAgendaList').innerHTML = items.map(e => {
      const s = SRC[e.source] || { icon: 'fa-circle', color: '#64748b', label: '' };
      const link = safeLink(e.link);
      const lead = e.actionable
        ? `<button type="button" class="td-check" role="checkbox" aria-checked="${e.done}" data-uid="${escHtml(e.uid)}" aria-label="${escHtml(e.title)}">${tick}</button>`
        : `<span class="td-check td-check-static" aria-hidden="true"><i class="fas ${escHtml(s.icon)}" style="color:${escHtml(s.color)}"></i></span>`;
      const meta = [e.time ? `<span><i class="fas fa-clock" aria-hidden="true"></i> ${escHtml(e.time)}</span>` : '',
                    `<span style="color:${escHtml(s.color)};font-weight:700">${escHtml(s.label)}</span>`,
                    e.actionable && e.priority && e.priority !== 'medium' ? `<span class="tdo-pri ${escHtml(e.priority)}">${escHtml(e.priority[0].toUpperCase() + e.priority.slice(1))}</span>` : '',
                    e.state === 'skipped' ? '<span>Skipped</span>' : e.state === 'rest' ? '<span>Rest day</span>' : ''].join('');
      return `<li class="td-item${e.done ? ' is-done' : ''}" style="--item-c:${escHtml(s.color)}">${lead}
        <div class="td-body"><div class="td-title">${escHtml(e.title)}</div><div class="td-meta">${meta}</div></div>
        ${link ? `<a class="pg-icon-btn" href="${escHtml(link)}" ${link.startsWith('http') ? 'target="_blank" rel="noopener"' : ''} aria-label="Open ${escHtml(e.title)}"><i class="fas fa-arrow-right" aria-hidden="true"></i></a>` : ''}
      </li>`;
    }).join('');
    $('calAgendaEmpty').hidden = items.length > 0;
    $('calAddInput').placeholder = selected === TODAY ? 'Add a todo for today…' : `Add a todo for ${d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' })}…`;
  }

  function select(date, { focus = false } = {}) {
    selected = date;
    grid.querySelectorAll('.cal-cell').forEach(c => {
      const on = c.dataset.date === date;
      c.setAttribute('aria-selected', String(on)); c.tabIndex = on ? 0 : -1;
      if (on && focus) c.focus();
    });
    paintAgenda();
    const u = new URL(location.href); u.searchParams.set('day', date); history.replaceState(history.state, '', u);
  }

  // ── Interaction ─────────────────────────────────────────────────────
  grid.addEventListener('click', e => {
    const cell = e.target.closest('.cal-cell');
    if (!cell) return;
    select(cell.dataset.date);
    if (matchMedia('(max-width: 900px)').matches) $('calAgendaTitle').scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
  });
  // Arrow keys move day by day / week by week (roving tabindex, one tab stop).
  grid.addEventListener('keydown', e => {
    const cell = e.target.closest('.cal-cell');
    if (!cell) return;
    const step = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[e.key];
    const cells = [...grid.querySelectorAll('.cal-cell')];
    let i = cells.indexOf(cell);
    if (step) i += step; else if (e.key === 'Home') i = 0; else if (e.key === 'End') i = cells.length - 1; else return;
    e.preventDefault();
    if (cells[i]) select(cells[i].dataset.date, { focus: true });
  });

  $('calSources').addEventListener('click', e => {
    const b = e.target.closest('.cal-src'); if (!b) return;
    hidden.has(b.dataset.src) ? hidden.delete(b.dataset.src) : hidden.add(b.dataset.src);
    try { localStorage.setItem('trackie.calHidden', JSON.stringify([...hidden])); } catch { /* per-device only */ }
    paintChips(); paintAll(); paintAgenda();
  });
  const paintChips = () => document.querySelectorAll('#calSources .cal-src').forEach(b => b.setAttribute('aria-pressed', String(!hidden.has(b.dataset.src))));

  // Tick a task in the agenda: optimistic, no page reload.
  $('calAgendaList').addEventListener('click', async e => {
    const btn = e.target.closest('button.td-check');
    if (!btn || btn.getAttribute('aria-busy') === 'true') return;
    const ev = (byDate[selected] || []).find(x => x.uid === btn.dataset.uid);
    if (!ev) return;
    const done = !ev.done;
    btn.setAttribute('aria-checked', String(done)); btn.setAttribute('aria-busy', 'true');
    btn.closest('.td-item').classList.toggle('is-done', done);
    if (done) Trackie.Delight?.tap();
    try {
      const res = await Trackie.API.post(`${base}/api/${ev.source === 'study' ? 'study_plan' : 'todos'}.php`,
        ev.source === 'study' ? { action: 'toggle', task_id: ev.id, completed: done ? 1 : 0 } : { action: 'toggle', todo_id: ev.id, completed: done ? 1 : 0 });
      if (!res?.success) throw res;
      ev.done = done;
      if (res.achievements?.length) Trackie.showAchievementToasts?.(res.achievements);
      if (done && res.xp?.gained) Trackie.Toast.success(`+${res.xp.gained} XP`, 1600);
      paintCell(grid.querySelector(`.cal-cell[data-date="${selected}"]`));
      await new Promise(r => setTimeout(r, 250));
      Trackie.UI.transition(paintAgenda, [...$('calAgendaList').children]);
    } catch (err) {
      btn.setAttribute('aria-checked', String(!done)); btn.closest('.td-item').classList.toggle('is-done', !done);
      Trackie.UI.toastError(err, "Couldn't update that task.");
    } finally { btn.removeAttribute('aria-busy'); }
  });

  // Quick add for the selected day — plain words can still name another day.
  const NL = Trackie.NL;
  main.addEventListener('focusin', e => { if (e.target.id === 'calAddInput') NL.load(); });
  main.addEventListener('input', e => { if (e.target.id === 'calAddInput') NL.hint($('calAddHint'), e.target.value, TODAY, NL.describe(selected, TODAY)); });
  $('calAdd').addEventListener('submit', async e => {
    e.preventDefault();
    await NL.load();
    const p = NL.parse($('calAddInput').value);
    if (!p.title) return;
    const due = p.due || selected;
    const btn = e.target.querySelector('button[type="submit"]'); btn.disabled = true;
    try {
      const res = await Trackie.API.post(`${base}/api/todos.php`, { action: 'add', title: p.title, priority: p.priority, due_date: due, tags: p.tags.join(',') });
      if (!res?.success) throw res;
      $('calAddInput').value = ''; NL.hint($('calAddHint'), '', TODAY);
      (byDate[due] ||= []).push({ uid: 'todo-' + res.id, source: 'todo', id: +res.id, title: p.title, date: due, time: null, done: false, actionable: true, priority: p.priority, link: `${base}/pages/todos.php` });
      hidden.delete('todo'); paintChips();
      const cell = grid.querySelector(`.cal-cell[data-date="${due}"]`);
      if (cell) { paintCell(cell); cell.classList.remove('is-bumped'); void cell.offsetWidth; cell.classList.add('is-bumped'); }
      if (due === selected) paintAgenda(); else Trackie.Toast.success(`Added for ${NL.describe(due, TODAY)}`, 2200);
    } catch (err) { Trackie.UI.toastError(err, "Couldn't add that todo."); }
    finally { btn.disabled = false; $('calAddInput').focus(); }
  });

  paintChips(); paintAll(); select(selected);
})();
</script>
