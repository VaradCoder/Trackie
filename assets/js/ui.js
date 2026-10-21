/**
 * Trackie.UI — shared async-state patterns: loading, empty, error.
 *
 * One markup + behaviour for "this part of the page is loading / has nothing
 * yet / failed", so every module reads the same way and gets the same
 * accessibility for free:
 *   loading → role="status", aria-busy on the container, skeleton lines
 *   empty   → the same .empty-state markup PHP renderEmptyState() prints,
 *             with an optional next-step button or link
 *   error   → role="alert", the REAL reason (ApiError messages are written
 *             to be shown) and an optional "Try again"
 *
 * Usage
 *   Trackie.UI.loading(el, 'Loading your games…');
 *   Trackie.UI.empty(el, { icon: 'fa-gamepad', title: 'No games yet', text: '…',
 *                          action: { label: 'Add game', icon: 'fa-plus', onClick: openAddGame } });
 *   Trackie.UI.error(el, err, { retry: load });
 *   await Trackie.UI.load(el, () => api(), data => render(data), { label: 'Loading…' });
 *   Trackie.UI.toastError(err, 'Could not save.');
 *
 * Needs escHtml() (app.js). Loaded right after app.js.
 */
(function () {
  'use strict';
  const esc = s => (typeof escHtml === 'function' ? escHtml(s) : String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])));
  const GENERIC = 'Something went wrong. Please try again.';

  /**
   * The message to show for a failure. ApiError (thrown by Trackie.API) and
   * {success:false, error} responses carry user-safe text; anything else
   * (TypeError from a render bug, etc.) gets the fallback, never a stack.
   */
  function errorText(err, fallback = GENERIC) {
    if (typeof err === 'string' && err.trim()) return err.trim();
    if (err && typeof err === 'object') {
      if (err.name === 'ApiError' && err.message) return err.message;
      if (typeof err.error === 'string' && err.error.trim()) return err.error.trim();
    }
    return fallback;
  }

  /**
   * Is trying the same request again likely to help? Only for transient
   * failures: no connection / timeout (status 0), 408, 429, 5xx, or a
   * {success:false} result whose reason is known to be temporary. Never for
   * 401 (session — app.js already redirects to sign-in), 403, 404 or 422.
   */
  const TRANSIENT_REASONS = ['rate_limited', 'network', 'timeout', 'unavailable'];
  function isRetryable(err) {
    if (!err || typeof err !== 'object') return false;
    const st = err.name === 'ApiError' ? err.status : err.httpStatus;
    if (typeof st === 'number' && (st === 0 || st === 408 || st === 429 || st >= 500)) return true;
    return TRANSIENT_REASONS.includes(err.reason);
  }
  const isAuthError = err => !!err && typeof err === 'object' && (err.name === 'ApiError' ? err.status : err.httpStatus) === 401;

  function loadingHTML(label = 'Loading…', { rows = 3, inline = false } = {}) {
    if (inline) {
      return `<p class="ui-loading ui-loading-inline" role="status"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> ${esc(label)}</p>`;
    }
    const widths = ['w-3/4', 'w-1/2', 'w-3/4', 'w-1/4'];
    const lines = Array.from({ length: Math.max(1, Math.min(6, rows)) }, (_, i) =>
      `<div class="skeleton skeleton-text ${widths[i % widths.length]}"></div>`).join('');
    return `<div class="ui-loading" role="status"><div class="ui-loading-lines" aria-hidden="true">${lines}</div>` +
           `<p class="ui-loading-label">${esc(label)}</p></div>`;
  }

  /** Next-step button/link. action: {label, icon?, href?, onClick?, primary?} */
  function actionHTML(action, idx) {
    if (!action || !action.label) return '';
    const icon = action.icon ? `<i class="fas ${esc(action.icon)}" aria-hidden="true"></i> ` : '';
    const cls = `btn ${action.primary === false ? 'btn-secondary' : 'btn-primary'} btn-sm`;
    if (action.href) {
      return `<a class="${cls}" href="${esc(action.href)}"${action.noSpa ? ' data-no-spa' : ''}>${icon}${esc(action.label)}</a>`;
    }
    return `<button type="button" class="${cls}" data-ui-act="${idx}">${icon}${esc(action.label)}</button>`;
  }

  /** Same markup as PHP renderEmptyState(). compact = inside a card/list; card = wrap in a .card. */
  function emptyHTML({ icon = 'fa-inbox', title = '', text = '', action = null, compact = false, card = false } = {}) {
    if (card) return `<div class="card">${emptyHTML({ icon, title, text, action, compact })}</div>`;
    return `<div class="empty-state${compact ? ' empty-state-compact' : ''}">` +
      `<div class="empty-state-icon" aria-hidden="true"><i class="fas ${esc(icon)}"></i></div>` +
      (title ? `<div class="empty-state-title">${esc(title)}</div>` : '') +
      (text ? `<p>${esc(text)}</p>` : '') +
      (action ? `<div class="empty-state-action">${actionHTML(action, 0)}</div>` : '') +
      `</div>`;
  }

  function errorHTML(err, { retry = null, action = null, fallback } = {}) {
    const msg = errorText(err, fallback);
    if (retry && !isRetryable(err)) retry = null;   // don't offer what can't help
    const btns = [
      retry ? `<button type="button" class="btn btn-secondary btn-sm" data-ui-act="retry"><i class="fas fa-rotate-right" aria-hidden="true"></i> Try again</button>` : '',
      action ? actionHTML(Object.assign({ primary: false }, action), 0) : '',
    ].join('');
    return `<div class="ui-error" role="alert"><i class="fas fa-triangle-exclamation ui-error-icon" aria-hidden="true"></i>` +
      `<div class="ui-error-body"><p>${esc(msg)}</p>${btns ? `<div class="ui-error-actions">${btns}</div>` : ''}</div></div>`;
  }

  function resolve(el) { return typeof el === 'string' ? document.getElementById(el) : el; }

  /** Put html into el and wire its data-ui-act buttons. Returns el. */
  function render(el, html, handlers = {}) {
    el = resolve(el);
    if (!el) return null;
    el.innerHTML = html;
    el.querySelectorAll('[data-ui-act]').forEach(b => {
      const fn = handlers[b.getAttribute('data-ui-act')];
      if (typeof fn === 'function') b.addEventListener('click', ev => { ev.preventDefault(); fn(ev); });
    });
    return el;
  }

  function loading(el, label, opts) {
    el = render(el, loadingHTML(label, opts));
    if (el) el.setAttribute('aria-busy', 'true');
    return el;
  }
  function done(el) { el = resolve(el); if (el) el.removeAttribute('aria-busy'); return el; }
  function empty(el, opts = {}) {
    el = render(el, emptyHTML(opts), { 0: opts.action && opts.action.onClick });
    return done(el);
  }
  function error(el, err, opts = {}) {
    el = render(el, errorHTML(err, opts), { retry: opts.retry, 0: opts.action && opts.action.onClick });
    return done(el);
  }

  /**
   * show loading → await fetcher() → paint(result). On a thrown error or a
   * {success:false} result, shows the real reason with "Try again".
   * Returns the result (or undefined on failure).
   */
  async function load(el, fetcher, paint, { label, rows, fallback } = {}) {
    el = resolve(el);
    if (!el) return undefined;
    const run = () => load(el, fetcher, paint, { label, rows, fallback });
    loading(el, label, { rows });
    try {
      const res = await fetcher();
      if (res && typeof res === 'object' && res.success === false) { error(el, res, { retry: run, fallback }); return undefined; }
      done(el);
      paint(res, el);
      return res;
    } catch (err) {
      error(el, err, { retry: run, fallback });
      return undefined;
    }
  }

  function toastError(err, fallback) {
    const msg = errorText(err, fallback);
    if (isAuthError(err)) return msg;   // app.js already toasted + is redirecting to sign-in
    if (window.Trackie && Trackie.Toast) Trackie.Toast.error(msg);
    return msg;
  }

  /**
   * Year-style heatmap: one column per week, one row per weekday, newest on
   * the right. Dates are plain 'YYYY-MM-DD' strings walked at local noon, so
   * neither the browser's zone nor DST can shift a day into the wrong cell
   * (pass the server's `today`, never new Date()).
   *
   *   heatmap(el, { today, days, state: d => 'done'|'skip'|'fail'|'off'|'empty'|'before',
   *                 color, weekStart: 0|1, label, legend: true })
   */
  const HM_TITLE = { done: 'done', skip: 'skipped', fail: 'missed (marked)', off: 'not scheduled', empty: 'not done', before: 'before this started' };
  function heatmap(el, opts) {
    el = typeof el === 'string' ? document.getElementById(el) : el;
    if (!el) return null;
    const { today, days = 365, state, color = 'var(--ok)', weekStart = 0, label = 'History', legend = true } = opts;
    const at = s => new Date(s + 'T12:00:00');
    const iso = d => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    const end = at(today);
    const start = new Date(end); start.setDate(start.getDate() - (days - 1));
    const lead = (start.getDay() - weekStart + 7) % 7;          // blank cells before the first day
    const month = new Intl.DateTimeFormat(undefined, { month: 'short' });
    const long = new Intl.DateTimeFormat(undefined, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });

    let cells = '', months = '', lastMonth = -1, counts = {};
    for (let i = 0; i < lead; i++) cells += '<span class="hm-cell is-pad" aria-hidden="true"></span>';
    for (let i = 0, d = new Date(start); i < days; i++, d.setDate(d.getDate() + 1)) {
      const col = Math.floor((i + lead) / 7);
      const s = state(iso(d)) || 'empty';
      counts[s] = (counts[s] || 0) + 1;
      cells += `<span class="hm-cell is-${s}" style="--c:${col}" title="${escHtml(long.format(d))} — ${HM_TITLE[s] || s}"></span>`;
      if (d.getMonth() !== lastMonth && d.getDate() <= 7) {
        months += `<span style="grid-column:${col + 1}">${escHtml(month.format(d))}</span>`;
        lastMonth = d.getMonth();
      }
    }
    const cols = Math.ceil((days + lead) / 7);
    const owed = (counts.done || 0) + (counts.empty || 0) + (counts.fail || 0);
    const summary = `${counts.done || 0} of ${owed} scheduled days done`;
    const key = (cls, txt) => `<span><i class="hm-cell is-${cls}" aria-hidden="true"></i> ${txt}</span>`;
    el.innerHTML =
      `<div class="hm" style="--hm-c:${escHtml(color)};--hm-cols:${cols}">` +
        `<div class="hm-scroll"><div class="hm-months" aria-hidden="true">${months}</div>` +
        `<div class="hm-grid" role="img" aria-label="${escHtml(label)}: ${summary}">${cells}</div></div>` +
        `<div class="hm-foot"><span class="hm-summary">${summary}</span>` +
        (legend ? `<span class="hm-legend">${key('done', 'Done')}${key('empty', 'Not done')}${counts.skip ? key('skip', 'Skipped') : ''}${counts.fail ? key('fail', 'Missed') : ''}${counts.off ? key('off', 'Not scheduled') : ''}</span>` : '') +
        `</div></div>`;
    const sc = el.querySelector('.hm-scroll');
    if (sc) sc.scrollLeft = sc.scrollWidth;                     // show the most recent weeks first
    return counts;
  }

  const UI = { errorText, isRetryable, loadingHTML, emptyHTML, errorHTML, render, loading, done, empty, error, load, toastError, heatmap };
  if (window.Trackie) window.Trackie.UI = UI;
  window.TrackieUI = UI;

  /* ── Delight: small physical feedback for real accomplishments ──
     tap()   a short haptic tick when something is completed (Android / PWA)
     burst() confetti from an element, for finishing the day or a milestone
     Both do nothing for prefers-reduced-motion. The confetti library (10 KB)
     is loaded the first time it's needed, from assets/vendor/. */
  const reduceMotion = () => !!window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
  let confettiLoad = null;
  function loadConfetti() {
    if (window.confetti) return Promise.resolve(window.confetti);
    return confettiLoad ||= new Promise(resolve => {
      const base = document.querySelector('meta[name="app-base"]')?.content || '';
      const s = document.createElement('script');
      s.src = `${base}/assets/vendor/confetti.min.js`;
      s.onload = () => resolve(window.confetti || null);
      s.onerror = () => { confettiLoad = null; resolve(null); };
      document.head.append(s);
    });
  }
  const Delight = {
    tap() {
      if (reduceMotion()) return;
      try { navigator.vibrate?.(12); } catch { /* not allowed here — fine */ }
    },
    async burst(el) {
      if (reduceMotion() || document.hidden) return;
      const confetti = await loadConfetti();
      if (!confetti) return;
      const r = el?.getBoundingClientRect?.();
      const origin = r ? { x: (r.left + r.width / 2) / innerWidth, y: (r.top + r.height / 2) / innerHeight } : { x: .5, y: .35 };
      const accent = getComputedStyle(document.documentElement).getPropertyValue('--accent').trim() || '#ef4444';
      const opts = { origin, colors: [accent, '#22c55e', '#f59e0b', '#3b82f6', '#a855f7'], disableForReducedMotion: true, zIndex: 9999 };
      confetti({ ...opts, particleCount: 70, spread: 70, startVelocity: 38, scalar: .9 });
      setTimeout(() => confetti({ ...opts, particleCount: 40, spread: 110, startVelocity: 28, scalar: .75, ticks: 160 }), 140);
    },
  };
  // One delegated listener gives every completion control the same tick:
  // todo / study checkboxes, routine checks and the habit "Done" button.
  document.addEventListener('change', e => {
    if (e.target.matches?.('input.todo-check, input.task-check') && e.target.checked) Delight.tap();
  });
  document.addEventListener('click', e => {
    // Bubbles after the control's own onclick. The routine check flips to
    // .checked synchronously; the habit button turns .is-active only after its
    // request returns, so "not yet active" means it is being logged now.
    const r = e.target.closest?.('.routine-check');
    if (r && r.classList.contains('checked')) Delight.tap();
    const h = e.target.closest?.('.habit-status-done');
    if (h && !h.classList.contains('is-active')) Delight.tap();
  });
  if (window.Trackie) window.Trackie.Delight = Delight;
  window.TrackieDelight = Delight;

  /**
   * Move rows with a View Transition: whatever apply() does to the DOM, the
   * rows glide to their new places and neighbours close the gap. Names exist
   * only for the transition. The update callback waits for a rendered frame,
   * which a throttled or background tab may not produce for a while, so it is
   * applied directly if late — a list must never wait on an animation.
   */
  function transition(apply, rows = []) {
    if (!document.startViewTransition || reduceMotion()) { apply(); return Promise.resolve(); }
    rows.forEach((r, i) => { r.style.viewTransitionName = 'tk-r' + i; r.style.viewTransitionClass = 'tk-row'; });
    let applied = false;
    const run = () => { if (!applied) { applied = true; apply(); } };
    const vt = document.startViewTransition(run);
    const late = setTimeout(() => { if (!applied) { vt.skipTransition?.(); run(); } }, 600);
    return Promise.race([vt.finished.catch(() => {}), new Promise(r => setTimeout(r, 1200))]).then(() => {
      run(); clearTimeout(late);
      rows.forEach(r => { r.style.viewTransitionName = ''; r.style.viewTransitionClass = ''; });
    });
  }
  UI.transition = transition;

  /**
   * Plain-language entry: "call mom tomorrow 6pm !high #family".
   *   !high / !h / !!!  → high      !low / !l → low
   *   #word             → tags      dates/times → chrono-node (lazy, 49 KB)
   * Without the parser (offline, blocked) the text is kept as the title.
   */
  const NL = (() => {
    let loading = null;
    const base = () => document.querySelector('meta[name="app-base"]')?.content || '';
    function load() {
      if (window.chrono) return Promise.resolve(window.chrono);
      return loading ||= new Promise(resolve => {
        const s = document.createElement('script');
        s.src = `${base()}/assets/vendor/chrono.min.js`;
        s.onload = () => resolve(window.chrono || null);
        s.onerror = () => { loading = null; resolve(null); };
        document.head.append(s);
      });
    }
    const PRI = /(?:^|\s)(!high|!h|!!!|!low|!l)(?=\s|$)/i;
    const TAG = /(?:^|\s)#([\p{L}\p{N}_-]{1,30})(?=\s|$)/gu;
    const ymd = d => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    function parse(text, now = new Date()) {
      let title = ` ${text} `, priority = 'medium';
      const p = title.match(PRI);
      if (p) { priority = /^!l/i.test(p[1]) ? 'low' : 'high'; title = title.replace(p[0], ' '); }
      const tags = [];
      title = title.replace(TAG, (m, t) => { tags.push(t.toLowerCase()); return ' '; });
      const r = window.chrono?.parse(title, now, { forwardDate: true })?.[0];
      if (r) title = title.replace(r.text, ' ');
      return {
        title: title.replace(/\s+/g, ' ').trim(), priority, tags,
        when: r ? r.start.date() : null, due: r ? ymd(r.start.date()) : null,
        hasTime: !!r?.start.isCertain('hour'),
      };
    }
    /** 'Today' · 'Tomorrow' · 'Yesterday' · 'Fri 24 Oct' relative to todayIso. */
    function describe(iso, todayIso) {
      const at = s => new Date(s + 'T12:00:00');
      const diff = Math.round((at(iso) - at(todayIso)) / 864e5);
      if (diff === 0) return 'Today';
      if (diff === 1) return 'Tomorrow';
      if (diff === -1) return 'Yesterday';
      return at(iso).toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' });
    }
    /** Preview chips for what parse() understood. `fallback` labels "no date typed". */
    function hint(el, text, todayIso, fallback = 'Today') {
      if (!el) return;
      const v = text.trim();
      if (!v) { el.replaceChildren(); return; }
      const r = parse(v);
      const tag = (icon, label, muted) => {
        const s = document.createElement('span'); s.className = 'td-tag' + (muted ? ' is-muted' : '');
        s.innerHTML = `<i class="fas ${icon}" aria-hidden="true"></i> `; s.append(label); return s;
      };
      const time = r.hasTime ? ' · ' + r.when.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }) : '';
      el.replaceChildren(
        tag('fa-calendar-day', r.due ? describe(r.due, todayIso) + time : fallback, !r.due),
        ...(r.priority !== 'medium' ? [tag('fa-flag', r.priority === 'high' ? 'High priority' : 'Low priority')] : []),
        ...r.tags.map(t => tag('fa-hashtag', t)),
        ...(r.title ? [] : [tag('fa-pen', 'Add a few words', true)]),
      );
    }
    return { load, parse, describe, hint, ymd };
  })();
  if (window.Trackie) window.Trackie.NL = NL;
  window.TrackieNL = NL;
})();
