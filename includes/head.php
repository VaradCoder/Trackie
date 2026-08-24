<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <?php /* Theme must be applied BEFORE first paint. Theme.init() runs on
           DOMContentLoaded, which is far too late — dark-mode users saw a
           white flash on every single page load. This tiny synchronous
           script runs before the stylesheet is parsed. It also falls back to
           the OS preference when nothing is stored, which the JS module does
           not do (it defaulted to 'light'), so dark-OS users no longer get a
           light page wrapped in the dark theme-color browser chrome declared
           below. Keep the storage key in sync with Theme.KEY in app.js. */ ?>
  <script>
    (function () {
      try {
        var t = localStorage.getItem('trackie-theme');
        if (!t) {
          t = window.matchMedia &&
              window.matchMedia('(prefers-color-scheme: dark)').matches
                ? 'dark' : 'light';
        }
        document.documentElement.setAttribute('data-theme', t);
      } catch (e) { /* private mode / storage blocked — keep the light default */ }
    })();
  </script>
  <?php // viewport-fit=cover activates the env(safe-area-inset-*) rules already
        // written in app.css (bottom-nav, modal-footer, net-banner, tour tip).
        // Without it those four rules silently resolve to 0 and the bottom nav
        // sits under the iOS home indicator in the installed PWA. ?>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="csrf-token" content="<?= csrf_token() ?>">
  <meta name="app-base"   content="<?= APP_BASE ?>"><?php // Used by JS modules ?>
  <title><?= h($pageTitle ?? 'Trackie') ?> — Trackie</title>
  <?php /* Favicon chain. This pointed at logo.png — 118 KB at 213x203 — which
           every visitor downloaded just to draw a 16px tab icon. icon-192 is
           20.7 KB and already exists for the PWA manifest, so reuse it and
           declare sizes so the browser can pick rather than guess. */ ?>
  <link rel="icon" type="image/png" sizes="192x192" href="<?= APP_BASE ?>/assets/images/icon-192.png">
  <link rel="apple-touch-icon" sizes="180x180" href="<?= APP_BASE ?>/assets/images/apple-touch-icon.png">

  <!-- PWA -->
  <link rel="manifest" href="<?= APP_BASE ?>/manifest.json">
  <meta name="theme-color" media="(prefers-color-scheme: light)" content="#ffffff">
  <meta name="theme-color" media="(prefers-color-scheme: dark)"  content="#0f172a">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="Trackie"><?php // apple-touch-icon is declared with the favicon chain above ?>

  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <?php // gstatic serves the actual font files — without this the browser
        // pays a second connection round-trip on the critical path. ?>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <!-- Icons -->
  <?php // cdnjs is a third origin on the critical path; without preconnect the
        // browser pays DNS + TLS before it can even start the icon CSS.
        // (Not subsetting FA: 132 distinct fa-* icons are in use across the
        //  app, so a hand-rolled subset would silently drop glyphs.) ?>
  <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

  <!-- Motion (motion.dev) — UI animation engine, loaded early so it's ready before Animate.init() -->
  <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>

  <!-- App styles (version = file mtime, forces browser to re-fetch after edits) -->
  <link rel="stylesheet" href="<?= assetUrl('assets/css/app.css') ?>">

  <?php if (!empty($extraCss)): ?>
    <?= $extraCss ?>
  <?php endif; ?>
</head>
<body>
<script>
  /* Sidebar docked-state guard — same idea as the theme guard above, but it
     has to run here because it writes to <body>, which does not exist yet in
     <head>. Sidebar.init() applied this class on DOMContentLoaded, so on a
     desktop reload the page painted once with the sidebar collapsed and then
     snapped open. Running before the first paint of any content removes that
     flash. Kept in sync with Sidebar's KEY + breakpoint in app.js. */
  (function () {
    try {
      if (window.matchMedia('(min-width: 1024px)').matches
          && localStorage.getItem('trackie-sidebar-open') !== '') {
        document.body.classList.add('sidebar-open');
      }
    } catch (e) { /* storage blocked — fall back to the CSS default */ }
  })();
</script>

<!-- Cold-start splash: shown once per browser session (not on every nav) -->
<div id="tk-splash" aria-hidden="true">
  <div class="tk-splash-mark">
    <img src="<?= APP_BASE ?>/assets/images/logo.png" alt="" width="56" height="56">
    <div class="tk-splash-ring"></div>
  </div>
</div>
<script>
(function () {
  var seen = sessionStorage.getItem('tk_splash_seen');
  var el = document.getElementById('tk-splash');
  if (seen) { el.remove(); return; }
  sessionStorage.setItem('tk_splash_seen', '1');
  el.classList.add('is-visible');
  window.addEventListener('load', function () {
    setTimeout(function () {
      el.classList.add('is-hiding');
      setTimeout(function () { el.remove(); }, 400);
    }, 250);
  });
})();
</script>

<!-- Flash passthrough for JS -->
<?php $flash = getFlash(); if ($flash): ?>
  <div id="php-flash" data-type="<?= h($flash['type']) ?>" data-message="<?= h($flash['message']) ?>"></div>
<?php endif; ?>

<div id="toast-container"></div>

<!-- Service worker registration (scope derived from app base) -->
<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function () {
    var base = document.querySelector('meta[name="app-base"]').content || '';
    navigator.serviceWorker.register(base + '/sw.js', { scope: base + '/' })
      .catch(function () { /* SW optional — app still works without it */ });
  });
}
</script>

<!-- Capacitor bridge — only acts when loaded inside the native app, else no-op -->
<script src="<?= assetUrl('assets/js/capacitor-bridge.js') ?>" defer></script>