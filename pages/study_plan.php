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

// Validate + carry all filter dimensions together
$typeFilter     = in_array($_GET['type']     ?? '', ['all','study','homework','practice','project','exam','reading','revision','other']) ? $_GET['type']     : 'all';
$subjectFilter  = sanitizeInput($_GET['subject']  ?? 'all');
$priorityFilter = in_array($_GET['priority'] ?? '', ['all','high','medium','low']) ? $_GET['priority'] : 'all';

function filterHref(array $overrides): string {
    global $typeFilter, $subjectFilter, $priorityFilter;
    $p = array_merge(
        ['type'=>$typeFilter, 'subject'=>$subjectFilter, 'priority'=>$priorityFilter],
        $overrides
    );
    $parts = [];
    foreach ($p as $k => $v) if ($v !== 'all' && $v !== '') $parts[] = urlencode($k).'='.urlencode($v);
    return '?' . implode('&', $parts);
}

// Build query
$sql    = "SELECT * FROM study_plan WHERE user_id=?";
$params = [$uid];

if ($typeFilter    !== 'all') { $sql .= " AND type=?";     $params[] = $typeFilter; }
if ($subjectFilter !== 'all') { $sql .= " AND subject=?";  $params[] = $subjectFilter; }
if ($priorityFilter !== 'all') { $sql .= " AND priority=?"; $params[] = $priorityFilter; }

$sql .= " ORDER BY due_date ASC, priority DESC, created_at DESC";
$tasks = fetchAll($sql, $params);

// Separate by date (only tasks WITH a due_date)
$todayTasks    = array_filter($tasks, fn($t) => $t['due_date'] === $today);
$upcomingTasks = array_filter($tasks, fn($t) => $t['due_date'] && $t['due_date'] > $today);
$pastTasks     = array_filter($tasks, fn($t) => $t['due_date'] && $t['due_date'] < $today);
$noDueTasks    = array_filter($tasks, fn($t) => !$t['due_date']);

// ── AI-style insight ──
$studyInsight = '';
$overdueStudy = count(array_filter($pastTasks, fn($t) => !$t['completed']));
if ($overdueStudy > 0) {
    $studyInsight = "{$overdueStudy} study task" . ($overdueStudy !== 1 ? 's are' : ' is') . " past due — reschedule or knock " . ($overdueStudy !== 1 ? 'them' : 'it') . " out today.";
} else {
    $subjDist = fetchAll(
        "SELECT subject, COUNT(*) c FROM study_plan
         WHERE user_id=? AND subject IS NOT NULL AND subject<>'' GROUP BY subject ORDER BY c DESC",
        [$uid]
    );
    if (count($subjDist) >= 2) {
        $totalS = array_sum(array_column($subjDist, 'c'));
        $least  = end($subjDist);
        $pct    = (int)round($least['c'] / $totalS * 100);
        $studyInsight = "\"{$least['subject']}\" gets only {$pct}% of your study tasks — the least of any subject. Balance it out?";
    } elseif (count($todayTasks) > 0) {
        $studyInsight = count($todayTasks) . " task" . (count($todayTasks) !== 1 ? 's' : '') . " due today — a focused block now keeps you ahead.";
    }
}

// Subjects for filter
$subjects = array_column(
    fetchAll("SELECT DISTINCT subject FROM study_plan WHERE user_id=? AND subject!='' AND subject IS NOT NULL", [$uid]),
    'subject'
);

// Today's progress
$todayTotal = count($todayTasks);
$todayDone  = array_sum(array_column(array_values($todayTasks), 'completed'));
$todayPct   = $todayTotal > 0 ? round($todayDone / $todayTotal * 100) : 0;

$typeColors = [
    'study'=>'blue','homework'=>'yellow','practice'=>'green','project'=>'purple',
    'exam'=>'red','reading'=>'gray','revision'=>'yellow','other'=>'gray',
];

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<?= renderPageHeader('Study Plan', [
  'icon'    => 'fa-book-open',
  'sub'     => 'Plan sessions, deadlines, and revision in one place.',
  'actions' => '<button class="btn btn-primary btn-sm" onclick="openModal(\'studyModal\')"><i class="fas fa-plus"></i> Add Task</button>',
]) ?>

<?= renderInsight($studyInsight) ?>

<div id="studyListWrap">
<!-- Today's progress -->
<?php if ($todayTotal > 0): ?>
  <div style="margin-bottom:1.25rem">
    <div style="display:flex;justify-content:space-between;font-size:.875rem;margin-bottom:.375rem">
      <span style="color:var(--muted)">Today's progress <span style="font-weight:600;color:var(--text)"><?= $todayDone ?>/<?= $todayTotal ?></span></span>
      <span style="font-weight:600"><?= $todayPct ?>%</span>
    </div>
    <div class="progress-track"><div class="progress-fill <?= $todayPct>=100?'green':'' ?>" style="width:<?= $todayPct ?>%"></div></div>
  </div>
<?php endif; ?>

<!-- Filters — all dimensions preserved -->
<div style="margin-bottom:var(--sp-4);display:flex;flex-wrap:wrap;gap:.625rem;align-items:center">
  <div class="filter-tabs">
    <?php
    $typeLabels = ['all'=>'All','study'=>'Study','homework'=>'HW','practice'=>'Practice',
                   'project'=>'Project','exam'=>'Exam','reading'=>'Reading','revision'=>'Revision','other'=>'Other'];
    foreach ($typeLabels as $k=>$lbl): ?>
      <a class="filter-tab <?= $typeFilter===$k?'active':'' ?>" href="<?= filterHref(['type'=>$k]) ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($subjects): ?>
    <select class="form-input" style="font-size:.8125rem;width:auto"
            onchange="Trackie.SpaNav.swap('<?= filterHref([]) ?>&subject='+this.value, true)">
      <option value="all" <?= $subjectFilter==='all'?'selected':'' ?>>All subjects</option>
      <?php foreach ($subjects as $s): ?>
        <option value="<?= h($s) ?>" <?= $subjectFilter===$s?'selected':'' ?>><?= h($s) ?></option>
      <?php endforeach; ?>
    </select>
  <?php endif; ?>

  <select class="form-input" style="font-size:.8125rem;width:auto"
          onchange="Trackie.SpaNav.swap('<?= filterHref([]) ?>&priority='+this.value, true)">
    <option value="all" <?= $priorityFilter==='all'?'selected':'' ?>>All priorities</option>
    <option value="high"   <?= $priorityFilter==='high'  ?'selected':'' ?>>High</option>
    <option value="medium" <?= $priorityFilter==='medium'?'selected':'' ?>>Medium</option>
    <option value="low"    <?= $priorityFilter==='low'   ?'selected':'' ?>>Low</option>
  </select>
</div>

<?php
function renderTaskList(array $tasks, string $apiBase, string $today): void {
    global $typeColors;
    if (empty($tasks)) {
        echo '<p style="color:var(--muted);font-size:.875rem;padding:var(--sp-2) 0">None.</p>';
        return;
    }
    foreach ($tasks as $t):
        $tc = $typeColors[$t['type']] ?? 'gray';
        $pc = $t['priority']==='high'?'red':($t['priority']==='medium'?'yellow':'blue');
        ?>
        <div class="todo-row <?= $t['completed']?'done':'' ?>" id="study-<?= $t['id'] ?>">
          <input type="checkbox" class="todo-check"
                 <?= $t['completed']?'checked':'' ?>
                 onchange="toggleStudy(<?= $t['id'] ?>, this.checked, this)">
          <div style="flex:1;min-width:0">
            <div class="todo-title"><?= h($t['title']) ?></div>
            <div class="todo-meta" style="margin-top:var(--sp-1)">
              <span class="badge badge-<?= $tc ?>"><?= ucfirst($t['type']) ?></span>
              <span class="badge badge-<?= $pc ?>"><?= ucfirst($t['priority']) ?></span>
              <?php if ($t['subject']): ?><span><i class="fas fa-book" style="font-size:.7rem"></i> <?= h($t['subject']) ?></span><?php endif; ?>
              <?php if ($t['due_date'] && $t['due_date'] !== $today): ?><span><i class="fas fa-calendar" style="font-size:.7rem"></i> <?= htmlspecialchars(date('M j',strtotime($t['due_date']))) ?></span><?php endif; ?>
            </div>
          </div>
          <button class="btn btn-icon btn-ghost btn-sm" style="color:var(--accent)"
                  onclick="deleteStudy(<?= $t['id'] ?>)">
            <i class="fas fa-trash"></i>
          </button>
        </div>
    <?php endforeach;
}
?>

<!-- Task sections -->
<?php
$studyFiltered = $typeFilter !== 'all' || $subjectFilter !== 'all' || $priorityFilter !== 'all';
?>
<?php if (empty($tasks)): ?>
  <div class="card">
    <div class="empty-state">
      <div class="empty-state-icon"><i class="fas fa-book-open"></i></div>
      <?php if ($studyFiltered): ?>
        <div class="empty-state-title">No tasks match these filters</div>
        <p>Try a different type, subject, or priority.</p>
      <?php else: ?>
        <div class="empty-state-title">Plan your next study session</div>
        <p>Add a task — a chapter to revise, an assignment, a topic to practice.</p>
      <?php endif; ?>
      <button class="btn btn-primary" style="margin-top:var(--sp-3)" onclick="openModal('studyModal')">
        <i class="fas fa-plus"></i> Add a study task
      </button>
    </div>
  </div>
<?php else: ?>

  <?php foreach ([
    ['label'=>"Today's Tasks",'items'=>$todayTasks],
    ['label'=>'Upcoming',     'items'=>$upcomingTasks],
    ['label'=>'No Due Date',  'items'=>$noDueTasks],
    ['label'=>'Past',         'items'=>$pastTasks],
  ] as $section):
    if (empty($section['items'])) continue; ?>
    <div style="margin-bottom:var(--sp-5)">
      <div style="font-size:.8125rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:.625rem">
        <?= $section['label'] ?>
        <span class="badge badge-gray" style="margin-left:.375rem"><?= count($section['items']) ?></span>
      </div>
      <div class="card">
        <?php renderTaskList(array_values($section['items']), APP_BASE . '/api', $today); ?>
      </div>
    </div>
  <?php endforeach; ?>

<?php endif; ?>
</div>

<!-- Add Study Task Modal -->
<div id="studyModal" class="modal-backdrop hidden">
  <div class="modal-box" style="max-width:540px">
    <div class="modal-header">
      <span class="modal-title">Add Study Task</span>
      <button class="btn btn-icon btn-ghost btn-sm" data-close-modal="studyModal" aria-label="Close dialog">&times;</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label for="studyTitle" class="form-label">Title <span style="color:var(--accent)">*</span></label>
        <input id="studyTitle" class="form-input" placeholder="Task title">
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label for="studySubject" class="form-label">Subject</label>
          <input id="studySubject" class="form-input" placeholder="e.g. Math, Physics">
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
            <?php foreach (['study','homework','practice','project','exam','reading','revision','other'] as $t): ?>
              <option value="<?= $t ?>"><?= ucfirst($t) ?></option>
            <?php endforeach; ?>
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
        <i class="fas fa-save"></i> Add Task
      </button>
    </div>
  </div>
</div>

</div>
<?php include '../includes/footer.php'; ?>

<script>
const API_BASE = '<?= APP_BASE ?>/api';

async function saveStudy() {
  const title = document.getElementById('studyTitle').value.trim();
  if (!title) { Trackie.Toast.warning('Title is required.'); return; }
  try {
    const res = await Trackie.API.post(`${API_BASE}/study_plan.php`, {
      action: 'add', title,
      subject:   document.getElementById('studySubject').value,
      due_date:  document.getElementById('studyDue').value,
      type:      document.getElementById('studyType').value,
      priority:  document.getElementById('studyPriority').value,
      description: document.getElementById('studyDesc').value,
      resource:  document.getElementById('studyResource').value,
    });
    if (res.success) {
      Trackie.Toast.success('Task added!');
      Trackie.closeModal('studyModal');
      await Trackie.refreshFragments(['studyListWrap']);
    }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}

async function toggleStudy(id, checked, el) {
  el.disabled = true;
  try {
    const res = await Trackie.API.post(`${API_BASE}/study_plan.php`, {
      action:'toggle', task_id:id, completed: checked ? 1 : 0
    });
    if (res.success) {
      document.getElementById(`study-${id}`)?.classList.toggle('done', checked);
    } else { Trackie.Toast.error(res.error || 'Failed.'); el.checked = !checked; }
  } catch { Trackie.Toast.error('Network error.'); el.checked = !checked; }
  el.disabled = false;
}

async function deleteStudy(id) {
  const ok = await Trackie.confirmDialog('Delete this task?', {confirmText:'Delete', danger:true});
  if (!ok) return;
  try {
    const res = await Trackie.API.post(`${API_BASE}/study_plan.php`, {action:'delete', task_id:id});
    if (res.success) { document.getElementById(`study-${id}`)?.remove(); Trackie.Toast.success('Deleted.'); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
</script>
