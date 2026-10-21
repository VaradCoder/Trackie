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
  el.querySelector = () => null;
  return el;
}

const toasts = [];
const docListeners = {};
let reduced = false;
const vibrations = [];
const ctx = {
  window: { Trackie: { Toast: { error: m => toasts.push(m) } }, matchMedia: () => ({ matches: reduced }) },
  document: {
    getElementById: id => (id === 'byId' ? byId : null),
    addEventListener: (t, fn) => (docListeners[t] ||= []).push(fn),
  },
  navigator: { vibrate: ms => vibrations.push(ms) },
  escHtml: s => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;'),
  console, Promise, Object, Array, String, Math, setTimeout, Date, Intl, Set,
};
ctx.Trackie = ctx.window.Trackie;
const byId = fakeEl();
vm.createContext(ctx);
vm.runInContext(fs.readFileSync(path.join(__dirname, '../../assets/js/ui.js'), 'utf8'), ctx);
const UI = ctx.window.Trackie.UI;
const Delight = ctx.window.Trackie.Delight;
// A control with classes, for the delegated completion listeners.
const ctl = (sel, classes, extra = {}) => {
  const node = { classList: { contains: c => classes.includes(c) }, matches: q => q.split(',').some(x => x.trim() === sel), ...extra };
  node.closest = q => (q.split(',').some(x => x.trim() === sel) ? node : null);
  return node;
};
const fire = (type, target) => (docListeners[type] || []).forEach(fn => fn({ target }));

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
  UI.error(a, new ApiError('Open Library did not answer (HTTP 502).', 502), { retry: () => retries++ });
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
  const http503 = o => Object.defineProperty(o, 'httpStatus', { value: 503, enumerable: false });
  await UI.load(b, async () => { calls++; return calls === 1 ? http503({ success: false, error: 'Steam is down.' }) : { success: true }; }, () => { painted = 'second'; });
  ok(/Steam is down\./.test(b.innerHTML) && /role="alert"/.test(b.innerHTML), 'load: {success:false} shows the error');
  b.buttons.find(x => x.act === 'retry').click();
  await new Promise(res => setTimeout(res, 0));
  ok(calls === 2 && painted === 'second', 'load: Try again re-runs the fetch and paints');
  await UI.load(b, async () => { throw new ApiError('Offline.', 0); }, () => {});
  ok(/Offline\./.test(b.innerHTML), 'load: thrown ApiError shows its message');

  // Retry policy: only transient failures get "Try again"
  const http = (st, o) => Object.defineProperty(o, 'httpStatus', { value: st, enumerable: false });
  const offersRetry = err => /data-ui-act="retry"/.test(UI.errorHTML(err, { retry: () => {} }));
  ok(offersRetry(new ApiError('No connection.', 0)), 'retry: offline/timeout (status 0)');
  ok(offersRetry(new ApiError('Busy.', 503)) && offersRetry(new ApiError('Slow down.', 429)) && offersRetry(new ApiError('Timeout.', 408)), 'retry: 5xx, 429, 408');
  ok(offersRetry(http(502, { success: false, error: 'Steam down.' })), 'retry: JSON {success:false} with HTTP 502');
  ok(offersRetry({ success: false, reason: 'rate_limited', error: 'Wait 30 s.' }), 'retry: known transient reason');
  ok(!offersRetry(new ApiError('Session expired.', 401)), 'NO retry: 401 (app.js redirects to sign-in)');
  ok(!offersRetry(http(403, { success: false, error: 'CSRF token mismatch' })) && !offersRetry(http(404, { success: false, error: 'Not found.' })), 'NO retry: 403, 404');
  ok(!offersRetry(http(422, { success: false, error: 'Title is required.' })), 'NO retry: 422 validation');
  ok(!offersRetry({ success: false, error: "Steam isn't connected yet." }), 'NO retry: plain {success:false} without a transient sign');
  ok(!offersRetry(new TypeError('x')), 'NO retry: programming errors');
  ok(Object.keys(http(500, { success: false, error: 'e' })).join() === 'success,error', 'httpStatus is non-enumerable (response shape unchanged)');

  // element by id + missing element is harmless
  UI.loading('byId', 'x');
  ok(byId.getAttribute('aria-busy') === 'true', 'accepts an element id');
  ok(UI.empty('missing', { title: 'x' }) === null, 'missing element → null, no throw');

  // toast
  // Delight: a haptic tick on real completions only, never with reduced motion
  vibrations.length = 0;
  Delight.tap();
  ok(vibrations.length === 1, 'Delight.tap vibrates once');
  reduced = true; Delight.tap(); reduced = false;
  ok(vibrations.length === 1, 'Delight.tap is silent with prefers-reduced-motion');
  vibrations.length = 0;
  fire('change', ctl('input.todo-check', [], { checked: true }));
  fire('change', ctl('input.todo-check', [], { checked: false }));
  ok(vibrations.length === 1, 'todo checkbox: tick on complete, not on un-complete');
  vibrations.length = 0;
  fire('click', ctl('.routine-check', ['checked']));
  fire('click', ctl('.routine-check', []));
  ok(vibrations.length === 1, 'routine check: tick when it turns on, not off');
  vibrations.length = 0;
  fire('click', ctl('.habit-status-done', []));
  fire('click', ctl('.habit-status-done', ['is-active']));
  ok(vibrations.length === 1, 'habit Done: tick when logging, not when clearing');
  reduced = true;
  await Delight.burst(null);
  reduced = false;
  ok(!('confetti' in ctx.window), 'burst with reduced motion never loads confetti');

  // heatmap: week-aligned, local dates, honest summary
  {
    const h = fakeEl();
    // 2026-10-21 is a Wednesday. 14 days back → starts Thu 2026-10-08.
    const done = new Set(['2026-10-08', '2026-10-21']);
    const counts = UI.heatmap(h, { today: '2026-10-21', days: 14, weekStart: 0, color: '#123456',
      state: d => done.has(d) ? 'done' : d === '2026-10-10' ? 'off' : d === '2026-10-11' ? 'skip' : 'empty' });
    const grid = h.innerHTML.match(/class="hm-grid"[^>]*>(.*?)<\/div>/)[1];   // the grid only, not the legend
    const cellsAll = [...grid.matchAll(/class="hm-cell is-([a-z]+)"/g)].map(m => m[1]);
    ok(cellsAll.filter(c => c !== 'pad').length === 14, 'heatmap: one cell per day', cellsAll.length);
    ok(cellsAll.slice(0, 4).join() === 'pad,pad,pad,pad' && cellsAll[4] === 'done', 'heatmap: first day sits on its weekday row (Thu after 4 blanks, Sunday start)');
    ok(/title="[^"]*21[^"]*2026 — done"/.test(h.innerHTML) && cellsAll[cellsAll.length - 1] === 'done', 'heatmap: today is the last cell, from the given date (not the clock)');
    ok(counts.done === 2 && counts.off === 1 && counts.skip === 1 && /2 of 12 scheduled days done/.test(h.innerHTML), 'heatmap: summary excludes skipped and unscheduled days');
    const h2 = fakeEl();
    UI.heatmap(h2, { today: '2026-10-21', days: 7, weekStart: 1, label: '<x>', color: '"red', state: () => 'empty' });
    ok(!h2.innerHTML.includes('<x>') && !h2.innerHTML.includes('"red'), 'heatmap: label and colour are escaped');
    ok([...h2.innerHTML.matchAll(/is-pad/g)].length === 3, 'heatmap: Monday-start week shifts the padding (Thu → 3 blanks)');
  }

  // NL: plain-language entry, with the real vendored chrono build
  {
    const NL = ctx.window.Trackie.NL;
    const raw = NL.parse('call mom tomorrow 6pm !high #Family');
    ok(raw.title === 'call mom tomorrow 6pm' && raw.priority === 'high' && raw.tags.join() === 'family' && raw.due === null,
       'NL without the parser: priority + tags still understood, date left in the title', raw);
    const cx = { window: {} }; cx.window.window = cx.window;
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../assets/vendor/chrono.min.js'), 'utf8'), cx.window);
    ctx.window.chrono = cx.window.chrono;
    const now = new Date(2026, 9, 21, 10, 0);   // Wed 21 Oct 2026, 10:00 local
    const r = NL.parse('call mom tomorrow 6pm !high #Family', now);
    ok(r.title === 'call mom' && r.due === '2026-10-22' && r.hasTime && r.when.getHours() === 18, 'NL: "tomorrow 6pm" → 22 Oct 18:00, removed from the title', r);
    const f = NL.parse('pay rent on friday !low', now);
    ok(f.title === 'pay rent' && f.due === '2026-10-23' && f.priority === 'low' && !f.hasTime, 'NL: "on friday" → the coming Friday, no time', f);
    const plain = NL.parse('  buy   milk  ', now);
    ok(plain.title === 'buy milk' && plain.due === null && plain.priority === 'medium' && plain.tags.length === 0, 'NL: plain text stays plain');
    ok(NL.parse('fix #bug-42 now', now).tags.join() === 'bug-42' && NL.parse('email a#b', now).tags.length === 0, 'NL: #tags need a word boundary');
    ok(NL.describe('2026-10-21', '2026-10-21') === 'Today' && NL.describe('2026-10-22', '2026-10-21') === 'Tomorrow' && NL.describe('2026-10-20', '2026-10-21') === 'Yesterday',
       'NL.describe: today / tomorrow / yesterday');
    delete ctx.window.chrono;
  }

  UI.toastError(new ApiError('Steam is down.', 503), 'Failed.');
  UI.toastError(new Error('boom'), 'Could not save.');
  UI.toastError(new ApiError('Your session has expired.', 401), 'Failed.');
  ok(toasts[0] === 'Steam is down.' && toasts[1] === 'Could not save.', 'toastError shows the real reason, fallback for bugs');
  ok(toasts.length === 2, 'toastError stays quiet on 401 (app.js already toasted and redirects)');

  console.log(`== ${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})();
