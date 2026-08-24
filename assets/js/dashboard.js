/**
 * Trackie — Dashboard JS
 * Loaded only on pages/dashboard.php.
 * Depends on app.js (Trackie global) being loaded first.
 */

/* ── Skeleton reveal helper ─────────────────────────────────────── */
const DashSkel = {
  show(id) {
    document.getElementById(`${id}-skeleton`)?.classList.remove('hidden');
    document.getElementById(`${id}-content`)?.classList.add('hidden');
  },
  hide(id) {
    document.getElementById(`${id}-skeleton`)?.classList.add('hidden');
    document.getElementById(`${id}-content`)?.classList.remove('hidden');
  },
};

/* ── Habit completion chart ──────────────────────────────────────── */
function initDashboardChart(labels, data, habitColors) {
  const canvas = document.getElementById('dashHabitChart');
  if (!canvas) return;

  // Reveal chart, hide skeleton
  DashSkel.hide('habit-chart');

  // A chart with every bar at 0 renders as a blank box (no visual signal
  // that anything happened) — swap in the same empty-state pattern used
  // elsewhere on the dashboard instead of silently drawing nothing.
  if (data.every(v => v === 0)) {
    const content = document.getElementById('habit-chart-content');
    if (content) {
      content.innerHTML = `
        <div class="empty-dash">
          <div class="empty-dash-icon" aria-hidden="true"><i class="fas fa-chart-column"></i></div>
          <p>No habit activity logged this week yet.</p>
        </div>`;
    }
    return;
  }

  // Read the live theme tokens instead of hardcoding old slate greys, so the
  // chart's axis colors follow whichever palette (light/dark) is active.
  const rootStyle = getComputedStyle(document.documentElement);
  const textColor = rootStyle.getPropertyValue('--muted').trim() || '#8a7a63';
  const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
  const gridColor = isDark ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.06)';

  new Chart(canvas, {
    type: 'bar',
    data: {
      labels,
      datasets: [{
        label: 'Habits completed',
        data,
        backgroundColor: data.map((_, i) =>
          habitColors[i % habitColors.length] || '#ef4444'
        ),
        borderRadius: 4,
        borderSkipped: false,
        barThickness: 'flex',
        maxBarThickness: 32,
      }],
    },
    options: {
      responsive:          true,
      maintainAspectRatio: false,
      animation:           { duration: 600 },
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: ctx => ` ${ctx.parsed.y} habit${ctx.parsed.y !== 1 ? 's' : ''} logged`,
          },
        },
      },
      scales: {
        x: {
          grid:   { display: false },
          ticks:  { color: textColor, font: { size: 11 } },
        },
        y: {
          beginAtZero: true,
          ticks:       { stepSize: 1, color: textColor, font: { size: 11 } },
          grid:        { color: gridColor },
        },
      },
    },
  });
}

/* ── XP feedback toast (Phase 2) ─────────────────────────────────── */
function showXpToast(xp) {
  if (!xp || !xp.ok) return;
  if (xp.leveledUp) {
    Trackie.Toast.success(`⚡ Level up! You're now Level ${xp.level} — ${xp.title}`, 5000);
  } else {
    Trackie.Toast.info(`+${xp.gained} XP`, 2500);
  }
}
window.showXpToast = showXpToast;

/* ── Todo toggle (dashboard) ─────────────────────────────────────── */
async function dashToggleTask(id, checked, el) {
  el.disabled = true;
  const row = document.getElementById(`dash-task-${id}`);

  // Optimistic UI
  row?.classList.toggle('done', checked);

  try {
    const res = await Trackie.API.post(
      `${window.TRACKIE_API}/todos.php`,
      { action: 'toggle', todo_id: id, completed: checked ? 1 : 0 }
    );
    if (!res.success) {
      // Revert
      row?.classList.toggle('done', !checked);
      el.checked = !checked;
      Trackie.Toast.error(res.error || 'Update failed.');
    } else {
      updateTaskSummary(checked);
      if (checked) showXpToast(res.xp);
    }
  } catch {
    row?.classList.toggle('done', !checked);
    el.checked = !checked;
    Trackie.Toast.error('Network error.');
  }
  el.disabled = false;
}

/** Increment/decrement the summary counters without a page reload */
function updateTaskSummary(completed) {
  const doneEl    = document.querySelector('.tasks-summary-cell:nth-child(2) .tasks-summary-num');
  const pendingEl = document.querySelector('.tasks-summary-cell:nth-child(3) .tasks-summary-num');
  const bar       = document.querySelector('.tasks-completion-bar .progress-fill');
  const pctEl     = document.querySelector('.tasks-completion-pct');
  const totalEl   = document.querySelector('.tasks-summary-cell:nth-child(1) .tasks-summary-num');

  if (!doneEl || !pendingEl) return;

  let done    = parseInt(doneEl.textContent)    || 0;
  let pending = parseInt(pendingEl.textContent) || 0;
  let total   = parseInt(totalEl?.textContent)  || (done + pending);

  if (completed) { done++;    pending--; }
  else           { done--;    pending++; }

  done    = Math.max(0, done);
  pending = Math.max(0, pending);
  const pct = total > 0 ? Math.round(done / total * 100) : 0;

  doneEl.textContent    = done;
  pendingEl.textContent = pending;
  if (pctEl) pctEl.textContent = `${pct}%`;
  if (bar)   bar.style.width   = `${pct}%`;
}

/* ── Habit log (dashboard) ───────────────────────────────────────── */
async function dashHabitLog(id, btn) {
  btn.disabled = true;
  const today = new Date().toISOString().slice(0, 10);

  try {
    const res = await Trackie.API.post(
      `${window.TRACKIE_API}/habits.php`,
      { action: 'log', habit_id: id, date: today }
    );
    if (res.success) {
      // Replace the habit item with a "done" state
      const row = document.getElementById(`hab-dash-${id}`);
      if (row) {
        const name  = row.querySelector('.hab-rec-name')?.textContent || '';
        const count = row.querySelector('.hab-rec-count')?.textContent || '';
        row.innerHTML = `
          <div class="hab-rec-icon" aria-hidden="true" style="background:rgba(34,197,94,.15);color:#16a34a">✅</div>
          <div>
            <div class="hab-rec-name">${name}</div>
            <div class="hab-rec-count">${count}</div>
          </div>
          <span class="hab-rec-done-badge" aria-label="Logged today">Done ✓</span>`;
      }
      Trackie.Toast.success('Habit logged!');
      showXpToast(res.xp);
    } else {
      Trackie.Toast.error(res.error || 'Log failed.');
    }
  } catch {
    Trackie.Toast.error('Network error.');
  }
  btn.disabled = false;
}

/* ── Spotify polling ─────────────────────────────────────────────── */
let _spotifyTimer = null;

function startSpotifyPolling(intervalMs = 30000) {
  // Clear any prior interval first — SPA re-entry calls this again without a
  // real page unload, so without this each visit would stack another poller.
  if (_spotifyTimer) { clearInterval(_spotifyTimer); _spotifyTimer = null; }
  async function poll() {
    try {
      const res = await fetch(`${window.TRACKIE_API}/spotify.php`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
      if (!res.ok) return;
      const data = await res.json();
      renderSpotifyState(data);
    } catch {
      // Fail silently — Spotify is a non-critical widget
    }
  }

  poll();   // immediate first poll
  _spotifyTimer = setInterval(poll, intervalMs);
}

function renderSpotifyState(data) {
  const container = document.getElementById('spotify-now-playing');
  if (!container) return;   // card is in not_connected or not_configured state

  if (!data.connected) {
    // Token expired — show re-connect (soft reload of card section only)
    return;
  }

  if (!data.playing || !data.track) {
    container.innerHTML = `
      <div class="empty-dash" role="status">
        <div class="empty-dash-icon" aria-hidden="true">
          <i class="fab fa-spotify" style="color:#1db954"></i>
        </div>
        <p id="spotify-idle-msg">Nothing playing right now.</p>
      </div>`;
    return;
  }

  const t = data.track;
  const artHtml = t.art
    ? `<img src="${t.art}" alt="Album art for ${escHtml(t.album)}">`
    : '';

  container.innerHTML = `
    <div class="spotify-now-playing">
      <div class="spotify-art" aria-hidden="true">${artHtml}</div>
      <div style="flex:1;min-width:0">
        <div class="spotify-track-name">${escHtml(t.name)}</div>
        <div class="spotify-track-artist">${escHtml(t.artist)}</div>
      </div>
      <div class="spotify-playing-dot" aria-label="Currently playing" title="Playing"></div>
    </div>`;
}

function escHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

/* ── Theme-aware chart update ────────────────────────────────────── */
const _origThemeToggle = Trackie.Theme.toggle;
Trackie.Theme.toggle = function () {
  _origThemeToggle.call(Trackie.Theme);
  // Chart.js doesn't auto-update on theme change — full reload is simplest
  // (chart redraws are expensive; skip for now since chart is not live-updating)
};

/* ── Live clock (welcome card) ───────────────────────────────────── */
// Handled inline in dash_welcome.php

/* ── Mount (idempotent; safe to call on every SPA entry) ──────────
   Exposed globally and driven by an inline script in dashboard.php so it
   re-runs on each SPA visit (inline scripts always re-execute; this
   external file is deduped after first load). */
window.mountDashboard = function mountDashboard() {
  const colors = (window.DASH_HABIT_COLORS?.length)
    ? window.DASH_HABIT_COLORS
    : Array(7).fill('#ef4444');

  if (typeof Chart !== 'undefined' && window.DASH_CHART_DATA) {
    initDashboardChart(window.DASH_CHART_LABELS, window.DASH_CHART_DATA, colors);
  }
  if (document.getElementById('spotify-now-playing')) {
    startSpotifyPolling(30000);
  }
};

// Stop polling when page navigates away
window.addEventListener('pagehide', () => {
  if (_spotifyTimer) clearInterval(_spotifyTimer);
});
