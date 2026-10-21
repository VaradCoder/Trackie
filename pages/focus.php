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
      WHERE f.user_id=? AND f.completed=1 ORDER BY f.id DESC LIMIT 8", [$uid]
);
// Tasks you can focus on: open top-level todos, due soonest first.
$openTodos = fetchAll(
    "SELECT id, title, due_date FROM todos
      WHERE user_id=? AND completed=0 AND deleted_at IS NULL AND parent_id IS NULL
      ORDER BY (due_date IS NULL), due_date, id DESC LIMIT 40", [$uid]
);
$preselectTodo = (int)($_GET['todo'] ?? 0);   // e.g. a "Focus on this" link from Todos
$fmt = fn($m) => $m >= 60 ? floor($m / 60) . 'h ' . ($m % 60) . 'm' : $m . 'm';

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<?= renderPageHeader('Focus', [
  'icon' => 'fa-stopwatch',
  'sub'  => 'One thing at a time. Every 4th break is a long one.',
]) ?>

<div class="pg-strip">
  <span><strong id="st-today"><?= $fmt($stats['today']) ?></strong> today</span>
  <span><strong><?= $fmt($stats['week']) ?></strong> this week</span>
  <span><i class="fas fa-fire" style="color:#f59e0b" aria-hidden="true"></i> <strong><?= (int)$stats['streak'] ?></strong>-day focus streak</span>
</div>

<div class="fc-layout">
  <section class="card fc-card" aria-label="Focus timer">
    <div class="pg-chips fc-chips" id="modeTabs" role="radiogroup" aria-label="What kind of focus">
      <?php foreach (['Study' => 'fa-book-open', 'Work' => 'fa-briefcase', 'Coding' => 'fa-code', 'Reading' => 'fa-book'] as $m => $ic): ?>
        <button type="button" class="pg-chip" role="radio" data-mode="<?= $m ?>" aria-checked="<?= $m === 'Study' ? 'true' : 'false' ?>"><i class="fas <?= $ic ?>" aria-hidden="true"></i> <?= $m ?></button>
      <?php endforeach; ?>
      <button type="button" class="pg-chip" role="radio" data-mode="Custom" aria-checked="false"><i class="fas fa-pen" aria-hidden="true"></i> Other…</button>
    </div>
    <input type="text" id="customLabel" class="form-input fc-custom" placeholder="Call it something (e.g. Deep work)" maxlength="30" hidden aria-label="Focus label">

    <div class="fc-ring" id="timerRing" data-phase="focus" style="--p:100">
      <svg viewBox="0 0 120 120" aria-hidden="true">
        <circle class="fc-track" cx="60" cy="60" r="54" pathLength="100"/>
        <circle class="fc-fill" cx="60" cy="60" r="54" pathLength="100"/>
      </svg>
      <div class="fc-center">
        <div id="timerDisplay" class="fc-time" role="timer" aria-live="off">25:00</div>
        <div id="timerPhase" class="fc-phase">Focus session</div>
        <div class="fc-cycle" id="fcCycle" aria-label="Session 1 of 4"><i></i><i></i><i></i><i></i></div>
      </div>
    </div>

    <div class="fc-controls">
      <button type="button" class="btn btn-primary fc-main" id="btnStart"><i class="fas fa-play" aria-hidden="true"></i> Start</button>
      <button type="button" class="btn btn-primary fc-main hidden" id="btnPause"><i class="fas fa-pause" aria-hidden="true"></i> Pause</button>
      <button type="button" class="btn btn-ghost" id="btnReset" aria-label="Reset the timer"><i class="fas fa-rotate-left" aria-hidden="true"></i></button>
      <button type="button" class="btn btn-ghost hidden" id="btnSkip"><i class="fas fa-forward" aria-hidden="true"></i> Skip break</button>
    </div>
    <p class="fc-keys">Space to start or pause</p>

    <div class="fc-settings">
      <div class="fc-row">
        <span class="fc-label" id="lenLbl">Length</span>
        <div class="pg-chips" id="lenChips" role="radiogroup" aria-labelledby="lenLbl">
          <?php foreach ([15, 25, 45, 60, 90] as $m): ?>
            <button type="button" class="pg-chip" role="radio" data-min="<?= $m ?>" aria-checked="<?= $m === 25 ? 'true' : 'false' ?>"><?= $m ?>m</button>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="fc-row">
        <label for="focusTodo" class="fc-label">Working on</label>
        <select id="focusTodo" class="form-input">
          <option value="">Nothing specific</option>
          <?php foreach ($openTodos as $t): ?>
            <option value="<?= (int)$t['id'] ?>" <?= $preselectTodo === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['title']) ?><?= $t['due_date'] ? ' · ' . h(date('j M', strtotime($t['due_date']))) : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <p class="form-hint u-mt-2">A finished focus session earns <strong>+20 XP</strong>.</p>
  </section>

  <div class="fc-side">
    <section>
      <h2 class="td-section-head">Recent sessions</h2>
      <?php if (empty($history)): ?>
        <div class="card"><?= renderEmptyState('fa-stopwatch', 'No sessions yet', 'Pick a length and press Start. Your sessions collect here.', '', true) ?></div>
      <?php else: ?>
        <ul class="td-list" id="focusHistory">
          <?php foreach ($history as $h): ?>
            <li class="td-item">
              <span class="td-check td-check-static fc-hist-ic<?= $h['type'] === 'focus' ? '' : ' is-break' ?>" aria-hidden="true"><i class="fas <?= $h['type'] === 'focus' ? 'fa-bolt' : 'fa-mug-hot' ?>"></i></span>
              <div class="td-body">
                <div class="td-title"><?= $h['type'] === 'focus' ? ($h['task'] ? h($h['task']) : ($h['mode'] ? h($h['mode']) . ' focus' : 'Focus session')) : 'Break' ?></div>
                <div class="td-meta"><span><?= (int)$h['duration_min'] ?> min</span><span><?= h(timeAgo($h['started_at'])) ?></span></div>
              </div>
              <?php if ($h['type'] === 'focus'): ?><strong class="u-c-warn u-fs-13">+20 XP</strong><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
    <section>
      <h2 class="td-section-head">Last 30 days by category</h2>
      <div class="card card-body" id="focusBreakdown"></div>
    </section>
  </div>
</div>

</div>
<?php include '../includes/footer.php'; ?>

<script>
const API_BASE = '<?= APP_BASE ?>/api';

let mode = 'Study';
let phase = 'focus';            // 'focus' | 'break'
let remaining = 25 * 60;
let timer = null;
let endAt = null;               // ms timestamp while running — the clock, not the interval
let FOCUS_MIN = 25;
let cycle = 0;                  // focus sessions finished in this round of four
const SHORT_BREAK = 5, LONG_BREAK = 15, ROUND = 4;
const breakMin = () => (cycle > 0 && cycle % ROUND === 0 ? LONG_BREAK : SHORT_BREAK);
const phaseLen = () => (phase === 'focus' ? FOCUS_MIN : breakMin()) * 60;

/* The timer is kept as an end time in localStorage, so it survives the page
   being backgrounded (phones pause timers), SPA navigation, and the app being
   closed: reopening Focus picks it up, and a session that ended meanwhile is
   saved with its real end time. In the Android app an alarm also rings at
   the end even if Trackie isn't running. */
const FOCUS_KEY = 'trackie.focus.state';
const FOCUS_STALE_MS = 12 * 3600 * 1000;   // an unfinished session older than this is dropped, not logged
function saveState() {
  try {
    localStorage.setItem(FOCUS_KEY, JSON.stringify({ phase, mode, focusMin: FOCUS_MIN, endAt, left: remaining, cycle,
      todo: document.getElementById('focusTodo')?.value || '' }));
  } catch (e) {}
}
function loadState() { try { return JSON.parse(localStorage.getItem(FOCUS_KEY) || 'null'); } catch (e) { return null; } }
function alertAtEnd() {
  if (!endAt) return;
  window.TrackieNative?.timerAlert('focus', new Date(endAt),
    phase === 'focus' ? '✅ Focus session complete' : '☕ Break over',
    phase === 'focus' ? `${mode} · ${FOCUS_MIN} min done. Time for a break.` : 'Ready for the next focus session?',
    '<?= APP_BASE ?>/pages/focus.php');
}

const disp    = document.getElementById('timerDisplay');
const phaseEl = document.getElementById('timerPhase');
const ring    = document.getElementById('timerRing');
const bStart  = document.getElementById('btnStart');
const bPause  = document.getElementById('btnPause');
const bSkip   = document.getElementById('btnSkip');

function render() {
  const m = String(Math.floor(remaining / 60)).padStart(2, '0');
  const s = String(remaining % 60).padStart(2, '0');
  disp.textContent = `${m}:${s}`;
  ring.style.setProperty('--p', (remaining / phaseLen() * 100).toFixed(2));
  ring.dataset.phase = phase;
  bSkip.classList.toggle('hidden', phase !== 'break');
  // Session dots: filled for finished ones in this round, the current one pulses.
  const inRound = cycle % ROUND;
  document.querySelectorAll('#fcCycle i').forEach((d, i) => {
    d.className = i < inRound || (phase === 'break' && inRound === 0 && cycle > 0) ? 'is-done' : (i === inRound && phase === 'focus' ? 'is-now' : '');
  });
  document.getElementById('fcCycle').setAttribute('aria-label', `Session ${Math.min(inRound + 1, ROUND)} of ${ROUND}`);
  if (timer) document.title = `${m}:${s} · ${phase === 'focus' ? 'Focus' : 'Break'} — Trackie`;
}
function setPhase(p) {
  phase = p;
  remaining = phaseLen();
  phaseEl.textContent = p === 'focus' ? 'Focus session' : (breakMin() === LONG_BREAK ? 'Long break' : 'Break');
  render();
  saveState();
}
function tick() {
  if (!endAt) return;
  remaining = Math.max(0, Math.ceil((endAt - Date.now()) / 1000));
  if (remaining <= 0) { complete(endAt); return; }
  render();
}
function start(restoring) {
  if (timer) return;
  endAt = endAt || Date.now() + remaining * 1000;
  timer = setInterval(tick, 250);
  saveState();
  alertAtEnd();
  bStart.classList.add('hidden'); bPause.classList.remove('hidden');
  ring.classList.add('is-running');
  if (phase === 'focus' && restoring !== true) spotifyFocusPlay();
  // Keep the screen awake for the duration of an active session.
  Trackie.Platform?.keepAwake(true);
  render();
}
function pause() {
  if (endAt) remaining = Math.max(0, Math.ceil((endAt - Date.now()) / 1000));
  clearInterval(timer); timer = null; endAt = null;
  window.TrackieNative?.cancelTimerAlert('focus');
  saveState();
  bStart.classList.remove('hidden'); bPause.classList.add('hidden');
  bStart.innerHTML = remaining < phaseLen() ? '<i class="fas fa-play" aria-hidden="true"></i> Resume' : '<i class="fas fa-play" aria-hidden="true"></i> Start';
  ring.classList.remove('is-running');
  spotifyFocusPause();
  Trackie.Platform?.keepAwake(false);
  document.title = 'Focus — Trackie';
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
function reset() { pause(); setPhase(phase); bStart.innerHTML = '<i class="fas fa-play" aria-hidden="true"></i> Start'; }
async function complete(endedAtMs) {
  pause();
  const wasFocus = phase === 'focus';
  const endedAt = Math.floor((endedAtMs || Date.now()) / 1000);
  if (wasFocus) {
    cycle++;
    try {
      const sel = document.getElementById('focusTodo');
      const todoId = sel?.value || '';
      const todoTitle = todoId ? sel.options[sel.selectedIndex].text.replace(/ · [^·]+$/, '') : '';
      const res = await Trackie.API.post(`${API_BASE}/focus.php`, {
        action: 'complete', type: 'focus', duration: FOCUS_MIN, mode,
        todo_id: todoId, ended_at: endedAt,   // real end time survives an offline replay or a closed app
      });
      // API.post() queues mutating calls while offline and returns
      // {success:true, queued:true}. That is the ONLY case where it is true to
      // say the session will sync later — a genuinely queued write.
      if (res.queued) {
        Trackie.Toast.info('Saved offline — this session will sync when you reconnect.');
      } else if (res.success) {
        if (res.stats) document.getElementById('st-today').textContent =
          res.stats.today >= 60 ? `${Math.floor(res.stats.today / 60)}h ${res.stats.today % 60}m` : `${res.stats.today}m`;
        Trackie.Delight?.burst(ring);
        res.xp?.leveledUp
          ? Trackie.Toast.success(`⚡ Level up! Level ${res.xp.level}`, 5000)
          : Trackie.Toast.success(res.xp?.gained ? `Focus complete! +${res.xp.gained} XP 🎉` : 'Focus complete! 🎉');
        // Session was for a task → offer to tick it off right here.
        if (todoId) Trackie.Toast.action(`Finished "${todoTitle}"?`, 'Mark done', async () => {
          try {
            const r = await Trackie.API.post(`${API_BASE}/todos.php`, { action: 'toggle', todo_id: todoId, completed: 1 }, { button: null });
            if (!r.success) throw r;
            Trackie.Toast.success(r.xp?.gained ? `Task done · +${r.xp.gained} XP` : 'Task done.');
            sel.querySelector(`option[value="${CSS.escape(todoId)}"]`)?.remove();
            sel.value = '';
          } catch (e) { Trackie.UI.toastError(e, 'Could not complete that task.'); }
        }, 12000);
        try { new Audio('data:audio/wav;base64,UklGRl9vT19XQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQ==').play()?.catch(() => {}); } catch (e) {}
        loadFocusBreakdown();
      } else {
        // Server reached us and refused. Never report this as success — that
        // is how a broken `mode` column silently destroyed every session.
        Trackie.UI.toastError(res, "Couldn't save this focus session.");
      }
    } catch (e) {
      // Online and the request still failed: a real error, not an offline
      // queue. Say so plainly rather than inventing a sync that won't happen.
      Trackie.UI.toastError(e, "Couldn't save this focus session — please try again.");
    }
    setPhase('break');
    const long = breakMin() === LONG_BREAK;
    Trackie.Toast.info(long ? `${ROUND} sessions done — take a ${LONG_BREAK}-minute break.` : `Time for a ${SHORT_BREAK}-minute break.`);
    Trackie.Platform?.speak(long ? 'Great round. Take a longer break.' : 'Focus session complete. Time for a short break.');
  } else {
    setPhase('focus');
    Trackie.Toast.info('Break over — ready to focus?');
    Trackie.Platform?.speak('Break over. Ready to focus?');
  }
}

// ── Choices: category, length, custom label ─────────────────────────
function setChecked(groupSel, el) { document.querySelectorAll(`${groupSel} [role="radio"]`).forEach(b => b.setAttribute('aria-checked', String(b === el))); }
document.getElementById('modeTabs').addEventListener('click', e => {
  const b = e.target.closest('[data-mode]'); if (!b) return;
  setChecked('#modeTabs', b);
  const custom = b.dataset.mode === 'Custom';
  const lbl = document.getElementById('customLabel');
  lbl.hidden = !custom;
  if (custom) { lbl.focus(); mode = lbl.value.trim() || 'Custom'; } else mode = b.dataset.mode;
  saveState();
});
document.getElementById('customLabel').addEventListener('input', e => { mode = e.target.value.trim().slice(0, 30) || 'Custom'; saveState(); });
document.getElementById('lenChips').addEventListener('click', e => {
  const b = e.target.closest('[data-min]'); if (!b) return;
  if (timer) { Trackie.Toast.info('Pause or finish this session first.'); return; }
  setChecked('#lenChips', b);
  FOCUS_MIN = +b.dataset.min;
  if (phase === 'focus') setPhase('focus');
  bStart.innerHTML = '<i class="fas fa-play" aria-hidden="true"></i> Start';
});
bStart.addEventListener('click', () => start());
bPause.addEventListener('click', pause);
bSkip.addEventListener('click', () => { pause(); setPhase('focus'); bStart.innerHTML = '<i class="fas fa-play" aria-hidden="true"></i> Start'; });
document.getElementById('btnReset').addEventListener('click', reset);
// Space starts / pauses — unless you're typing somewhere.
document.addEventListener('keydown', e => {
  const t = e.target instanceof Element ? e.target : null;   // can be the document itself
  if (e.code !== 'Space' || e.repeat || t?.closest('input, textarea, select, button, a, [contenteditable]')) return;
  if (!document.getElementById('timerRing')) return;
  e.preventDefault();
  timer ? pause() : start();
});
// Back from the background: phones throttle timers there, so catch up now.
document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') tick(); });
// Leaving the page (SPA navigation): stop ticking against the detached page,
// but keep the session — its end time is saved, and coming back resumes it.
Trackie.SpaNav?.onLeave?.(() => {
  if (!timer) return;
  clearInterval(timer); timer = null;
  Trackie.Platform?.keepAwake(false);
  document.title = 'Trackie';
  Trackie.Toast.info(window.TrackieNative ? "Focus timer still running — you'll get a notification when it's done."
                                          : 'Focus timer still running — come back to Focus to finish it.');
});

// Restore a session from before (closed app, other page, reload).
(function restoreFocus() {
  const st = loadState();
  if (!st || !st.phase) { render(); return; }
  mode = st.mode || mode;
  FOCUS_MIN = Math.max(1, Math.min(180, parseInt(st.focusMin, 10) || 25));
  cycle = Math.max(0, parseInt(st.cycle, 10) || 0);
  phase = st.phase === 'break' ? 'break' : 'focus';
  phaseEl.textContent = phase === 'focus' ? 'Focus session' : (breakMin() === LONG_BREAK ? 'Long break' : 'Break');
  const tab = document.querySelector(`#modeTabs [data-mode="${CSS.escape(mode)}"]`);
  setChecked('#modeTabs', tab || document.querySelector('#modeTabs [data-mode="Custom"]'));
  if (!tab) { const l = document.getElementById('customLabel'); l.hidden = false; l.value = mode === 'Custom' ? '' : mode; }
  const len = document.querySelector(`#lenChips [data-min="${FOCUS_MIN}"]`);
  if (len) setChecked('#lenChips', len);
  const sel = document.getElementById('focusTodo');
  if (sel && st.todo && !sel.value && sel.querySelector(`option[value="${CSS.escape(st.todo)}"]`)) sel.value = st.todo;

  if (st.endAt && st.endAt > Date.now()) {              // still running
    endAt = st.endAt;
    remaining = Math.ceil((endAt - Date.now()) / 1000);
    render();
    start(true);
  } else if (st.endAt) {                                // ended while you were away
    if (phase === 'focus' && Date.now() - st.endAt < FOCUS_STALE_MS) { endAt = st.endAt; remaining = 0; render(); complete(st.endAt); }
    else setPhase('focus');
  } else {                                              // paused
    remaining = Math.max(1, Math.min(phaseLen(), parseInt(st.left, 10) || phaseLen()));
    render();
    if (remaining < phaseLen()) bStart.innerHTML = '<i class="fas fa-play" aria-hidden="true"></i> Resume';
  }
})();
loadFocusBreakdown();

async function loadFocusBreakdown() {
  const box = document.getElementById('focusBreakdown');
  if (!box) return;
  await Trackie.UI.load(box, () => Trackie.API.post(API_BASE + '/focus.php', { action: 'breakdown', days: 30 }), res => {
    if (!res.breakdown.length) {
      Trackie.UI.empty(box, { compact: true, icon: 'fa-chart-pie', title: 'No focus time yet', text: 'Finish a few sessions to see where your time goes.' });
      return;
    }
    const max = Math.max(...res.breakdown.map(r => r.minutes));
    // Mode is user text (custom labels): always escaped.
    box.innerHTML = res.breakdown.map(r => {
      const pct = max > 0 ? Math.round(r.minutes / max * 100) : 0;
      const label = r.minutes >= 60 ? `${Math.floor(r.minutes / 60)}h ${r.minutes % 60}m` : `${r.minutes}m`;
      return `<div class="fc-bar"><div class="fc-bar-top"><span>${escHtml(r.mode || 'Focus')}</span><span class="u-c-muted">${label} · ${+r.sessions} session${+r.sessions === 1 ? '' : 's'}</span></div>
        <div class="progress-track"><div class="progress-fill" style="width:${pct}%"></div></div></div>`;
    }).join('');
  }, { label: 'Loading…', rows: 3, fallback: 'Could not load the breakdown.' });
}
</script>
