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
require_once '../includes/today_board.php';

['items' => $items, 'notDue' => $notDue, 'routinesSkipped' => $routinesSkipped, 'routineRestToday' => $routineRestToday] = todayBoard($uid, $today);
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

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<?php if (($_GET['onboarded'] ?? '') === '1'): ?>
  <?= renderInsight("You're set up! Type your first task below — try “gym tomorrow 7am” — and tick things off as you go.", 'fa-champagne-glasses') ?>
<?php endif; ?>

<div id="todayBoard" class="td-page<?= $total && $doneN === $total ? ' is-clear' : '' ?>"
     data-today="<?= h($today) ?>">

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


</div>
<?php include '../includes/footer.php'; ?>

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

  // The row glides to its new home and neighbours close the gap (shared helper).
  function move(li, done) {
    return Trackie.UI.transition(() => {
      li.classList.toggle('is-done', done);
      place(li, done);
      const L = lists();
      if (done && !L.today.children.length && !L.overdue.children.length) $('tdDoneSec').open = true;
      sync();
    }, [...board().querySelectorAll('.td-item[data-key]')]);
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

  // ── Quick add in plain language: "call mom tomorrow 6pm !high #family" (Trackie.NL)
  const NL = Trackie.NL;
  main.addEventListener('focusin', e => { if (e.target.id === 'tdAddInput') NL.load(); });
  main.addEventListener('input', e => { if (e.target.id === 'tdAddInput') NL.hint($('tdAddHint'), e.target.value, today()); });
  main.addEventListener('submit', async e => {
    if (e.target.id !== 'tdAdd') return;
    e.preventDefault();
    await NL.load();
    const { title, priority, due, tags } = NL.parse($('tdAddInput').value);
    if (!title) { $('tdAddInput').focus(); return; }
    const when = due || today();
    const btn = e.target.querySelector('button[type="submit"]');
    btn.disabled = true;
    try {
      const res = await Trackie.API.post(`${base}/api/todos.php`, { action: 'add', title, priority, due_date: when, tags: tags.join(',') });
      if (!res || !res.success) throw res;
      $('tdAddInput').value = ''; NL.hint($('tdAddHint'), '', today());
      if (when === today()) {
        await Trackie.refreshFragments(['todayBoard']);
        board().querySelector(`.td-item[data-key="todo-${res.id}"]`)?.classList.add('is-new');
        Trackie.Toast.success('Added to today', 1800);
      } else {
        Trackie.Toast.success(`Added for ${NL.describe(when, today())}`, 2500);
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
