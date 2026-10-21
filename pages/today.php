<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
if (is_file(__DIR__ . '/../includes/gamification.php')) {
    require_once __DIR__ . '/../includes/gamification.php';
}

requireAuth();

$uid         = currentUserId();
resetRecurringTodos($uid);
$pageTitle   = 'Today';
$currentPage = 'today';
$today       = date('Y-m-d');
$hour        = (int)date('G');
$greeting    = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$userName    = $_SESSION['user_name'] ?? 'there';
$firstName   = trim(explode(' ', (string)$userName)[0]) ?: 'there';

require_once '../includes/habit_schedule.php';
require_once '../includes/routines.php';
require_once '../includes/activity.php';

/* ════════════════════════════════════════════════════════════════
   One list of everything on today's plate. Each item:
     key, kind (todo|study|habit|routine|workout), id, title, done,
     section ('overdue'|'today'), sort (string), icon, color, meta[], link
   ════════════════════════════════════════════════════════════════ */
$items = [];
$priRank = ['high' => 0, 'medium' => 1, 'low' => 2];
$recurLabel = ['daily' => 'Every day', 'weekly' => 'This week', 'monthly' => 'This month'];

// ── Todos: due today, overdue (one-off), and recurring ones for this period ──
foreach (fetchAll(
    "SELECT id, title, priority, completed, due_date, recurring FROM todos
      WHERE user_id=? AND deleted_at IS NULL AND parent_id IS NULL AND (
            due_date = ?
            -- overdue one-offs: still open, or finished today (they stay in Done until tomorrow)
         OR (recurring = 'none' AND due_date < ? AND (completed = 0 OR DATE(completed_at) = ?))
         OR (recurring <> 'none' AND (due_date IS NULL OR due_date <= ?)))
      ORDER BY due_date IS NULL, due_date LIMIT 100",
    [$uid, $today, $today, $today, $today]) as $t) {
    $overdue = $t['recurring'] === 'none' && $t['due_date'] && $t['due_date'] < $today;
    $meta = [];
    if ($overdue) {
        $days = (int)((strtotime($today) - strtotime($t['due_date'])) / 86400);
        $meta[] = ['html' => '<span class="td-due-late">' . ($days === 1 ? 'Yesterday' : $days . ' days late') . '</span>'];
    }
    if ($t['recurring'] !== 'none') $meta[] = ['icon' => 'fa-repeat', 'text' => $recurLabel[$t['recurring']] ?? 'Repeats'];
    if ($t['priority'] !== 'medium') $meta[] = ['html' => '<span class="td-pri ' . h($t['priority']) . '">' . ucfirst(h($t['priority'])) . '</span>'];
    $items[] = [
        'key' => 'todo-' . $t['id'], 'kind' => 'todo', 'id' => (int)$t['id'], 'title' => $t['title'],
        'done' => (bool)$t['completed'], 'section' => $overdue ? 'overdue' : 'today',
        'sort' => '3' . ($priRank[$t['priority']] ?? 1) . str_pad((string)$t['id'], 9, '0', STR_PAD_LEFT),
        'icon' => 'fa-check', 'label' => 'Todo', 'color' => $t['priority'] === 'high' ? '#ef4444' : 'var(--ok)', 'meta' => $meta,
    ];
}

// ── Study tasks due today ──
if (tableExists('study_plan')) {
    foreach (fetchAll("SELECT id, title, priority, completed FROM study_plan WHERE user_id=? AND due_date=?", [$uid, $today]) as $t) {
        $items[] = [
            'key' => 'study-' . $t['id'], 'kind' => 'study', 'id' => (int)$t['id'], 'title' => $t['title'],
            'done' => (bool)$t['completed'], 'section' => 'today',
            'sort' => '3' . ($priRank[$t['priority']] ?? 1) . str_pad((string)$t['id'], 9, '0', STR_PAD_LEFT),
            'icon' => 'fa-graduation-cap', 'label' => 'Study', 'color' => '#8b5cf6', 'meta' => [],
        ];
    }
}

// ── Habits due today (schedule-aware); the rest are listed as "not due" ──
$weekStart = (int)userSetting($uid, 'week_start');
$hLogs = [];
foreach (fetchAll("SELECT l.habit_id, l.date_completed d FROM logs l JOIN habits h ON h.id=l.habit_id
                   WHERE h.user_id=? AND l.date_completed >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)", [$uid]) as $r) {
    $hLogs[$r['habit_id']][] = $r['d'];
}
$notDue = [];
foreach (fetchAll("SELECT id, name, color, frequency, schedule_days FROM habits WHERE user_id=? ORDER BY name", [$uid]) as $h) {
    $done = in_array($today, $hLogs[$h['id']] ?? [], true);
    if (!$done && !habitDueOn($h, $today, $hLogs[$h['id']] ?? [], $weekStart)) { $notDue[] = $h['name']; continue; }
    $items[] = [
        'key' => 'habit-' . $h['id'], 'kind' => 'habit', 'id' => (int)$h['id'], 'title' => $h['name'],
        'done' => $done, 'section' => 'today', 'sort' => '2' . strtolower($h['name']),
        'icon' => 'fa-heart', 'label' => 'Habit', 'color' => $h['color'] ?: '#ef4444',
        'meta' => [['text' => habitScheduleLabel($h)]],
    ];
}

// ── Routines (time-ordered; skipped / rest-day ones aren't owed today) ──
$routineRestToday = (bool)routineRestDays($uid, $today, $today);
$routinesSkipped  = [];
foreach (routinesOnDay($uid, $today) as $r) {
    if ($r['state'] === 'off') continue;
    if ($r['state'] === 'skipped' || $r['state'] === 'rest') { $routinesSkipped[] = $r['title'] . ($r['state'] === 'skipped' ? ' (skipped today)' : ''); continue; }
    $items[] = [
        'key' => 'routine-' . $r['id'], 'kind' => 'routine', 'id' => (int)$r['id'], 'title' => $r['title'],
        'done' => $r['state'] === 'done', 'section' => 'today', 'sort' => '1' . substr($r['time_slot'], 0, 5),
        'icon' => 'fa-clock', 'label' => date('g:i A', strtotime($r['time_slot'])), 'color' => 'var(--info)', 'meta' => [],
    ];
}

// ── Today's planned workout (started on the Fitness page) ──
if (tableExists('workout_plans')) {
    $plan = fetchOne("SELECT id, name FROM workout_plans WHERE user_id=? AND day_of_week=? ORDER BY id LIMIT 1", [$uid, date('l')]);
    if ($plan) {
        $trained = (bool)fetchOne("SELECT id FROM workout_logs WHERE user_id=? AND log_date=? LIMIT 1", [$uid, $today]);
        $items[] = [
            'key' => 'workout-' . $plan['id'], 'kind' => 'workout', 'id' => (int)$plan['id'], 'title' => $plan['name'],
            'done' => $trained, 'section' => 'today', 'sort' => '15',
            'icon' => 'fa-dumbbell', 'label' => 'Workout', 'color' => '#f97316', 'meta' => [],
            'link' => APP_BASE . '/pages/gym.php',
        ];
    }
}

usort($items, static fn($a, $b) => strcmp($a['sort'], $b['sort']));
$total   = count($items);
$doneN   = count(array_filter($items, static fn($i) => $i['done']));
$pct     = $total ? (int)round($doneN / $total * 100) : 0;
$open    = array_values(array_filter($items, static fn($i) => !$i['done']));
$overdue = array_values(array_filter($open, static fn($i) => $i['section'] === 'overdue'));
$todayOpen = array_values(array_filter($open, static fn($i) => $i['section'] === 'today'));
$doneList  = array_values(array_filter($items, static fn($i) => $i['done']));
$next      = $todayOpen[0] ?? $overdue[0] ?? null;

// ── Reminders still to come today, goals snapshot ──
$remindersToday = tableExists('reminders')
    ? fetchAll("SELECT id, title, next_fire_at FROM reminders
                 WHERE user_id = ? AND active = 1 AND next_fire_at BETWEEN NOW() AND CONCAT(CURDATE(), ' 23:59:59')
                 ORDER BY next_fire_at LIMIT 6", [$uid])
    : [];
require_once '../includes/goal_sources.php';
syncLinkedGoals($uid);
$goals = fetchAll(
    "SELECT id, goal_name, progress, target_value, deadline FROM goals
      WHERE user_id=? AND progress < target_value ORDER BY (deadline IS NULL), deadline ASC LIMIT 2", [$uid]);

// ── Streak / level / score ──
$xpData     = function_exists('xpSummary')   ? xpSummary($uid)   : ['level' => 1, 'title' => 'Beginner'];
$scoreData  = function_exists('trackieScore') ? trackieScore($uid) : ['score' => 0, 'has_data' => false];
$streakData = activityReady() ? activityStreak($uid) : habitStreaks($uid);

/** One row of the list. Everything user-supplied is escaped here. */
function renderTodayItem(array $it, bool $new = false): string {
    $meta = '<span><i class="fas ' . h($it['icon']) . '" aria-hidden="true"></i> ' . h($it['label']) . '</span>';
    foreach ($it['meta'] as $m) {
        $meta .= isset($m['html']) ? $m['html']
               : '<span>' . (isset($m['icon']) ? '<i class="fas ' . h($m['icon']) . '" aria-hidden="true"></i> ' : '') . h($m['text']) . '</span>';
    }
    $title = h($it['title']);
    if ($it['kind'] === 'workout') {
        $control = $it['done']
            ? '<span class="td-check td-check-static" aria-hidden="true">' . todayTickSvg() . '</span>'
            : '<a class="td-check td-check-static" href="' . h($it['link']) . '" aria-label="Start ' . $title . '">' . todayTickSvg() . '</a>';
        $after = $it['done'] ? '' : '<a class="td-go" href="' . h($it['link']) . '"><i class="fas fa-play" aria-hidden="true"></i> Start</a>';
    } else {
        $control = '<button type="button" class="td-check" role="checkbox" aria-checked="' . ($it['done'] ? 'true' : 'false') . '"'
                 . ' aria-label="' . $title . '">' . todayTickSvg() . '</button>';
        $after = '';
    }
    return '<li class="td-item' . ($it['done'] ? ' is-done' : '') . ($new ? ' is-new' : '') . '"'
         . ' data-key="' . h($it['key']) . '" data-kind="' . h($it['kind']) . '" data-id="' . (int)$it['id'] . '"'
         . ' data-section="' . h($it['section']) . '" data-sort="' . h($it['sort']) . '"'
         . ' style="--item-c:' . h($it['color']) . '">'
         . $control
         . '<div class="td-body"><div class="td-title">' . $title . '</div><div class="td-meta">' . $meta . '</div></div>'
         . $after . '</li>';
}
function todayTickSvg(): string {
    return '<svg viewBox="0 0 26 26" aria-hidden="true"><circle class="td-check-ring" cx="13" cy="13" r="11.5"/>'
         . '<path class="td-check-tick" d="M8 13.4l3.2 3.2L18 9.8"/></svg>';
}

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<div id="todayBoard" class="td-page<?= $total && $doneN === $total ? ' is-clear' : '' ?>"
     data-today="<?= h($today) ?>" data-chrono="<?= h(assetUrl('assets/vendor/chrono.min.js')) ?>">

  <section class="td-hero" aria-labelledby="tdHello">
    <div class="td-hero-text">
      <h1 class="td-hello" id="tdHello"><?= h($greeting) ?>, <?= h($firstName) ?></h1>
      <p class="td-date"><?= date('l, F j') ?></p>
      <p class="td-summary" id="tdSummary" aria-live="polite">
        <?php if (!$total): ?>
          A clean slate. Add the first thing you want to get done today.
        <?php elseif ($doneN === $total): ?>
          Everything's done. Enjoy the rest of your day.
        <?php else: ?>
          <strong><?= $total - $doneN ?></strong> left<?= $next ? ' · next up: ' . h($next['title']) : '' ?>
        <?php endif; ?>
      </p>
    </div>
    <div class="td-ring<?= $total && $doneN === $total ? ' is-complete' : '' ?><?= $doneN ? '' : ' is-empty' ?>" id="tdRing" style="--p:<?= $pct ?>"
         role="img" aria-label="<?= $doneN ?> of <?= $total ?> done today">
      <svg viewBox="0 0 100 100" aria-hidden="true">
        <circle class="td-ring-track" cx="50" cy="50" r="42" pathLength="100"/>
        <circle class="td-ring-fill"  cx="50" cy="50" r="42" pathLength="100"/>
      </svg>
      <div class="td-ring-label" aria-hidden="true">
        <span class="td-ring-num"><span id="tdDoneN"><?= $doneN ?></span>/<span id="tdTotalN"><?= $total ?></span></span>
        <span class="td-ring-sub">done</span>
      </div>
    </div>
    <div class="td-chips">
      <a class="td-chip" href="<?= APP_BASE ?>/pages/progress.php"><i class="fas fa-fire" style="color:#f59e0b" aria-hidden="true"></i> <?= (int)$streakData['current'] > 0 ? (int)$streakData['current'] . '-day streak' : 'Start a streak today' ?></a>
      <a class="td-chip" href="<?= APP_BASE ?>/pages/progress.php"><i class="fas fa-bolt" style="color:var(--accent)" aria-hidden="true"></i> Level <?= (int)($xpData['level'] ?? 1) ?></a>
      <?php if (!empty($scoreData['has_data']) && (int)$scoreData['score'] > 0): ?>
        <a class="td-chip" href="<?= APP_BASE ?>/pages/analytics.php"><i class="fas fa-gauge-high" style="color:var(--info)" aria-hidden="true"></i> Score <?= (int)$scoreData['score'] ?></a>
      <?php endif; ?>
    </div>
  </section>

  <form class="td-add" id="tdAdd" autocomplete="off">
    <label class="td-add-row">
      <i class="fas fa-plus" aria-hidden="true"></i>
      <span class="sr-only">Add a task</span>
      <input class="td-add-input" id="tdAddInput" name="title" maxlength="150" enterkeyhint="done"
             placeholder="Add a task, e.g. “gym at 7am”"
             title="Type a date or time in plain words (tomorrow 6pm, next Fri), and !high or !low for priority">
      <button type="submit" class="btn btn-primary btn-sm td-add-btn">Add</button>
    </label>
    <div class="td-add-hint" id="tdAddHint" aria-live="polite"></div>
  </form>

  <div class="td-clear" role="status">
    <span class="td-clear-emoji" aria-hidden="true">🎉</span>
    <div><strong>All clear for today</strong><span>Every habit, task and routine is done. That's a full day.</span></div>
  </div>

  <section class="td-section is-overdue" id="tdOverdueSec"<?= $overdue ? '' : ' hidden' ?>>
    <h2 class="td-section-head">Overdue <span class="td-count" id="tdOverdueN"><?= count($overdue) ?></span></h2>
    <ul class="td-list" id="tdOverdue"><?php foreach ($overdue as $it) echo renderTodayItem($it); ?></ul>
  </section>

  <section class="td-section" id="tdTodaySec"<?= $todayOpen || !$total ? '' : ' hidden' ?>>
    <h2 class="td-section-head">Up next <span class="td-count" id="tdOpenN"><?= count($todayOpen) ?></span></h2>
    <ul class="td-list" id="tdOpen"><?php foreach ($todayOpen as $it) echo renderTodayItem($it); ?></ul>
    <?php if (!$total): ?>
      <div class="card"><?= renderEmptyState('fa-mug-hot', 'Nothing planned yet',
        'Type a task above, or set up the things you repeat.',
        '<a class="btn btn-secondary btn-sm" href="' . APP_BASE . '/pages/habits.php">Add a habit</a> '
        . '<a class="btn btn-secondary btn-sm" href="' . APP_BASE . '/pages/routines.php">Plan a routine</a>', true) ?></div>
    <?php endif; ?>
  </section>

  <details class="td-section td-done" id="tdDoneSec"<?= $doneList ? '' : ' hidden' ?><?= $doneList && !$todayOpen && !$overdue ? ' open' : '' ?>>
    <summary><i class="fas fa-chevron-right" aria-hidden="true"></i> Done <span class="td-count" id="tdDoneCount"><?= count($doneList) ?></span></summary>
    <ul class="td-list" id="tdDone"><?php foreach ($doneList as $it) echo renderTodayItem($it); ?></ul>
  </details>

  <?php if ($notDue || $routinesSkipped || $routineRestToday): ?>
    <p class="form-hint u-mb-4">
      <?php if ($routineRestToday): ?><i class="fas fa-couch" aria-hidden="true"></i> Rest day — nothing here is expected today. <?php endif; ?>
      <?php if ($notDue): ?><i class="fas fa-moon" aria-hidden="true"></i> Not due today: <?= h(implode(', ', $notDue)) ?>. <?php endif; ?>
      <?php if ($routinesSkipped): ?><i class="fas fa-forward" aria-hidden="true"></i> Not expected: <?= h(implode(', ', $routinesSkipped)) ?>.<?php endif; ?>
    </p>
  <?php endif; ?>
</div>

<?php if ($remindersToday): ?>
<section class="td-section">
  <h2 class="td-section-head">Later today</h2>
  <ul class="td-list">
    <?php foreach ($remindersToday as $r): ?>
      <li class="td-item">
        <span class="td-check td-check-static" aria-hidden="true"><i class="fas fa-bell u-c-muted"></i></span>
        <div class="td-body"><div class="td-title"><?= h($r['title']) ?></div>
          <div class="td-meta"><span><?= date('g:i A', strtotime($r['next_fire_at'])) ?></span></div></div>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php if ($goals): ?>
<section class="td-section">
  <h2 class="td-section-head">Goals <a class="u-fs-12" href="<?= APP_BASE ?>/pages/goals.php">All goals</a></h2>
  <div class="card card-body">
    <?php foreach ($goals as $g):
      $gp = $g['target_value'] > 0 ? min(100, (int)round($g['progress'] / $g['target_value'] * 100)) : 0; ?>
      <div class="u-mb-3">
        <div class="u-flex u-justify-between u-fs-13 u-mb-1"><span><?= h($g['goal_name']) ?></span><span class="u-c-muted"><?= $gp ?>%</span></div>
        <div class="progress-track"><div class="progress-fill" style="width:<?= $gp ?>%"></div></div>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<script>
(() => {
  // Elements are looked up on use and events are delegated from #page-main:
  // a Quick-add refresh replaces #todayBoard, and bound nodes would go stale.
  const main = document.getElementById('page-main');
  if (!main || !document.getElementById('todayBoard')) return;
  const base = document.querySelector('meta[name="app-base"]')?.content || '';
  const $ = id => document.getElementById(id);
  const board = () => $('todayBoard');
  const today = () => board().dataset.today;
  const lists = () => ({ overdue: $('tdOverdue'), today: $('tdOpen'), done: $('tdDone') });
  const reduce = () => matchMedia('(prefers-reduced-motion: reduce)').matches;

  // ── API per kind → the server's JSON ({success, xp?, achievements?})
  function call(kind, id, done) {
    const post = (file, body) => Trackie.API.post(`${base}/api/${file}`, body);
    switch (kind) {
      case 'todo':    return post('todos.php', { action: 'toggle', todo_id: id, completed: done ? 1 : 0 });
      case 'study':   return post('study_plan.php', { action: 'toggle', task_id: id, completed: done ? 1 : 0 });
      case 'habit':   return post('habits.php', done ? { action: 'log', habit_id: id, date: today(), status: 'done' }
                                                     : { action: 'unlog', habit_id: id, date: today() });
      case 'routine': return post('routines.php', { action: done ? 'complete' : 'uncomplete', routine_id: id });
    }
    return Promise.reject(new Error('Unknown item'));
  }

  // ── Counters, ring and hero sentence, all derived from the DOM.
  function sync() {
    const L = lists(), b = board();
    const total = b.querySelectorAll('.td-item[data-key]').length;
    const done = L.done.children.length;
    const ring = $('tdRing');
    ring.style.setProperty('--p', total ? Math.round(done / total * 100) : 0);
    ring.setAttribute('aria-label', `${done} of ${total} done today`);
    $('tdDoneN').textContent = done; $('tdTotalN').textContent = total;
    $('tdOverdueN').textContent = L.overdue.children.length;
    $('tdOpenN').textContent = L.today.children.length;
    $('tdDoneCount').textContent = done;
    $('tdOverdueSec').hidden = !L.overdue.children.length;
    $('tdTodaySec').hidden = !L.today.children.length && total > 0;
    $('tdDoneSec').hidden = !done;
    const clear = total > 0 && done === total;
    ring.classList.toggle('is-complete', clear);
    ring.classList.toggle('is-empty', !done);
    b.classList.toggle('is-clear', clear);
    const next = L.today.querySelector('.td-title') || L.overdue.querySelector('.td-title');
    const s = $('tdSummary');
    if (!total) s.textContent = 'A clean slate. Add the first thing you want to get done today.';
    else if (clear) s.textContent = "Everything's done. Enjoy the rest of your day.";
    else s.replaceChildren(Object.assign(document.createElement('strong'), { textContent: total - done }),
                           ` left${next ? ' · next up: ' + next.textContent : ''}`);
    return clear;
  }

  // A row returns to its home list in server order (data-sort); done rows go on top of Done.
  function place(li, done) {
    const L = lists();
    if (done) { L.done.prepend(li); return; }
    const list = L[li.dataset.section] || L.today;
    list.insertBefore(li, [...list.children].find(x => x.dataset.sort > li.dataset.sort) || null);
  }

  // The row glides to its new home and neighbours close the gap (View Transitions).
  // Names exist only for this transition, then are removed.
  function move(li, done) {
    const apply = () => {
      li.classList.toggle('is-done', done);
      place(li, done);
      const L = lists();
      if (done && !L.today.children.length && !L.overdue.children.length) $('tdDoneSec').open = true;
      sync();
    };
    if (!document.startViewTransition || reduce()) { apply(); return Promise.resolve(); }
    const rows = [...board().querySelectorAll('.td-item[data-key]')];
    rows.forEach((r, i) => { r.style.viewTransitionName = 'td-r' + i; r.style.viewTransitionClass = 'td-row'; });
    // The update callback waits for a rendered frame, which a throttled or
    // backgrounded tab may not produce for a while: the list must never wait
    // on an animation, so apply it ourselves if the frame is late.
    let applied = false;
    const run = () => { if (!applied) { applied = true; apply(); } };
    const vt = document.startViewTransition(run);
    const late = setTimeout(() => { if (!applied) { vt.skipTransition?.(); run(); } }, 600);
    const cleanup = () => { clearTimeout(late); rows.forEach(r => { r.style.viewTransitionName = ''; r.style.viewTransitionClass = ''; }); };
    return Promise.race([vt.finished.catch(() => {}), new Promise(r => setTimeout(r, 1200))]).then(() => { run(); cleanup(); });
  }

  main.addEventListener('click', async e => {
    const btn = e.target.closest('#todayBoard button.td-check');
    if (!btn || btn.getAttribute('aria-busy') === 'true') return;
    const li = btn.closest('.td-item');
    const done = btn.getAttribute('aria-checked') !== 'true';
    btn.setAttribute('aria-checked', String(done));   // instant feedback: the tick draws now
    btn.setAttribute('aria-busy', 'true');
    li.classList.toggle('is-done', done);
    if (done) Trackie.Delight?.tap();
    try {
      const res = await call(li.dataset.kind, +li.dataset.id, done);
      if (!res || !res.success) throw res || new Error();
      if (res.achievements?.length) Trackie.showAchievementToasts?.(res.achievements);
      if (done && res.xp?.gained) Trackie.Toast.success(`+${res.xp.gained} XP`, 1800);
      await new Promise(r => setTimeout(r, done && !reduce() ? 320 : 0));   // let the tick finish
      await move(li, done);
      if (done && sync()) { Trackie.Delight?.burst($('tdRing')); Trackie.Toast.success('All done for today 🎉', 4000); }
    } catch (err) {
      btn.setAttribute('aria-checked', String(!done));
      li.classList.toggle('is-done', !done);
      Trackie.UI.toastError(err, "Couldn't update that — please try again.");
    } finally {
      btn.removeAttribute('aria-busy');
    }
  });

  // ── Quick add in plain language: "call mom tomorrow 6pm !high"
  let chronoLoading = null;
  const loadChrono = () => chronoLoading ||= new Promise(res => {
    if (window.chrono) return res(window.chrono);
    const s = Object.assign(document.createElement('script'), { src: board().dataset.chrono });
    s.onload = () => res(window.chrono); s.onerror = () => res(null);   // no parser → plain title, due today
    document.head.append(s);
  });
  const PRI = /(?:^|\s)(!high|!h|!!!|!low|!l)(?=\s|$)/i;
  function parse(text) {
    let title = text, priority = 'medium';
    const p = title.match(PRI);
    if (p) { priority = /^!l/i.test(p[1]) ? 'low' : 'high'; title = title.replace(p[0], ' '); }
    const r = window.chrono?.parse(title, new Date(), { forwardDate: true })?.[0];
    if (r) title = title.replace(r.text, ' ');
    return { title: title.replace(/\s+/g, ' ').trim(), priority, when: r ? r.start.date() : null, hasTime: !!r?.start.isCertain('hour') };
  }
  const ymd = d => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  function describe(d) {
    const t = ymd(d), tm = new Date(); tm.setDate(tm.getDate() + 1);
    if (t === today()) return 'Today';
    if (t === ymd(tm)) return 'Tomorrow';
    return d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' });
  }
  function renderHint() {
    const input = $('tdAddInput'), hint = $('tdAddHint');
    const v = input.value.trim();
    if (!v) { hint.replaceChildren(); return; }
    const { title, priority, when, hasTime } = parse(v);
    const tag = (icon, text, muted) => {
      const s = document.createElement('span'); s.className = 'td-tag' + (muted ? ' is-muted' : '');
      s.innerHTML = `<i class="fas ${icon}" aria-hidden="true"></i> `; s.append(text); return s;
    };
    const time = hasTime ? ' · ' + when.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }) : '';
    hint.replaceChildren(
      tag('fa-calendar-day', when ? describe(when) + time : 'Today', !when),
      ...(priority !== 'medium' ? [tag('fa-flag', priority === 'high' ? 'High priority' : 'Low priority')] : []),
      ...(title ? [] : [tag('fa-pen', 'Add a few words', true)]),
    );
  }
  main.addEventListener('focusin', e => { if (e.target.id === 'tdAddInput') loadChrono(); });
  main.addEventListener('input', e => { if (e.target.id === 'tdAddInput') renderHint(); });
  main.addEventListener('submit', async e => {
    if (e.target.id !== 'tdAdd') return;
    e.preventDefault();
    await loadChrono();
    const { title, priority, when } = parse($('tdAddInput').value);
    if (!title) { $('tdAddInput').focus(); return; }
    const due = when ? ymd(when) : today();
    const btn = e.target.querySelector('button[type="submit"]');
    btn.disabled = true;
    try {
      const res = await Trackie.API.post(`${base}/api/todos.php`, { action: 'add', title, priority, due_date: due });
      if (!res || !res.success) throw res;
      $('tdAddInput').value = ''; renderHint();
      if (due === today()) {
        await Trackie.refreshFragments(['todayBoard']);
        board().querySelector(`.td-item[data-key="todo-${res.id}"]`)?.classList.add('is-new');
        Trackie.Toast.success('Added to today', 1800);
      } else {
        Trackie.Toast.success(`Added for ${describe(when)}`, 2500);
      }
    } catch (err) {
      Trackie.UI.toastError(err, "Couldn't add that task.");
    } finally {
      const b = board().querySelector('#tdAdd button[type="submit"]'); if (b) b.disabled = false;
      $('tdAddInput')?.focus();
    }
  });
})();
</script>

</div>
<?php include '../includes/footer.php'; ?>
