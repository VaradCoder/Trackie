<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/activity.php';

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Writing';
$currentPage = 'writing';

if (!tableExists('writings')) renderSetupNeeded('Writing');
if (!tableExists('writing_log')) renderSetupNeeded('Writing');

$pieces = fetchAll("SELECT id, title, type, status, word_count, updated_at, created_at FROM writings WHERE user_id=? ORDER BY COALESCE(updated_at, created_at) DESC", [$uid]);
$logs = array_column(fetchAll("SELECT log_date, words_added FROM writing_log WHERE user_id=? AND log_date >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)", [$uid]), 'words_added', 'log_date');
$todayWords = (int)($logs[date('Y-m-d')] ?? 0);
$weekWords = 0;
for ($i = 0; $i < 7; $i++) $weekWords += (int)($logs[date('Y-m-d', strtotime("-{$i} day"))] ?? 0);
$goal = (int)(fetchOne("SELECT writing_goal g FROM user_settings WHERE user_id=?", [$uid])['g'] ?? 0);
$streak = activityReady() ? activityStreak($uid, 'writing') : ['current' => 0, 'best' => 0];
$totalWords = array_sum(array_column($pieces, 'word_count'));
$statusMeta = ['idea' => 'Idea', 'drafting' => 'Drafting', 'editing' => 'Editing', 'published' => 'Published'];
$typeMeta = ['draft' => 'Draft', 'article' => 'Article', 'story' => 'Story', 'book' => 'Book', 'idea' => 'Idea'];

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<div class="rd-head">
  <h1><i class="fas fa-pen-nib" style="color:var(--accent)"></i> Writing</h1>
  <div class="rd-head-actions"><button class="btn btn-primary btn-sm" onclick="openNewPiece()"><i class="fas fa-plus"></i> New piece</button></div>
</div>

<div class="grid-stats" style="margin-bottom:1.25rem">
  <div class="stat-card"><div class="stat-val"><?= number_format($todayWords) ?><?= $goal ? '<small> / ' . number_format($goal) . '</small>' : '' ?></div><div class="stat-label">Words added today</div></div>
  <div class="stat-card"><div class="stat-val">🔥 <?= (int)$streak['current'] ?></div><div class="stat-label">Writing streak<?= $streak['best'] > $streak['current'] ? ' · best ' . (int)$streak['best'] : '' ?></div></div>
  <div class="stat-card"><div class="stat-val"><?= number_format($weekWords) ?></div><div class="stat-label">Words added · 7 days</div></div>
  <div class="stat-card"><div class="stat-val"><?= number_format($totalWords) ?></div><div class="stat-label">Words across <?= count($pieces) ?> pieces</div></div>
</div>

<div class="hb-grid2" style="margin-bottom:1.25rem">
  <div class="card card-body">
    <div class="fit-card-label">Words added · last 14 days</div>
    <?php $days = []; for ($i = 13; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-{$i} day")); $days[$d] = (int)($logs[$d] ?? 0); } $max = max(1, $goal, ...array_values($days)); ?>
    <div class="hb-bars">
      <?php foreach ($days as $d => $n): ?><div class="hb-bar" title="<?= h(formatDate($d, 'D M j')) ?>: <?= number_format($n) ?> words"><span style="height:<?= round($n / $max * 100) ?>%"></span><em><?= date('j', strtotime($d)) ?></em></div><?php endforeach; ?>
    </div>
    <p class="hb-foot">Counted from text you add in Trackie's editor. Deleting text never lowers a day's total.</p>
  </div>
  <div class="card card-body">
    <div class="fit-card-label">Daily word goal</div>
    <?php if ($goal): $pct = min(100, (int)round($todayWords / $goal * 100)); ?>
      <div class="rd-goal-num"><?= $pct ?>%</div><div class="hb-progress rd-progress-lg"><span style="width:<?= $pct ?>%"></span></div>
    <?php endif; ?>
    <div class="rd-goal-form" style="margin-top:.75rem">
      <div class="form-group"><label class="form-label" for="wGoal">Words per day</label><input id="wGoal" type="number" min="1" max="50000" class="form-input" value="<?= $goal ?: '' ?>" placeholder="e.g. 500"></div>
      <button class="btn btn-primary btn-sm" onclick="saveGoal()">Save</button>
    </div>
  </div>
</div>

<?php if (!$pieces): ?>
  <div class="card"><div class="empty-state"><div class="empty-state-icon"><i class="fas fa-pen-nib"></i></div>
    <div class="empty-state-title">Nothing written yet</div><p>Start a piece and write right here — it saves as you type.</p>
    <button class="btn btn-primary" style="margin-top:.75rem" onclick="openNewPiece()"><i class="fas fa-plus"></i> Start writing</button></div></div>
<?php else: ?>
  <div class="grid-cards">
    <?php foreach ($pieces as $p): ?>
      <div class="habit-card" id="piece-<?= (int)$p['id'] ?>">
        <button class="rd-title rd-link" onclick="openEditor(<?= (int)$p['id'] ?>)"><?= h($p['title']) ?></button>
        <div class="rd-author"><?= h($typeMeta[$p['type']] ?? $p['type']) ?> · <?= number_format((int)$p['word_count']) ?> words<?= $p['updated_at'] ? ' · edited ' . h(formatDate($p['updated_at'], 'M j')) : '' ?></div>
        <div class="rd-book-actions" style="margin-top:.75rem">
          <select class="form-input" aria-label="Status" onchange="setStatus(<?= (int)$p['id'] ?>, this.value)">
            <?php foreach ($statusMeta as $k => $l): ?><option value="<?= $k ?>" <?= $p['status'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
          </select>
          <button class="btn btn-secondary btn-sm" onclick="openEditor(<?= (int)$p['id'] ?>)"><i class="fas fa-pen"></i> Write</button>
          <button class="btn btn-icon btn-ghost btn-sm" style="color:var(--accent)" onclick="deletePiece(<?= (int)$p['id'] ?>)" aria-label="Delete piece"><i class="fas fa-trash"></i></button>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- New piece -->
<div id="newPieceModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header"><span class="modal-title">New piece</span><button class="btn btn-icon btn-ghost btn-sm" data-close-modal="newPieceModal" aria-label="Close dialog">&times;</button></div>
    <div class="modal-body">
      <div class="form-group"><label for="npTitle" class="form-label">Title <span style="color:var(--accent)">*</span></label><input id="npTitle" class="form-input" maxlength="150"></div>
      <div class="form-group"><label for="npType" class="form-label">Type</label><select id="npType" class="form-input"><?php foreach ($typeMeta as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="modal-footer"><button class="btn btn-secondary btn-sm" data-close-modal="newPieceModal">Cancel</button><button class="btn btn-primary btn-sm" onclick="createPiece()">Create &amp; write</button></div>
  </div>
</div>

<!-- Editor -->
<div id="editorModal" class="modal-backdrop hidden">
  <div class="modal-box wr-editor">
    <div class="modal-header">
      <input id="edTitle" class="wr-title" maxlength="150" aria-label="Title">
      <span class="wr-status" id="edStatus" aria-live="polite"></span>
      <button class="btn btn-icon btn-ghost btn-sm" onclick="closeEditor()" aria-label="Close editor">&times;</button>
    </div>
    <textarea id="edText" class="wr-text" placeholder="Start writing…" spellcheck="true"></textarea>
    <div class="wr-foot"><span id="edWords">0 words</span><span id="edToday"></span></div>
  </div>
</div>

</div>
<?php include '../includes/footer.php'; ?>

<script>
const API_BASE = '<?= APP_BASE ?>/api';
const wPost = d => Trackie.API.post(`${API_BASE}/writing.php`, d);
const W_GOAL = <?= (int)$goal ?>;
let edId = null, edTimer = null, edDirty = false, edTitle0 = '';
function countWords(t) { return (t.match(/[\p{L}\p{N}]+(?:['’][\p{L}\p{N}]+)*/gu) || []).length; }
function openNewPiece() { document.getElementById('npTitle').value = ''; Trackie.openModal('newPieceModal'); }
async function createPiece() {
  const title = document.getElementById('npTitle').value.trim();
  if (!title) { Trackie.Toast.warning('Title is required.'); return; }
  const res = await wPost({ action: 'add', title, type: document.getElementById('npType').value });
  if (!res.success) { Trackie.Toast.error(res.error || 'Failed.'); return; }
  Trackie.closeModal('newPieceModal'); openEditor(res.id);
}
async function openEditor(id) {
  const res = await wPost({ action: 'get', item_id: id });
  if (!res.success) { Trackie.Toast.error(res.error || 'Not found.'); return; }
  edId = id; edDirty = false;
  document.getElementById('edTitle').value = edTitle0 = res.piece.title;
  document.getElementById('edText').value = res.piece.content || '';
  document.getElementById('edStatus').textContent = '';
  updateCount();
  Trackie.openModal('editorModal');
  setTimeout(() => document.getElementById('edText').focus(), 50);
}
function updateCount() { const n = countWords(document.getElementById('edText').value); document.getElementById('edWords').textContent = `${n.toLocaleString()} word${n === 1 ? '' : 's'}`; }
async function saveNow() {
  if (!edId) return;
  clearTimeout(edTimer);
  const title = document.getElementById('edTitle').value.trim();
  if (title && title !== edTitle0) { await wPost({ action: 'edit', item_id: edId, title }); edTitle0 = title; }
  if (!edDirty) return;
  edDirty = false;
  document.getElementById('edStatus').textContent = 'Saving…';
  try {
    const res = await wPost({ action: 'save_content', item_id: edId, content: document.getElementById('edText').value });
    if (!res.success) { document.getElementById('edStatus').textContent = 'Not saved'; edDirty = true; Trackie.Toast.error(res.error || 'Save failed.'); return; }
    document.getElementById('edStatus').textContent = 'Saved';
    document.getElementById('edToday').textContent = `Today: ${res.today.toLocaleString()} words added${W_GOAL ? ` · goal ${W_GOAL.toLocaleString()}` : ''}`;
    if (res.xp?.ok) Trackie.Toast.success(`Writing streak kept · +${res.xp.gained} XP`);
  } catch { document.getElementById('edStatus').textContent = 'Offline — will retry'; edDirty = true; }
}
document.getElementById('edText').addEventListener('input', () => {
  edDirty = true; updateCount(); document.getElementById('edStatus').textContent = 'Editing…';
  clearTimeout(edTimer); edTimer = setTimeout(saveNow, 1500);
});
document.getElementById('edTitle').addEventListener('change', saveNow);
async function closeEditor() { await saveNow(); Trackie.closeModal('editorModal'); location.reload(); }
window.addEventListener('beforeunload', e => { if (edDirty) { e.preventDefault(); e.returnValue = ''; } });
async function setStatus(id, status) { const r = await wPost({ action: 'update_status', item_id: id, status }); if (r.success) Trackie.Toast.success('Status updated.'); }
async function deletePiece(id) {
  if (!await Trackie.confirmDialog('Delete this piece? The text is removed permanently.', { confirmText: 'Delete', danger: true })) return;
  const r = await wPost({ action: 'delete', item_id: id }); if (r.success) document.getElementById(`piece-${id}`)?.remove();
}
async function saveGoal() {
  const r = await wPost({ action: 'goal_save', writing_goal: document.getElementById('wGoal').value });
  if (r.success) location.reload(); else Trackie.Toast.error(r.error || 'Failed.');
}
</script>
