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

// Coding activity + synced GitHub data (read from Trackie's DB, never live).
require_once '../includes/activity.php';
require_once '../includes/providers.php';
$codeStreak = activityReady() ? activityStreak($uid, 'coding') : ['current' => 0, 'best' => 0];
$hasSessions = tableExists('coding_sessions');
$sessions = $hasSessions ? fetchAll(
    "SELECT s.*, p.name project_name FROM coding_sessions s LEFT JOIN projects p ON p.id=s.project_id
     WHERE s.user_id=? ORDER BY s.session_date DESC, s.id DESC LIMIT 50", [$uid]) : [];
$weekMin = $hasSessions ? (int)fetchOne("SELECT COALESCE(SUM(minutes),0) m FROM coding_sessions WHERE user_id=? AND session_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)", [$uid])['m'] : 0;
$langMin = $hasSessions ? fetchAll("SELECT language, SUM(minutes) m FROM coding_sessions WHERE user_id=? AND language IS NOT NULL GROUP BY language ORDER BY m DESC LIMIT 6", [$uid]) : [];
$gh = provider('github');
$ghConnected = $gh && $gh->isConnected($uid);
$repos = $ghConnected ? syncedData($uid, 'github', 'repo', 100) : [];
$pushes = $ghConnected ? syncedData($uid, 'github', 'push', 30) : [];
usort($repos, static fn($a, $b) => strcmp($b['data']['pushed_at'] ?? '', $a['data']['pushed_at'] ?? ''));
$repoLangs = [];
foreach ($repos as $r) if (!empty($r['data']['language'])) $repoLangs[$r['data']['language']] = ($repoLangs[$r['data']['language']] ?? 0) + 1;
arsort($repoLangs);
$commits7 = 0;
foreach ($pushes as $p) if ($p['occurred_at'] && strtotime($p['occurred_at']) >= strtotime('-7 days')) $commits7 += (int)($p['data']['commits'] ?? 0);
$fmtMin = static fn(int $m) => $m >= 60 ? intdiv($m, 60) . 'h' . ($m % 60 ? ' ' . ($m % 60) . 'm' : '') : $m . 'm';

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

<div class="rd-head">
  <h1><i class="fas fa-code" style="color:var(--accent)"></i> Coding</h1>
  <div class="rd-head-actions">
    <button class="btn btn-secondary btn-sm" onclick="openCodeSession()"><i class="fas fa-stopwatch"></i> Log session</button>
    <button class="btn btn-primary btn-sm" onclick="openAddProject()"><i class="fas fa-plus"></i> New project</button>
  </div>
</div>
<div class="grid-stats" style="margin-bottom:1.25rem">
  <div class="stat-card"><div class="stat-val">🔥 <?= (int)$codeStreak['current'] ?></div><div class="stat-label">Coding streak<?= $codeStreak['best'] > $codeStreak['current'] ? ' · best ' . (int)$codeStreak['best'] : '' ?></div></div>
  <div class="stat-card"><div class="stat-val"><?= $fmtMin($weekMin) ?></div><div class="stat-label">Coded this week</div></div>
  <div class="stat-card"><div class="stat-val"><?= count(array_filter($projects, fn($p) => $p['status'] === 'active')) ?></div><div class="stat-label">Active projects</div></div>
  <div class="stat-card"><div class="stat-val"><?= $ghConnected ? $commits7 : '—' ?></div><div class="stat-label"><?= $ghConnected ? 'Commits pushed · 7 days' : 'GitHub not connected' ?></div></div>
</div>
<div class="filter-tabs" style="margin-bottom:1.25rem" id="codeTabs" role="tablist">
  <button class="filter-tab active" data-tab="projects" role="tab" aria-selected="true">Projects</button>
  <button class="filter-tab" data-tab="sessions" role="tab" aria-selected="false" tabindex="-1">Sessions</button>
  <button class="filter-tab" data-tab="github" role="tab" aria-selected="false" tabindex="-1"><i class="fab fa-github"></i> GitHub</button>
</div>
<div id="ctab-projects">

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
          <span style="display:flex;flex-shrink:0">
          <button aria-label="Edit project" class="btn btn-icon btn-ghost btn-sm" onclick="openEditProject(<?= $p['id'] ?>)" title="Edit"><i class="fas fa-pen"></i></button>
          <button aria-label="Delete project" class="btn btn-icon btn-ghost btn-sm" style="color:var(--accent);flex-shrink:0" onclick="deleteProject(<?= $p['id'] ?>)" title="Delete">
            <i class="fas fa-trash"></i>
          </button>
          </span>
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

</div>

<div id="ctab-sessions" class="hidden">
  <?php if ($langMin): ?>
    <div class="hb-chips"><span class="hb-chips-label">Time by language</span>
      <?php foreach ($langMin as $l): ?><span class="hb-chip"><?= h($l['language']) ?> · <?= $fmtMin((int)$l['m']) ?></span><?php endforeach; ?></div>
  <?php endif; ?>
  <?php if (!$sessions): ?>
    <div class="card card-body hb-empty-line">No coding sessions yet. Log time spent coding — on a project or just practice.</div>
  <?php else: ?>
    <div class="card">
      <?php foreach ($sessions as $s): ?>
        <div class="todo-row" id="csess-<?= (int)$s['id'] ?>">
          <div style="flex:1;min-width:0">
            <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
              <span class="todo-title"><?= $fmtMin((int)$s['minutes']) ?></span>
              <?php if ($s['language']): ?><span class="category-badge"><?= h($s['language']) ?></span><?php endif; ?>
              <?php if ($s['project_name']): ?><span class="badge badge-blue"><?= h($s['project_name']) ?></span><?php endif; ?>
            </div>
            <div class="todo-meta"><span><?= h(formatDate($s['session_date'])) ?></span><?php if ($s['notes']): ?><span><?= h($s['notes']) ?></span><?php endif; ?></div>
          </div>
          <button class="btn btn-icon btn-ghost btn-sm" style="color:var(--accent)" onclick="deleteCodeSession(<?= (int)$s['id'] ?>)" aria-label="Delete session"><i class="fas fa-trash"></i></button>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div id="ctab-github" class="hidden">
  <?php if (!$ghConnected): ?>
    <div class="card"><div class="empty-state"><div class="empty-state-icon"><i class="fab fa-github"></i></div>
      <div class="empty-state-title">Connect GitHub</div><p>See your repositories, languages and recent pushes here. Trackie only reads public data.</p>
      <a class="btn btn-primary" style="margin-top:.75rem" href="<?= APP_BASE ?>/pages/settings.php"><i class="fas fa-plug"></i> Connect in Settings</a></div></div>
  <?php elseif (!$repos): ?>
    <div class="card card-body hb-empty-line">GitHub is connected but nothing has synced yet — use "Sync now" in Settings.</div>
  <?php else: ?>
    <?php if ($repoLangs): ?>
      <div class="hb-chips"><span class="hb-chips-label">Languages (by repo)</span>
        <?php foreach (array_slice($repoLangs, 0, 8, true) as $l => $n): ?><span class="hb-chip"><?= h($l) ?> · <?= (int)$n ?></span><?php endforeach; ?></div>
    <?php endif; ?>
    <div class="hb-grid2">
      <div class="card card-body"><div class="fit-card-label">Repositories · recently pushed</div>
        <?php foreach (array_slice($repos, 0, 12) as $r): $d = $r['data']; ?>
          <div class="hb-row"><span><a href="<?= h(preg_match('#^https://github\.com/#', $d['html_url'] ?? '') ? $d['html_url'] : '#') ?>" target="_blank" rel="noopener"><?= h($d['name'] ?? '') ?></a>
            <?php if (!empty($d['language'])): ?><small class="rd-author"> · <?= h($d['language']) ?></small><?php endif; ?></span>
            <b title="Stars">★ <?= (int)($d['stars'] ?? 0) ?></b></div>
        <?php endforeach; ?>
      </div>
      <div class="card card-body"><div class="fit-card-label">Recent pushes</div>
        <?php if (!$pushes): ?><p class="hb-empty-line">No recent public pushes.</p><?php endif; ?>
        <?php foreach (array_slice($pushes, 0, 10) as $p): $d = $p['data']; ?>
          <div class="hb-row"><span><?= h($d['repo'] ?? '') ?><small class="rd-author" style="display:block"><?= h(mb_strimwidth((string)($d['messages'][0] ?? ''), 0, 60, '…')) ?></small></span>
            <b><?= (int)($d['commits'] ?? 0) ?> commit<?= (int)($d['commits'] ?? 0) === 1 ? '' : 's' ?></b></div>
        <?php endforeach; ?>
      </div>
    </div>
    <p class="hb-foot">From your last GitHub sync. Refresh with "Sync now" in Settings.</p>
  <?php endif; ?>
</div>

<!-- Coding session modal -->
<div id="codeSessionModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header"><span class="modal-title">Log coding session</span><button class="btn btn-icon btn-ghost btn-sm" data-close-modal="codeSessionModal" aria-label="Close dialog">&times;</button></div>
    <div class="modal-body">
      <div class="rd-form-row">
        <div class="form-group"><label for="csMin" class="form-label">Minutes <span style="color:var(--accent)">*</span></label><input id="csMin" type="number" min="1" max="1440" class="form-input" value="45"></div>
        <div class="form-group"><label for="csDate" class="form-label">Date</label><input id="csDate" type="date" class="form-input" max="<?= date('Y-m-d') ?>"></div>
      </div>
      <div class="rd-form-row">
        <div class="form-group"><label for="csLang" class="form-label">Language</label>
          <input id="csLang" class="form-input" maxlength="40" list="csLangs" placeholder="e.g. PHP, Python">
          <datalist id="csLangs"><?php foreach (array_unique(array_merge(array_column($langMin, 'language'), array_keys($repoLangs))) as $l): ?><option value="<?= h($l) ?>"><?php endforeach; ?></datalist></div>
        <div class="form-group"><label for="csProject" class="form-label">Project</label>
          <select id="csProject" class="form-input"><option value="">None</option><?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h($p['name']) ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="form-group"><label for="csNotes" class="form-label">What did you work on?</label><textarea id="csNotes" class="form-input" rows="2" maxlength="500"></textarea></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary btn-sm" data-close-modal="codeSessionModal">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="saveCodeSession()"><i class="fas fa-save"></i> Save</button>
    </div>
  </div>
</div>

<!-- Add project modal -->
<div id="addProjectModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header">
      <input type="hidden" id="projId"><span class="modal-title" id="projModalTitle">New Project</span>
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
document.getElementById('codeTabs').addEventListener('click', e => {
  const b = e.target.closest('[data-tab]'); if (!b) return;
  document.querySelectorAll('#codeTabs [data-tab]').forEach(x => { const on = x === b; x.classList.toggle('active', on); x.setAttribute('aria-selected', on); });
  ['projects', 'sessions', 'github'].forEach(t => document.getElementById(`ctab-${t}`).classList.toggle('hidden', t !== b.dataset.tab));
});
function openCodeSession() {
  document.getElementById('csDate').value = '<?= date('Y-m-d') ?>';
  ['csLang', 'csNotes', 'csProject'].forEach(i => document.getElementById(i).value = '');
  Trackie.openModal('codeSessionModal');
}
async function saveCodeSession() {
  try {
    const res = await Trackie.API.post(`${API_BASE}/projects.php`, { action: 'session_log', minutes: document.getElementById('csMin').value,
      session_date: document.getElementById('csDate').value, language: document.getElementById('csLang').value,
      project_id: document.getElementById('csProject').value, notes: document.getElementById('csNotes').value });
    if (res.success) { Trackie.Toast.success('Session logged' + (res.xp?.ok ? ` · +${res.xp.gained} XP` : '')); location.reload(); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function deleteCodeSession(id) {
  if (!await Trackie.confirmDialog('Delete this session?', { confirmText: 'Delete', danger: true })) return;
  const res = await Trackie.API.post(`${API_BASE}/projects.php`, { action: 'session_delete', session_id: id });
  if (res.success) document.getElementById(`csess-${id}`)?.remove();
}
async function openEditProject(id) {
  const res = await Trackie.API.post(`${API_BASE}/projects.php`, { action: 'get', project_id: id });
  if (!res.success) { Trackie.Toast.error(res.error || 'Not found.'); return; }
  const p = res.project;
  document.getElementById('projId').value = p.id;
  document.getElementById('projName').value = p.name;
  document.getElementById('projDesc').value = p.description || '';
  document.getElementById('projGithub').value = p.github_url || '';
  document.getElementById('projStatus').closest('.form-group').classList.add('hidden');
  document.getElementById('projModalTitle').textContent = 'Edit Project';
  Trackie.openModal('addProjectModal');
}

function openAddProject() {
  document.getElementById('projId').value = '';
  document.getElementById('projModalTitle').textContent = 'New Project';
  document.getElementById('projStatus').closest('.form-group').classList.remove('hidden');
  document.getElementById('projName').value = '';
  document.getElementById('projDesc').value = '';
  document.getElementById('projGithub').value = '';
  document.getElementById('projStatus').value = 'planning';
  Trackie.openModal('addProjectModal');
}
async function saveProject() {
  const name = document.getElementById('projName').value.trim();
  if (!name) { Trackie.Toast.warning('Project name is required.'); return; }
  const id = document.getElementById('projId').value;
  try {
    const res = await Trackie.API.post(`${API_BASE}/projects.php`, { action: id ? 'edit' : 'add', project_id: id, name,
      description: document.getElementById('projDesc').value.trim(), github_url: document.getElementById('projGithub').value.trim(),
      status: document.getElementById('projStatus').value });
    if (res.success) { Trackie.Toast.success(id ? 'Project updated.' : 'Project created!'); Trackie.closeModal('addProjectModal'); location.reload(); }
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
