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

require_once '../includes/habit_schedule.php';
$weekStart = userSetting($uid, 'week_start');
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

// Log dates per habit → schedule-aware per-habit streaks and week targets.
$logsByHabit = [];
foreach (fetchAll("SELECT l.habit_id, l.date_completed d FROM logs l JOIN habits h ON h.id=l.habit_id WHERE h.user_id=?", [$uid]) as $r) {
    $logsByHabit[$r['habit_id']][] = $r['d'];
}
$weekFrom = weekStartOf(date('Y-m-d'), $weekStart);

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
// Status marks for the 7-day strip (done lives in `logs`, skip/fail here).
$weekDays = [];
for ($i = 6; $i >= 0; $i--) $weekDays[] = date('Y-m-d', strtotime("-$i day"));
$marks = [];
if (tableExists('habit_status_log')) {
    foreach (fetchAll("SELECT habit_id, log_date d, status FROM habit_status_log WHERE user_id=? AND log_date >= ?", [$uid, $weekDays[0]]) as $r) {
        $marks[$r['habit_id']][$r['d']] = $r['status'];
    }
}

// Due today and not done first, then done today, then not due today.
$dueToday = static fn(array $h) => habitDueOn($h, date('Y-m-d'), $logsByHabit[$h['id']] ?? [], $weekStart);
usort($habits, static function ($a, $b) use ($dueToday) {
    $rank = static fn($h) => $h['logged_today'] ? 1 : ($dueToday($h) ? 0 : 2);
    return [$rank($a), strtolower($a['name'])] <=> [$rank($b), strtolower($b['name'])];
});
$totalHabits = count($habits);
$dueList     = array_filter($habits, static fn($h) => $h['logged_today'] || $dueToday($h));
$dueDone     = count(array_filter($dueList, static fn($h) => (bool)$h['logged_today']));
// Overall habit streak — scheduled-off days don't break it (see habitStreaks())
$habitStreak = habitStreaks($uid)['current'];
?>
<div class="pg-strip" id="habitsStatsWrap">
  <?php if ($totalHabits): ?>
    <span><strong><?= $dueDone ?></strong> of <strong><?= count($dueList) ?></strong> done today</span>
    <span><i class="fas fa-fire" style="color:#f59e0b" aria-hidden="true"></i> <?= $habitStreak > 0 ? '<strong>' . $habitStreak . '</strong>-day streak' : 'Start a streak today' ?></span>
    <span><strong><?= $totalHabits ?></strong> habit<?= $totalHabits === 1 ? '' : 's' ?></span>
  <?php endif; ?>
</div>

<div id="habitsSuggestionsWrap">
<?php if (!empty($suggestions)): ?>
  <div class="card card-body u-mb-6">
    <div class="u-fs-14 u-fw-6 u-mb-1">
      <i class="fas fa-wand-magic-sparkles u-c-accent"></i> Suggested for you
    </div>
    <p class="form-hint u-mb-3">Based on the hobbies you picked in Profile.</p>
    <div class="u-flex u-flex-wrap u-gap-2h">
      <?php foreach ($suggestions as $sg): ?>
        <button type="button" class="hobby-chip" style="border-radius:.625rem"
                onclick="addSuggested('<?= h(addslashes($sg['name'])) ?>','<?= h($sg['freq']) ?>','<?= h($sg['color']) ?>',this)">
          <i class="fas fa-plus u-fs-11-2 u-mr-1h"></i><?= h($sg['name']) ?>
          <span style="opacity:.7;font-weight:400"> · <?= h($sg['hobby']) ?></span>
        </button>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>
</div>

<div id="habitsListWrap">
<?php if (empty($habits)): ?>
  <div class="card"><?= renderEmptyState('fa-heart', 'No habits yet',
    'Pick one small thing you want to do most days. Tick it off here — or right from Today.',
    '<button type="button" class="btn btn-primary btn-sm" onclick="openAddHabit()"><i class="fas fa-plus" aria-hidden="true"></i> Add your first habit</button>') ?></div>
<?php else: ?>
  <div class="hb-grid">
    <?php foreach ($habits as $h):
      $hLogs    = $logsByHabit[$h['id']] ?? [];
      $target   = habitWeekTarget($h);
      $achieved = min(count(array_filter($hLogs, static fn($d) => $d >= $weekFrom)), $target);
      $hStreak  = habitStreak($h, $hLogs, $weekStart);
      $isDue    = habitDueOn($h, date('Y-m-d'), $hLogs, $weekStart);
      $curStatus = $h['logged_today'] ? 'done' : ($h['today_status'] ?: null);
      $logSet   = array_flip($hLogs);
      $weekly   = $h['frequency'] === 'weekly';
      $sched    = habitDays($h);
    ?>
      <article class="card hb-card<?= $h['logged_today'] ? ' is-done-today' : '' ?><?= !$isDue && !$curStatus ? ' is-off-today' : '' ?>"
               id="habit-<?= (int)$h['id'] ?>" data-id="<?= (int)$h['id'] ?>" style="--hc:<?= h($h['color'] ?: '#ef4444') ?>">
        <header class="hb-head">
          <span class="habit-dot" style="background:<?= h($h['color'] ?: '#ef4444') ?>" aria-hidden="true"></span>
          <div class="hb-titles">
            <h3 class="hb-name"><?= h($h['name']) ?></h3>
            <div class="hb-sub"><?= h(habitScheduleLabel($h)) ?> · <span class="hb-week-count"><?= $achieved ?>/<?= $target ?></span> this week</div>
          </div>
          <span class="hb-flame<?= $hStreak['current'] > 0 ? ' is-lit' : '' ?>" data-streak="<?= (int)$hStreak['current'] ?>"
                title="Current streak · best <?= (int)$hStreak['best'] ?>">
            <i class="fas fa-fire" aria-hidden="true"></i>
            <span><?= (int)$hStreak['current'] ?></span><span class="sr-only"> <?= $weekly ? 'week' : 'day' ?> streak</span>
          </span>
          <div class="hb-menu">
            <button type="button" class="pg-icon-btn" onclick="openEditHabit(<?= (int)$h['id'] ?>)" title="Edit" aria-label="Edit <?= h($h['name']) ?>"><i class="fas fa-pen" aria-hidden="true"></i></button>
            <button type="button" class="pg-icon-btn is-danger" onclick="deleteHabit(<?= (int)$h['id'] ?>)" title="Delete" aria-label="Delete <?= h($h['name']) ?>"><i class="fas fa-trash" aria-hidden="true"></i></button>
          </div>
        </header>

        <?php /* Last 7 days. Tap a day to log or un-log it — fixing yesterday is
                 the most common reason people open a habit tracker. */ ?>
        <div class="hb-week" role="group" aria-label="Last 7 days of <?= h($h['name']) ?>">
          <?php foreach ($weekDays as $d):
            $t = strtotime($d . ' 12:00');
            $st = isset($logSet[$d]) ? 'done' : ($marks[$h['id']][$d] ?? null);
            if (!$st) $st = (!$weekly && $sched && !in_array((int)date('w', $t), $sched, true)) ? 'off' : 'open';
            if ($d < ($h['created_at'] ? substr($h['created_at'], 0, 10) : '') && $st !== 'done') $st = 'before';
            $label = date('D j M', $t) . ': ' . ['done' => 'done', 'skip' => 'skipped', 'fail' => 'missed', 'off' => 'not scheduled', 'open' => 'not done', 'before' => 'before this habit'][$st];
          ?>
            <button type="button" class="hb-day is-<?= $st ?><?= $d === $today ? ' is-today' : '' ?>" data-date="<?= $d ?>"
                    aria-pressed="<?= $st === 'done' ? 'true' : 'false' ?>" aria-label="<?= h($label) ?>" title="<?= h($label) ?>"
                    <?= $st === 'before' ? 'disabled' : '' ?>>
              <span class="hb-day-name"><?= h(mb_substr(date('D', $t), 0, 1)) ?></span>
              <span class="hb-day-dot"><i class="fas fa-check" aria-hidden="true"></i></span>
            </button>
          <?php endforeach; ?>
        </div>

        <?php if (!$isDue && !$curStatus): ?>
          <p class="hb-rest"><i class="fas fa-moon" aria-hidden="true"></i> Not scheduled today — a rest day for this habit.</p>
        <?php endif; ?>
        <div id="habit-btn-<?= (int)$h['id'] ?>"<?= $isDue ? ' data-due="1"' : '' ?>>
          <?= renderHabitStatusControl((int)$h['id'], $curStatus) ?>
        </div>

        <button type="button" class="hb-history-btn" onclick="toggleHabitHistory(<?= (int)$h['id'] ?>, this)" aria-expanded="false">
          <i class="fas fa-calendar-days" aria-hidden="true"></i> Year history
        </button>
        <div id="habit-history-<?= (int)$h['id'] ?>" class="habit-history hidden u-mt-2"></div>
      </article>
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
        <label for="habitName" class="form-label">Habit name <span class="u-c-accent">*</span></label>
        <input type="hidden" id="habitId">
        <input id="habitName" class="form-input" placeholder="e.g. Exercise, Read, Meditate">
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label for="habitFreq" class="form-label">Frequency</label>
          <select id="habitFreq" class="form-input" onchange="habitDaysUI()">
            <option value="daily">Every day</option>
            <option value="days">Specific days</option>
            <option value="weekly">Once a week</option>
          </select>
        </div>
        <div class="form-group">
          <label for="habitColor" class="form-label">Color</label>
          <input id="habitColor" class="form-input" type="color" value="#ef4444" style="height:2.5rem;padding:var(--sp-1) .5rem">
        </div>
      </div>
      <div class="form-group hidden" id="habitDaysWrap">
        <span class="form-label">On these days</span>
        <div class="habit-days">
          <?php foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 0 => 'Sun'] as $v => $l): ?>
            <label class="habit-day"><input type="checkbox" value="<?= $v ?>"><span><?= $l ?></span></label>
          <?php endforeach; ?>
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

const HABIT_DAY_IDS = [0, 1, 2, 3, 4, 5, 6];
function habitDaysUI() {
  const show = document.getElementById('habitFreq').value === 'days';
  document.getElementById('habitDaysWrap').classList.toggle('hidden', !show);
}
function setHabitDays(csv) {
  const on = new Set(String(csv || '').split(',').filter(Boolean).map(Number));
  document.querySelectorAll('#habitDaysWrap input').forEach(i => i.checked = on.has(+i.value));
}
function getHabitDays() {
  return [...document.querySelectorAll('#habitDaysWrap input:checked')].map(i => i.value).join(',');
}
function openAddHabit() {
  setHabitDays('1,3,5'); setTimeout(habitDaysUI);
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
    const freqSel = document.getElementById('habitFreq').value;
    const days = freqSel === 'days' ? getHabitDays() : '';
    if (freqSel === 'days' && !days) { Trackie.Toast.warning('Pick at least one day.'); return; }
    const res = await Trackie.API.post(`${API_BASE}/habits.php`, {
      action: editId ? 'edit' : 'add', habit_id: editId,
      name,
      frequency: freqSel === 'weekly' ? 'weekly' : 'daily',
      schedule_days: days,
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
    document.getElementById('habitFreq').value  = res.habit.frequency === 'weekly' ? 'weekly' : (res.habit.schedule_days ? 'days' : 'daily');
    setHabitDays(res.habit.schedule_days || '1,3,5'); habitDaysUI();
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
    // Tapping the active status again clears the day; another status replaces it.
    const action = wasActive ? 'unlog' : 'log';
    const payload = { action, habit_id: id, date: TODAY };
    if (action === 'log') payload.status = status;
    const res = await Trackie.API.post(`${API_BASE}/habits.php`, payload);
    if (!res.success) throw res;
    if (res.achievements?.length) Trackie.showAchievementToasts?.(res.achievements);
    group.querySelectorAll('button').forEach(b => b.classList.remove('is-active'));
    if (wasActive) {
      Trackie.Toast.info('Cleared for today.');
    } else {
      btn.classList.add('is-active', 'pop');
      const due = [...document.querySelectorAll('[data-due="1"]')];
      if (status === 'done' && due.length && due.every(d => d.querySelector('.habit-status-done.is-active'))) {
        Trackie.Delight?.burst(btn);
        Trackie.Toast.success('Every habit done for today 🎉', 3500);
      } else {
        const m = HABIT_MSG[status];
        Trackie.Toast[m.toast](m.text + (status === 'done' && res.xp?.gained ? ` +${res.xp.gained} XP` : ''));
      }
      await new Promise(r => setTimeout(r, 350));   // let the pop play before the card re-renders
    }
    await refreshHabit(id);
  } catch (err) {
    Trackie.UI.toastError(err, "Couldn't update that habit.");
  }
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
async function toggleHabitHistory(id, btn) {
  const panel = document.getElementById('habit-history-' + id);
  if (!panel) return;
  const wasHidden = panel.classList.contains('hidden');
  panel.classList.toggle('hidden');
  btn?.setAttribute('aria-expanded', String(wasHidden));
  if (wasHidden) { loadHabitHistory(id); }
}

/** Re-render one habit card from the server (streak, week count, status are
 *  server truth), and make the flame jump if the streak just grew. */
async function refreshHabit(id) {
  const before = +(document.querySelector(`#habit-${id} .hb-flame`)?.dataset.streak || 0);
  await Trackie.refreshFragments(['habit-' + id, 'habitsStatsWrap']);
  const flame = document.querySelector(`#habit-${id} .hb-flame`);
  if (flame && +flame.dataset.streak > before) flame.classList.add('is-bump');
}

// Tap a day in the 7-day strip to log or un-log it.
document.getElementById('page-main')?.addEventListener('click', async e => {
  const day = e.target.closest('.hb-day');
  if (!day || day.disabled || day.getAttribute('aria-busy') === 'true') return;
  const card = day.closest('.hb-card');
  const id = +card.dataset.id, date = day.dataset.date;
  const wasDone = day.classList.contains('is-done');
  const prev = day.className;
  day.setAttribute('aria-busy', 'true');
  day.className = day.className.replace(/is-(done|open|skip|fail|off)/, '') + (wasDone ? ' is-open' : ' is-done');
  day.setAttribute('aria-pressed', String(!wasDone));
  if (!wasDone) Trackie.Delight?.tap();
  try {
    const res = await Trackie.API.post(`${API_BASE}/habits.php`, wasDone
      ? { action: 'unlog', habit_id: id, date }
      : { action: 'log', habit_id: id, date, status: 'done' });
    if (!res.success) throw res;
    if (res.achievements?.length) Trackie.showAchievementToasts?.(res.achievements);
    if (!wasDone && res.xp?.gained) Trackie.Toast.success(`+${res.xp.gained} XP`, 1600);
    await refreshHabit(id);
  } catch (err) {
    day.className = prev; day.setAttribute('aria-pressed', String(wasDone));
    Trackie.UI.toastError(err, "Couldn't update that day.");
  } finally { day.removeAttribute('aria-busy'); }
});

async function loadHabitHistory(id) {
  const panel = document.getElementById('habit-history-' + id);
  if (!panel) return;
  const color = document.querySelector('#habit-' + id + ' .habit-dot')?.style.background || 'var(--ok)';
  // A year, week by week. The server sends its own "today" and which days the
  // schedule doesn't cover, so a rest day never looks like a miss and the
  // grid can't drift by a day across time zones.
  await Trackie.UI.load(panel,
    () => Trackie.API.post(API_BASE + '/habits.php', { action: 'history', habit_id: id, days: 365 }),
    res => {
      const done = new Set(res.dates), skip = new Set(res.skipped || []), fail = new Set(res.failed || []), off = new Set(res.off || []);
      Trackie.UI.heatmap(panel, {
        today: res.today, days: 365, color, weekStart: <?= (int)$weekStart ?>, label: 'Last 12 months',
        state: d => d < (res.created_on || '') ? 'before'
                  : done.has(d) ? 'done' : skip.has(d) ? 'skip' : fail.has(d) ? 'fail'
                  : off.has(d) || res.weekly ? 'off'   // a weekly habit isn't owed on any one day
                  : d > res.today ? 'before' : 'empty',
      });
    },
    { label: 'Loading history…', rows: 2, fallback: 'Could not load history.' });
}

</script>
