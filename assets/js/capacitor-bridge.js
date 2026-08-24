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
})();
