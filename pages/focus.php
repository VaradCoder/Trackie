<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/gamification.php';

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Focus';
$currentPage = 'focus';

if (!tableExists('focus_sessions')) renderSetupNeeded('Focus');

$stats = focusStats($uid);
$history = fetchAll(
    "SELECT f.duration_min, f.type, f.mode, f.started_at, t.title AS task
       FROM focus_sessions f
       LEFT JOIN todos t ON t.id = f.todo_id AND t.user_id = f.user_id
      WHERE f.user_id=? AND f.completed=1 ORDER BY f.id DESC LIMIT 10", [$uid]
);
// Tasks you can focus on: open top-level todos, due soonest first.
$openTodos = fetchAll(
    "SELECT id, title, due_date FROM todos
      WHERE user_id=? AND completed=0 AND deleted_at IS NULL AND parent_id IS NULL
      ORDER BY (due_date IS NULL), due_date, id DESC LIMIT 40", [$uid]
);
$preselectTodo = (int)($_GET['todo'] ?? 0);   // e.g. a "Focus on this" link from Todos
$fmt = fn($m) => $m >= 60 ? floor($m/60).'h '.($m%60).'m' : $m.'m';

// Single toggle for the optional Lottie break-ring animation. Empty by
// default, so the ~30KB lottie-player library isn't fetched on every Focus
// visit for a feature nobody has turned on — paste a .json/.lottie URL here
// to enable it (the CSS ring stays as the fallback either way).
$focusLottieSrc = '';

require_once '../includes/head.php';
?>
<?php if ($focusLottieSrc): ?>
<!-- lottie-player web component — only loaded when an animation is actually configured -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/lottie-player/2.0.12/lottie-player.js" defer></script>
<?php endif; ?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<?= renderPageHeader('Focus', [
  'icon' => 'fa-stopwatch',
  'sub'  => 'Pomodoro — 25 min focus, 5 min break',
]) ?>

<!-- Stat row -->
<div class="grid-stats">
  <div class="stat-card"><div class="stat-val" id="st-today"><?= $fmt($stats['today']) ?></div><div class="stat-label">Focused today</div></div>
  <div class="stat-card"><div class="stat-val"><?= $fmt($stats['week']) ?></div><div class="stat-label">This week</div></div>
  <div class="stat-card"><div class="stat-val"><?= $fmt($stats['month']) ?></div><div class="stat-label">This month</div></div>
  <div class="stat-card"><div class="stat-val" style="color:#f59e0b"><i class="fas fa-fire" style="font-size:1rem"></i> <?= $stats['streak'] ?></div><div class="stat-label">Focus streak</div></div>
</div>

<!-- Timer -->
<div class="card card-body" style="text-align:center;margin:1.25rem 0;max-width:520px">
  <div class="filter-tabs" style="justify-content:center;margin-bottom:var(--sp-5)" id="modeTabs">
    <button class="filter-tab active" data-mode="Study">📚 Study</button>
    <button class="filter-tab" data-mode="Work">💼 Work</button>
    <button class="filter-tab" data-mode="Coding">💻 Coding</button>
    <button class="filter-tab" data-mode="Reading">📖 Reading</button>
    <button class="filter-tab" data-mode="Custom" id="tabCustom"><i class="fas fa-sliders-h"></i> Custom</button>
  </div>
  <div id="customTimerForm" class="hidden" style="display:flex;gap:var(--sp-2);justify-content:center;margin:0 0 1rem;flex-wrap:wrap;align-items:center">
    <input type="text" id="customLabel" placeholder="Label (e.g. Deep Work)" maxlength="30" style="max-width:160px;padding:var(--sp-2) .75rem;border-radius:8px;border:1px solid var(--border);background:var(--surface2);color:var(--text)">
    <input type="number" id="customMinutes" placeholder="Minutes" min="1" max="180" style="max-width:90px;padding:var(--sp-2) .75rem;border-radius:8px;border:1px solid var(--border);background:var(--surface2);color:var(--text)">
    <button class="btn btn-primary" id="btnApplyCustom" style="padding:var(--sp-2) 1rem"><i class="fas fa-check"></i> Set</button>
  </div>

  <div class="focus-task">
    <label for="focusTodo" class="form-label" style="margin:0">Working on</label>
    <select id="focusTodo" class="form-input">
      <option value="">Nothing specific</option>
      <?php foreach ($openTodos as $t): ?>
        <option value="<?= (int)$t['id'] ?>" <?= $preselectTodo === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['title']) ?><?= $t['due_date'] ? ' · ' . h(date('j M', strtotime($t['due_date']))) : '' ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div id="timerRing" class="timer-ring">
    <!-- Set FOCUS_LOTTIE_SRC below to a .lottie/.json URL from lottiefiles.com to
         replace this CSS ring with a real animation. Falls back gracefully if empty
         or if the asset fails to load. -->
    <lottie-player id="focusLottie" class="timer-lottie hidden" loop></lottie-player>
    <div id="timerDisplay" style="font-size:4rem;font-weight:800;line-height:1;color:var(--text);font-variant-numeric:tabular-nums">25:00</div>
  </div>
  <div id="timerPhase" style="font-size:.875rem;color:var(--muted);margin-top:var(--sp-2);text-transform:uppercase;letter-spacing:.08em">Focus session</div>

  <div style="display:flex;gap:var(--sp-2);justify-content:center;margin-top:var(--sp-5);flex-wrap:wrap">
    <button class="btn btn-primary" id="btnStart"><i class="fas fa-play"></i> Start</button>
    <button class="btn btn-secondary hidden" id="btnPause"><i class="fas fa-pause"></i> Pause</button>
    <button class="btn btn-ghost" id="btnReset"><i class="fas fa-rotate-left"></i> Reset</button>
  </div>
  <p class="form-hint" style="margin-top:var(--sp-4)">Complete a focus session to earn <strong>+20 XP</strong>.</p>
</div>

<!-- History -->
<div style="font-size:.9375rem;font-weight:600;margin:0 0 .75rem">Recent sessions</div>
<div class="card" id="focusHistory">
  <?php if (empty($history)): ?>
    <div class="empty-state"><div class="empty-state-icon"><i class="fas fa-stopwatch"></i></div>
      <div class="empty-state-title">No sessions yet</div><p>Start your first focus session above.</p></div>
  <?php else: foreach ($history as $h): ?>
    <div class="todo-row">
      <div style="width:34px;height:34px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:var(--accent-bg);color:var(--accent)">
        <i class="fas <?= $h['type']==='focus'?'fa-bolt':'fa-mug-hot' ?>" style="font-size:.8125rem"></i>
      </div>
      <div style="flex:1;min-width:0">
        <span class="todo-title"><?= $h['type'] === 'focus' ? ($h['task'] ? h($h['task']) : ($h['mode'] ? h($h['mode']) . ' focus' : 'Focus session')) : 'Break' ?></span>
        <div class="todo-meta"><?= (int)$h['duration_min'] ?> min · <?= timeAgo($h['started_at']) ?></div>
      </div>
      <?php if ($h['type']==='focus'): ?><strong style="color:#f59e0b">+20 XP</strong><?php endif; ?>
    </div>
  <?php endforeach; endif; ?>
</div>

  <!-- Category breakdown -->
  <div style="font-size:.9375rem;font-weight:600;margin:var(--sp-5) 0 .75rem">Time by category (last 30 days)</div>
  <div class="card" id="focusBreakdown">
    <div style="font-size:.8125rem;color:var(--muted)"><i class="fas fa-spinner fa-spin"></i> Loading…</div>
  </div>
</div>
<?php include '../includes/footer.php'; ?>

<script>
const API_BASE = '<?= APP_BASE ?>/api';

// ── Optional Lottie animation ring (falls back to a CSS pulse if unset/broken) ──
// Single source of truth is the $focusLottieSrc PHP var above — it also
// controls whether the lottie-player script even loads on this page.
const FOCUS_LOTTIE_SRC = <?= json_encode($focusLottieSrc) ?>;
(function setupFocusLottie() {
  const el = document.getElementById('focusLottie');
  if (!FOCUS_LOTTIE_SRC) return; // CSS ring stays as the default
  el.addEventListener('error', () => el.classList.add('hidden'));
  el.setAttribute('autoplay', '');
  el.setAttribute('src', FOCUS_LOTTIE_SRC); // safe even before the custom element upgrades
  el.classList.remove('hidden');
  document.getElementById('timerRing').classList.add('has-lottie');
})();

let mode = 'Study';
let phase = 'focus';            // 'focus' | 'break'
let remaining = 25 * 60;
let timer = null;
let FOCUS_MIN = 25; const BREAK_MIN = 5;

const disp  = document.getElementById('timerDisplay');
const phaseEl = document.getElementById('timerPhase');
const bStart = document.getElementById('btnStart');
const bPause = document.getElementById('btnPause');

function render() {
  const m = String(Math.floor(remaining/60)).padStart(2,'0');
  const s = String(remaining%60).padStart(2,'0');
  disp.textContent = `${m}:${s}`;
  document.title = `${m}:${s} · Focus — Trackie`;
}
function setPhase(p) {
  phase = p;
  remaining = (p === 'focus' ? FOCUS_MIN : BREAK_MIN) * 60;
  phaseEl.textContent = p === 'focus' ? 'Focus session' : 'Break';
  render();
}
function tick() {
  remaining--;
  if (remaining <= 0) { complete(); return; }
  render();
}
function start() {
  if (timer) return;
  timer = setInterval(tick, 1000);
  bStart.classList.add('hidden'); bPause.classList.remove('hidden');
  document.getElementById('timerRing').classList.add('is-running');
  document.getElementById('focusLottie').play?.();
  if (phase === 'focus') spotifyFocusPlay();
  // Keep the screen awake for the duration of an active session.
  Trackie.Platform?.keepAwake(true);
}
function pause() {
  clearInterval(timer); timer = null;
  bStart.classList.remove('hidden'); bPause.classList.add('hidden');
  document.getElementById('timerRing').classList.remove('is-running');
  document.getElementById('focusLottie').pause?.();
  spotifyFocusPause();
  Trackie.Platform?.keepAwake(false);
}

/* ── Spotify Focus Session Sync (see Music module → Listen tab) ──────
   Silently no-ops if no playlist is synced or Spotify isn't connected —
   never blocks or errors the timer itself. */
function spotifyFocusPlay() {
  const uri = localStorage.getItem('trackie_focus_playlist_uri');
  if (!uri) return;
  Trackie.API.post(`${API_BASE}/spotify.php`, { action: 'play', playlist_uri: uri })
    .then(res => { if (res && res.noActiveDevice) Trackie.Toast.info('Open Spotify on a device to auto-play your focus playlist.'); })
    .catch(() => {});
}
function spotifyFocusPause() {
  if (!localStorage.getItem('trackie_focus_playlist_uri')) return;
  Trackie.API.post(`${API_BASE}/spotify.php`, { action: 'pause' }).catch(() => {});
}
function reset() {
  pause(); setPhase(phase);
}
async function complete() {
  pause();
  const wasFocus = phase === 'focus';
  if (wasFocus) {
    try {
      const sel = document.getElementById('focusTodo');
      const todoId = sel?.value || '';
      const todoTitle = todoId ? sel.options[sel.selectedIndex].text.replace(/ · [^·]+$/, '') : '';
      const res = await Trackie.API.post(`${API_BASE}/focus.php`, {
        action:'complete', type:'focus', duration: FOCUS_MIN, mode,
        todo_id: todoId, ended_at: Math.floor(Date.now() / 1000),   // real end time survives an offline replay
      });
      // API.post() queues mutating calls while offline and returns
      // {success:true, queued:true}. That is the ONLY case where it is true to
      // say the session will sync later — a genuinely queued write.
      if (res.queued) {
        Trackie.Toast.info('Saved offline — this session will sync when you reconnect.');
      } else if (res.success) {
        if (res.stats) document.getElementById('st-today').textContent =
          res.stats.today >= 60 ? `${Math.floor(res.stats.today/60)}h ${res.stats.today%60}m` : `${res.stats.today}m`;
        res.xp?.leveledUp
          ? Trackie.Toast.success(`⚡ Level up! Level ${res.xp.level}`, 5000)
          : Trackie.Toast.success(res.xp?.gained ? `Focus complete! +${res.xp.gained} XP 🎉` : 'Focus complete! 🎉');
        // Session was for a task → offer to tick it off right here.
        if (todoId) Trackie.Toast.action(`Finished "${todoTitle}"?`, 'Mark done', async () => {
          try {
            const r = await Trackie.API.post(`${API_BASE}/todos.php`, { action: 'toggle', todo_id: todoId, completed: 1 }, { button: null });
            if (!r.success) { Trackie.Toast.error(r.error || 'Could not complete that task.'); return; }
            Trackie.Toast.success(r.xp?.gained ? `Task done · +${r.xp.gained} XP` : 'Task done.');
            sel.querySelector(`option[value="${CSS.escape(todoId)}"]`)?.remove();
            sel.value = '';
          } catch (e) { Trackie.Toast.error(e.message || 'Could not complete that task.'); }
        }, 12000);
        try { new Audio('data:audio/wav;base64,UklGRl9vT19XQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQ==').play()?.catch(() => {}); } catch (e) {}
        loadFocusBreakdown();
      } else {
        // Server reached us and refused. Never report this as success — that
        // is how a broken `mode` column silently destroyed every session.
        Trackie.Toast.error(res.error || "Couldn't save this focus session.");
      }
    } catch (e) {
      // Online and the request still failed: a real error, not an offline
      // queue. Say so plainly rather than inventing a sync that won't happen.
      Trackie.Toast.error(e.message || "Couldn't save this focus session — please try again.");
    }
    setPhase('break');
    Trackie.Toast.info('Time for a 5-minute break.');
    Trackie.Platform?.speak('Focus session complete. Time for a short break.');
  } else {
    setPhase('focus');
    Trackie.Toast.info('Break over — ready to focus?');
    Trackie.Platform?.speak('Break over. Ready to focus?');
  }
}

document.querySelectorAll('#modeTabs [data-mode]').forEach(b =>
  b.addEventListener('click', () => {
    document.querySelectorAll('#modeTabs [data-mode]').forEach(x => x.classList.remove('active'));
    b.classList.add('active');
    if (b.dataset.mode === 'Custom') {
      document.getElementById('customTimerForm').classList.remove('hidden');
      return;
    }
    document.getElementById('customTimerForm').classList.add('hidden');
    mode = b.dataset.mode;
    if (!timer) { FOCUS_MIN = 25; document.querySelector('.page-header-sub').textContent = 'Pomodoro — 25 min focus, 5 min break'; setPhase('focus'); }
  }));
document.getElementById('btnApplyCustom').addEventListener('click', () => {
  if (timer) { Trackie.Toast.info('Pause or finish the current session first.'); return; }
  const label = (document.getElementById('customLabel').value || 'Custom').trim().slice(0,30) || 'Custom';
  let mins = parseInt(document.getElementById('customMinutes').value, 10);
  if (!mins || mins < 1) mins = 25;
  if (mins > 180) mins = 180;
  FOCUS_MIN = mins;
  mode = label;
  document.querySelector('.page-header-sub').textContent = label + ' — ' + mins + ' min focus session';
  setPhase('focus');
  Trackie.Toast.success('Custom timer set: ' + label + ' — ' + mins + 'm');
});
bStart.addEventListener('click', start);
bPause.addEventListener('click', pause);
document.getElementById('btnReset').addEventListener('click', reset);
window.addEventListener('beforeunload', () => { if (timer) document.title = 'Trackie'; });
// Leaving the page (SPA navigation): stop the countdown so it can't keep
// ticking against the detached page, rewrite the tab title, or post a
// session later. Say so rather than silently dropping a running session.
Trackie.SpaNav?.onLeave?.(() => {
  if (!timer) return;
  pause();
  Trackie.Toast.info('Focus timer stopped because you left the Focus page.');
});
render();
loadFocusBreakdown();

async function loadFocusBreakdown() {
  const box = document.getElementById('focusBreakdown');
  if (!box) return;
  try {
    const res = await Trackie.API.post(API_BASE + '/focus.php', {action:'breakdown', days:30});
    if (!res.success || !res.breakdown.length) {
      box.innerHTML = '<div class="empty-state"><div class="empty-state-icon"><i class="fas fa-chart-pie"></i></div>' +
        '<div class="empty-state-title">No focus data yet</div><p>Complete a few sessions to see your time by category.</p></div>';
      return;
    }
    const max = Math.max.apply(null, res.breakdown.map(function(r){ return r.minutes; }));
    box.innerHTML = res.breakdown.map(function(r) {
      const pct = max > 0 ? Math.round((r.minutes / max) * 100) : 0;
      const label = r.minutes >= 60 ? Math.floor(r.minutes/60) + 'h ' + (r.minutes%60) + 'm' : r.minutes + 'm';
      return '<div style="margin-bottom:var(--sp-3)">' +
        '<div style="display:flex;justify-content:space-between;font-size:.8125rem;margin-bottom:var(--sp-1)">' +
          '<span>' + r.mode + '</span><span style="color:var(--muted)">' + label + ' \u00b7 ' + r.sessions + ' sessions</span>' +
        '</div>' +
        '<div class="progress-track"><div class="progress-fill" style="width:' + pct + '%"></div></div>' +
      '</div>';
    }).join('');
  } catch (e) {
    box.innerHTML = '<div style="font-size:.8125rem;color:var(--muted)">Network error.</div>';
  }
}
</script>
