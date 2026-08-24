/**
 * Trackie — Onboarding tour
 * A lightweight, dependency-free spotlight tour for new users.
 * Auto-starts once on the dashboard; replayable via Trackie.startTour().
 */
(function () {
  'use strict';

  const KEY = 'trackie_tour_done';

  const steps = [
    {
      center: true, emoji: '👋',
      title: 'Welcome to Trackie!',
      text: 'Your personal OS for tasks, habits, goals, study & finance. Let\'s take a quick 30-second tour — you can skip anytime.',
    },
    {
      el: '[data-toggle-sidebar]', emoji: '🧭',
      title: 'Your menu',
      text: 'Tap the menu button to open the sidebar and jump between Todos, Habits, Goals, Finance and more.',
    },
    {
      el: '#searchTrigger', emoji: '🔍',
      title: 'Search anything',
      text: 'Find any todo, habit, goal or study task instantly. Tip: press Ctrl + K from anywhere.',
    },
    {
      el: '#quickAddBtn', emoji: '⚡',
      title: 'Quick add',
      text: 'Add a todo from any page without losing your place. The keyboard shortcut is N.',
    },
    {
      el: '#notifBtn', emoji: '🔔',
      title: 'Reminders & nudges',
      text: 'Due tasks, habit nudges and reminders land here. Turn on browser notifications from the Reminders page.',
    },
    {
      el: '[data-toggle-theme]', emoji: '🌗',
      title: 'Light or dark',
      text: 'Switch the theme to match your vibe — it\'s remembered next time you visit.',
    },
    {
      el: '.dash-grid', emoji: '📊',
      title: 'Your dashboard',
      text: 'Everything at a glance: today\'s todos, habits, your featured goal, and weekly momentum.',
    },
    {
      center: true, emoji: '🎉', finale: true,
      title: 'You\'re all set!',
      text: 'That\'s the tour. Start by adding your first todo or habit. You can replay this anytime from the ? shortcuts menu.',
    },
  ];

  let idx = 0, dim, spot, tip;

  function el(sel) { return sel ? document.querySelector(sel) : null; }

  function cleanup() {
    [dim, spot, tip].forEach(n => n && n.remove());
    dim = spot = tip = null;
    document.removeEventListener('keydown', onKey);
    window.removeEventListener('resize', reposition);
  }

  function end(skipped) {
    try { localStorage.setItem(KEY, '1'); } catch (e) {}
    const finale = steps[idx] && steps[idx].finale && !skipped;
    cleanup();
    if (finale) confetti();
  }

  function onKey(e) {
    if (e.key === 'Escape') end(true);
    else if (e.key === 'Enter' || e.key === 'ArrowRight') next();
  }

  function next() { idx++; idx >= steps.length ? end(false) : render(); }

  function reposition() {
    if (!tip) return;
    const step = steps[idx];
    const pad = 8;
    const mobile = window.innerWidth <= 600;
    const target = (!step.center && el(step.el)) ? el(step.el) : null;

    // Reset tooltip position each call
    tip.style.top = tip.style.bottom = tip.style.left = tip.style.right = 'auto';
    tip.style.transform = 'none';

    // No target → dim the whole screen, center the tooltip (welcome / finish)
    if (!target) {
      spot.style.boxShadow = '0 0 0 9999px rgba(15,23,42,.62)';
      spot.style.width = spot.style.height = '0px';
      spot.style.top = '50%';
      spot.style.left = '50%';
      tip.style.top = '50%';
      tip.style.left = '50%';
      tip.style.transform = 'translate(-50%, -50%)';
      return;
    }

    // Spotlight the target (box-shadow ring comes from CSS)
    spot.style.boxShadow = '';
    const r = target.getBoundingClientRect();
    spot.style.top    = (r.top - pad) + 'px';
    spot.style.left   = (r.left - pad) + 'px';
    spot.style.width  = (r.width + pad * 2) + 'px';
    spot.style.height = (r.height + pad * 2) + 'px';

    // Mobile: pin the tooltip to the bottom-center, above the bottom nav,
    // so it (and its buttons) are always fully on-screen.
    if (mobile) {
      tip.style.left = '50%';
      tip.style.transform = 'translateX(-50%)';
      tip.style.bottom = '76px';
      return;
    }

    // Desktop: place below the target, flipping above if it would overflow.
    const tipRect = tip.getBoundingClientRect();
    const gap = 14;
    let top = r.bottom + gap;
    if (top + tipRect.height > window.innerHeight - 8) {
      top = Math.max(8, r.top - tipRect.height - gap);
    }
    let left = r.left + r.width / 2 - tipRect.width / 2;
    left = Math.max(8, Math.min(left, window.innerWidth - tipRect.width - 8));
    tip.style.top = top + 'px';
    tip.style.left = left + 'px';
  }

  function render() {
    const step = steps[idx];
    const target = el(step.el);

    // Bring target into view if off-screen
    if (target && !step.center) {
      const r = target.getBoundingClientRect();
      if (r.top < 60 || r.bottom > window.innerHeight) {
        target.scrollIntoView({ block: 'center', behavior: 'smooth' });
      }
    }

    const isLast = idx === steps.length - 1;
    const dots = steps.map((_, i) =>
      `<span class="tk-tour-dot ${i === idx ? 'active' : ''}"></span>`).join('');

    tip.innerHTML =
      `<div class="tk-tour-emoji">${step.emoji || ''}</div>` +
      `<div class="tk-tour-title">${step.title}</div>` +
      `<div class="tk-tour-text">${step.text}</div>` +
      `<div class="tk-tour-foot">` +
        `<div class="tk-tour-dots">${dots}</div>` +
        `<div class="tk-tour-btns">` +
          (isLast ? '' : `<button class="tk-tour-skip" data-tour-skip>Skip</button>`) +
          `<button class="btn btn-primary btn-sm" data-tour-next>${isLast ? 'Finish' : 'Next'}</button>` +
        `</div>` +
      `</div>`;

    tip.querySelector('[data-tour-next]').addEventListener('click', next);
    tip.querySelector('[data-tour-skip]')?.addEventListener('click', () => end(true));

    // Two frames so the tooltip has measured dimensions before positioning
    requestAnimationFrame(() => requestAnimationFrame(reposition));
  }

  function start() {
    cleanup();
    idx = 0;
    dim  = document.createElement('div'); dim.id  = 'tk-tour-dim';
    spot = document.createElement('div'); spot.id = 'tk-tour-spot';
    tip  = document.createElement('div'); tip.id  = 'tk-tour-tip';
    dim.addEventListener('click', () => end(true));
    document.body.append(dim, spot, tip);
    document.addEventListener('keydown', onKey);
    window.addEventListener('resize', reposition);
    render();
  }

  /* ── Confetti finale (vanilla canvas, no dependency) ──────────── */
  function confetti() {
    const cv = document.createElement('canvas');
    cv.id = 'tk-confetti';
    cv.width = window.innerWidth;
    cv.height = window.innerHeight;
    document.body.appendChild(cv);
    const ctx = cv.getContext('2d');
    const colors = ['#ef4444', '#f59e0b', '#22c55e', '#3b82f6', '#a855f7'];
    const pieces = Array.from({ length: 140 }, () => ({
      x: Math.random() * cv.width,
      y: -20 - Math.random() * cv.height * 0.3,
      r: 4 + Math.random() * 6,
      c: colors[(Math.random() * colors.length) | 0],
      vx: -2 + Math.random() * 4,
      vy: 2 + Math.random() * 4,
      rot: Math.random() * Math.PI,
      vr: -0.2 + Math.random() * 0.4,
    }));
    const t0 = performance.now();
    (function frame(now) {
      const elapsed = now - t0;
      ctx.clearRect(0, 0, cv.width, cv.height);
      pieces.forEach(p => {
        p.x += p.vx; p.y += p.vy; p.vy += 0.05; p.rot += p.vr;
        ctx.save();
        ctx.translate(p.x, p.y); ctx.rotate(p.rot);
        ctx.fillStyle = p.c;
        ctx.fillRect(-p.r / 2, -p.r / 2, p.r, p.r * 0.6);
        ctx.restore();
      });
      if (elapsed < 2600) requestAnimationFrame(frame);
      else cv.remove();
    })(t0);
  }

  document.addEventListener('DOMContentLoaded', () => {
    window.Trackie = window.Trackie || {};
    window.Trackie.startTour = start;

    // Auto-start once, only on the dashboard, only for new users.
    // TRACKIE_TOUR_AUTOSTART is set by dashboard.php from the user's
    // experience_level: someone who said they're experienced gets the tour
    // available but not forced. Undefined (any other page, or a user who
    // skipped the question) means auto-start, preserving the old behaviour.
    let done = false;
    try { done = localStorage.getItem(KEY) === '1'; } catch (e) {}
    const autoOk = window.TRACKIE_TOUR_AUTOSTART !== false;
    if (!done && autoOk && document.querySelector('.dash-grid')) {
      setTimeout(start, 700);
    }
  });
})();
