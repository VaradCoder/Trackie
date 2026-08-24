<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Projects';
$currentPage = 'projects';

if (!tableExists('projects')) renderSetupNeeded('Projects');

$projects = fetchAll(
    "SELECT p.*,
            COUNT(t.id) AS total_tasks,
            SUM(t.status='done') AS done_tasks
     FROM projects p
     LEFT JOIN project_tasks t ON t.project_id = p.id
     WHERE p.user_id=? GROUP BY p.id ORDER BY p.created_at DESC",
    [$uid]
);

$statusMeta = [
    'planning' => ['label' => 'Planning', 'badge' => 'badge-gray',   'icon' => 'fa-lightbulb'],
    'active'   => ['label' => 'Active',   'badge' => 'badge-blue',   'icon' => 'fa-bolt'],
    'paused'   => ['label' => 'Paused',   'badge' => 'badge-yellow', 'icon' => 'fa-pause'],
    'done'     => ['label' => 'Done',     'badge' => 'badge-green',  'icon' => 'fa-check'],
];

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem">
  <h1 style="font-size:1.125rem;font-weight:600;margin:0"><i class="fas fa-code" style="color:var(--accent)"></i> Projects</h1>
  <button class="btn btn-primary btn-sm" onclick="openAddProject()"><i class="fas fa-plus"></i> New Project</button>
</div>

<div id="projectsListWrap">
<?php if (empty($projects)): ?>
  <div class="card">
    <div class="empty-state">
      <div class="empty-state-icon"><i class="fas fa-diagram-project"></i></div>
      <div class="empty-state-title">No projects yet</div>
      <p>Track what you're building — link a GitHub repo and break it into tasks.</p>
      <button class="btn btn-primary" style="margin-top:.75rem" onclick="openAddProject()"><i class="fas fa-plus"></i> Add your first project</button>
    </div>
  </div>
<?php else: ?>
  <div class="grid-cards-lg">
    <?php foreach ($projects as $p): $sm = $statusMeta[$p['status']]; $pct = $p['total_tasks'] > 0 ? round($p['done_tasks'] / $p['total_tasks'] * 100) : 0; ?>
      <div class="habit-card" id="project-<?= $p['id'] ?>">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:.625rem">
          <div style="min-width:0">
            <div style="font-weight:600;font-size:.9375rem;color:var(--text)"><?= h($p['name']) ?></div>
            <?php if ($p['description']): ?><div style="font-size:.8125rem;color:var(--muted);margin-top:.125rem"><?= h($p['description']) ?></div><?php endif; ?>
          </div>
          <button aria-label="Delete project" class="btn btn-icon btn-ghost btn-sm" style="color:var(--accent);flex-shrink:0" onclick="deleteProject(<?= $p['id'] ?>)" title="Delete">
            <i class="fas fa-trash"></i>
          </button>
        </div>

        <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:.75rem">
          <select class="form-input" style="width:auto;font-size:.75rem;padding:.25rem .5rem" onchange="setProjectStatus(<?= $p['id'] ?>, this.value)">
            <?php foreach ($statusMeta as $k => $m): ?>
              <option value="<?= $k ?>" <?= $p['status']===$k?'selected':'' ?>><?= $m['label'] ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($p['github_url']): ?>
            <a href="<?= h($p['github_url']) ?>" target="_blank" rel="noopener" class="btn btn-ghost btn-sm" style="font-size:.75rem">
              <i class="fab fa-github"></i> Repo
            </a>
          <?php endif; ?>
        </div>

        <?php if ($p['total_tasks'] > 0): ?>
          <div style="margin-bottom:.75rem">
            <div style="display:flex;justify-content:space-between;font-size:.75rem;margin-bottom:.25rem;color:var(--muted)">
              <span><?= (int)$p['done_tasks'] ?>/<?= (int)$p['total_tasks'] ?> tasks</span><span><?= $pct ?>%</span>
            </div>
            <div class="progress-track"><div class="progress-fill <?= $pct>=100?'green':'' ?>" style="width:<?= $pct ?>%"></div></div>
          </div>
        <?php endif; ?>

        <button class="btn btn-ghost btn-sm" style="width:100%;font-size:.75rem" onclick="toggleTasks(<?= $p['id'] ?>)">
          <i class="fas fa-list-check"></i> Tasks
        </button>
        <div id="tasks-<?= $p['id'] ?>" class="hidden" style="margin-top:.625rem"></div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
</div>

<!-- Add project modal -->
<div id="addProjectModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header">
      <span class="modal-title">New Project</span>
      <button class="btn btn-icon btn-ghost btn-sm" data-close-modal="addProjectModal" aria-label="Close dialog">&times;</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label for="projName" class="form-label">Project name <span style="color:var(--accent)">*</span></label>
        <input id="projName" class="form-input" placeholder="e.g. Trackie v3">
      </div>
      <div class="form-group">
        <label for="projDesc" class="form-label">Description</label>
        <input id="projDesc" class="form-input" placeholder="One-line summary (optional)">
      </div>
      <div class="form-group">
        <label for="projGithub" class="form-label"><i class="fab fa-github"></i> GitHub repo URL</label>
        <input id="projGithub" class="form-input" placeholder="https://github.com/you/repo (optional)">
      </div>
      <div class="form-group">
        <label for="projStatus" class="form-label">Status</label>
        <select id="projStatus" class="form-input">
          <?php foreach ($statusMeta as $k => $m): ?><option value="<?= $k ?>"><?= $m['label'] ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary btn-sm" data-close-modal="addProjectModal">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="saveProject()"><i class="fas fa-save"></i> Create</button>
    </div>
  </div>
</div>

</div>
<?php include '../includes/footer.php'; ?>

<script>
const API_BASE = '<?= APP_BASE ?>/api';

function openAddProject() {
  document.getElementById('projName').value = '';
  document.getElementById('projDesc').value = '';
  document.getElementById('projGithub').value = '';
  document.getElementById('projStatus').value = 'planning';
  Trackie.openModal('addProjectModal');
}
async function saveProject() {
  const name = document.getElementById('projName').value.trim();
  if (!name) { Trackie.Toast.warning('Project name is required.'); return; }
  try {
    const res = await Trackie.API.post(`${API_BASE}/projects.php`, {
      action: 'add', name,
      description: document.getElementById('projDesc').value.trim(),
      github_url: document.getElementById('projGithub').value.trim(),
      status: document.getElementById('projStatus').value,
    });
    if (res.success) { Trackie.Toast.success('Project created!'); Trackie.closeModal('addProjectModal'); await Trackie.refreshFragments(['projectsListWrap']); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function deleteProject(id) {
  const ok = await Trackie.confirmDialog('Delete this project and its tasks?', {confirmText:'Delete', danger:true});
  if (!ok) return;
  try {
    const res = await Trackie.API.post(`${API_BASE}/projects.php`, {action:'delete', project_id:id});
    if (res.success) { document.getElementById(`project-${id}`)?.remove(); Trackie.Toast.success('Project deleted.'); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function setProjectStatus(id, status) {
  try {
    const res = await Trackie.API.post(`${API_BASE}/projects.php`, {action:'update_status', project_id:id, status});
    if (res.success) Trackie.Toast.success('Status updated.');
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}

async function toggleTasks(projectId) {
  const panel = document.getElementById(`tasks-${projectId}`);
  const wasHidden = panel.classList.contains('hidden');
  panel.classList.toggle('hidden');
  if (wasHidden) await loadTasks(projectId);
}

const taskStatusIcon = { todo: 'fa-circle', doing: 'fa-spinner', done: 'fa-circle-check' };
const nextStatus = { todo: 'doing', doing: 'done', done: 'todo' };

async function loadTasks(projectId) {
  const panel = document.getElementById(`tasks-${projectId}`);
  panel.innerHTML = '<div style="font-size:.75rem;color:var(--muted)"><i class="fas fa-spinner fa-spin"></i> Loading…</div>';
  try {
    const res = await Trackie.API.post(`${API_BASE}/projects.php`, {action:'tasks', project_id:projectId});
    if (!res.success) { panel.innerHTML = '<div style="font-size:.75rem;color:var(--muted)">Could not load tasks.</div>'; return; }
    renderTasks(projectId, res.tasks);
  } catch { panel.innerHTML = '<div style="font-size:.75rem;color:var(--muted)">Network error.</div>'; }
}
function renderTasks(projectId, tasks) {
  const panel = document.getElementById(`tasks-${projectId}`);
  const rows = tasks.map(t => `
    <div style="display:flex;align-items:center;gap:.5rem;padding:.375rem 0;border-bottom:1px solid var(--border)">
      <button class="btn btn-icon btn-ghost btn-sm" style="width:1.75rem;height:1.75rem" onclick="cycleTaskStatus(${t.id}, '${t.status}', ${projectId})" title="Cycle status" aria-label="Cycle status">
        <i class="fas ${taskStatusIcon[t.status]}" style="font-size:.8125rem;color:${t.status==='done'?'#16a34a':t.status==='doing'?'#f59e0b':'var(--muted)'}"></i>
      </button>
      <span style="flex:1;font-size:.8125rem;${t.status==='done'?'text-decoration:line-through;color:var(--muted)':''}">${escProj(t.title)}</span>
      <button aria-label="Delete task" class="btn btn-icon btn-ghost btn-sm" style="width:1.75rem;height:1.75rem" onclick="deleteTask(${t.id}, ${projectId})" aria-label="Delete task"><i class="fas fa-xmark" style="font-size:.75rem"></i></button>
    </div>`).join('');
  panel.innerHTML = rows + `
    <div style="display:flex;gap:.5rem;margin-top:.5rem">
      <input class="form-input" style="font-size:.8125rem" placeholder="Add a task…" id="newTaskInput-${projectId}"
             onkeydown="if(event.key==='Enter') addTask(${projectId})">
      <button class="btn btn-secondary btn-sm" onclick="addTask(${projectId})" aria-label="Add task"><i class="fas fa-plus" aria-hidden="true"></i></button>
    </div>`;
}
function escProj(s) {
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
async function addTask(projectId) {
  const input = document.getElementById(`newTaskInput-${projectId}`);
  const title = input.value.trim();
  if (!title) return;
  try {
    const res = await Trackie.API.post(`${API_BASE}/projects.php`, {action:'add_task', project_id:projectId, title});
    if (res.success) { input.value = ''; await loadTasks(projectId); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function cycleTaskStatus(taskId, current, projectId) {
  try {
    const res = await Trackie.API.post(`${API_BASE}/projects.php`, {action:'update_task_status', task_id:taskId, status: nextStatus[current]});
    if (res.success) await loadTasks(projectId);
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function deleteTask(taskId, projectId) {
  try {
    const res = await Trackie.API.post(`${API_BASE}/projects.php`, {action:'delete_task', task_id:taskId});
    if (res.success) await loadTasks(projectId);
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
</script>
