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
    if (window.Trackie && Trackie.Toast) Trackie.Toast.error(msg);
    return msg;
  }

  const UI = { errorText, loadingHTML, emptyHTML, errorHTML, render, loading, done, empty, error, load, toastError };
  if (window.Trackie) window.Trackie.UI = UI;
  window.TrackieUI = UI;
})();
