<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Reminders';
$currentPage = 'reminders';

// Table may not exist yet on an un-migrated database — degrade gracefully.
if (!tableExists('reminders')) renderSetupNeeded('Reminders');

$reminders = fetchAll(
    "SELECT r.*, h.name AS habit_name
     FROM reminders r
     LEFT JOIN habits h ON h.id = r.habit_id
     WHERE r.user_id=?
     ORDER BY r.active DESC, r.next_fire_at ASC",
    [$uid]
);

$habits = fetchAll("SELECT id, name FROM habits WHERE user_id=? ORDER BY name", [$uid]);

$activeCount = count(array_filter($reminders, fn($r) => $r['active']));

/** Human-readable schedule line for a reminder row. */
function describeReminder(array $r): string {
    $time = date('g:i A', strtotime($r['remind_time']));
    switch ($r['type']) {
        case 'once':
            return formatDate($r['remind_date']) . " · {$time}";
        case 'recurring':
            $n = (int)$r['repeat_every'];
            $u = $r['repeat_unit'] . ($n > 1 ? 's' : '');
            return $n === 1 && $r['repeat_unit'] === 'day'
                ? "Daily at {$time}"
                : "Every {$n} {$u}" . ($r['repeat_unit'] !== 'hour' ? " at {$time}" : " from {$time}");
        case 'smart':
            return 'If "' . ($r['habit_name'] ?? 'habit') . "\" isn't done by {$time}";
    }
    return $time;
}

$typeMeta = [
    'once'      => ['icon' => 'fa-calendar-day', 'badge' => 'badge-blue',   'label' => 'One-time'],
    'recurring' => ['icon' => 'fa-rotate',       'badge' => 'badge-purple', 'label' => 'Recurring'],
    'smart'     => ['icon' => 'fa-wand-magic-sparkles', 'badge' => 'badge-yellow', 'label' => 'Smart'],
];

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<?= renderPageHeader('Reminders', [
  'icon'    => 'fa-bell',
  'sub'     => 'Never miss a habit check-in or a one-time nudge.',
  'actions' =>
      '<span class="badge badge-gray" id="remActiveCount">' . (int)$activeCount . ' active</span>'
    . '<button class="btn btn-secondary btn-sm hidden" id="notifPermBtn" onclick="enableBrowserNotifs()"><i class="fas fa-bell"></i> Enable browser notifications</button>'
    . '<button class="btn btn-primary btn-sm" onclick="openAddReminder()"><i class="fas fa-plus"></i> New Reminder</button>',
]) ?>

<!-- List -->
<div class="card" id="remListWrap">
  <?php if (empty($reminders)): ?>
    <div class="empty-state">
      <div class="empty-state-icon"><i class="fas fa-bell"></i></div>
      <div class="empty-state-title">No reminders yet</div>
      <p>Never forget to drink water, take medicine, or revise a chapter.</p>
      <button class="btn btn-primary" style="margin-top:var(--sp-3)" onclick="openAddReminder()">
        <i class="fas fa-plus"></i> Add your first reminder
      </button>
    </div>
  <?php else: ?>
    <?php foreach ($reminders as $r): $m = $typeMeta[$r['type']]; ?>
      <div class="todo-row <?= $r['active'] ? '' : 'rem-paused' ?>" id="rem-<?= $r['id'] ?>">
        <div style="width:36px;height:36px;border-radius:.5rem;background:var(--surface2);display:flex;align-items:center;justify-content:center;flex-shrink:0;color:var(--accent)">
          <i class="fas <?= $m['icon'] ?>"></i>
        </div>

        <div style="flex:1;min-width:0">
          <div style="display:flex;align-items:center;gap:var(--sp-2);flex-wrap:wrap">
            <span class="todo-title rem-title"><?= h($r['title']) ?></span>
            <span class="badge <?= $m['badge'] ?>"><?= $m['label'] ?></span>
            <span class="badge badge-gray rem-paused-badge" style="<?= $r['active'] ? 'display:none' : '' ?>">Paused</span>
          </div>
          <div class="todo-meta" style="margin-top:var(--sp-1)">
            <span><i class="fas fa-clock" style="font-size:.7rem"></i> <?= h(describeReminder($r)) ?></span>
            <span class="rem-next" style="color:var(--subtle);<?= $r['active'] ? '' : 'display:none' ?>">
              Next: <?= $r['active'] ? date('M j, g:i A', strtotime($r['next_fire_at'])) : '' ?>
            </span>
            <?php if ($r['notes']): ?>
              <span><?= h($r['notes']) ?></span>
            <?php endif; ?>
          </div>
        </div>

        <div class="todo-actions">
          <button class="btn btn-icon btn-ghost btn-sm rem-toggle-btn" title="<?= $r['active'] ? 'Pause' : 'Resume' ?>"
                  aria-label="<?= $r['active'] ? 'Pause' : 'Resume' ?> reminder <?= h($r['title']) ?>"
                  onclick="toggleReminder(<?= $r['id'] ?>, this)">
            <i class="fas <?= $r['active'] ? 'fa-pause' : 'fa-play' ?>"></i>
          </button>
          <button class="btn btn-icon btn-ghost btn-sm" title="Edit" aria-label="Edit" onclick="editReminder(<?= $r['id'] ?>)">
            <i class="fas fa-pen"></i>
          </button>
          <button class="btn btn-icon btn-ghost btn-sm" title="Delete" style="color:var(--accent)"
                  onclick="deleteReminder(<?= $r['id'] ?>)">
            <i class="fas fa-trash"></i>
          </button>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- Add / Edit Modal -->
<div id="reminderModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header">
      <span class="modal-title" id="reminderModalTitle">New Reminder</span>
      <button class="btn btn-icon btn-ghost btn-sm" data-close-modal="reminderModal" aria-label="Close dialog">&times;</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="remId">

      <div class="form-group">
        <label for="remTitle" class="form-label">Title <span style="color:var(--accent)">*</span></label>
        <input id="remTitle" class="form-input" placeholder="e.g. Drink water, Take medicine" maxlength="200">
      </div>

      <div class="form-group">
        <label class="form-label" id="lbl-rem-type">Type</label>
        <div class="filter-tabs" role="tablist" aria-labelledby="lbl-rem-type">
          <button type="button" class="filter-tab active" data-rem-type="once">One-time</button>
          <button type="button" class="filter-tab" data-rem-type="recurring">Recurring</button>
          <button type="button" class="filter-tab" data-rem-type="smart">Smart (habit)</button>
        </div>
      </div>

      <!-- One-time fields -->
      <div id="remFieldsOnce" class="form-grid-2">
        <div class="form-group">
          <label for="remDate" class="form-label">Date <span style="color:var(--accent)">*</span></label>
          <input id="remDate" class="form-input" type="date">
        </div>
        <div class="form-group">
          <label for="remTimeOnce" class="form-label">Time <span style="color:var(--accent)">*</span></label>
          <input id="remTimeOnce" class="form-input" type="time">
        </div>
      </div>

      <!-- Recurring fields -->
      <div id="remFieldsRecurring" class="hidden">
        <div class="form-grid-2">
          <div class="form-group">
            <label for="remEvery" class="form-label">Repeat every</label>
            <div style="display:flex;gap:var(--sp-2)">
              <input id="remEvery" class="form-input" type="number" min="1" max="99" value="1" style="width:5rem">
              <select id="remUnit" class="form-input">
                <option value="hour">hour(s)</option>
                <option value="day" selected>day(s)</option>
                <option value="week">week(s)</option>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label for="remTimeRec" class="form-label">At / starting from</label>
            <input id="remTimeRec" class="form-input" type="time">
          </div>
        </div>
      </div>

      <!-- Smart fields -->
      <div id="remFieldsSmart" class="hidden">
        <div class="form-grid-2">
          <div class="form-group">
            <label for="remHabit" class="form-label">Habit <span style="color:var(--accent)">*</span></label>
            <select id="remHabit" class="form-input">
              <option value="">Choose a habit…</option>
              <?php foreach ($habits as $hb): ?>
                <option value="<?= $hb['id'] ?>"><?= h($hb['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label for="remTimeSmart" class="form-label">Remind me at</label>
            <input id="remTimeSmart" class="form-input" type="time" value="18:00">
          </div>
        </div>
        <p class="form-hint" style="margin-top:-.25rem">
          Fires only if the habit hasn't been logged that day.
        </p>
      </div>

      <div class="form-group" style="margin-top:var(--sp-1)">
        <label for="remNotes" class="form-label">Notes <span style="font-weight:400;color:var(--muted)">(optional)</span></label>
        <input id="remNotes" class="form-input" placeholder="Shown in the notification" maxlength="500">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary btn-sm" data-close-modal="reminderModal">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="saveReminder()">
        <i class="fas fa-save"></i> Save
      </button>
    </div>
  </div>
</div>

</div>
<?php include '../includes/footer.php'; ?>

<style>
.rem-paused { opacity: .55; }
.rem-paused .rem-title { text-decoration: none; }
</style>

<script>
const API_BASE = '<?= APP_BASE ?>/api';
let remType = 'once';

/* Type tab switching */
document.querySelectorAll('[data-rem-type]').forEach(btn => {
  btn.addEventListener('click', () => setRemType(btn.dataset.remType));
});

function setRemType(t) {
  remType = t;
  document.querySelectorAll('[data-rem-type]').forEach(b =>
    b.classList.toggle('active', b.dataset.remType === t));
  document.getElementById('remFieldsOnce').classList.toggle('hidden', t !== 'once');
  document.getElementById('remFieldsRecurring').classList.toggle('hidden', t !== 'recurring');
  document.getElementById('remFieldsSmart').classList.toggle('hidden', t !== 'smart');
}

function openAddReminder() {
  document.getElementById('remId').value        = '';
  document.getElementById('remTitle').value     = '';
  document.getElementById('remNotes').value     = '';
  document.getElementById('remDate').value      = new Date().toISOString().slice(0, 10);
  document.getElementById('remTimeOnce').value  = '';
  document.getElementById('remEvery').value     = '1';
  document.getElementById('remUnit').value      = 'day';
  document.getElementById('remTimeRec').value   = '09:00';
  document.getElementById('remHabit').value     = '';
  document.getElementById('remTimeSmart').value = '18:00';
  document.getElementById('reminderModalTitle').textContent = 'New Reminder';
  setRemType('once');
  Trackie.openModal('reminderModal');
}

async function editReminder(id) {
  try {
    const res = await Trackie.API.post(`${API_BASE}/reminders.php`, { action: 'get', reminder_id: id });
    if (!res.success) { Trackie.Toast.error('Could not load reminder.'); return; }
    const r = res.reminder;
    const hhmm = (r.remind_time || '').slice(0, 5);

    document.getElementById('remId').value        = r.id;
    document.getElementById('remTitle').value     = r.title;
    document.getElementById('remNotes').value     = r.notes || '';
    document.getElementById('remDate').value      = r.remind_date || '';
    document.getElementById('remTimeOnce').value  = hhmm;
    document.getElementById('remEvery').value     = r.repeat_every || 1;
    document.getElementById('remUnit').value      = r.repeat_unit || 'day';
    document.getElementById('remTimeRec').value   = hhmm;
    document.getElementById('remHabit').value     = r.habit_id || '';
    document.getElementById('remTimeSmart').value = hhmm;
    document.getElementById('reminderModalTitle').textContent = 'Edit Reminder';
    setRemType(r.type);
    Trackie.openModal('reminderModal');
  } catch { Trackie.Toast.error('Network error.'); }
}

async function saveReminder() {
  const id    = document.getElementById('remId').value;
  const title = document.getElementById('remTitle').value.trim();
  if (!title) { Trackie.Toast.warning('Title is required.'); return; }

  const time = remType === 'once'      ? document.getElementById('remTimeOnce').value
             : remType === 'recurring' ? document.getElementById('remTimeRec').value
             :                           document.getElementById('remTimeSmart').value;
  if (!time) { Trackie.Toast.warning('Pick a time.'); return; }

  const payload = {
    action:       id ? 'edit' : 'add',
    reminder_id:  id || '',
    title,
    notes:        document.getElementById('remNotes').value,
    type:         remType,
    remind_date:  document.getElementById('remDate').value,
    remind_time:  time,
    repeat_every: document.getElementById('remEvery').value,
    repeat_unit:  document.getElementById('remUnit').value,
    habit_id:     document.getElementById('remHabit').value,
  };

  try {
    const res = await Trackie.API.post(`${API_BASE}/reminders.php`, payload);
    if (res.success) {
      Trackie.Toast.success(id ? 'Reminder updated.' : 'Reminder added.'); window.TrackieNativeSyncReminders?.();
      Trackie.closeModal('reminderModal');
      await Trackie.refreshFragments(['remActiveCount', 'remListWrap']);
    } else { Trackie.Toast.error(res.error || 'Save failed.'); }
  } catch { Trackie.Toast.error('Network error.'); }
}

function formatNextFire(iso) {
  const d = new Date(iso.replace(' ', 'T'));
  return d.toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}

async function toggleReminder(id, btn) {
  btn.disabled = true;
  try {
    const res = await Trackie.API.post(`${API_BASE}/reminders.php`, { action: 'toggle', reminder_id: id });
    if (res.success) {
      const row = document.getElementById(`rem-${id}`);
      const isActive = res.active == 1;
      row.classList.toggle('rem-paused', !isActive);
      row.querySelector('.rem-paused-badge').style.display = isActive ? 'none' : '';
      const nextEl = row.querySelector('.rem-next');
      nextEl.style.display = isActive ? '' : 'none';
      if (isActive && res.next_fire_at) nextEl.textContent = 'Next: ' + formatNextFire(res.next_fire_at);
      const icon = btn.querySelector('i');
      icon.className = `fas ${isActive ? 'fa-pause' : 'fa-play'}`;
      btn.title = isActive ? 'Pause' : 'Resume';
      Trackie.Toast.success(isActive ? 'Reminder resumed.' : 'Reminder paused.'); window.TrackieNativeSyncReminders?.();
    } else { Trackie.Toast.error(res.error || 'Failed.'); }
  } catch { Trackie.Toast.error('Network error.'); }
  btn.disabled = false;
}

async function deleteReminder(id) {
  const ok = await Trackie.confirmDialog('Delete this reminder?', { confirmText: 'Delete', danger: true });
  if (!ok) return;
  try {
    const res = await Trackie.API.post(`${API_BASE}/reminders.php`, { action: 'delete', reminder_id: id });
    if (res.success) { document.getElementById(`rem-${id}`)?.remove(); Trackie.Toast.success('Reminder deleted.'); window.TrackieNativeSyncReminders?.(); }
    else Trackie.Toast.error(res.error || 'Delete failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}

/* Browser-notification permission prompt */
function enableBrowserNotifs() {
  if (!('Notification' in window)) { Trackie.Toast.warning('This browser does not support notifications.'); return; }
  Notification.requestPermission().then(p => {
    if (p === 'granted') {
      Trackie.Toast.success('Browser notifications enabled!'); window.TrackieNativeSyncReminders?.();
      document.getElementById('notifPermBtn')?.classList.add('hidden');
      new Notification('🔔 Trackie reminders are on', {
        body: 'You\'ll get a notification when a reminder is due.',
        icon: '<?= APP_BASE ?>/assets/images/logo.png',
      });
    } else {
      Trackie.Toast.warning('Notifications stay off until you allow them.');
    }
  });
}

// Show the enable button only when permission hasn't been granted yet
if ('Notification' in window && Notification.permission !== 'granted') {
  document.getElementById('notifPermBtn')?.classList.remove('hidden');
}
</script>
