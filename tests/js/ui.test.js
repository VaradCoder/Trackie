// Unit tests for assets/js/ui.js (Trackie.UI). Run: node tests/js/ui.test.js
// A tiny fake DOM is enough: ui.js only sets innerHTML, attributes and wires
// [data-ui-act] buttons.
const fs = require('fs'), vm = require('vm'), path = require('path');

function fakeEl() {
  const el = { attrs: {}, buttons: [], _html: '' };
  el.setAttribute = (k, v) => { el.attrs[k] = String(v); };
  el.removeAttribute = k => { delete el.attrs[k]; };
  el.getAttribute = k => (k in el.attrs ? el.attrs[k] : null);
  Object.defineProperty(el, 'innerHTML', {
    get: () => el._html,
    set: v => {
      el._html = v;
      el.buttons = [...v.matchAll(/data-ui-act="([^"]+)"/g)].map(m => {
        const b = { act: m[1], handlers: [], getAttribute: k => (k === 'data-ui-act' ? m[1] : null) };
        b.addEventListener = (t, fn) => b.handlers.push(fn);
        b.click = () => b.handlers.forEach(fn => fn({ preventDefault() {} }));
        return b;
      });
    },
  });
  el.querySelectorAll = sel => (sel === '[data-ui-act]' ? el.buttons : []);
  return el;
}

const toasts = [];
const ctx = {
  window: { Trackie: { Toast: { error: m => toasts.push(m) } } },
  document: { getElementById: id => (id === 'byId' ? byId : null) },
  escHtml: s => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;'),
  console, Promise, Object, Array, String, Math, setTimeout,
};
ctx.Trackie = ctx.window.Trackie;
const byId = fakeEl();
vm.createContext(ctx);
vm.runInContext(fs.readFileSync(path.join(__dirname, '../../assets/js/ui.js'), 'utf8'), ctx);
const UI = ctx.window.Trackie.UI;

let pass = 0, fail = 0;
const ok = (c, m, x) => { if (c) { pass++; console.log('PASS  ' + m); } else { fail++; console.log('FAIL  ' + m + (x !== undefined ? ' ' + JSON.stringify(x) : '')); } };
class ApiError extends Error { constructor(m, s) { super(m); this.name = 'ApiError'; this.status = s; } }

(async () => {
  // errorText: real reasons surface, internals never do
  ok(UI.errorText(new ApiError('Steam is down (HTTP 503).')) === 'Steam is down (HTTP 503).', 'ApiError message is shown as-is');
  ok(UI.errorText({ success: false, error: 'Title is required.' }) === 'Title is required.', '{success:false, error} message is shown');
  ok(UI.errorText(new TypeError('x is undefined'), 'Could not load.') === 'Could not load.', 'programming errors are NOT shown to users (fallback instead)');
  ok(UI.errorText(null) === 'Something went wrong. Please try again.', 'null → generic message');

  // loading
  const a = fakeEl();
  UI.loading(a, 'Loading your games…');
  ok(a.getAttribute('aria-busy') === 'true', 'loading sets aria-busy on the container');
  ok(/role="status"/.test(a.innerHTML) && /Loading your games…/.test(a.innerHTML), 'loading has role=status and the visible label');
  ok((a.innerHTML.match(/skeleton-text/g) || []).length === 3, 'default 3 skeleton lines');
  ok(/aria-hidden="true"/.test(a.innerHTML), 'skeleton lines are hidden from screen readers');
  ok(/role="status"[^>]*>.*fa-spinner/.test(UI.loadingHTML('Searching…', { inline: true })), 'inline variant = small spinner line');

  // empty: same markup as PHP renderEmptyState + next step
  let clicked = 0;
  UI.empty(a, { icon: 'fa-gamepad', title: 'No games <yet>', text: 'Add one', action: { label: 'Add game', icon: 'fa-plus', onClick: () => clicked++ } });
  ok(a.getAttribute('aria-busy') === null, 'empty clears aria-busy');
  ok(/class="empty-state"/.test(a.innerHTML) && /empty-state-title">No games &lt;yet&gt;/.test(a.innerHTML), 'empty uses .empty-state and escapes text');
  a.buttons[0].click();
  ok(clicked === 1, 'empty-state action button calls its handler');
  ok(/href="\/x\?a=1&amp;b=2" data-no-spa/.test(UI.emptyHTML({ title: 't', action: { label: 'Go', href: '/x?a=1&b=2', noSpa: true } })), 'link actions are escaped and can opt out of SPA nav');
  ok(/empty-state-compact/.test(UI.emptyHTML({ title: 't', compact: true })), 'compact variant for inside cards');
  ok(/^<div class="card"><div class="empty-state">/.test(UI.emptyHTML({ title: 't', card: true })), 'card option wraps the empty state in a .card');

  // error + retry
  let retries = 0;
  UI.error(a, new ApiError('Open Library did not answer (HTTP 502).'), { retry: () => retries++ });
  ok(/role="alert"/.test(a.innerHTML) && /Open Library did not answer/.test(a.innerHTML), 'error has role=alert and the real reason');
  a.buttons.find(b => b.act === 'retry').click();
  ok(retries === 1, '"Try again" calls retry');
  ok(!/data-ui-act="retry"/.test(UI.errorHTML('x')), 'no retry button when no retry given');

  // load(): success, {success:false}, throw → retry works
  const b = fakeEl();
  let painted = null;
  const r = await UI.load(b, async () => ({ success: true, items: [1, 2] }), d => { painted = d.items.length; }, { label: 'Loading…' });
  ok(painted === 2 && r.items.length === 2 && b.getAttribute('aria-busy') === null, 'load: paints the result and clears aria-busy');
  let calls = 0;
  await UI.load(b, async () => { calls++; return calls === 1 ? { success: false, error: 'Not connected.' } : { success: true }; }, () => { painted = 'second'; });
  ok(/Not connected\./.test(b.innerHTML) && /role="alert"/.test(b.innerHTML), 'load: {success:false} shows the error');
  b.buttons.find(x => x.act === 'retry').click();
  await new Promise(res => setTimeout(res, 0));
  ok(calls === 2 && painted === 'second', 'load: Try again re-runs the fetch and paints');
  await UI.load(b, async () => { throw new ApiError('Offline.'); }, () => {});
  ok(/Offline\./.test(b.innerHTML), 'load: thrown ApiError shows its message');

  // element by id + missing element is harmless
  UI.loading('byId', 'x');
  ok(byId.getAttribute('aria-busy') === 'true', 'accepts an element id');
  ok(UI.empty('missing', { title: 'x' }) === null, 'missing element → null, no throw');

  // toast
  UI.toastError(new ApiError('Session expired.'), 'Failed.');
  UI.toastError(new Error('boom'), 'Could not save.');
  ok(toasts[0] === 'Session expired.' && toasts[1] === 'Could not save.', 'toastError shows the real reason, fallback for bugs');

  console.log(`== ${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})();
