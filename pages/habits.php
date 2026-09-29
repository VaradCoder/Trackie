<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Habits';
$currentPage = 'habits';
$today       = date('Y-m-d');

$habits = fetchAll(
    "SELECT h.*,
            COUNT(l.id)                                                  AS total_logs,
            COUNT(CASE WHEN l.date_completed >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) THEN 1 END) AS logs_week,
            MAX(CASE WHEN l.date_completed = CURDATE() THEN 1 ELSE 0 END) AS logged_today,
            s.status                                                     AS today_status
     FROM habits h
     LEFT JOIN logs l ON l.habit_id = h.id
     LEFT JOIN habit_status_log s ON s.habit_id = h.id AND s.log_date = CURDATE()
     WHERE h.user_id = ?
     GROUP BY h.id, s.status
     ORDER BY h.created_at DESC",
    [$uid]
);

// ── Hobby-based suggestions (USP: personalize from Profile → Your hobbies) ──
$userHobbies = fetchOne("SELECT hobbies FROM users WHERE id=?", [$uid])['hobbies'] ?? null;
$existingNames = array_map('mb_strtolower', array_column($habits, 'name'));
$suggestions = array_filter(
    suggestedHabitsForHobbies($userHobbies),
    fn($s) => !in_array(mb_strtolower($s['name']), $existingNames, true)
);

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<?= renderPageHeader('My Habits', [
  'icon'    => 'fa-heart',
  'sub'     => 'Build streaks and track daily consistency.',
  'actions' => '<button class="btn btn-primary btn-sm" onclick="openAddHabit()"><i class="fas fa-plus"></i> New Habit</button>',
]) ?>

<!-- Quick stats -->
<?php
$totalHabits    = count($habits);
$loggedToday    = array_sum(array_column($habits, 'logged_today'));
$completionRate = $totalHabits > 0 ? round($loggedToday / $totalHabits * 100) : 0;
// Longest active streak across all habits (uses the log dates we already have)
$allLogDates = array_column(
    fetchAll("SELECT DISTINCT l.date_completed FROM logs l JOIN habits h ON h.id=l.habit_id WHERE h.user_id=?", [$uid]),
    'date_completed'
);
$habitStreak = calculateStreaks($allLogDates, userNeutralDates($uid))['current'] ?? 0;

// ── AI-style insight (rule-based, from the user's own data) ──
$habitsInsight = '';
if ($totalHabits > 0) {
    $topHabit = null;
    foreach ($habits as $hh) {
        if ($topHabit === null || (int)$hh['total_logs'] > (int)$topHabit['total_logs']) $topHabit = $hh;
    }
    if ($completionRate >= 80) {
        $habitsInsight = "Strong day — you've completed {$completionRate}% of today's habits. Consistency like this builds streaks.";
    } elseif ($topHabit && (int)$topHabit['total_logs'] > 0) {
        $habitsInsight = "\"{$topHabit['name']}\" is your most consistent habit with {$topHabit['total_logs']} log" . ((int)$topHabit['total_logs'] !== 1 ? 's' : '') . ". "
                       . ($loggedToday < $totalHabits ? "You have " . ($totalHabits - $loggedToday) . " left to log today." : "All logged today!");
    } elseif ($habitStreak > 0) {
        $habitsInsight = "You're on a {$habitStreak}-day streak — log a habit today to keep it alive.";
    }
}
?>
<div class="grid-stats" style="margin-bottom:var(--sp-5)" id="habitsStatsWrap">
  <div class="stat-card">
    <div class="stat-val"><?= $totalHabits ?></div>
    <div class="stat-label"><i class="fas fa-list" style="color:#64748b"></i> Total habits</div>
  </div>
  <div class="stat-card">
    <div class="stat-val"><?= $loggedToday ?></div>
    <div class="stat-label"><i class="fas fa-check-circle" style="color:var(--ok)"></i> Logged today</div>
  </div>
  <div class="stat-card">
    <div class="stat-val"><?= $completionRate ?>%</div>
    <div class="stat-label"><i class="fas fa-gauge-high" style="color:var(--info)"></i> Today's rate</div>
  </div>
  <div class="stat-card">
    <div class="stat-val" style="color:#f59e0b"><?= $habitStreak ?> d</div>
    <div class="stat-label"><i class="fas fa-fire" style="color:#f59e0b"></i> Current streak</div>
  </div>
</div>

<?= renderInsight($habitsInsight) ?>

<div id="habitsSuggestionsWrap">
<?php if (!empty($suggestions)): ?>
  <div class="card card-body" style="margin-bottom:var(--sp-5)">
    <div style="font-size:.875rem;font-weight:600;margin-bottom:var(--sp-1)">
      <i class="fas fa-wand-magic-sparkles" style="color:var(--accent)"></i> Suggested for you
    </div>
    <p class="form-hint" style="margin-bottom:var(--sp-3)">Based on the hobbies you picked in Profile.</p>
    <div style="display:flex;flex-wrap:wrap;gap:.625rem">
      <?php foreach ($suggestions as $sg): ?>
        <button type="button" class="hobby-chip" style="border-radius:.625rem"
                onclick="addSuggested('<?= h(addslashes($sg['name'])) ?>','<?= h($sg['freq']) ?>','<?= h($sg['color']) ?>',this)">
          <i class="fas fa-plus" style="font-size:.7rem;margin-right:.375rem"></i><?= h($sg['name']) ?>
          <span style="opacity:.7;font-weight:400"> · <?= h($sg['hobby']) ?></span>
        </button>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>
</div>

<div id="habitsListWrap">
<?php if (empty($habits)): ?>
  <div class="card">
    <div class="empty-state">
      <div class="empty-state-icon"><i class="fas fa-heart"></i></div>
      <div class="empty-state-title">No habits yet</div>
      <p>Start building positive habits for a better life.</p>
      <button class="btn btn-primary" style="margin-top:var(--sp-3)" onclick="openAddHabit()">
        <i class="fas fa-plus"></i> Add your first habit
      </button>
    </div>
  </div>
<?php else: ?>
  <div class="grid-cards">
    <?php foreach ($habits as $h):
      // Correct progress: daily = logs this week / 7, weekly = min(logs this week, 1) / 1
      $target   = $h['frequency'] === 'daily' ? 7 : 1;
      $achieved = min((int)$h['logs_week'], $target);
      $pct      = round($achieved / $target * 100);
    ?>
      <div class="habit-card" id="habit-<?= $h['id'] ?>">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:.875rem">
          <div style="display:flex;align-items:center;gap:.625rem">
            <span class="habit-dot" style="background:<?= h($h['color']) ?>"></span>
            <div>
              <div style="font-weight:600;font-size:.9375rem;color:var(--text)"><?= h($h['name']) ?></div>
              <span class="badge badge-gray" style="margin-top:var(--sp-1)"><?= ucfirst($h['frequency']) ?></span>
            </div>
          </div>
          <button class="btn btn-icon btn-ghost btn-sm" onclick="openEditHabit(<?= $h['id'] ?>)" title="Edit" aria-label="Edit habit"><i class="fas fa-pen"></i></button>
          <button class="btn btn-icon btn-ghost btn-sm" style="color:var(--accent)"
                  onclick="deleteHabit(<?= $h['id'] ?>)" title="Delete">
            <i class="fas fa-trash"></i>
          </button>
        </div>

        <!-- Stats -->
        <div style="display:flex;gap:var(--sp-4);margin-bottom:.875rem;font-size:.8125rem;color:var(--muted)">
          <span>Total: <strong style="color:var(--text)"><?= $h['total_logs'] ?></strong></span>
          <span>This week: <strong style="color:var(--text)"><?= $h['logs_week'] ?></strong></span>
        </div>

        <!-- Progress bar (this week) -->
        <div style="margin-bottom:.875rem">
          <div style="display:flex;justify-content:space-between;font-size:.8125rem;margin-bottom:var(--sp-1)">
            <span style="color:var(--muted)">
              <?= $h['frequency'] === 'daily' ? 'Week progress' : 'Done this week' ?>
            </span>
            <span style="font-weight:600;color:var(--text)"><?= $pct ?>%</span>
          </div>
          <div class="progress-track">
            <div class="progress-fill <?= $pct >= 100 ? 'green' : '' ?>"
                 style="width:<?= $pct ?>%;background:<?= $h['color'] ?>"></div>
          </div>
        </div>

        <!-- Log status: Done / Fail / Skip -->
        <?php
          $curStatus = $h['logged_today'] ? 'done' : ($h['today_status'] ?: null);
        ?>
        <div id="habit-btn-<?= $h['id'] ?>">
          <?= renderHabitStatusControl($h['id'], $curStatus) ?>
        </div>

        <!-- History toggle -->
        <div style="margin-top:var(--sp-3)">
          <button class="btn btn-ghost btn-sm" style="width:100%;font-size:.75rem" onclick="toggleHabitHistory(<?= $h['id'] ?>)">
            <i class="fas fa-calendar-alt"></i> View history
          </button>
          <div id="habit-history-<?= $h['id'] ?>" class="habit-history hidden" style="margin-top:var(--sp-2)"></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
</div>

<!-- Add habit modal -->
<div id="addHabitModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header">
      <span class="modal-title" id="habitModalTitle">New Habit</span>
      <button class="btn btn-icon btn-ghost btn-sm" data-close-modal="addHabitModal" aria-label="Close dialog">&times;</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label for="habitName" class="form-label">Habit name <span style="color:var(--accent)">*</span></label>
        <input type="hidden" id="habitId">
        <input id="habitName" class="form-input" placeholder="e.g. Exercise, Read, Meditate">
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label for="habitFreq" class="form-label">Frequency</label>
          <select id="habitFreq" class="form-input">
            <option value="daily">Daily</option>
            <option value="weekly">Weekly</option>
          </select>
        </div>
        <div class="form-group">
          <label for="habitColor" class="form-label">Color</label>
          <input id="habitColor" class="form-input" type="color" value="#ef4444" style="height:2.5rem;padding:var(--sp-1) .5rem">
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary btn-sm" data-close-modal="addHabitModal">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="saveHabit()">
        <i class="fas fa-save"></i> Add Habit
      </button>
    </div>
  </div>
</div>

</div>
<?php include '../includes/footer.php'; ?>

<script>
const API_BASE = '<?= APP_BASE ?>/api';
const TODAY = '<?= $today ?>';

async function addSuggested(name, freq, color, btn) {
  btn.disabled = true;
  try {
    const res = await Trackie.API.post(`${API_BASE}/habits.php`, { action: 'add', name, frequency: freq, color });
    if (res.success) {
      Trackie.Toast.success(`"${name}" added!`);
      btn.remove();
    } else { Trackie.Toast.error(res.error || 'Failed.'); btn.disabled = false; }
  } catch { Trackie.Toast.error('Network error.'); btn.disabled = false; }
}

function openAddHabit() {
  document.getElementById('habitId').value    = '';
  document.getElementById('habitModalTitle').textContent = 'New Habit';
  document.getElementById('habitName').value  = '';
  document.getElementById('habitFreq').value  = 'daily';
  document.getElementById('habitColor').value = '#ef4444';
  Trackie.openModal('addHabitModal');
}

async function saveHabit() {
  const name = document.getElementById('habitName').value.trim();
  if (!name) { Trackie.Toast.warning('Name is required.'); return; }
  try {
    const editId = document.getElementById('habitId').value;
    const res = await Trackie.API.post(`${API_BASE}/habits.php`, {
      action: editId ? 'edit' : 'add', habit_id: editId,
      name,
      frequency: document.getElementById('habitFreq').value,
      color: document.getElementById('habitColor').value,
    });
    if (res.success) {
      Trackie.Toast.success(editId ? 'Habit updated.' : 'Habit added!');
      Trackie.closeModal('addHabitModal');
      await Trackie.refreshFragments(['habitsStatsWrap', 'habitsSuggestionsWrap', 'habitsListWrap']);
    } else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}

async function openEditHabit(id) {
  try {
    const res = await Trackie.API.post(`${API_BASE}/habits.php`, { action: 'get', habit_id: id });
    if (!res.success) { Trackie.Toast.error(res.error || 'Not found.'); return; }
    document.getElementById('habitId').value    = res.habit.id;
    document.getElementById('habitName').value  = res.habit.name;
    document.getElementById('habitFreq').value  = res.habit.frequency;
    document.getElementById('habitColor').value = res.habit.color || '#ef4444';
    document.getElementById('habitModalTitle').textContent = 'Edit Habit';
    Trackie.openModal('addHabitModal');
  } catch { Trackie.Toast.error('Network error.'); }
}

const HABIT_MSG = {
  done: { toast: 'success', text: 'Nice — logged as Done!' },
  fail: { toast: 'warning', text: 'Marked as Fail. Tomorrow’s a new shot.' },
  skip: { toast: 'info',    text: 'Skipped for today.' },
};

async function setHabitStatus(id, status, btn) {
  const group = btn.closest('.habit-status-group');
  const wasActive = btn.classList.contains('is-active');
  group.querySelectorAll('button').forEach(b => b.disabled = true);
  try {
    // Toggling the same status off unlogs the day; switching status re-logs it
    const action = wasActive ? 'unlog' : 'log';
    const payload = { action, habit_id: id, date: TODAY };
    if (action === 'log') payload.status = status;
    const res = await Trackie.API.post(`${API_BASE}/habits.php`, payload);
    if (res.success) {
      group.querySelectorAll('button').forEach(b => b.classList.remove('is-active'));
      if (!wasActive) {
        btn.classList.add('is-active', 'pop');
        setTimeout(() => btn.classList.remove('pop'), 350);
        const m = HABIT_MSG[status];
        Trackie.Toast[m.toast](m.text);
      } else {
        Trackie.Toast.info('Cleared for today.');
      }
    } else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
  group.querySelectorAll('button').forEach(b => b.disabled = false);
}

async function deleteHabit(id) {
  const ok = await Trackie.confirmDialog('Delete this habit and all its logs?', {confirmText:'Delete', danger:true});
  if (!ok) return;
  try {
    const res = await Trackie.API.post(`${API_BASE}/habits.php`, {action:'delete', habit_id:id});
    if (res.success) {
      document.getElementById(`habit-${id}`)?.remove();
      Trackie.Toast.success('Habit deleted.');
    } else Trackie.Toast.error(res.error || 'Delete failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function toggleHabitHistory(id) {
  const panel = document.getElementById('habit-history-' + id);
  if (!panel) return;
  const wasHidden = panel.classList.contains('hidden');
  panel.classList.toggle('hidden');
  if (wasHidden) { loadHabitHistory(id); }
}

async function loadHabitHistory(id) {
  const panel = document.getElementById('habit-history-' + id);
  if (!panel) return;
  panel.innerHTML = '<div style="font-size:.75rem;color:var(--muted)"><i class="fas fa-spinner fa-spin"></i> Loading\u2026</div>';
  try {
    const days = 84;
    const res = await Trackie.API.post(API_BASE + '/habits.php', {action:'history', habit_id:id, days:days});
    if (!res.success) { panel.innerHTML = '<div style="font-size:.75rem;color:var(--muted)">Could not load history.</div>'; return; }
    const loggedSet = {};
    res.dates.forEach(function(d){ loggedSet[d] = true; });
    const card = document.getElementById('habit-' + id);
    const dot = card ? card.querySelector('.habit-dot') : null;
    const color = dot ? dot.style.background : '#ef4444';
    const today = new Date();
    let cells = '';
    for (let i = days - 1; i >= 0; i--) {
      const d = new Date(today);
      d.setDate(d.getDate() - i);
      const iso = d.toISOString().slice(0,10);
      const on = !!loggedSet[iso];
      cells += '<div title="' + iso + (on ? ' \u2014 done' : '') + '" style="width:.6rem;height:.6rem;border-radius:2px;background:' + (on ? color : 'var(--surface2)') + '"></div>';
    }
    panel.innerHTML = '<div style="display:flex;flex-wrap:wrap;gap:2px;max-width:100%">' + cells + '</div>' +
      '<div style="font-size:.6875rem;color:var(--muted);margin-top:.375rem">Last ' + days + ' days</div>';
  } catch (e) {
    panel.innerHTML = '<div style="font-size:.75rem;color:var(--muted)">Network error.</div>';
  }
}

</script>
