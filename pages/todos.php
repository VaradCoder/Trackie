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
$filter = in_array($_GET['filter'] ?? '', ['all','pending','completed','overdue','today'])
        ? $_GET['filter'] : 'all';
$search = sanitizeInput($_GET['search'] ?? '');

// Top-level todos only: subtasks (parent_id set) live in their parent's panel.
$sql    = "SELECT * FROM todos WHERE user_id=? AND deleted_at IS NULL AND parent_id IS NULL";
$params = [$uid];

if ($filter === 'completed') $sql .= " AND completed=1";
elseif ($filter === 'pending') $sql .= " AND completed=0";
elseif ($filter === 'overdue') $sql .= " AND completed=0 AND due_date < CURDATE()";
elseif ($filter === 'today')   $sql .= " AND DATE(due_date)=CURDATE()";

if ($search) {
    $sql .= " AND (title LIKE ? OR description LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
$sql  .= " ORDER BY completed ASC, priority DESC, due_date ASC, created_at DESC";
$todos = fetchAll($sql, $params);

$stats = fetchOne(
    "SELECT COUNT(*) total,
            SUM(completed=1) done,
            SUM(completed=0) pending,
            SUM(completed=0 AND due_date < CURDATE()) overdue
     FROM todos WHERE user_id=? AND deleted_at IS NULL AND parent_id IS NULL",
    [$uid]
);

// ── Timeline view (Phase 2 Week 2) — one scannable page: Overdue / Today /
// Upcoming / No date / Completed, instead of clicking through five separate
// filter tabs to see "where do things stand." Independent of $filter/$search
// above — a timeline shows everything, grouped by date, not a filtered slice.
$view = ($_GET['view'] ?? '') === 'timeline' ? 'timeline' : 'list';
$timelineGroups = [];
if ($view === 'timeline') {
    $allTodos = fetchAll(
        "SELECT * FROM todos WHERE user_id=? AND deleted_at IS NULL AND parent_id IS NULL
         ORDER BY completed ASC, FIELD(priority,'high','medium','low'), due_date ASC, created_at DESC",
        [$uid]
    );
    $today = date('Y-m-d');
    $timelineGroups = ['Overdue' => [], 'Today' => [], 'Upcoming' => [], 'No due date' => [], 'Completed' => []];
    foreach ($allTodos as $t) {
        if ($t['completed'])                                  $timelineGroups['Completed'][] = $t;
        elseif (!$t['due_date'])                               $timelineGroups['No due date'][] = $t;
        elseif ($t['due_date'] < $today)                       $timelineGroups['Overdue'][] = $t;
        elseif ($t['due_date'] === $today)                     $timelineGroups['Today'][] = $t;
        else                                                   $timelineGroups['Upcoming'][] = $t;
    }
}

// ── AI-style insight (rule-based) ──
$todosInsight = '';
$pendingTotal = (int)($stats['pending'] ?? 0);
if (($stats['overdue'] ?? 0) > 0) {
    $od = (int)$stats['overdue'];
    $todosInsight = "You have {$od} overdue task" . ($od !== 1 ? 's' : '') . " — clearing these first frees the most mental space.";
} elseif ($pendingTotal > 0) {
    $prio = fetchOne(
        "SELECT priority, COUNT(*) c FROM todos
         WHERE user_id=? AND deleted_at IS NULL AND parent_id IS NULL AND completed=0
         GROUP BY priority ORDER BY c DESC LIMIT 1",
        [$uid]
    );
    if ($prio) {
        $pct = (int)round($prio['c'] / $pendingTotal * 100);
        $todosInsight = "Most of your open tasks ({$pct}%) are {$prio['priority']} priority.";
    }
} elseif (($stats['total'] ?? 0) > 0) {
    $todosInsight = "Inbox zero — every task is done. Nice work.";
}

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<?= renderPageHeader('All Todos', [
  'icon'    => 'fa-check-square',
  'sub'     => 'Capture, prioritise, and finish what matters today.',
  'actions' => '<button class="btn btn-primary btn-sm" onclick="openModal(\'todoModal\')"><i class="fas fa-plus"></i> New Todo</button>',
]) ?>

<!-- Stats -->
<div class="grid-stats" id="todoStatsWrap">
  <?php
  $sc = [
    ['l'=>'Total',     'v'=>$stats['total']  ?? 0, 'icon'=>'fa-list',             'c'=>'#64748b'],
    ['l'=>'Completed', 'v'=>$stats['done']   ?? 0, 'icon'=>'fa-check-circle',     'c'=>'#22c55e'],
    ['l'=>'Pending',   'v'=>$stats['pending']?? 0, 'icon'=>'fa-clock',            'c'=>'#f59e0b'],
    ['l'=>'Overdue',   'v'=>$stats['overdue']?? 0, 'icon'=>'fa-exclamation-circle','c'=>'#ef4444'],
  ];
  foreach ($sc as $s): ?>
    <div class="stat-card">
      <div style="display:flex;align-items:center;gap:.625rem">
        <i class="fas <?= $s['icon'] ?>" style="font-size:1.25rem;color:<?= $s['c'] ?>"></i>
        <div>
          <div class="stat-val" style="font-size:1.375rem"><?= $s['v'] ?></div>
          <div class="stat-label"><?= $s['l'] ?></div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?= renderInsight($todosInsight) ?>

<!-- View toggle: List (filtered) vs. Timeline (everything, grouped by date) -->
<div style="display:flex;align-items:center;justify-content:space-between;gap:var(--sp-3);margin-bottom:var(--sp-3);flex-wrap:wrap">
  <div class="filter-tabs">
    <a class="filter-tab <?= $view==='list'?'active':'' ?>" href="?filter=<?= h($filter) ?><?= $search ? '&search='.urlencode($search) : '' ?>">
      <i class="fas fa-list" style="font-size:.75rem"></i> List
    </a>
    <a class="filter-tab <?= $view==='timeline'?'active':'' ?>" href="?view=timeline">
      <i class="fas fa-timeline" style="font-size:.75rem"></i> Timeline
    </a>
  </div>
</div>

<?php if ($view === 'list'): ?>
<!-- Filters + search -->
<div style="display:flex;align-items:center;gap:var(--sp-3);margin-bottom:var(--sp-4);flex-wrap:wrap">
  <div class="filter-tabs">
    <?php
    $tabs = ['all'=>'All','pending'=>'Pending','completed'=>'Done','overdue'=>'Overdue','today'=>'Today'];
    foreach ($tabs as $k => $lbl):
      $cnt = '';
      if ($k === 'overdue' && ($stats['overdue'] ?? 0) > 0) $cnt = " ({$stats['overdue']})";
    ?>
      <a class="filter-tab <?= $filter===$k?'active':'' ?>"
         href="?filter=<?= $k ?><?= $search ? '&search='.urlencode($search) : '' ?>">
        <?= $lbl ?><?= $cnt ?>
      </a>
    <?php endforeach; ?>
  </div>
  <form method="GET" style="display:flex;gap:var(--sp-2);flex:1;max-width:300px">
    <input type="hidden" name="filter" value="<?= h($filter) ?>">
    <input name="search" class="form-input" style="font-size:.875rem"
           placeholder="Search todos…" value="<?= h($search) ?>">
    <button type="submit" class="btn btn-secondary btn-sm" aria-label="Search todos">
            <i class="fas fa-search" aria-hidden="true"></i>
          </button>
    <?php if ($search): ?>
      <a href="?filter=<?= h($filter) ?>" class="btn btn-ghost btn-sm">Clear</a>
    <?php endif; ?>
  </form>
</div>
<?php endif; ?>

<?php if ($view === 'list'): ?>
<!-- List -->
<div class="card" id="todoList">
  <?php if (empty($todos)): ?>
    <div class="empty-state">
      <div class="empty-state-icon"><i class="fas fa-clipboard-list"></i></div>
      <div class="empty-state-title"><?= $search ? 'No todos match your search' : 'Ready to get things done?' ?></div>
      <?php if ($search): ?>
        <p>Try a different keyword, or clear the search to see everything.</p>
      <?php else: ?>
        <p>Add your first task and start building momentum.</p>
        <button class="btn btn-primary" style="margin-top:var(--sp-3)" onclick="openModal('todoModal')">
          <i class="fas fa-plus"></i> Add your first todo
        </button>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <?php foreach ($todos as $t): ?>
      <?php
        $overdue = !$t['completed'] && $t['due_date'] && $t['due_date'] < date('Y-m-d');
        $badgeCls = $t['priority'] === 'high' ? 'badge-red' : ($t['priority'] === 'low' ? 'badge-blue' : 'badge-yellow');
      ?>
      <!-- Parent todo row -->
      <div class="todo-row <?= $t['completed'] ? 'done' : '' ?>" id="todo-<?= $t['id'] ?>">
        <input type="checkbox" class="todo-check"
               <?= $t['completed'] ? 'checked' : '' ?>
               onchange="toggleTodo(<?= $t['id'] ?>, this.checked, this)">

        <div style="flex:1;min-width:0">
          <div style="display:flex;align-items:center;gap:var(--sp-2);flex-wrap:wrap">
            <span class="todo-title"><?= h($t['title']) ?></span>
            <?php if ($t['category']): ?>
              <span class="category-badge"><?= h($t['category']) ?></span>
            <?php endif; ?>
            <?php if ($t['tags']): ?>
              <?php foreach (explode(',', $t['tags']) as $tag): $tag = trim($tag); if (!$tag) continue; ?>
                <span class="tag-pill">#<?= h($tag) ?></span>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <?php if ($t['description']): ?>
            <div class="todo-meta" style="margin-top:.125rem"><?= h($t['description']) ?></div>
          <?php endif; ?>
          <div class="todo-meta" style="margin-top:var(--sp-1)">
            <?php if ($t['due_date']): ?>
              <span style="<?= $overdue ? 'color:var(--accent)' : '' ?>">
                <i class="fas fa-calendar" style="font-size:.7rem"></i>
                <?= formatDate($t['due_date']) ?>
                <?= $overdue ? '· <strong>Overdue</strong>' : '' ?>
              </span>
            <?php endif; ?>
            <span class="badge <?= $badgeCls ?>"><?= ucfirst($t['priority']) ?></span>
            <?php if (($t['recurring'] ?? 'none') !== 'none'): ?>
              <span class="badge badge-gray"><i class="fas fa-rotate" style="font-size:.7rem"></i> <?= ucfirst($t['recurring']) ?></span>
            <?php endif; ?>
            <?php if ($t['location']): ?>
              <span><i class="fas fa-map-marker-alt" style="font-size:.7rem"></i> <?= h($t['location']) ?></span>
            <?php endif; ?>
          </div>
        </div>

        <div class="todo-actions">
          <!-- Add subtask -->
          <button class="btn btn-icon btn-ghost btn-sm" title="Add subtask"
                  onclick="toggleSubtasks(<?= $t['id'] ?>)"
                  id="sub-toggle-<?= $t['id'] ?>"
                  aria-label="Show/add subtasks">
            <i class="fas fa-list-ul"></i>
          </button>
          <?php if (!$t['completed']): ?>
          <a class="btn btn-icon btn-ghost btn-sm" title="Focus on this" aria-label="Start a focus session on this task"
             href="<?= APP_BASE ?>/pages/focus.php?todo=<?= (int)$t['id'] ?>">
            <i class="fas fa-stopwatch"></i>
          </a>
          <?php endif; ?>
          <button aria-label="Edit" class="btn btn-icon btn-ghost btn-sm" title="Edit"
                  onclick="editTodo(<?= $t['id'] ?>)">
            <i class="fas fa-pen"></i>
          </button>
          <button aria-label="Delete" class="btn btn-icon btn-ghost btn-sm" title="Delete" style="color:var(--accent)"
                  onclick="deleteTodo(<?= $t['id'] ?>)">
            <i class="fas fa-trash"></i>
          </button>
        </div>
      </div>

      <!-- Subtasks panel (hidden until toggled) -->
      <div id="subtasks-<?= $t['id'] ?>" class="subtask-panel hidden"
           style="padding:.375rem 1rem .5rem 3rem;background:var(--surface2)">
        <div class="subtask-list" id="subtask-list-<?= $t['id'] ?>">
          <div style="font-size:.8rem;color:var(--muted)"><i class="fas fa-spinner fa-spin"></i> Loading…</div>
        </div>
        <div style="display:flex;gap:var(--sp-2);margin-top:var(--sp-2);flex-wrap:wrap">
          <input type="text" class="form-input" style="font-size:.8125rem;flex:1;min-width:80px"
                 id="subtask-input-<?= $t['id'] ?>"
                 placeholder="Add subtask…"
                 onkeydown="if(event.key==='Enter'){addSubtask(<?= $t['id'] ?>,this)}"
                 aria-label="New subtask for <?= h($t['title']) ?>">
          <button class="btn btn-primary btn-sm" onclick="addSubtask(<?= $t['id'] ?>, document.getElementById('subtask-input-<?= $t['id'] ?>'))">
            <i class="fas fa-plus"></i>
          </button>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($view === 'timeline'): ?>
<!-- Timeline: everything, grouped by date, one scannable page -->
<div id="todoTimelineWrap">
  <?php
  $groupMeta = [
    'Overdue'     => ['icon' => 'fa-triangle-exclamation', 'color' => 'var(--accent)'],
    'Today'       => ['icon' => 'fa-star',                 'color' => 'var(--warn)'],
    'Upcoming'    => ['icon' => 'fa-calendar',              'color' => 'var(--info)'],
    'No due date' => ['icon' => 'fa-inbox',                 'color' => 'var(--muted)'],
    'Completed'   => ['icon' => 'fa-check-circle',          'color' => 'var(--ok)'],
  ];
  $anyShown = false;
  foreach ($timelineGroups as $label => $items):
    if (empty($items)) continue;
    $anyShown = true;
    $gm = $groupMeta[$label];
  ?>
    <div style="margin-bottom:var(--sp-5)">
      <div style="font-size:.8125rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:.625rem;display:flex;align-items:center;gap:var(--sp-2)">
        <i class="fas <?= $gm['icon'] ?>" style="color:<?= $gm['color'] ?>"></i>
        <?= h($label) ?>
        <span class="badge badge-gray"><?= count($items) ?></span>
      </div>
      <div class="card">
        <?php foreach ($items as $t): ?>
          <div class="todo-row <?= $t['completed'] ? 'done' : '' ?>" id="tl-todo-<?= $t['id'] ?>">
            <input type="checkbox" class="todo-check" <?= $t['completed'] ? 'checked' : '' ?>
                   onchange="toggleTodo(<?= $t['id'] ?>, this.checked, this)"
                   aria-label="Mark <?= h($t['title']) ?> as done">
            <span class="priority-pip <?= h($t['priority']) ?>" aria-hidden="true"></span>
            <div style="flex:1;min-width:0">
              <div class="todo-title"><?= h($t['title']) ?></div>
              <?php if ($t['due_date'] && $label !== 'Today'): ?>
                <div class="todo-meta"><?= formatDate($t['due_date']) ?></div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$anyShown): ?>
    <div class="card">
      <div class="empty-state">
        <div class="empty-state-icon"><i class="fas fa-timeline"></i></div>
        <div class="empty-state-title">Nothing on the timeline yet</div>
        <p>Add a task to see it grouped by when it's due.</p>
        <button class="btn btn-primary" style="margin-top:var(--sp-3)" onclick="openModal('todoModal')">
          <i class="fas fa-plus"></i> Add your first todo
        </button>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

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
        <label for="todoTitle" class="form-label">Title <span style="color:var(--accent)">*</span></label>
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
          <label for="todoTags" class="form-label">Tags <span class="form-hint" style="margin:0">(comma-separated)</span></label>
          <input id="todoTags" class="form-input" placeholder="e.g. urgent, physics">
        </div>
      </div>
    </div>
    <div class="modal-footer">
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

async function toggleTodo(id, checked, el) {
  el.disabled = true;
  const row = document.getElementById(`todo-${id}`);
  // Optimistic: strike through immediately so the interaction feels instant.
  row?.classList.toggle('done', checked);
  try {
    const res = await Trackie.API.post(`${API_BASE}/todos.php`, {
      action:'toggle', todo_id:id, completed: checked ? 1 : 0
    });
    if (res.success) {
      if (checked) {
        // Motivating completion feedback: XP + how many are left.
        const remaining = document.querySelectorAll('.todo-row:not(.done)').length;
        const xpTxt = res.xp?.ok ? `+${res.xp.gained} XP` : '';
        if (res.xp?.leveledUp) {
          Trackie.Toast.success(`⚡ Level up! Level ${res.xp.level} — ${res.xp.title}`, 5000);
        } else if (remaining === 0) {
          Trackie.Toast.success(`🎉 All tasks done! ${xpTxt} — you're clear.`, 3500);
        } else {
          Trackie.Toast.success(`✅ Nice! ${xpTxt} · ${remaining} task${remaining !== 1 ? 's' : ''} left`, 2800);
        }
      }
      // Keep the stat cards live without a reload.
      Trackie.refreshFragments(['todoStatsWrap']);
      if (res.achievements?.length) Trackie.showAchievementToasts(res.achievements);
    } else {
      Trackie.Toast.error(res.error || 'Failed.');
      el.checked = !checked; row?.classList.toggle('done', !checked);
    }
  } catch {
    Trackie.Toast.error('Network error.');
    el.checked = !checked; row?.classList.toggle('done', !checked);
  }
  el.disabled = false;
}

async function deleteTodo(id) {
  const ok = await Trackie.confirmDialog('Delete this todo?', {confirmText:'Delete', danger:true});
  if (!ok) return;
  try {
    const res = await Trackie.API.post(`${API_BASE}/todos.php`, {action:'delete', todo_id:id});
    if (res.success) {
      const row = document.getElementById(`todo-${id}`);
      row?.remove();
      Trackie.Toast.action('Todo deleted.', 'Undo', async () => {
        const r = await Trackie.API.post(`${API_BASE}/todos.php`, {action:'restore', todo_id:id});
        if (r.success) { Trackie.Toast.success('Restored.'); await Trackie.refreshFragments(['todoStatsWrap', 'todoList']); }
        else Trackie.Toast.error(r.error || 'Could not restore.');
      });
      Trackie.refreshFragments(['todoStatsWrap']);
    } else Trackie.Toast.error(res.error || 'Delete failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}

async function toggleSubtasks(id) {
  const panel = document.getElementById('subtasks-' + id);
  if (!panel) return;
  const wasHidden = panel.classList.contains('hidden');
  panel.classList.toggle('hidden');
  if (wasHidden) { await loadSubtasks(id); }
}

function escSub(s) {
  return String(s).replace(/[&<>"']/g, function(c) {
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
  });
}

async function loadSubtasks(id) {
  const list = document.getElementById('subtask-list-' + id);
  if (!list) return;
  list.innerHTML = '<div style="font-size:.8rem;color:var(--muted)"><i class="fas fa-spinner fa-spin"></i> Loading\u2026</div>';
  try {
    const res = await Trackie.API.post(API_BASE + '/todos.php', {action:'subtasks', parent_id:id});
    if (!res.success) { list.innerHTML = '<div style="font-size:.8rem;color:var(--muted)">Could not load subtasks.</div>'; return; }
    if (!res.subtasks.length) { list.innerHTML = '<div style="font-size:.8rem;color:var(--muted)">No subtasks yet.</div>'; return; }
    list.innerHTML = res.subtasks.map(function(s) {
      return '<div style="display:flex;align-items:center;gap:var(--sp-2);padding:var(--sp-1) 0">' +
        '<input type="checkbox" ' + (s.completed ? 'checked' : '') + ' onchange="toggleSubtask(' + s.id + ', this.checked, ' + id + ')">' +
        '<span style="flex:1;font-size:.8125rem;' + (s.completed ? 'text-decoration:line-through;color:var(--muted)' : '') + '">' + escSub(s.title) + '</span>' +
        '<button class="btn btn-icon btn-ghost btn-sm" title="Delete subtask" aria-label="Delete subtask" onclick="deleteSubtask(' + s.id + ', ' + id + ')"><i class="fas fa-trash" style="font-size:.7rem"></i></button>' +
        '</div>';
    }).join('');
  } catch (e) {
    list.innerHTML = '<div style="font-size:.8rem;color:var(--muted)">Network error.</div>';
  }
}

async function addSubtask(parentId, inputEl) {
  const title = inputEl.value.trim();
  if (!title) return;
  inputEl.disabled = true;
  try {
    const res = await Trackie.API.post(API_BASE + '/todos.php', {action:'add_subtask', parent_id: parentId, title: title});
    if (res.success) { inputEl.value = ''; await loadSubtasks(parentId); }
    else { Trackie.Toast.error(res.error || 'Could not add subtask.'); }
  } catch (e) { Trackie.Toast.error('Network error.'); }
  inputEl.disabled = false;
}

async function toggleSubtask(id, checked, parentId) {
  try {
    const res = await Trackie.API.post(API_BASE + '/todos.php', {action:'toggle', todo_id:id, completed: checked?1:0});
    if (!res.success) { Trackie.Toast.error(res.error || 'Failed.'); }
  } catch (e) { Trackie.Toast.error('Network error.'); }
  await loadSubtasks(parentId);
}

async function deleteSubtask(id, parentId) {
  const ok = await Trackie.confirmDialog('Delete this subtask?', {confirmText:'Delete', danger:true});
  if (!ok) return;
  try {
    const res = await Trackie.API.post(API_BASE + '/todos.php', {action:'delete', todo_id:id});
    if (res.success) { Trackie.Toast.success('Subtask deleted.'); await loadSubtasks(parentId); }
    else { Trackie.Toast.error(res.error || 'Delete failed.'); }
  } catch (e) { Trackie.Toast.error('Network error.'); }
}

function openAddTodo() {
  document.getElementById('todoId').value               = '';
  document.getElementById('todoTitle').value            = '';
  document.getElementById('todoDesc').value             = '';
  document.getElementById('todoDue').value              = '';
  document.getElementById('todoPriority').value         = 'medium';
  document.getElementById('todoLocation').value         = '';
  document.getElementById('todoRecurring').value        = 'none';
  document.getElementById('todoCategory').value         = '';
  document.getElementById('todoTags').value             = '';
  document.getElementById('todoModalTitle').textContent = 'New Todo';
  Trackie.openModal('todoModal');
}

async function editTodo(id) {
  try {
    const res = await Trackie.API.post(`${API_BASE}/todos.php`, {action:'get', todo_id:id});
    if (!res.success) { Trackie.Toast.error('Could not load todo.'); return; }
    const t = res.todo;
    document.getElementById('todoId').value               = t.id;
    document.getElementById('todoTitle').value            = t.title;
    document.getElementById('todoDesc').value             = t.description || '';
    document.getElementById('todoDue').value              = t.due_date || '';
    document.getElementById('todoPriority').value         = t.priority;
    document.getElementById('todoLocation').value         = t.location || '';
    document.getElementById('todoRecurring').value        = t.recurring || 'none';
    document.getElementById('todoCategory').value         = t.category || '';
    document.getElementById('todoTags').value             = t.tags || '';
    document.getElementById('todoModalTitle').textContent = 'Edit Todo';
    Trackie.openModal('todoModal');
  } catch { Trackie.Toast.error('Network error.'); }
}

async function saveTodo() {
  const id    = document.getElementById('todoId').value;
  const title = document.getElementById('todoTitle').value.trim();
  if (!title) { Trackie.Toast.warning('Title is required.'); return; }

  const payload = {
    action:      id ? 'edit' : 'add',
    todo_id:     id || '',
    title,
    description: document.getElementById('todoDesc').value,
    due_date:    document.getElementById('todoDue').value,
    priority:    document.getElementById('todoPriority').value,
    location:    document.getElementById('todoLocation').value,
    recurring:   document.getElementById('todoRecurring').value,
    category:    document.getElementById('todoCategory').value,
    tags:        document.getElementById('todoTags').value,
  };

  try {
    const res = await Trackie.API.post(`${API_BASE}/todos.php`, payload);
    if (res.success) {
      Trackie.Toast.success(id ? 'Todo updated.' : 'Todo added.');
      Trackie.closeModal('todoModal');
      await Trackie.refreshFragments(['todoStatsWrap', 'todoList']);
    } else { Trackie.Toast.error(res.error || 'Save failed.'); }
  } catch { Trackie.Toast.error('Network error.'); }
}

// Attach new-todo button
document.querySelector('[onclick="openModal(\'todoModal\')"]')?.addEventListener('click', openAddTodo);
</script>
