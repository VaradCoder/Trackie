/**
 * Trackie — Capacitor bridge
 * Runs on the live website. When the page is loaded inside the Capacitor
 * native app, it wires up native behaviour. In a normal browser it does
 * nothing (the `isNativePlatform` guard returns early).
 *
 * Uses the global `window.Capacitor.Plugins` API because the remote site
 * has no bundler — Capacitor injects the bridge + plugins into this page.
 */
(function () {
  'use strict';

  var Cap = window.Capacitor;
  if (!Cap || typeof Cap.isNativePlatform !== 'function' || !Cap.isNativePlatform()) {
    return; // running in a regular web browser — nothing to do
  }

  var P = Cap.Plugins || {};
  document.documentElement.classList.add('is-native');

  // ── Splash + status bar ─────────────────────────────────────
  function ready() {
    if (P.SplashScreen) { try { P.SplashScreen.hide(); } catch (e) {} }
    if (P.StatusBar) {
      try {
        P.StatusBar.setStyle({ style: 'DARK' });
        P.StatusBar.setBackgroundColor({ color: '#0f172a' });
      } catch (e) {}
    }
  }
  if (document.readyState !== 'loading') ready();
  else document.addEventListener('DOMContentLoaded', ready);
  window.addEventListener('load', function () {
    if (P.SplashScreen) { try { P.SplashScreen.hide(); } catch (e) {} }
  });

  // ── Android hardware back button ────────────────────────────
  if (P.App && P.App.addListener) {
    P.App.addListener('backButton', function (info) {
      // 1) Close an open overlay first (modal / search / shortcuts)
      var overlay = document.querySelector(
        '.modal-backdrop:not(.hidden), .search-overlay:not(.hidden), #shortcutsModal:not(.hidden)'
      );
      if (overlay) {
        overlay.classList.add('hidden');
        document.body.style.overflow = '';
        return;
      }
      // 2) Close the mobile sidebar drawer if open
      var sb = document.getElementById('sidebar');
      if (sb && sb.classList.contains('open')) {
        if (window.Trackie && window.Trackie.Sidebar) window.Trackie.Sidebar.close();
        else sb.classList.remove('open');
        document.body.style.overflow = '';
        return;
      }
      // 3) Navigate back if there is history
      if ((info && info.canGoBack) || window.history.length > 1) {
        window.history.back();
        return;
      }
      // 4) At the root screen → exit the app
      if (P.App.exitApp) P.App.exitApp();
    });
  }

  // ── External links open in the system browser ───────────────
  document.addEventListener('click', function (ev) {
    var a = ev.target.closest && ev.target.closest('a[href]');
    if (!a) return;
    var href = a.getAttribute('href');
    if (!href || href.charAt(0) === '#' || href.indexOf('javascript:') === 0) return;

    var url;
    try { url = new URL(href, location.href); } catch (e) { return; }

    var isExternal =
      url.protocol === 'tel:' || url.protocol === 'mailto:' ||
      url.origin !== location.origin || a.target === '_blank';

    if (isExternal && P.Browser) {
      ev.preventDefault();
      try { P.Browser.open({ url: url.href }); } catch (e) { window.open(url.href, '_system'); }
    }
  }, true);

  // ── Native reminders (LocalNotifications plugin) ────────────
  // Web reminders only fire while a Trackie tab is open (free hosting has no
  // scheduler). In the app, the next 7 days of reminders are scheduled with
  // the OS so they fire even when the app is closed. Inactive until the app
  // is built with @capacitor/local-notifications.
  var LN = P.LocalNotifications;
  var syncing = false;
  function csrf() { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.content : ''; }
  function base() { var m = document.querySelector('meta[name="app-base"]'); return m ? m.content : ''; }
  async function syncReminders() {
    if (!LN || syncing || !csrf()) return;           // not logged in / plugin missing
    syncing = true;
    try {
      var perm = await LN.checkPermissions();
      if (perm.display !== 'granted') perm = await LN.requestPermissions();
      if (perm.display !== 'granted') return;
      var body = new FormData(); body.append('action', 'upcoming'); body.append('csrf_token', csrf());
      var res = await fetch(base() + '/api/reminders.php', { method: 'POST', body: body, credentials: 'same-origin',
                                                          headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf() } });
      var data = await res.json();
      if (!data || !data.success) return;
      // Replace everything previously scheduled by Trackie.
      var pending = await LN.getPending();
      if (pending.notifications && pending.notifications.length) await LN.cancel({ notifications: pending.notifications.map(function (n) { return { id: n.id }; }) });
      var list = (data.occurrences || []).map(function (o, i) {
        return { id: 1000 + i, title: '⏰ ' + o.title, body: o.body, schedule: { at: new Date(o.at), allowWhileIdle: true },
                 extra: { url: base() + '/pages/reminders.php' } };
      });
      if (list.length) await LN.schedule({ notifications: list });
    } catch (e) { /* never break the page over notifications */ }
    finally { syncing = false; }
  }
  if (LN) {
    window.addEventListener('load', function () { setTimeout(syncReminders, 1500); });
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') syncReminders(); });
    window.TrackieNativeSyncReminders = syncReminders;   // called after a reminder is saved
    if (LN.addListener) LN.addListener('localNotificationActionPerformed', function (ev) {
      var url = ev && ev.notification && ev.notification.extra && ev.notification.extra.url;
      if (url && url.charAt(0) === '/') location.href = url;
    });
  }
})();
