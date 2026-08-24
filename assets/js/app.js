/**
 * Trackie v1.0 — Frontend Application
 * Modules: NProgress, Toast, Theme, Sidebar, API, Confirm Dialog
 */

/* ── NProgress (top loading bar) ──────────────────────────────── */
const NProgress = (() => {
  let _status = null;
  let _trickleTimer = null;
  let bar, peg;

  function _render() {
    if (document.getElementById('nprogress')) return;
    const el = document.createElement('div');
    el.id = 'nprogress';
    el.innerHTML = '<div class="bar"><div class="peg"></div></div>';
    document.body.appendChild(el);
    bar = el.querySelector('.bar');
    peg = el.querySelector('.peg');
  }

  function _setWidth(n) {
    if (!bar) _render();
    bar.style.width = (n * 100).toFixed(2) + '%';
    bar.style.opacity = '1';
  }

  function _trickle() {
    if (_status === null) return;
    const inc = _status < 0.2 ? 0.1
              : _status < 0.5 ? 0.04
              : _status < 0.8 ? 0.02
              : 0.005;
    _status = Math.min(_status + inc, 0.994);
    _setWidth(_status);
    _trickleTimer = setTimeout(_trickle, 400);
  }

  return {
    start() {
      _render();
      _status = _status === null ? 0.05 : Math.min(_status, 0.984);
      _setWidth(_status);
      clearTimeout(_trickleTimer);
      _trickle();
    },
    done() {
      if (!bar) return;
      clearTimeout(_trickleTimer);
      _status = 1;
      _setWidth(1);
      bar.style.transition = 'width .3s ease';
      setTimeout(() => {
        bar.style.opacity = '0';
        setTimeout(() => {
          const el = document.getElementById('nprogress');
          if (el) el.remove();
          bar = peg = null;
          _status = null;
        }, 300);
      }, 200);
    },
  };
})();

/* ── Page skeleton (covers content while the next page loads) ──── */
const PageSkeleton = (() => {
  function show() {
    if (document.getElementById('page-skeleton')) return;
    const el = document.createElement('div');
    el.id = 'page-skeleton';
    el.setAttribute('aria-hidden', 'true');
    el.innerHTML =
      '<div class="psk-row"><div class="psk-line psk-w-40"></div><div class="psk-line psk-w-20"></div></div>' +
      '<div class="psk-grid">' +
      Array.from({ length: 8 }, () => '<div class="psk-card"></div>').join('') +
      '</div>';
    document.body.appendChild(el);
  }
  function hide() { document.getElementById('page-skeleton')?.remove(); }
  return { show, hide };
})();

// Auto-start on link clicks, finish on load
document.addEventListener('click', e => {
  const a = e.target.closest('a[href]');
  if (!a) return;
  const href = a.getAttribute('href');
  if (
    !href ||
    href.startsWith('#') ||
    href.startsWith('javascript:') ||
    a.target === '_blank' ||
    a.hasAttribute('download') ||
    e.ctrlKey || e.metaKey || e.shiftKey
  ) return;
  NProgress.start();
  PageSkeleton.show();
});

window.addEventListener('load', () => NProgress.done());
// Remove the skeleton if we return via the back/forward cache
window.addEventListener('pageshow', () => PageSkeleton.hide());

/* ── Toast notifications ──────────────────────────────────────── */
const Toast = (() => {
  let container;

  function _container() {
    if (!container) {
      container = document.getElementById('toast-container');
      if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        document.body.appendChild(container);
      }
    }
    return container;
  }

  const icons = {
    success: '<i class="fas fa-check-circle toast-icon"></i>',
    error:   '<i class="fas fa-exclamation-circle toast-icon"></i>',
    warning: '<i class="fas fa-triangle-exclamation toast-icon"></i>',
    info:    '<i class="fas fa-info-circle toast-icon"></i>',
  };

  function show(msg, type = 'success', duration = 4000) {
    const c = _container();
    const t = document.createElement('div');
    t.className = `toast toast-${type}`;
    t.innerHTML = `
      ${icons[type] || ''}
      <span class="toast-msg">${msg}</span>
      <button class="toast-close" aria-label="Dismiss">×</button>`;
    t.querySelector('.toast-close').addEventListener('click', () => dismiss(t));
    c.appendChild(t);
    if (duration > 0) setTimeout(() => dismiss(t), duration);
    return t;
  }

  // Distinct celebratory moment for achievement unlocks — richer than a
  // one-line toast (emoji badge + title + subtitle) so it reads as an event,
  // not just another status message. Same lifecycle/animation as show().
  function achievement(emoji, name, desc, duration = 4500) {
    const c = _container();
    const t = document.createElement('div');
    t.className = 'toast toast-achievement';
    t.innerHTML = `
      <span class="toast-ach-badge" aria-hidden="true">${emoji || '🏆'}</span>
      <span class="toast-ach-body">
        <span class="toast-ach-eyebrow">Achievement unlocked</span>
        <span class="toast-ach-name">${name}</span>
        ${desc ? `<span class="toast-ach-desc">${desc}</span>` : ''}
      </span>
      <button class="toast-close" aria-label="Dismiss">×</button>`;
    t.querySelector('.toast-close').addEventListener('click', () => dismiss(t));
    c.appendChild(t);
    if (duration > 0) setTimeout(() => dismiss(t), duration);
    return t;
  }

  function dismiss(t) {
    if (!t || !t.parentNode) return;
    t.classList.add('hiding');
    setTimeout(() => t.remove(), 200);
  }

  return {
    success:     (m, d) => show(m, 'success', d),
    error:       (m, d) => show(m, 'error',   d),
    warning:     (m, d) => show(m, 'warning', d),
    info:        (m, d) => show(m, 'info',    d),
    achievement: (emoji, name, desc, d) => achievement(emoji, name, desc, d),
  };
})();

// Fires one achievement toast per unlocked item, staggered ~450ms apart so a
// multi-unlock moment (rare, but possible) reads as a sequence of events
// instead of a stacked pile-up. `list` is the `achievements` array API
// endpoints return: [{ key, emoji, name, desc }, ...].
function showAchievementToasts(list) {
  (list || []).forEach((a, i) => {
    setTimeout(() => Toast.achievement(a.emoji, a.name, a.desc), i * 450);
  });
}

/* ── Theme (dark mode) ────────────────────────────────────────── */
const Theme = (() => {
  const KEY = 'trackie-theme';

  function apply(t) {
    document.documentElement.setAttribute('data-theme', t);
    document.querySelectorAll('[data-theme-icon]').forEach(el => {
      el.className = t === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
    });
  }

  function toggle() {
    const cur = document.documentElement.getAttribute('data-theme') || 'light';
    const next = cur === 'dark' ? 'light' : 'dark';
    localStorage.setItem(KEY, next);
    apply(next);
  }

  function init() {
    // The blocking script in head.php has ALREADY resolved and applied the
    // theme before first paint (stored value, else OS preference). Trust that
    // result rather than re-deriving it — re-reading localStorage here with a
    // hardcoded 'light' fallback would override the OS-dark default the
    // inline script just set, reintroducing the flash it exists to prevent.
    const current = document.documentElement.getAttribute('data-theme')
                 || localStorage.getItem(KEY)
                 || 'light';
    apply(current);   // still needed: syncs the moon/sun toggle icon
    document.querySelectorAll('[data-toggle-theme]').forEach(btn => {
      btn.addEventListener('click', toggle);
    });
  }

  return { init, toggle, apply };
})();

/* ── Sidebar ───────────────────────────────────────────────────────
   Desktop (≥1024px): docked open BY DEFAULT. It used to default closed,
   which meant the primary navigation of a productivity app was hidden until
   you found the hamburger — Notion/Linear/VS Code all ship it visible. The
   stored value is now only consulted to honour an explicit user choice.
   Keep the 1024px here in sync with the CSS dock blocks and the
   `max-width: 1023px` bottom-nav block. */
const Sidebar = (() => {
  const KEY       = 'trackie-sidebar-open';
  const GROUP_KEY = 'trackie-nav-collapsed';   // JSON array of collapsed group keys
  const mq        = window.matchMedia('(min-width: 1024px)');
  let sidebar, overlay;

  // Sync the hamburger → X animation + aria with the current open state
  function syncHb() {
    const active = (mq.matches && document.body.classList.contains('sidebar-open'))
                || (!mq.matches && !!sidebar?.classList.contains('open'));
    document.querySelectorAll('[data-toggle-sidebar]').forEach(b => {
      b.classList.toggle('is-active', active);
      b.setAttribute('aria-expanded', String(active));
    });
  }

  /* The drawer is hidden with `transform`, which moves it offscreen but keeps
     every link and button in the tab order. On mobile that left ~30 invisible
     controls to tab through before reaching the page. `inert` removes the
     whole subtree from focus and the a11y tree; it is only applied below the
     desktop breakpoint, where the sidebar is genuinely a closed drawer. */
  function applyInert() {
    if (!sidebar) return;
    const shouldBeInert = !mq.matches && !sidebar.classList.contains('open');
    if ('inert' in HTMLElement.prototype) {
      sidebar.inert = shouldBeInert;
    } else if (shouldBeInert) {          // older Safari / Firefox ESR
      sidebar.setAttribute('aria-hidden', 'true');
    } else {
      sidebar.removeAttribute('aria-hidden');
    }
  }

  // Mobile drawer
  function open() {
    sidebar?.classList.add('open');
    overlay?.classList.add('visible');
    document.body.style.overflow = 'hidden';
    applyInert();
    syncHb();
    // Move focus into the drawer so keyboard users land where they opened.
    sidebar?.querySelector('a, button')?.focus({ preventScroll: true });
  }

  function close() {
    const hadFocus = sidebar?.contains(document.activeElement);
    sidebar?.classList.remove('open');
    overlay?.classList.remove('visible');
    document.body.style.overflow = '';
    // Blur before inerting — a focused element inside an inert subtree leaves
    // focus stranded on <body> with no visible indicator.
    if (hadFocus) {
      document.activeElement.blur();
      document.querySelector('[data-toggle-sidebar]')?.focus({ preventScroll: true });
    }
    applyInert();
    syncHb();
  }

  function toggle() {
    if (mq.matches) {
      // Desktop: dock/undock the sidebar, remember the choice
      const isOpen = document.body.classList.toggle('sidebar-open');
      localStorage.setItem(KEY, isOpen ? '1' : '');
    } else {
      sidebar?.classList.contains('open') ? close() : open();
    }
    syncHb();
  }

  /* ── Collapsible nav groups ──────────────────────────────────────
     Stored as a JSON array of collapsed group keys. A group containing the
     current page is force-expanded regardless of the stored preference —
     never hide the page the user is looking at. */
  function readCollapsed() {
    try { return JSON.parse(localStorage.getItem(GROUP_KEY) || '[]'); }
    catch { return []; }
  }

  function writeCollapsed(list) {
    try { localStorage.setItem(GROUP_KEY, JSON.stringify(list)); } catch {}
  }

  function setGroup(toggleBtn, expanded) {
    const panel = document.getElementById(toggleBtn.getAttribute('aria-controls'));
    toggleBtn.setAttribute('aria-expanded', String(expanded));
    if (panel) panel.hidden = !expanded;
  }

  /* Click handling is DELEGATED from document, not bound per button.
     #sidebarNav is replaced wholesale by refreshFragments() (profile.php does
     this when hobbies change), which destroyed per-node listeners and left the
     collapse toggles dead until a full reload. Delegation survives any number
     of DOM swaps. Registered once; initGroups() may be called repeatedly. */
  let groupsDelegated = false;

  function initGroups() {
    const collapsed = readCollapsed();

    // Visual state DOES need re-applying after every swap — the new markup
    // arrives with server defaults and no knowledge of localStorage.
    document.querySelectorAll('.sidebar-group-toggle').forEach(btn => {
      const key = btn.dataset.navGroup;
      // Force-expand the group holding the active page, whatever was stored.
      setGroup(btn, btn.dataset.holdsCurrent === '1' || !collapsed.includes(key));
    });

    if (groupsDelegated) return;
    groupsDelegated = true;

    document.addEventListener('click', e => {
      const btn = e.target.closest('.sidebar-group-toggle');
      if (!btn) return;
      const key = btn.dataset.navGroup;
      const nowExpanded = btn.getAttribute('aria-expanded') !== 'true';
      setGroup(btn, nowExpanded);
      const list = readCollapsed().filter(k => k !== key);
      if (!nowExpanded) list.push(key);
      writeCollapsed(list);
    });
  }

  function init() {
    sidebar  = document.getElementById('sidebar');
    overlay  = document.getElementById('sidebar-overlay');

    // Desktop: open by default. Only an explicit stored '' (user closed it)
    // keeps it shut — an absent key means "never chose", which should show
    // the nav rather than hide it.
    if (mq.matches && localStorage.getItem(KEY) !== '') {
      document.body.classList.add('sidebar-open');
    }

    document.querySelectorAll('[data-toggle-sidebar]').forEach(btn => {
      btn.addEventListener('click', toggle);
    });

    overlay?.addEventListener('click', close);

    document.addEventListener('keydown', e => {
      if (e.key === 'Escape') close();
    });

    // Reset mobile drawer state when crossing the desktop breakpoint.
    // close() re-runs applyInert(), which is what clears `inert` when the
    // viewport grows to desktop and the sidebar becomes permanent nav again.
    mq.addEventListener('change', close);

    // Sidebar footer quick-add mirrors the topbar/FAB action
    document.getElementById('sidebarQuickAdd')
      ?.addEventListener('click', () => QuickAdd.open());

    initGroups();
    applyInert();   // set the correct state on first paint, not just on toggle
    syncHb();
  }

  return { init, open, close, toggle, initGroups, applyInert };
})();

/* ── API helper ───────────────────────────────────────────────── */
const API = (() => {
  const csrfMeta = () =>
    document.querySelector('meta[name="csrf-token"]')?.content || '';

  async function request(url, options = {}) {
    NProgress.start();
    const isForm = options.body instanceof FormData;

    const headers = {
      'X-CSRF-TOKEN': csrfMeta(),
      'X-Requested-With': 'XMLHttpRequest',
      ...options.headers,
    };

    if (!isForm && typeof options.body === 'object' && options.body !== null) {
      options.body = JSON.stringify(options.body);
      headers['Content-Type'] = 'application/json';
    }

    try {
      const res = await fetch(url, { ...options, headers });
      NProgress.done();
      if (!res.ok && res.status !== 422) {
        const txt = await res.text();
        throw new Error(txt || `HTTP ${res.status}`);
      }
      const ct = res.headers.get('Content-Type') || '';
      return ct.includes('json') ? res.json() : res.text();
    } catch (err) {
      NProgress.done();
      throw err;
    }
  }

  // Actions that only READ — never queued offline (queueing a read is pointless).
  const READ_ACTIONS = new Set(['get','list','subtasks','poll','stats','search','get_tx','get_sub']);

  // Raw POST (always hits the network) — used directly by the sync replayer.
  function rawPost(url, data) {
    const body = data instanceof FormData ? data : new FormData();
    if (!(data instanceof FormData)) {
      Object.entries(data || {}).forEach(([k, v]) => body.append(k, v));
    }
    body.append('csrf_token', csrfMeta());
    return request(url, { method: 'POST', body });
  }

  // Offline-aware POST: queues mutating /api/ calls when offline, returns a
  // synthetic success so optimistic UI stays consistent (Phase B).
  async function post(url, data) {
    const queueable = url.includes('/api/')
      && data && !(data instanceof FormData)
      && !READ_ACTIONS.has(data.action);
    try {
      return await rawPost(url, data);
    } catch (err) {
      if (queueable && !navigator.onLine) {
        await SyncQueue.enqueue(url, data);
        return { success: true, queued: true };
      }
      throw err;   // online server error, or a read — let the caller handle it
    }
  }

  function postJSON(url, data) {
    return request(url, {
      method: 'POST',
      body: { ...data, csrf_token: csrfMeta() },
    });
  }

  return { get: url => request(url), post, rawPost, postJSON };
})();

/* ── Offline sync queue (Phase B — IndexedDB write queue) ──────── */
const SyncQueue = (() => {
  const DB = 'trackie-sync', STORE = 'queue';
  let dbPromise;

  function db() {
    if (dbPromise) return dbPromise;
    dbPromise = new Promise((resolve, reject) => {
      const r = indexedDB.open(DB, 1);
      r.onupgradeneeded = () => r.result.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true });
      r.onsuccess = () => resolve(r.result);
      r.onerror   = () => reject(r.error);
    });
    return dbPromise;
  }
  function op(mode, fn) {
    return db().then(d => new Promise((resolve, reject) => {
      const tx = d.transaction(STORE, mode);
      const rq = fn(tx.objectStore(STORE));
      tx.oncomplete = () => resolve(rq && rq.result);
      tx.onerror    = () => reject(tx.error);
    })).catch(() => null);
  }
  const getAll = () => op('readonly',  s => s.getAll()).then(r => r || []);
  const remove = id => op('readwrite', s => s.delete(id));
  const enqueue = (url, data) =>
    op('readwrite', s => s.add({ url, data, ts: Date.now(), retries: 0 })).then(refresh);
  const count = async () => (await getAll()).length;

  let draining = false;
  async function drain() {
    if (draining || !navigator.onLine) return;
    draining = true;
    const items = await getAll();
    if (items.length) Net.syncing(items.length);
    let synced = 0;
    for (const it of items) {
      try {
        await API.rawPost(it.url, it.data);   // server dedups (awardXpOnce, unique log)
        await remove(it.id);
        synced++;
      } catch {
        if (!navigator.onLine) break;          // went offline again → keep the rest
        it.retries = (it.retries || 0) + 1;    // online failure (e.g., 500/expired)
        if (it.retries > 5) await remove(it.id);              // give up on a poison item
        else await op('readwrite', s => s.put(it));          // keep for a later retry
      }
    }
    draining = false;
    await refresh();
    if (synced > 0 && (await count()) === 0) Net.synced();
  }
  async function refresh() { Net.pending(await count()); }
  function init() {
    window.addEventListener('online', () => setTimeout(drain, 800));
    if (navigator.onLine) setTimeout(drain, 1500);   // flush leftovers from last session
    refresh();
  }
  return { init, enqueue, drain, count };
})();

/* ── Confirm dialog (replaces alert/confirm) ─────────────────── */
function confirmDialog(msg, opts = {}) {
  return new Promise(resolve => {
    const bkd = document.createElement('div');
    bkd.className = 'modal-backdrop';
    bkd.innerHTML = `
      <div class="modal-box" style="max-width:360px">
        <div class="modal-body" style="padding:1.5rem 1.25rem 1rem">
          <p style="font-size:.9375rem;color:var(--text);margin:0">${msg}</p>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary btn-sm" data-cancel>
            ${opts.cancelText || 'Cancel'}
          </button>
          <button class="btn btn-primary btn-sm" data-confirm
            style="${opts.danger ? 'background:var(--accent)' : ''}">
            ${opts.confirmText || 'Confirm'}
          </button>
        </div>
      </div>`;
    document.body.appendChild(bkd);
    const remove = v => { bkd.remove(); resolve(v); };
    bkd.querySelector('[data-confirm]').addEventListener('click', () => remove(true));
    bkd.querySelector('[data-cancel]').addEventListener('click',  () => remove(false));
    bkd.addEventListener('click', e => { if (e.target === bkd) remove(false); });
  });
}

/* ── Modal helpers ────────────────────────────────────────────── */
function openModal(id) {
  const m = document.getElementById(id);
  if (m) { m.classList.remove('hidden'); document.body.style.overflow = 'hidden'; }
}

// Entrance is still the CSS `modal-in` keyframe (fires automatically when
// `.hidden` is removed). Exit had no animation at all before — this adds one
// via motion.dev, with instant-hide fallback so a blocked/failed CDN load
// (or prefers-reduced-motion) never leaves the modal stuck on screen.
function closeModal(id) {
  const m = document.getElementById(id);
  if (!m || m.classList.contains('hidden')) return;
  document.body.style.overflow = '';

  const box = m.querySelector('.modal-box');
  const reduce = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
  if (reduce || !box || !window.Motion?.animate) {
    m.classList.add('hidden');
    return;
  }

  const hide = () => {
    m.classList.add('hidden');
    m.style.opacity = '';
    box.style.opacity = '';
    box.style.transform = '';
  };
  Promise.all([
    window.Motion.animate(m,   { opacity: [1, 0] }, { duration: .15, easing: 'ease-in' }).finished,
    window.Motion.animate(box, { opacity: [1, 0], scale: [1, .96], y: [0, -8] }, { duration: .15, easing: 'ease-in' }).finished,
  ]).then(hide).catch(hide);
}

// Close modal on backdrop click
document.addEventListener('click', e => {
  if (e.target.classList.contains('modal-backdrop')) {
    closeModal(e.target.id);
  }
  if (e.target.closest('[data-close-modal]')) {
    const id = e.target.closest('[data-close-modal]').dataset.closeModal;
    closeModal(id || e.target.closest('.modal-backdrop')?.id);
  }
});

/* ── Skeleton helpers ────────────────────────────────────────── */
function showSkeleton(containerId, count = 3) {
  const el = document.getElementById(containerId);
  if (!el) return;
  el.innerHTML = Array.from({ length: count }, () => `
    <div class="skeleton-card" style="margin-bottom:.75rem">
      <div class="skeleton skeleton-text w-3/4" style="margin-bottom:.75rem"></div>
      <div class="skeleton skeleton-text w-1/2"></div>
    </div>`).join('');
}

function clearSkeleton(containerId) {
  const el = document.getElementById(containerId);
  if (el) el.innerHTML = '';
}

/* ── Live fragment refresh ────────────────────────────────────────
   Re-fetches the current page's HTML in the background and swaps
   only the given container IDs into the live DOM — avoids a full
   navigation/reload after create actions while staying in sync with
   the server-rendered markup (no duplicated templates in JS). */
async function refreshFragments(ids) {
  try {
    const res = await fetch(location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    const html = await res.text();
    const doc = new DOMParser().parseFromString(html, 'text/html');
    ids.forEach(id => {
      const fresh = doc.getElementById(id);
      const current = document.getElementById(id);
      if (fresh && current) current.replaceWith(fresh);
    });
    return true;
  } catch {
    return false;
  }
}

/* ── Checkbox toggle helper used by todo/habit/study pages ─────── */
async function toggleItem(url, data, row, doneClass = 'done') {
  try {
    const res = await API.post(url, data);
    if (res.success) {
      if (data.completed == 1 || data.completed === true) {
        row.classList.add(doneClass);
      } else {
        row.classList.remove(doneClass);
      }
    } else {
      Toast.error(res.error || 'Update failed.');
    }
  } catch {
    Toast.error('Network error.');
  }
}

/* ── Notification system ──────────────────────────────────────── */
const Notifications = (() => {
  const BASE = () => document.querySelector('meta[name="app-base"]')?.content || '';

  const iconMap = {
    overdue:   { i: 'fa-exclamation-circle', bg: '#fef2f2', c: '#dc2626' },
    due_today: { i: 'fa-clock',              bg: '#fffbeb', c: '#d97706' },
    habit:     { i: 'fa-heart',              bg: '#fdf4ff', c: '#9333ea' },
    goal:      { i: 'fa-bullseye',           bg: '#eff6ff', c: '#2563eb' },
    reminder:  { i: 'fa-bell',               bg: '#fef2f2', c: '#ef4444' },
    achievement:{ i: 'fa-trophy',            bg: '#fffbeb', c: '#d97706' },
    xp:        { i: 'fa-bolt',               bg: '#fffbeb', c: '#f59e0b' },
    streak:    { i: 'fa-fire',               bg: '#fef2f2', c: '#ef4444' },
    success:   { i: 'fa-circle-check',       bg: '#f0fdf4', c: '#16a34a' },
    system:    { i: 'fa-bell',               bg: 'var(--surface2)', c: 'var(--muted)' },
  };

  function timeAgo(ts) {
    const d = Math.floor((Date.now() - new Date(ts)) / 1000);
    if (d < 60)    return 'just now';
    if (d < 3600)  return Math.floor(d/60) + 'm ago';
    if (d < 86400) return Math.floor(d/3600) + 'h ago';
    return Math.floor(d/86400) + 'd ago';
  }

  function renderItem(n) {
    const ico = iconMap[n.type] || iconMap.system;
    const cls = n.is_read == 1 ? '' : ' unread';
    const href = n.link || '#';
    return `
      <a class="notif-item${cls}" href="${href}"
         data-id="${n.id}" onclick="Trackie.Notifications.markRead(${n.id})">
        <div class="notif-icon" style="background:${ico.bg};color:${ico.c}">
          <i class="fas ${ico.i}"></i>
        </div>
        <div class="notif-body">
          <div class="notif-title">${n.title}</div>
          ${n.message ? `<div class="notif-msg">${n.message}</div>` : ''}
        </div>
        <div class="notif-time">${timeAgo(n.created_at)}</div>
      </a>`;
  }

  async function load() {
    const list = document.getElementById('notifList');
    if (!list) return;
    try {
      const res = await fetch(`${BASE()}/api/notifications.php?action=list&limit=15`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const data = await res.json();
      if (!data.notifications?.length) {
        list.innerHTML = '<div class="notif-empty">🎉 You\'re all caught up!</div>';
      } else {
        list.innerHTML = data.notifications.map(renderItem).join('');
      }
      updateBadge(data.unread || 0);
    } catch {
      list.innerHTML = '<div class="notif-empty">Could not load notifications.</div>';
    }
  }

  function updateBadge(count) {
    const badge = document.getElementById('notifBadge');
    if (!badge) return;
    if (count > 0) {
      badge.textContent = count > 99 ? '99+' : count;
      badge.classList.remove('hidden');
    } else {
      badge.classList.add('hidden');
    }
  }

  async function markRead(id) {
    fetch(`${BASE()}/api/notifications.php`, {
      method:  'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
      body:    `action=mark_read&id=${id}&csrf_token=${document.querySelector('meta[name="csrf-token"]')?.content}`,
    }).catch(() => {});
  }

  async function markAllRead() {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    await fetch(`${BASE()}/api/notifications.php`, {
      method:  'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
      body:    `action=mark_all_read&csrf_token=${csrf}`,
    });
    document.querySelectorAll('.notif-item.unread').forEach(el => el.classList.remove('unread'));
    updateBadge(0);
  }

  function init() {
    const btn      = document.getElementById('notifBtn');
    const dropdown = document.getElementById('notifDropdown');
    const markAll  = document.getElementById('notifMarkAll');
    if (!btn || !dropdown) return;

    btn.addEventListener('click', e => {
      e.stopPropagation();
      const isOpen = !dropdown.classList.contains('hidden');
      dropdown.classList.toggle('hidden');
      btn.setAttribute('aria-expanded', String(!isOpen));
      if (!isOpen) load();
    });

    markAll?.addEventListener('click', () => markAllRead());

    document.addEventListener('click', e => {
      if (!btn.contains(e.target) && !dropdown.contains(e.target)) {
        dropdown.classList.add('hidden');
        btn.setAttribute('aria-expanded', 'false');
      }
    });
  }

  return { init, markRead, markAllRead, updateBadge };
})();

/* ── Command palette + global search ──────────────────────────────
   Empty query → navigation + quick-action commands (Linear/VS-Code
   style). Typing → fuzzy-filtered commands PLUS live data search.
   Commands can be links (SPA-navigated) or actions (run a function). */
const Search = (() => {
  const BASE = () => document.querySelector('meta[name="app-base"]')?.content || '';
  let _timer = null;
  let _active = -1;
  let _optId  = 0;   // monotonic, so option ids stay unique across re-renders

  const iconMap = { Todos: 'fa-check-square', Habits: 'fa-heart', Goals: 'fa-bullseye', 'Study Plan': 'fa-book-open' };

  /* Navigation commands are READ FROM THE RENDERED SIDEBAR rather than
     duplicated here. The sidebar is itself a render of navTree() (PHP), so
     this keeps the palette automatically in sync — including hobby modules,
     which vary per user, and any future destination. It also means the list
     refreshes for free when the sidebar is re-rendered (e.g. after changing
     hobbies on Profile). Previously this was a hand-maintained array of 17
     entries that silently drifted from the real nav. */
  const NAV_KEYWORDS = {
    dashboard: 'home', todos: 'task tasks', focus: 'pomodoro timer',
    study_plan: 'study', finance: 'money budget expenses spending',
    progress: 'xp achievements level', music: 'spotify', profile: 'account',
    settings: 'integrations preferences', gym: 'workout fitness exercise',
    library: 'books reading', projects: 'code coding github',
  };

  function navCommands() {
    return Array.from(document.querySelectorAll('#sidebarNav .nav-item')).map(a => {
      const href  = a.getAttribute('href') || '';
      const slug  = (href.split('/').pop() || '').replace(/\.php.*$/, '');
      const group = a.closest('.sidebar-group-items')
        ?.previousElementSibling?.querySelector('.sidebar-label')?.textContent?.trim() || '';
      return {
        label: 'Go to ' + (a.dataset.navLabel || a.textContent.trim()),
        sub:   group,
        icon:  a.querySelector('i')?.className.replace('fas ', '') || 'fa-arrow-right',
        href,
        kw:    NAV_KEYWORDS[slug] || '',
      };
    });
  }

  // Action commands — things that DO something rather than navigate.
  // `run:` names a handler in ACTIONS below.
  const ACTION_COMMANDS = [
    { label: 'New todo',         sub: 'Quick add a task',   icon: 'fa-plus', run: 'newTodo',     kw: 'add create task' },
    { label: 'Toggle dark mode', sub: 'Light / dark theme', icon: 'fa-moon', run: 'toggleTheme', kw: 'theme light dark appearance' },
  ];

  const COMMANDS = () => [...ACTION_COMMANDS, ...navCommands()];

  const ACTIONS = {
    newTodo: () => window.Trackie?.QuickAdd?.open?.(),
    toggleTheme: () => window.Trackie?.Theme?.toggle?.(),
  };

  function filterCommands(q) {
    const all = COMMANDS();          // re-read the sidebar on every keystroke
    if (!q) return all;
    const n = q.toLowerCase();
    return all.filter(c =>
      (c.label + ' ' + (c.sub || '') + ' ' + (c.kw || '')).toLowerCase().includes(n)
    );
  }

  function open() {
    const overlay = document.getElementById('searchOverlay');
    const input   = document.getElementById('searchInput');
    overlay?.classList.remove('hidden');
    if (input) input.value = '';
    input?.focus();
    document.body.style.overflow = 'hidden';
    input?.setAttribute('aria-expanded', 'true');
    render({ results: [] }, '');   // show default command list immediately
  }

  function close() {
    const overlay = document.getElementById('searchOverlay');
    overlay?.classList.add('hidden');
    document.body.style.overflow = '';
    const input = document.getElementById('searchInput');
    if (input) { input.value = ''; }
    const results = document.getElementById('searchResults');
    if (results) results.innerHTML = '';
    input?.setAttribute('aria-expanded', 'false');
    input?.removeAttribute('aria-activedescendant');
    _active = -1;
  }

  async function query(q) {
    // Always (re)render commands instantly; fetch data only for 2+ chars.
    let data = { results: [] };
    if (q.length >= 2) {
      try {
        const res = await fetch(`${BASE()}/api/search.php?q=${encodeURIComponent(q)}`, {
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        data = await res.json();
      } catch { /* offline — commands still work */ }
    }
    render(data, q);
  }

  function render(data, q) {
    const el = document.getElementById('searchResults');
    if (!el) return;
    let html = '';

    const cmds = filterCommands(q);
    if (cmds.length) {
      html += `<div class="search-group-label"><i class="fas fa-bolt"></i>Commands</div>`;
      for (const c of cmds) {
        // nav commands carry a ready-made href; action commands use run:
        const href = c.href || (c.page ? `${BASE()}/pages/${c.page}.php` : '#');
        html += `
          <a class="search-result-item" href="${href}" ${c.run ? `data-run="${c.run}"` : ''}>
            <div class="search-result-icon"><i class="fas ${c.icon}"></i></div>
            <div>
              <div class="search-result-title">${escHtml(c.label)}</div>
              ${c.sub ? `<div class="search-result-sub">${escHtml(c.sub)}</div>` : ''}
            </div>
          </a>`;
      }
    }

    for (const group of (data.results || [])) {
      const ico = iconMap[group.label] || 'fa-search';
      html += `<div class="search-group-label"><i class="fas ${ico}"></i>${group.label}</div>`;
      for (const item of group.items) {
        // role=option + a stable id: required children of role=listbox, and
        // the id is what aria-activedescendant points at.
        html += `
          <a class="search-result-item" href="${item.url}"
             role="option" id="sr-opt-${_optId}" aria-selected="false">
            <div class="search-result-icon"><i class="fas ${ico}"></i></div>
            <div>
              <div class="search-result-title">${escHtml(item.title)}</div>
              ${item.sub ? `<div class="search-result-sub">${escHtml(item.sub)}</div>` : ''}
            </div>
          </a>`;
        _optId++;
      }
    }

    if (!html) {
      el.innerHTML = `<div class="search-no-results">No results for "<strong>${escHtml(q)}</strong>"</div>`;
      return;
    }

    html += `<div class="search-footer">
      <span><kbd>↑↓</kbd> Navigate</span>
      <span><kbd>Enter</kbd> Open</span>
      <span><kbd>Esc</kbd> Close</span>
    </div>`;
    el.innerHTML = html;
    _active = -1;
    // Auto-highlight the first row so Enter works without arrowing.
    const first = el.querySelector('.search-result-item');
    if (first) { _active = 0; setActiveOption(first); }
  }

  /* Keep the visual highlight, aria-selected and aria-activedescendant in
     lockstep. Three things used to drift apart here: only the CSS class was
     being set, so assistive tech had no idea which row was current. */
  function setActiveOption(item) {
    const input = document.getElementById('searchInput');
    document.querySelectorAll('.search-result-item').forEach(i => {
      i.classList.remove('active');
      i.setAttribute('aria-selected', 'false');
    });
    if (!item) { input?.removeAttribute('aria-activedescendant'); return; }
    item.classList.add('active');
    item.setAttribute('aria-selected', 'true');
    input?.setAttribute('aria-activedescendant', item.id);
  }

  // Intercept action-commands so a `data-run` row fires its handler instead
  // of navigating to "#".
  function handleItemClick(e, item) {
    const run = item.dataset.run;
    if (run) {
      e.preventDefault();
      close();
      ACTIONS[run]?.();
      return true;
    }
    close();
    return false;
  }

  function navigate(dir) {
    const items = document.querySelectorAll('.search-result-item');
    if (!items.length) return;
    _active = Math.max(0, Math.min(items.length - 1, _active + dir));
    setActiveOption(items[_active]);
    items[_active]?.scrollIntoView({ block: 'nearest' });
  }

  function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

  function init() {
    const trigger  = document.getElementById('searchTrigger');
    const overlay  = document.getElementById('searchOverlay');
    const backdrop = document.getElementById('searchBackdrop');
    const input    = document.getElementById('searchInput');
    const results  = document.getElementById('searchResults');
    if (!overlay) return;

    trigger?.addEventListener('click', open);
    backdrop?.addEventListener('click', close);

    results?.addEventListener('click', e => {
      const item = e.target.closest('.search-result-item');
      if (item) handleItemClick(e, item);
    });

    input?.addEventListener('input', e => {
      clearTimeout(_timer);
      _timer = setTimeout(() => query(e.target.value.trim()), 200);
    });

    input?.addEventListener('keydown', e => {
      if (e.key === 'ArrowDown') { e.preventDefault(); navigate(1); }
      if (e.key === 'ArrowUp')   { e.preventDefault(); navigate(-1); }
      if (e.key === 'Enter') {
        const active = document.querySelector('.search-result-item.active');
        if (active) { if (!handleItemClick(e, active)) active.click(); }
      }
      if (e.key === 'Escape') close();
    });
  }

  return { init, open, close };
})();

/* ── Quick Add todo (global, from topbar) ────────────────────── */
const QuickAdd = (() => {
  const BASE = () => document.querySelector('meta[name="app-base"]')?.content || '';

  function open() {
    const modal = document.getElementById('quickAddModal');
    if (!modal) return;
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    const due = document.getElementById('quickAddDue');
    if (due && !due.value) due.value = new Date().toISOString().slice(0, 10);
    setTimeout(() => document.getElementById('quickAddTitle')?.focus(), 50);
  }

  function close() {
    closeModal('quickAddModal');
  }

  async function save() {
    const titleEl = document.getElementById('quickAddTitle');
    const title   = titleEl?.value.trim();
    if (!title) { Toast.warning('Title is required.'); titleEl?.focus(); return; }

    const btn = document.getElementById('quickAddSave');
    if (btn) btn.disabled = true;
    try {
      const res = await API.post(`${BASE()}/api/todos.php`, {
        action:   'add',
        title,
        due_date: document.getElementById('quickAddDue')?.value || '',
        priority: document.getElementById('quickAddPriority')?.value || 'medium',
      });
      if (res.success) {
        Toast.success('Todo added!');
        if (titleEl) titleEl.value = '';
        close();
        // Live-refresh the relevant fragment instead of reloading (SPA rule).
        if (/todos\.php/.test(location.pathname)) {
          refreshFragments(['todoStatsWrap', 'todoList']);
        } else if (/dashboard\.php/.test(location.pathname)) {
          refreshFragments(['dashTodosCard']);
        }
      } else {
        Toast.error(res.error || 'Could not add todo.');
      }
    } catch {
      Toast.error('Network error.');
    }
    if (btn) btn.disabled = false;
  }

  let _listening = false;
  function toggleVoice() {
    const btn = document.getElementById('quickAddMic');
    const input = document.getElementById('quickAddTitle');
    if (!input) return;
    if (_listening) return;   // one-shot; ignore double taps
    _listening = true;
    btn?.classList.add('is-listening');
    Platform.listen(
      transcript => {
        input.value = (input.value ? input.value + ' ' : '') + transcript;
        input.focus();
      },
      () => { _listening = false; btn?.classList.remove('is-listening'); }
    );
  }

  function init() {
    document.getElementById('quickAddBtn')?.addEventListener('click', open);
    document.getElementById('bottomNavFab')?.addEventListener('click', open);
    document.getElementById('quickAddSave')?.addEventListener('click', save);
    document.getElementById('quickAddMic')?.addEventListener('click', toggleVoice);
    document.getElementById('quickAddTitle')?.addEventListener('keydown', e => {
      if (e.key === 'Enter') { e.preventDefault(); save(); }
    });
    // Hide the mic entirely where speech isn't supported.
    if (!Platform.supports.speech) document.getElementById('quickAddMic')?.remove();
  }

  return { init, open, close, save };
})();

/* ── Reminder engine (polls server, fires browser notifications) ── */
const Reminders = (() => {
  const BASE = () => document.querySelector('meta[name="app-base"]')?.content || '';
  const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
  let _timer = null;

  function notifyBrowser(r) {
    if (!('Notification' in window) || Notification.permission !== 'granted') return;
    try {
      const n = new Notification('⏰ ' + r.title, {
        body: r.message || 'Trackie reminder',
        icon: `${BASE()}/assets/images/Logo.png`,
        tag:  `trackie-reminder-${r.id}`,   // collapse duplicates across tabs
      });
      n.onclick = () => { window.focus(); n.close(); };
    } catch { /* some browsers restrict constructor — bell still shows it */ }
  }

  async function poll() {
    try {
      // Raw fetch (not API.post) so the top progress bar doesn't flash every minute
      const res = await fetch(`${BASE()}/api/reminders.php`, {
        method:  'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
        body:    `action=poll&csrf_token=${encodeURIComponent(csrf())}`,
      });
      if (!res.ok) return;
      const data = await res.json();
      if (data.fired?.length) {
        data.fired.forEach(r => {
          notifyBrowser(r);
          Toast.info(`⏰ ${r.title}`, 8000);
        });
        Notifications.updateBadge(data.unread || 0);
      }
    } catch { /* offline / logged out — try again next tick */ }
  }

  function init() {
    // Only on authenticated app pages (they render the sidebar)
    if (!document.getElementById('sidebar')) return;
    poll();
    _timer = setInterval(poll, 60000);
    window.addEventListener('pagehide', () => clearInterval(_timer));
  }

  return { init, poll };
})();

/* ── Keyboard shortcuts ──────────────────────────────────────── */
const Shortcuts = (() => {
  const defs = [
    { key: 'ctrl+k', label: 'Search',     action: () => Search.open() },
    { key: 'n',      label: 'New Todo',   action: () => { if (typeof openAddTodo === 'function') openAddTodo(); } },
    { key: '?',      label: 'Show shortcuts', action: () => Shortcuts.toggle() },
    { key: 'Escape', label: 'Close / Back', action: () => { Search.close(); Shortcuts.close(); } },
  ];

  function toggle() {
    document.getElementById('shortcutsModal')?.classList.toggle('hidden');
  }
  function close() {
    document.getElementById('shortcutsModal')?.classList.add('hidden');
  }

  function buildModal() {
    if (document.getElementById('shortcutsModal')) return;
    const modal = document.createElement('div');
    modal.id = 'shortcutsModal';
    modal.className = 'shortcuts-modal hidden';
    modal.innerHTML = `
      <div class="shortcuts-box">
        <div class="shortcuts-title">⌨️ Keyboard Shortcuts</div>
        <div class="shortcuts-grid">
          ${defs.map(d => `
            <div class="shortcut-row">
              <span class="shortcut-label">${d.label}</span>
              <span class="shortcut-key"><kbd>${d.key}</kbd></span>
            </div>`).join('')}
        </div>
        <button class="btn btn-primary btn-sm hidden" id="installAppBtn"
                style="width:100%;justify-content:center;margin-top:1rem">
          <i class="fas fa-download"></i> Install Trackie as an app
        </button>
        <button class="btn btn-secondary btn-sm" id="replayTourBtn"
                style="width:100%;justify-content:center;margin-top:.5rem">
          <i class="fas fa-graduation-cap"></i> Replay the tutorial
        </button>
        <p style="margin-top:.75rem;font-size:.8125rem;color:var(--muted);text-align:center">
          Press <kbd style="padding:.125rem .375rem;border:1px solid var(--border);border-radius:.25rem">Esc</kbd> to close
        </p>
      </div>`;
    modal.addEventListener('click', e => { if (e.target === modal) close(); });
    document.body.appendChild(modal);
    modal.querySelector('#replayTourBtn')?.addEventListener('click', () => {
      close();
      window.Trackie?.startTour?.();
    });
    modal.querySelector('#installAppBtn')?.addEventListener('click', () => {
      PWA.install();
    });
    // If the install prompt is already available, reveal the button now.
    if (PWA.canInstall()) modal.querySelector('#installAppBtn')?.classList.remove('hidden');
  }

  function init() {
    buildModal();
    document.addEventListener('keydown', e => {
      // Skip if typing in an input/textarea
      const tag = document.activeElement?.tagName;
      if (['INPUT','TEXTAREA','SELECT'].includes(tag) && e.key !== 'Escape') return;

      if ((e.ctrlKey || e.metaKey) && e.key === 'k') { e.preventDefault(); Search.open(); return; }
      if (e.key === '?') { toggle(); return; }
      if (e.key === 'Escape') { Search.close(); close(); return; }
      if (e.key === 'n' && !e.ctrlKey && !e.metaKey) {
        e.preventDefault();
        // Page-specific add modal if available, else the global quick-add
        if (typeof openAddTodo === 'function') openAddTodo();
        else QuickAdd.open();
      }
    });
  }

  return { init, toggle, close };
})();

/* ── Network status indicator (offline / back-online) ─────────── */
const Net = (() => {
  let banner, pendingN = 0;
  function ensure() {
    if (!banner) {
      banner = document.createElement('div');
      banner.id = 'net-banner';
      banner.className = 'net-banner hidden';
      banner.setAttribute('role', 'status');
      document.body.appendChild(banner);
    }
    return banner;
  }
  function paintOffline() {
    ensure();
    const tail = pendingN > 0
      ? ` — ${pendingN} change${pendingN > 1 ? 's' : ''} saved, will sync`
      : ' — viewing saved data';
    banner.innerHTML = '<i class="fas fa-wifi"></i> You\'re offline' + tail;
    banner.classList.remove('hidden', 'net-online');
    banner.classList.add('net-offline');
  }
  function setOffline() { paintOffline(); }
  function setOnline() {
    if (!banner) return;
    banner.innerHTML = '<i class="fas fa-check"></i> Back online';
    banner.classList.remove('net-offline');
    banner.classList.add('net-online');
    setTimeout(() => banner.classList.add('hidden'), 2500);
  }
  // Sync-status hooks used by SyncQueue
  function pending(n) { pendingN = n; if (navigator.onLine === false) paintOffline(); }
  function syncing(n) { Toast.info(`Syncing ${n} change${n > 1 ? 's' : ''}…`, 2000); }
  function synced()   { Toast.success('All changes synced ✓', 2500); }
  function init() {
    window.addEventListener('offline', setOffline);
    window.addEventListener('online', setOnline);
    if (navigator.onLine === false) setOffline();
  }
  return { init, setOffline, setOnline, pending, syncing, synced };
})();

/* ── PWA install ──────────────────────────────────────────────── */
const PWA = (() => {
  let deferredPrompt = null;

  function init() {
    window.addEventListener('beforeinstallprompt', e => {
      e.preventDefault();              // suppress the mini-infobar; we offer our own button
      deferredPrompt = e;
      document.getElementById('installAppBtn')?.classList.remove('hidden');
    });
    window.addEventListener('appinstalled', () => {
      deferredPrompt = null;
      document.getElementById('installAppBtn')?.classList.add('hidden');
      Toast.success('Trackie installed! 🎉');
    });
  }

  function canInstall() { return !!deferredPrompt; }

  async function install() {
    if (!deferredPrompt) {
      // iOS / unsupported: guide the user to the manual flow
      Toast.info('Open your browser menu and choose "Install app" / "Add to Home Screen".', 6000);
      return;
    }
    deferredPrompt.prompt();
    try { await deferredPrompt.userChoice; } catch {}
    deferredPrompt = null;
    document.getElementById('installAppBtn')?.classList.add('hidden');
  }

  return { init, canInstall, install };
})();

/* ── Animations (entrance stagger + count-up) ─────────────────── */
const Animate = (() => {
  function reduceMotion() {
    return !!window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
  }

  // Staggered rise-in for cards. Uses motion.dev (window.Motion, loaded via
  // CDN in footer.php) for spring-eased opacity/y when available; falls back
  // to the plain CSS keyframe + JS delay if the CDN script ever fails to
  // load, so this never leaves elements stuck invisible.
  function entrance() {
    const sel = [
      '.dash-col > *', '.dash-analytics > *',
      '.page-content > .card', '.grid-stats > *',
      '.grid-cards > *', '.grid-cards-lg > *',
      '.profile-grid > *',
    ].join(',');
    const els = document.querySelectorAll(sel);
    if (!els.length || reduceMotion()) return;

    if (window.Motion?.animate) {
      window.Motion.animate(
        els,
        { opacity: [0, 1], y: [16, 0] },
        { delay: window.Motion.stagger(0.045), duration: .5, easing: [.22, 1, .36, 1] }
      );
      return;
    }
    els.forEach((el, i) => {
      el.style.animationDelay = Math.min(i * 45, 400) + 'ms';
      el.classList.add('tk-rise');
    });
  }

  // Count-up for stat numbers — uses anime.js when available, preserving
  // any currency prefix / unit suffix (₹5,000 · +14% · 12 d).
  /* Stat-card count-up. Previously the app's ONLY use of anime.js — 17 KB on
     every page for this one effect, so it's now a plain rAF tween instead
     (same 900ms easeOutCubic, same output). See MD/CHANGELOG.md. */
  function countUp() {
    const reduce = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    const easeOutCubic = t => 1 - Math.pow(1 - t, 3);

    document.querySelectorAll('.stat-val').forEach(el => {
      const m = el.textContent.trim().match(/^([^\d-]*)(-?[\d,]+)(.*)$/);
      if (!m) return;
      const target = parseInt(m[2].replace(/,/g, ''), 10);
      if (!isFinite(target) || target === 0) return;

      const prefix = m[1], suffix = m[3];
      const render = v => { el.textContent = prefix + v.toLocaleString() + suffix; };

      // Honour reduced-motion by jumping straight to the final value.
      if (reduce) { render(target); return; }

      const DURATION = 900;
      let start = null;
      const step = ts => {
        if (start === null) start = ts;
        const p = Math.min((ts - start) / DURATION, 1);
        render(Math.round(easeOutCubic(p) * target));
        if (p < 1) requestAnimationFrame(step);
      };
      requestAnimationFrame(step);
    });
  }

  function init() { entrance(); countUp(); }
  return { init, entrance, countUp };
})();

/* ── Boot all modules on DOMContentLoaded ────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
  Theme.init();
  Sidebar.init();
  Notifications.init();
  Search.init();
  Shortcuts.init();
  QuickAdd.init();
  Reminders.init();
  Animate.init();
  Ripple.init();
  Viewport.init();
  PWA.init();
  Net.init();
  SyncQueue.init();

  // Inject app-base meta if missing (for JS modules that need it)
  if (!document.querySelector('meta[name="app-base"]')) {
    const base = document.querySelector('meta[name="csrf-token"]')?.closest('head');
    if (base) {
      const m = document.createElement('meta');
      m.name = 'app-base';
      // Derive from window.TRACKIE_API if set, else use relative
      m.content = window.TRACKIE_API?.replace('/api','') || '';
      base.appendChild(m);
    }
  }

  // Flash message auto-show
  const flashEl = document.getElementById('php-flash');
  if (flashEl) {
    const type = flashEl.dataset.type || 'info';
    const msg  = flashEl.dataset.message || '';
    if (msg) Toast[type]?.(msg);
    flashEl.remove();
  }

  // Form loading state
  document.querySelectorAll('form[data-loading]').forEach(form => {
    form.addEventListener('submit', () => {
      const btn = form.querySelector('[type="submit"]');
      if (btn) { btn.disabled = true; btn.dataset.orig = btn.innerHTML; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…'; }
    });
  });

  // Auto-dismiss alert banners
  document.querySelectorAll('.alert[data-auto-dismiss]').forEach(el => {
    setTimeout(() => {
      el.style.transition = 'opacity .3s ease';
      el.style.opacity = '0';
      setTimeout(() => el.remove(), 300);
    }, parseInt(el.dataset.autoDismiss) || 4000);
  });
});

/* ── SPA-style navigation (pjax-lite) ─────────────────────────────
   Swaps only #page-main via fetch() + DOM parsing; sidebar/topbar
   stay untouched (persistent). Falls back to a real navigation the
   moment anything looks unsafe — missing #page-main on either side,
   cross-origin link, non-HTML response — so a page that hasn't been
   converted yet, or any unexpected failure, just behaves like a
   normal link click instead of breaking.

   Script-safety note: this codebase's per-page inline scripts nearly
   all declare `const API_BASE = ...` at the top level. Re-injecting
   a second <script> with that same top-level const anywhere else in
   the document — even on a totally different page — throws
   "Identifier has already been declared" in the shared global scope
   and silently kills that page's JS. Re-injected inline scripts are
   passed through a const/let → var rewrite before execution to avoid
   that collision; the original page source is untouched, so a real
   browser load/refresh is unaffected either way. */
const SpaNav = (() => {
  const loadedScriptSrcs = new Set(
    Array.from(document.scripts).map(s => s.src).filter(Boolean).map(stripV)
  );

  function stripV(src) {
    try {
      const u = new URL(src, location.href);
      u.searchParams.delete('v');
      return u.origin + u.pathname;
    } catch { return src; }
  }

  function isSpaLink(a) {
    if (!a) return false;
    const href = a.getAttribute('href');
    if (!href || href.startsWith('#') || href.startsWith('javascript:')) return false;
    if (a.target === '_blank' || a.hasAttribute('download') || a.dataset.noSpa !== undefined) return false;
    let url;
    try { url = new URL(href, location.href); } catch { return false; }
    if (url.origin !== location.origin) return false;
    if (!/\/pages\/[^/]+\.php$/.test(url.pathname)) return false;
    if (/\/pages\/(logout|auth|login|register|forgot_password|reset_password|setup|spotify_callback)\.php$/.test(url.pathname)) return false;
    return true;
  }

  function runInlineScript(text) {
    // Run each re-injected page script inside its own function scope so its
    // top-level `const`/`let`/`var`/`function` declarations can NEVER collide
    // with bindings left in the global scope by a previously (hard-)loaded
    // page — e.g. two pages both declaring `const API_BASE`. Without this, the
    // second script throws "Identifier already declared" and the whole page's
    // JS dies silently (saves/toggles do nothing until a hard reload).
    //
    // Inline `onclick="saveTodo()"` handlers still need those functions at
    // global scope, so we detect top-level `function NAME(` declarations and
    // re-export them to `window` after the body runs. Scripts that assign to
    // `window.*` directly (data-injection blocks) are unaffected.
    const fnNames = [...text.matchAll(/(?:^|[\n;])\s*(?:async\s+)?function\s+([A-Za-z_$][\w$]*)\s*\(/g)]
      .map(m => m[1]);
    const reexport = fnNames.length
      ? '\n;' + fnNames.map(n => `try{window[${JSON.stringify(n)}]=${n};}catch(_e){}`).join('')
      : '';
    const s = document.createElement('script');
    s.textContent = '(function(){\n' + text + reexport + '\n})();';
    document.body.appendChild(s);
    s.remove();
  }

  function loadExternalScript(src) {
    return new Promise(resolve => {
      if (loadedScriptSrcs.has(stripV(src))) { resolve(); return; }
      loadedScriptSrcs.add(stripV(src));
      const s = document.createElement('script');
      s.src = src;
      s.onload = resolve;
      s.onerror = resolve;
      document.body.appendChild(s);
    });
  }

  async function runScripts(scripts) {
    for (const old of scripts) {
      if (old.src) await loadExternalScript(old.src);
      else if (old.textContent.trim()) runInlineScript(old.textContent);
    }
  }

  function syncChrome(doc, pathname) {
    document.title = doc.title;
    const newTopTitle = doc.querySelector('.topbar-title');
    const curTopTitle = document.querySelector('.topbar-title');
    if (newTopTitle && curTopTitle) curTopTitle.textContent = newTopTitle.textContent;

    let activeLink = null;
    document.querySelectorAll('#sidebarNav .nav-item, .bottom-nav-item').forEach(a => {
      try {
        const u = new URL(a.getAttribute('href'), location.href);
        const isActive = u.pathname === pathname;
        a.classList.toggle('active', isActive);
        // Keep the a11y state in step with the visual state, not just the class.
        if (isActive) { a.setAttribute('aria-current', 'page'); activeLink = activeLink || a; }
        else          { a.removeAttribute('aria-current'); }
      } catch {}
    });

    // If the destination lives in a collapsed group, expand it — otherwise the
    // user lands on a page whose nav entry is hidden.
    const panel = activeLink?.closest('.sidebar-group-items');
    if (panel?.hidden) {
      panel.hidden = false;
      document.querySelector(`[aria-controls="${panel.id}"]`)
        ?.setAttribute('aria-expanded', 'true');
    }
  }

  // Per-page stylesheets (e.g. dashboard.css) live in <head>, which swap()
  // never otherwise touches — add any the new page needs that aren't
  // already loaded. Never removes stylesheets: harmless if a style rule
  // goes briefly unused after leaving a page, but removing prematurely
  // (before the fade-in paints) risks a flash of unstyled content.
  function syncStylesheets(doc) {
    const have = new Set(
      Array.from(document.querySelectorAll('link[rel="stylesheet"]')).map(l => stripV(l.href))
    );
    doc.querySelectorAll('link[rel="stylesheet"]').forEach(l => {
      const key = stripV(l.href);
      if (!have.has(key)) {
        const clone = document.createElement('link');
        clone.rel = 'stylesheet';
        clone.href = l.getAttribute('href');
        document.head.appendChild(clone);
        have.add(key);
      }
    });
  }

  async function swap(url, push) {
    const target = document.getElementById('page-main');
    if (!target) { location.href = url; return; }

    NProgress.start();
    target.style.transition = 'opacity .15s ease';
    target.style.opacity = '.35';

    let html;
    try {
      const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      const ct = res.headers.get('content-type') || '';
      if (!res.ok || !ct.includes('text/html')) throw new Error('bad response');
      html = await res.text();
    } catch {
      location.href = url;
      return;
    }

    const doc = new DOMParser().parseFromString(html, 'text/html');
    const freshMain = doc.getElementById('page-main');
    if (!freshMain) { location.href = url; return; }

    // Scripts that live after #page-main in the source (per-page data
    // injection + script blocks some pages place after the shared footer).
    const trailingScripts = Array.from(doc.body.querySelectorAll('script'))
      .filter(s => !freshMain.contains(s));
    const mainScripts = Array.from(freshMain.querySelectorAll('script'));

    const u = new URL(url, location.href);
    syncStylesheets(doc);

    // Swap #page-main. Wrapped in the View Transitions API (feature-detected;
    // no-op to an instant swap where unsupported or reduced-motion is set) so
    // the browser cross-fades the old content out / new content in instead of
    // the old dimmed node just popping straight to the new full-opacity one.
    // See the `#page-main { view-transition-name }` rule in app.css.
    const applyDom = () => {
      document.getElementById('page-main').replaceWith(freshMain);
      freshMain.id = 'page-main';
      syncChrome(doc, u.pathname);
    };
    const reduceMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    if (!reduceMotion && document.startViewTransition) {
      document.startViewTransition(applyDom);
    } else {
      applyDom();
    }

    await runScripts(mainScripts);
    await runScripts(trailingScripts);

    // Re-run entrance/count-up animations for the freshly injected content
    // (Animate.init normally only fires on DOMContentLoaded, which SPA nav
    // skips). Guarded so a failure never blocks the navigation completing.
    try { Animate.init(); } catch {}

    NProgress.done();
    PageSkeleton.hide();

    // Close the mobile drawer after navigating. Without this, tapping a link
    // inside the off-canvas sidebar swaps the page underneath but leaves the
    // drawer open, the overlay visible, and body.overflow locked to 'hidden'
    // — i.e. the new page is unreachable and unscrollable until the user
    // manually dismisses. (Regression introduced with SPA nav: a full page
    // load used to reset this implicitly.)
    try { Sidebar.close(); } catch {}

    window.scrollTo({ top: 0, behavior: 'auto' });
    if (push) history.pushState({ spa: true }, '', url);
  }

  document.addEventListener('click', e => {
    const a = e.target.closest('a[href]');
    if (!isSpaLink(a) || e.ctrlKey || e.metaKey || e.shiftKey) return;
    const url = new URL(a.href, location.href);
    if (url.pathname === location.pathname && url.search === location.search) return;
    e.preventDefault();
    swap(a.href, true);
  });

  window.addEventListener('popstate', () => swap(location.href, false));

  return { swap };
})();

/* ── Viewport: on-screen keyboard handling ─────────────────────────
   `vh`/`dvh` do NOT shrink when a mobile keyboard opens, so a bottom-sheet
   modal stayed full-screen-tall and its pinned footer (Save / Add) ended up
   underneath the keyboard — users couldn't reach the button that submits the
   form they were typing into. visualViewport reports the space the keyboard
   actually leaves; we publish it as --vvh and flag `body.kb-open` so CSS can
   react. Also keeps the focused field scrolled into view.

   No-ops entirely on desktop and on browsers without visualViewport. */
const Viewport = (() => {
  const vv = window.visualViewport;
  // Below this much shrinkage we assume it's browser chrome, not a keyboard.
  const KB_THRESHOLD = 150;

  function sync() {
    if (!vv) return;
    document.documentElement.style.setProperty('--vvh', vv.height + 'px');
    const kbOpen = (window.innerHeight - vv.height) > KB_THRESHOLD;
    document.body.classList.toggle('kb-open', kbOpen);
  }

  function scrollFocusedIntoView(el) {
    if (!vv || !el) return;
    // Wait for the keyboard animation to settle before measuring.
    setTimeout(() => {
      const r = el.getBoundingClientRect();
      const safeBottom = vv.height - 24;
      if (r.bottom > safeBottom || r.top < 8) {
        el.scrollIntoView({ block: 'center', behavior: 'smooth' });
      }
    }, 250);
  }

  function init() {
    if (!vv) return;
    sync();
    vv.addEventListener('resize', sync);
    vv.addEventListener('scroll', sync);

    document.addEventListener('focusin', e => {
      if (e.target.matches('input, textarea, select')) scrollFocusedIntoView(e.target);
    });
  }

  return { init, sync };
})();

/* ── Ripple: satisfying tactile feedback on every .btn ────────────
   One delegated pointer listener — covers SPA-swapped buttons with no
   re-binding. Honours prefers-reduced-motion at spawn time. Pure visual;
   never interferes with the button's own click handler. */
const Ripple = (() => {
  function spawn(e) {
    if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return;
    const btn = e.target.closest('.btn');
    if (!btn || btn.disabled) return;
    const rect = btn.getBoundingClientRect();
    const size = Math.max(rect.width, rect.height);
    const el = document.createElement('span');
    el.className = 'tk-ripple';
    el.style.width = el.style.height = size + 'px';
    el.style.left = (e.clientX - rect.left - size / 2) + 'px';
    el.style.top  = (e.clientY - rect.top  - size / 2) + 'px';
    btn.appendChild(el);
    setTimeout(() => el.remove(), 600);
  }
  function init() { document.addEventListener('pointerdown', spawn, { passive: true }); }
  return { init };
})();

/* ── CrudModal: shared add/edit/delete-modal + fragment-refresh flow ──
   Phase 2 Week 1 — collapses the saveX()/editX()/deleteX() boilerplate that
   was independently hand-written on Todos, Habits, Goals, Routines,
   Reminders, Study Plan, and Finance (same ~40 lines, 6 times over). New
   pages configure one object instead of writing this flow again; existing
   pages migrate incrementally (no forced rewrite in one pass — each page's
   own hand-written version keeps working until it's moved over).

   config = {
     endpoint:     API_BASE + '/routines.php',
     modalId:      'routineModal',
     idInputId:    'routineId',      // hidden input holding the record id (empty = add)
     idParam:      'routine_id',     // payload key the API expects for the id
     fields:       { title: 'routineTitle', time_slot: 'routineTime', ... },  // payload key -> input id
     addAction:    'add', editAction: 'edit', deleteAction: 'delete',
     refreshIds:   ['routinesListWrap'],
     requiredField:'title',          // payload key that must be non-empty
     messages:     { add: 'Routine added.', edit: 'Routine updated.', delete: 'Routine deleted.', validation: 'Title is required.' },
     confirmDelete:'Delete this routine?',
     rowIdPrefix:  'routine-',       // for optimistic row removal on delete, without waiting on refresh
   } */
function createCrudModal(config) {
  function readPayload() {
    const payload = {};
    for (const [key, inputId] of Object.entries(config.fields)) {
      const el = document.getElementById(inputId);
      if (el) payload[key] = el.value;
    }
    return payload;
  }

  async function save() {
    const id = document.getElementById(config.idInputId)?.value || '';
    const payload = readPayload();

    const required = config.requiredFields || (config.requiredField ? [config.requiredField] : []);
    for (const key of required) {
      if (!String(payload[key] || '').trim()) {
        Toast.warning(config.messages?.validation || 'Please fill in the required fields.');
        return false;
      }
    }

    payload.action = id ? (config.editAction || 'edit') : (config.addAction || 'add');
    payload[config.idParam] = id;

    try {
      const res = await API.post(config.endpoint, payload);
      if (res.success) {
        Toast.success(id ? (config.messages?.edit || 'Updated.') : (config.messages?.add || 'Added.'));
        closeModal(config.modalId);
        if (config.refreshIds?.length) await refreshFragments(config.refreshIds);
        return true;
      }
      Toast.error(res.error || 'Save failed.');
      return false;
    } catch {
      Toast.error('Network error.');
      return false;
    }
  }

  async function remove(id) {
    const ok = await confirmDialog(config.confirmDelete || 'Delete this item?', { confirmText: 'Delete', danger: true });
    if (!ok) return false;
    try {
      const res = await API.post(config.endpoint, { action: config.deleteAction || 'delete', [config.idParam]: id });
      if (res.success) {
        Toast.success(config.messages?.delete || 'Deleted.');
        const row = config.rowIdPrefix ? document.getElementById(config.rowIdPrefix + id) : null;
        if (row) row.remove();
        else if (config.refreshIds?.length) await refreshFragments(config.refreshIds);
        return true;
      }
      Toast.error(res.error || 'Delete failed.');
      return false;
    } catch {
      Toast.error('Network error.');
      return false;
    }
  }

  function openAdd(resetValues = {}) {
    const idEl = document.getElementById(config.idInputId);
    if (idEl) idEl.value = '';
    for (const inputId of Object.values(config.fields)) {
      const el = document.getElementById(inputId);
      if (el) el.value = resetValues[inputId] ?? '';
    }
    openModal(config.modalId);
  }

  function fillForEdit(record) {
    const idEl = document.getElementById(config.idInputId);
    if (idEl) idEl.value = record.id ?? '';
    for (const [key, inputId] of Object.entries(config.fields)) {
      const el = document.getElementById(inputId);
      if (el && record[key] !== undefined) el.value = record[key] ?? '';
    }
    openModal(config.modalId);
  }

  return { save, remove, openAdd, fillForEdit };
}

/* ── Platform: native browser capabilities ────────────────────────
   Thin, defensively-wrapped access to the device APIs that genuinely
   help Trackie (per v2 scope): Clipboard, Web Share, Wake Lock, Speech
   Recognition, Speech Synthesis. Every method degrades gracefully and
   never throws — callers can always call and check the boolean. */
const Platform = (() => {
  async function copy(text) {
    try { await navigator.clipboard.writeText(text); Toast.success('Copied to clipboard'); return true; }
    catch { Toast.error('Copy failed — clipboard blocked'); return false; }
  }

  async function share(data) {
    if (navigator.share) {
      try { await navigator.share(data); return true; }
      catch (e) { if (e?.name === 'AbortError') return false; /* fall through */ }
    }
    return copy(data.url || data.text || data.title || '');
  }

  // Wake Lock — keep the screen awake (focus timer). Browsers drop the
  // lock when the tab is hidden, so we re-acquire on re-focus while the
  // caller still wants it.
  let _wakeLock = null, _wantLock = false;
  async function keepAwake(on) {
    _wantLock = on;
    if (!('wakeLock' in navigator)) return false;
    if (on) {
      try { _wakeLock = await navigator.wakeLock.request('screen'); return true; }
      catch { return false; }
    }
    try { await _wakeLock?.release(); } catch {}
    _wakeLock = null;
    return true;
  }
  document.addEventListener('visibilitychange', () => {
    if (_wantLock && document.visibilityState === 'visible') keepAwake(true);
  });

  function speak(text, opts = {}) {
    if (!('speechSynthesis' in window)) return false;
    try {
      const u = new SpeechSynthesisUtterance(text);
      u.rate = opts.rate ?? 1; u.pitch = opts.pitch ?? 1;
      window.speechSynthesis.speak(u);
      return true;
    } catch { return false; }
  }

  // Speech recognition — one-shot dictation. Returns the recognizer (so
  // the caller can .abort()) or null when unsupported.
  function listen(onResult, onEnd) {
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SR) { Toast.warning('Voice input isn’t supported in this browser.'); onEnd?.(); return null; }
    let rec;
    try { rec = new SR(); } catch { onEnd?.(); return null; }
    rec.lang = 'en-US'; rec.interimResults = false; rec.maxAlternatives = 1;
    rec.onresult = e => { try { onResult(e.results[0][0].transcript); } catch {} };
    rec.onerror  = () => onEnd?.();
    rec.onend    = () => onEnd?.();
    try { rec.start(); } catch { onEnd?.(); return null; }
    return rec;
  }

  const supports = {
    share:    typeof navigator.share === 'function',
    clipboard:!!(navigator.clipboard && navigator.clipboard.writeText),
    wakeLock: 'wakeLock' in navigator,
    speech:   !!(window.SpeechRecognition || window.webkitSpeechRecognition),
    tts:      'speechSynthesis' in window,
  };

  return { copy, share, keepAwake, speak, listen, supports };
})();

/* Export globals */
window.Trackie = {
  NProgress, Toast, Theme, Sidebar, API,
  confirmDialog, openModal, closeModal,
  showSkeleton, clearSkeleton, toggleItem, refreshFragments,
  Notifications, Search, Shortcuts, QuickAdd, Reminders, PageSkeleton, Animate, PWA, Net, SyncQueue, SpaNav, Platform, Ripple, Viewport,
  createCrudModal, showAchievementToasts
};
