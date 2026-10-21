<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Study Plan';
$currentPage = 'study_plan';
$today       = date('Y-m-d');
$tomorrow    = date('Y-m-d', strtotime('+1 day'));
$weekEnd     = date('Y-m-d', strtotime('+6 day'));

const STUDY_TYPES = ['study', 'homework', 'practice', 'project', 'exam', 'reading', 'revision', 'other'];
$typeColors = ['study' => '#3b82f6', 'homework' => '#f59e0b', 'practice' => '#22c55e', 'project' => '#8b5cf6',
               'exam' => '#ef4444', 'reading' => '#64748b', 'revision' => '#eab308', 'other' => '#94a3b8'];

// Everything; type / subject / priority filters run in the browser (no reload).
$tasks = fetchAll("SELECT * FROM study_plan WHERE user_id=? ORDER BY due_date IS NULL, due_date ASC, FIELD(priority,'high','medium','low'), created_at DESC", [$uid]);

$groups = ['overdue' => [], 'today' => [], 'week' => [], 'later' => [], 'nodate' => [], 'done' => []];
foreach ($tasks as $t) {
    if ($t['completed'])              $g = 'done';
    elseif (!$t['due_date'])          $g = 'nodate';
    elseif ($t['due_date'] < $today)  $g = 'overdue';
    elseif ($t['due_date'] === $today) $g = 'today';
    elseif ($t['due_date'] <= $weekEnd) $g = 'week';
    else                              $g = 'later';
    $groups[$g][] = $t;
}
// Done: most recent first, and only the latest 30 on the page.
usort($groups['done'], static fn($a, $b) => strcmp((string)$b['completed_at'], (string)$a['completed_at']));
$doneTotal = count($groups['done']);
$groups['done'] = array_slice($groups['done'], 0, 30);
$groupMeta = ['overdue' => ['Overdue', 'fa-triangle-exclamation'], 'today' => ['Today', 'fa-sun'], 'week' => ['This week', 'fa-calendar-week'],
              'later' => ['Later', 'fa-calendar'], 'nodate' => ['No date', 'fa-inbox']];

// Subjects with progress → filter chips ("Physics 3/8").
$subjects = [];
foreach ($tasks as $t) {
    $s = trim((string)$t['subject']); if ($s === '') continue;
    $subjects[$s] ??= ['done' => 0, 'total' => 0];
    $subjects[$s]['total']++; if ($t['completed']) $subjects[$s]['done']++;
}
uksort($subjects, 'strcasecmp');

// Upcoming exams → countdown cards, with what's still open for that subject before the exam.
$exams = array_values(array_filter($tasks, static fn($t) => $t['type'] === 'exam' && !$t['completed'] && $t['due_date'] && $t['due_date'] >= $today));
$exams = array_slice($exams, 0, 3);

$openN = count($tasks) - $doneTotal;
$init  = ['type' => in_array($_GET['type'] ?? '', STUDY_TYPES, true) ? $_GET['type'] : '',
          'subject' => isset($subjects[$_GET['subject'] ?? '']) ? $_GET['subject'] : '',
          'priority' => in_array($_GET['priority'] ?? '', ['high', 'medium', 'low'], true) ? $_GET['priority'] : ''];

function studyRow(array $t, string $today, array $typeColors): string {
    $done = (bool)$t['completed'];
    $col = $typeColors[$t['type']] ?? '#94a3b8';
    $pri = in_array($t['priority'], ['low', 'medium', 'high'], true) ? $t['priority'] : 'medium';
    $meta = '<span class="sp-type" style="--tc:' . h($col) . '">' . h(ucfirst($t['type'])) . '</span>';
    if ($pri !== 'medium') $meta .= '<span class="tdo-pri ' . $pri . '">' . ucfirst($pri) . '</span>';
    if ($t['subject']) $meta .= '<span><i class="fas fa-book" aria-hidden="true"></i> ' . h($t['subject']) . '</span>';
    if ($t['due_date'] && $t['due_date'] !== $today) {
        $diff = (int)round((strtotime($t['due_date']) - strtotime($today)) / 86400);
        $txt = !$done && $diff < 0 ? ($diff === -1 ? 'Yesterday' : (-$diff) . ' days late') : ($diff === 1 ? 'Tomorrow' : ($diff > 1 && $diff < 7 ? date('l', strtotime($t['due_date'])) : date('j M', strtotime($t['due_date']))));
        $meta .= '<span class="tdo-due' . (!$done && $diff < 0 ? ' is-late' : '') . '"><i class="fas fa-calendar-day" aria-hidden="true"></i> ' . h($txt) . '</span>';
    }
    if ($t['resource'] && preg_match('~^https?://~i', $t['resource'])) {
        $meta .= '<a class="sp-res" href="' . h($t['resource']) . '" target="_blank" rel="noopener noreferrer"><i class="fas fa-link" aria-hidden="true"></i> Resource</a>';
    }
    $title = h($t['title']);
    return '<li class="td-item tdo-row sp-row' . ($done ? ' is-done' : '') . '" id="study-' . (int)$t['id'] . '" data-id="' . (int)$t['id'] . '"'
        . ' data-type="' . h($t['type']) . '" data-subject="' . h((string)$t['subject']) . '" data-pri="' . $pri . '" style="--item-c:' . h($col) . '">'
        . '<button type="button" class="td-check" role="checkbox" aria-checked="' . ($done ? 'true' : 'false') . '" aria-label="' . $title . '">'
        . '<svg viewBox="0 0 26 26" aria-hidden="true"><circle class="td-check-ring" cx="13" cy="13" r="11.5"/><path class="td-check-tick" d="M8 13.4l3.2 3.2L18 9.8"/></svg></button>'
        . '<div class="td-body"><button type="button" class="tdo-open" onclick="openEditStudy(' . (int)$t['id'] . ')"><span class="td-title">' . $title . '</span></button>'
        . '<div class="td-meta">' . $meta . '</div></div>'
        . '<div class="tdo-actions">'
        . (!$done ? '<a class="tdo-act" href="' . APP_BASE . '/pages/focus.php" title="Focus session" aria-label="Start a focus session"><i class="fas fa-stopwatch" aria-hidden="true"></i></a>' : '')
        . '<button type="button" class="tdo-act tdo-del" onclick="deleteStudy(' . (int)$t['id'] . ')" title="Delete" aria-label="Delete ' . $title . '"><i class="fas fa-trash" aria-hidden="true"></i></button>'
        . '</div></li>';
}

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<?= renderPageHeader('Study Plan', [
  'icon'    => 'fa-book-open',
  'sub'     => 'Sessions, deadlines and exams — with the countdown in front of you.',
  'actions' => '<button type="button" class="btn btn-secondary btn-sm" onclick="openNewStudy()"><i class="fas fa-sliders" aria-hidden="true"></i> Detailed task</button>',
]) ?>

<div id="studyListWrap" data-today="<?= h($today) ?>"
     data-init-type="<?= h($init['type']) ?>" data-init-subject="<?= h($init['subject']) ?>" data-init-priority="<?= h($init['priority']) ?>">

  <div class="pg-strip">
    <span><strong><?= $openN ?></strong> open</span>
    <?php if ($groups['overdue']): ?><span class="is-late"><strong><?= count($groups['overdue']) ?></strong> overdue</span><?php endif; ?>
    <span><strong><?= $doneTotal ?></strong> done</span>
  </div>

  <?php if ($exams): ?>
    <div class="sp-exams">
      <?php foreach ($exams as $e):
        $days = (int)round((strtotime($e['due_date']) - strtotime($today)) / 86400);
        $openForSubject = $e['subject'] ? count(array_filter($tasks, static fn($t) => !$t['completed'] && $t['id'] !== $e['id'] && strcasecmp((string)$t['subject'], $e['subject']) === 0)) : 0;
      ?>
        <article class="card sp-exam<?= $days <= 3 ? ' is-near' : '' ?>">
          <div class="sp-exam-count"><strong><?= $days === 0 ? 'Today' : $days ?></strong><?= $days === 0 ? '' : '<span>' . ($days === 1 ? 'day' : 'days') . '</span>' ?></div>
          <div class="sp-exam-text">
            <div class="sp-exam-title"><?= h($e['title']) ?></div>
            <div class="sp-exam-sub"><?= h(date('l j M', strtotime($e['due_date']))) ?><?= $e['subject'] ? ' · ' . h($e['subject']) : '' ?></div>
            <?php if ($e['subject']): ?>
              <button type="button" class="sp-exam-link" data-filter-subject="<?= h($e['subject']) ?>">
                <?= $openForSubject ? $openForSubject . ' open task' . ($openForSubject === 1 ? '' : 's') . ' for ' . h($e['subject']) . ' →' : 'Nothing open for ' . h($e['subject']) . ' — add revision' ?>
              </button>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form class="td-add" id="spAdd" autocomplete="off">
    <label class="td-add-row">
      <i class="fas fa-plus" aria-hidden="true"></i>
      <span class="sr-only">Add a study task</span>
      <input class="td-add-input" id="spAddInput" maxlength="150" enterkeyhint="done"
             placeholder="e.g. “revise chapter 3 friday #physics”"
             title="Plain words for the date; #subject; exam, homework, practice, read, revise set the type; !high / !low">
      <button type="submit" class="btn btn-primary btn-sm td-add-btn">Add</button>
    </label>
    <div class="td-add-hint" id="spAddHint" aria-live="polite"></div>
  </form>

  <?php if ($tasks): ?>
    <div class="sp-filters">
      <?php if ($subjects): ?>
        <div class="pg-chips" role="toolbar" aria-label="Subjects">
          <button type="button" class="pg-chip" data-f="subject" data-v="">All subjects</button>
          <?php foreach ($subjects as $s => $c): ?>
            <button type="button" class="pg-chip" data-f="subject" data-v="<?= h($s) ?>"><?= h($s) ?> <b class="sp-chip-n"><?= $c['done'] ?>/<?= $c['total'] ?></b></button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <div class="pg-chips" role="toolbar" aria-label="Type">
        <button type="button" class="pg-chip" data-f="type" data-v="">All types</button>
        <?php foreach (STUDY_TYPES as $ty): if (!array_filter($tasks, static fn($t) => $t['type'] === $ty)) continue; ?>
          <button type="button" class="pg-chip" data-f="type" data-v="<?= $ty ?>"><span class="sp-dot" style="background:<?= $typeColors[$ty] ?>" aria-hidden="true"></span> <?= ucfirst($ty) ?></button>
        <?php endforeach; ?>
        <button type="button" class="pg-chip" data-f="priority" data-v="high"><i class="fas fa-flag" aria-hidden="true"></i> High</button>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!$tasks): ?>
    <div class="card"><?= renderEmptyState('fa-book-open', 'Plan your next study session',
      'Type one above: “practice past paper sunday #maths”, or add your exam — “physics exam 12 nov #physics” — to get a countdown.') ?></div>
  <?php endif; ?>

  <?php foreach ($groupMeta as $g => [$label, $icon]): ?>
    <section class="td-section tdo-group<?= $g === 'overdue' ? ' is-overdue' : '' ?>" data-group="<?= $g ?>"<?= $groups[$g] ? '' : ' hidden' ?>>
      <h2 class="td-section-head"><span><i class="fas <?= $icon ?>" aria-hidden="true"></i> <?= $label ?></span> <span class="td-count"><?= count($groups[$g]) ?></span></h2>
      <ul class="td-list" data-list="<?= $g ?>"><?php foreach ($groups[$g] as $t) echo studyRow($t, $today, $typeColors); ?></ul>
    </section>
  <?php endforeach; ?>
  <details class="td-section td-done tdo-group" data-group="done"<?= $groups['done'] ? '' : ' hidden' ?>>
    <summary><i class="fas fa-chevron-right" aria-hidden="true"></i> Done <span class="td-count"><?= $doneTotal ?></span></summary>
    <ul class="td-list" data-list="done"><?php foreach ($groups['done'] as $t) echo studyRow($t, $today, $typeColors); ?></ul>
  </details>
  <div class="card sp-nomatch" hidden><?= renderEmptyState('fa-filter', 'Nothing matches', 'Try another subject or type.', '', true) ?></div>
</div>

<!-- Add / edit study task -->
<div id="studyModal" class="modal-backdrop hidden">
  <div class="modal-box" style="max-width:540px">
    <div class="modal-header">
      <span class="modal-title">Add Study Task</span>
      <button class="btn btn-icon btn-ghost btn-sm" data-close-modal="studyModal" aria-label="Close dialog">&times;</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label for="studyTitle" class="form-label">Title <span class="u-c-accent">*</span></label>
        <input type="hidden" id="studyId">
        <input id="studyTitle" class="form-input" placeholder="Task title">
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label for="studySubject" class="form-label">Subject</label>
          <input id="studySubject" class="form-input" placeholder="e.g. Math, Physics" list="studySubjects">
          <datalist id="studySubjects"><?php foreach (array_keys($subjects) as $s): ?><option value="<?= h($s) ?>"><?php endforeach; ?></datalist>
        </div>
        <div class="form-group">
          <label for="studyDue" class="form-label">Due date</label>
          <input id="studyDue" class="form-input" type="date">
        </div>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label for="studyType" class="form-label">Type</label>
          <select id="studyType" class="form-input">
            <?php foreach (STUDY_TYPES as $t): ?><option value="<?= $t ?>"><?= ucfirst($t) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="studyPriority" class="form-label">Priority</label>
          <select id="studyPriority" class="form-input">
            <option value="high">High</option>
            <option value="medium" selected>Medium</option>
            <option value="low">Low</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label for="studyDesc" class="form-label">Notes</label>
        <textarea id="studyDesc" class="form-input" rows="2" placeholder="Optional"></textarea>
      </div>
      <div class="form-group">
        <label for="studyResource" class="form-label">Resource link</label>
        <input id="studyResource" class="form-input" type="url" placeholder="https://…">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary btn-sm" data-close-modal="studyModal">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="saveStudy()">
        <i class="fas fa-save"></i> Save
      </button>
    </div>
  </div>
</div>

</div>
<?php include '../includes/footer.php'; ?>

<script>
const API_BASE = '<?= APP_BASE ?>/api';
const STUDY_FIELDS = { studyTitle: 'title', studySubject: 'subject', studyDue: 'due_date', studyType: 'type',
                       studyPriority: 'priority', studyDesc: 'description', studyResource: 'resource' };
(() => {
  const main = document.getElementById('page-main');
  if (!main || !document.getElementById('studyListWrap')) return;
  const $ = id => document.getElementById(id);
  const wrap = () => $('studyListWrap');
  const NL = Trackie.NL;
  const reduce = () => matchMedia('(prefers-reduced-motion: reduce)').matches;
  const f = { type: wrap().dataset.initType || '', subject: wrap().dataset.initSubject || '', priority: wrap().dataset.initPriority || '' };

  function apply() {
    let shown = 0;
    wrap().querySelectorAll('.sp-row').forEach(li => {
      const m = (!f.type || li.dataset.type === f.type) && (!f.subject || li.dataset.subject.toLowerCase() === f.subject.toLowerCase())
             && (!f.priority || li.dataset.pri === f.priority);
      li.hidden = !m; if (m) shown++;
    });
    wrap().querySelectorAll('.tdo-group').forEach(sec => {
      const all = sec.querySelectorAll('.sp-row').length, vis = [...sec.querySelectorAll('.sp-row')].filter(r => !r.hidden).length;
      sec.hidden = !vis;
      const c = sec.querySelector('.td-count'); if (c && sec.dataset.group !== 'done') c.textContent = (f.type || f.subject || f.priority) ? `${vis} of ${all}` : all;
    });
    const nm = wrap().querySelector('.sp-nomatch'); if (nm) nm.hidden = shown > 0 || !wrap().querySelector('.sp-row');
    wrap().querySelectorAll('[data-f]').forEach(b => b.setAttribute('aria-pressed', String((f[b.dataset.f] || '') === b.dataset.v)));
    const u = new URL(location.href);
    for (const k of ['type', 'subject', 'priority']) f[k] ? u.searchParams.set(k, f[k]) : u.searchParams.delete(k);
    history.replaceState(history.state, '', u);
  }

  main.addEventListener('click', async e => {
    const chip = e.target.closest('#studyListWrap [data-f]');
    if (chip) { f[chip.dataset.f] = f[chip.dataset.f] === chip.dataset.v ? '' : chip.dataset.v; apply(); return; }
    const ex = e.target.closest('[data-filter-subject]');
    if (ex) { f.subject = ex.dataset.filterSubject; f.type = ''; apply(); wrap().querySelector('.sp-filters')?.scrollIntoView({ behavior: reduce() ? 'auto' : 'smooth', block: 'start' }); return; }
    const btn = e.target.closest('#studyListWrap button.td-check');
    if (!btn || btn.getAttribute('aria-busy') === 'true') return;
    const li = btn.closest('.sp-row');
    const done = btn.getAttribute('aria-checked') !== 'true';
    btn.setAttribute('aria-checked', String(done)); btn.setAttribute('aria-busy', 'true');
    li.classList.toggle('is-done', done);
    if (done) Trackie.Delight?.tap();
    try {
      const res = await Trackie.API.post(`${API_BASE}/study_plan.php`, { action: 'toggle', task_id: li.dataset.id, completed: done ? 1 : 0 });
      if (!res?.success) throw res;
      if (res.achievements?.length) Trackie.showAchievementToasts?.(res.achievements);
      if (done && res.xp?.gained) Trackie.Toast.success(`+${res.xp.gained} XP`, 1600);
      await new Promise(r => setTimeout(r, done && !reduce() ? 300 : 0));
      await refresh();   // regroup + subject counts from the server
    } catch (err) {
      btn.setAttribute('aria-checked', String(!done)); li.classList.toggle('is-done', !done);
      Trackie.UI.toastError(err, "Couldn't update that task.");
    } finally { btn.removeAttribute('aria-busy'); }
  });

  // Quick add: #subject, type from words, date and !priority from NL.
  const TYPE_WORDS = [[/\b(exam|test|midterm|final|quiz)\b/i, 'exam'], [/\b(homework|hw|assignment|worksheet)\b/i, 'homework'],
                      [/\b(revise|revision|review)\b/i, 'revision'], [/\b(practice|practise|past paper|drill)\b/i, 'practice'],
                      [/\b(read|reading|chapter)\b/i, 'reading'], [/\bproject\b/i, 'project']];
  function parseStudy(text) {
    const p = NL.parse(text);
    const type = (TYPE_WORDS.find(([re]) => re.test(p.title)) || [, 'study'])[1];
    const subject = p.tags[0] ? p.tags[0].replace(/[-_]/g, ' ').replace(/\b\w/g, c => c.toUpperCase()) : '';
    return { ...p, type, subject };
  }
  function hint() {
    const el = $('spAddHint'), v = $('spAddInput')?.value || '';
    NL.hint(el, v, wrap().dataset.today, 'No date');
    if (!v.trim() || !el) return;
    const r = parseStudy(v);
    el.querySelectorAll('.td-tag').forEach(t => { if (t.querySelector('.fa-hashtag')) t.remove(); });
    const tag = (icon, label) => { const s = document.createElement('span'); s.className = 'td-tag'; s.innerHTML = `<i class="fas ${icon}" aria-hidden="true"></i> `; s.append(label); return s; };
    el.prepend(tag('fa-layer-group', r.type[0].toUpperCase() + r.type.slice(1)));
    if (r.subject) el.append(tag('fa-book', r.subject));
  }
  main.addEventListener('focusin', e => { if (e.target.id === 'spAddInput') NL.load().then(hint); });
  main.addEventListener('input', e => { if (e.target.id === 'spAddInput') hint(); });
  main.addEventListener('submit', async e => {
    if (e.target.id !== 'spAdd') return;
    e.preventDefault();
    await NL.load();
    const r = parseStudy($('spAddInput').value);
    if (!r.title) return;
    const btn = e.target.querySelector('button[type="submit"]'); btn.disabled = true;
    try {
      const res = await Trackie.API.post(`${API_BASE}/study_plan.php`, { action: 'add', title: r.title, subject: r.subject || f.subject, due_date: r.due || '', type: r.type, priority: r.priority, description: '', resource: '' });
      if (!res?.success) throw res;
      $('spAddInput').value = ''; $('spAddHint').replaceChildren();
      await refresh();
      const li = res.id && $('study-' + res.id);
      if (li) { if (li.hidden) { f.type = f.subject = f.priority = ''; apply(); } li.classList.add('is-new'); li.scrollIntoView({ block: 'nearest' }); }
      else Trackie.Toast.success('Added.', 1800);
    } catch (err) { Trackie.UI.toastError(err, "Couldn't add that task."); }
    finally { const b = document.querySelector('#spAdd button[type="submit"]'); if (b) b.disabled = false; $('spAddInput')?.focus(); }
  });

  async function refresh() { await Trackie.refreshFragments(['studyListWrap']); apply(); }
  window.studyRefresh = refresh;
  apply();
})();

function openNewStudy() {
  document.getElementById('studyId').value = '';
  Object.keys(STUDY_FIELDS).forEach(i => { const el = document.getElementById(i); el.value = el.tagName === 'SELECT' ? el.options[0].value : ''; });
  document.getElementById('studyPriority').value = 'medium';
  const t = document.querySelector('#studyModal .modal-title'); if (t) t.textContent = 'Add Task';
  Trackie.openModal('studyModal');
}
async function openEditStudy(id) {
  try {
    const res = await Trackie.API.post(`${API_BASE}/study_plan.php`, { action: 'get', task_id: id });
    if (!res.success) throw res;
    document.getElementById('studyId').value = res.task.id;
    Object.entries(STUDY_FIELDS).forEach(([i, k]) => document.getElementById(i).value = res.task[k] || '');
    const t = document.querySelector('#studyModal .modal-title'); if (t) t.textContent = 'Edit Task';
    Trackie.openModal('studyModal');
  } catch (e) { Trackie.UI.toastError(e, 'Could not load that task.'); }
}
async function saveStudy() {
  const title = document.getElementById('studyTitle').value.trim();
  if (!title) { Trackie.Toast.warning('Title is required.'); return; }
  const v = k => document.getElementById(k).value;
  try {
    const editId = v('studyId');
    const res = await Trackie.API.post(`${API_BASE}/study_plan.php`, {
      action: editId ? 'edit' : 'add', task_id: editId, title, subject: v('studySubject'), due_date: v('studyDue'),
      type: v('studyType'), priority: v('studyPriority'), description: v('studyDesc'), resource: v('studyResource'),
    });
    if (!res.success) throw res;
    Trackie.Toast.success(editId ? 'Task updated.' : 'Task added!');
    Trackie.closeModal('studyModal');
    await window.studyRefresh?.();
  } catch (e) { Trackie.UI.toastError(e, 'Save failed.'); }
}
async function deleteStudy(id) {
  const ok = await Trackie.confirmDialog('Delete this task?', { confirmText: 'Delete', danger: true });
  if (!ok) return;
  try {
    const res = await Trackie.API.post(`${API_BASE}/study_plan.php`, { action: 'delete', task_id: id });
    if (!res.success) throw res;
    await Trackie.UI.transition(() => document.getElementById(`study-${id}`)?.remove(), [...document.querySelectorAll('.sp-row:not([hidden])')]);
    window.studyRefresh?.();
    Trackie.Toast.success('Deleted.');
  } catch (e) { Trackie.UI.toastError(e, 'Delete failed.'); }
}
</script>
