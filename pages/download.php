<?php
/**
 * Public "Get the app" page — no sign-in needed, safe to share.
 *
 * The APK lives in GitHub Releases (built + signed by .github/workflows/android.yml).
 * Links default to /releases/latest, which always works; the script below then
 * asks the GitHub API for the newest release and swaps in the direct .apk link,
 * version, size and date. The page adapts to the visitor: Android gets the
 * download first, iPhone gets "Add to Home Screen", desktop gets a QR code.
 */
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

const TRACKIE_RELEASES = 'https://github.com/VaradCoder/Trackie/releases/latest';

$pageTitle       = 'Get the Trackie app';
$metaIndex       = true;
$metaDescription = 'Install Trackie on your Android phone: reminders as real notifications, even with the app closed. Free, no ads.';
$bodyClass       = 'lp-body';
$pageUrl         = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'trackie.free.nf') . APP_BASE . '/pages/download.php';

require_once '../includes/head.php';
?>
<main class="lp dl" id="page-main">
  <header class="lp-nav">
    <a class="lp-brand" href="<?= APP_BASE ?>/"><img src="<?= APP_BASE ?>/assets/images/icon-192.png" alt="" width="32" height="32"> Trackie</a>
    <nav class="lp-nav-links" aria-label="Account">
      <a href="<?= APP_BASE ?>/pages/auth.php?tab=login" class="lp-link">Sign in</a>
      <a href="<?= APP_BASE ?>/pages/auth.php?tab=register" class="btn btn-primary btn-sm">Get started</a>
    </nav>
  </header>

  <!-- Opened inside the installed app -->
  <section class="dl-hero" id="dlInApp" hidden>
    <img class="dl-icon" src="<?= APP_BASE ?>/assets/images/icon-192.png" alt="" width="88" height="88">
    <h1>You're already using the app</h1>
    <p class="lp-lead">This is Trackie for Android <span id="dlInAppVer"></span>. New versions are offered automatically, so there's nothing to download here.</p>
    <div class="lp-cta"><a href="<?= APP_BASE ?>/pages/dashboard.php" class="btn btn-primary">Back to Trackie</a></div>
  </section>

  <section class="dl-hero" id="dlMain">
    <img class="dl-icon" src="<?= APP_BASE ?>/assets/images/icon-192.png" alt="" width="88" height="88">
    <h1>Trackie for <span>Android</span></h1>
    <p class="lp-lead">Your reminders as real phone notifications, even when the app is closed. Same account, same data as the website.</p>

    <div class="lp-cta">
      <a href="<?= TRACKIE_RELEASES ?>" class="btn btn-primary dl-btn" id="dlBtn" rel="noopener">
        <i class="fab fa-android" aria-hidden="true"></i> <span id="dlBtnText">Download for Android</span>
      </a>
    </div>
    <p class="lp-note" id="dlMeta">Free · no ads · Android 7.0 or newer</p>

    <div class="dl-qr" id="dlQr" hidden>
      <div class="dl-qr-code" id="dlQrCode" role="img" aria-label="QR code that opens this page on your phone"></div>
      <p>On a computer? Scan this with your Android phone's camera to open this page there.</p>
    </div>

    <div class="dl-ios" id="dlIos" hidden>
      <i class="fab fa-apple" aria-hidden="true"></i>
      <div>
        <h2>On iPhone or iPad?</h2>
        <p>There's no App Store version yet, but Trackie installs from Safari: open
          <a href="<?= APP_BASE ?>/"><?= h($_SERVER['HTTP_HOST'] ?? 'trackie.free.nf') ?></a>, tap
          <strong>Share</strong> <i class="fas fa-arrow-up-from-bracket" aria-hidden="true"></i>, then
          <strong>Add to Home Screen</strong>.</p>
      </div>
    </div>
  </section>

  <section class="lp-points" aria-label="What the app adds">
    <div class="lp-point"><i class="fas fa-bell"></i><div><h3>Reminders that actually ring</h3><p>Scheduled as phone alarms, so they arrive on time with the app closed and the phone asleep.</p></div></div>
    <div class="lp-point"><i class="fas fa-rotate"></i><div><h3>Syncs in the background</h3><p>Add a reminder on the website and your phone picks it up within about 15 minutes. No need to open the app.</p></div></div>
    <div class="lp-point"><i class="fas fa-bolt"></i><div><h3>Always up to date</h3><p>The app loads the live Trackie, so new features appear straight away. When a new app version is out, it tells you.</p></div></div>
  </section>

  <section class="dl-steps" id="install">
    <h2>How to install</h2>
    <ol>
      <li><strong>Download</strong> the APK with the button above (about 12&nbsp;MB).</li>
      <li><strong>Open</strong> the downloaded file. If Android asks, allow <em>Install unknown apps</em> for your browser. This is needed for any app that doesn't come from the Play Store.</li>
      <li><strong>Install</strong>, open Trackie and sign in (or create a free account).</li>
      <li><strong>Allow notifications</strong>, and turn on <em>Alarms &amp; reminders</em> when asked, so reminders ring on time.</li>
    </ol>

    <h2>If reminders arrive late</h2>
    <p>Some phones (Xiaomi, Oppo, Vivo, Realme, OnePlus, Samsung) pause background apps to save battery. Open
      <em>Settings → Apps → Trackie → Battery</em> and choose <strong>Unrestricted</strong> (or <em>No restrictions</em>).</p>

    <h2>Questions</h2>
    <dl class="dl-faq">
      <dt>Is it safe to install from outside the Play Store?</dt>
      <dd>The APK is built automatically from Trackie's public source code on GitHub and signed with the same key every time.
        Android checks that signature, so updates can only come from Trackie.</dd>
      <dt>Do I need a new account?</dt>
      <dd>No. Sign in with the account you use on the website; everything is already there.</dd>
      <dt>How do updates work?</dt>
      <dd>Most updates need nothing from you, because the app shows the live site. When the app itself changes, Trackie shows
        <em>Update available</em>; tap <strong>Download</strong> and install it over the top. Your data stays.</dd>
      <dt>"App not installed" error?</dt>
      <dd>An older Trackie built differently is probably already on the phone. Uninstall it first, then install again.
        Your data is on the server, so nothing is lost.</dd>
      <dt>How do I remove it?</dt>
      <dd>Uninstall it like any app. To sign the phone out remotely, use <em>Settings → Phone app → Sign out</em> on the website.</dd>
    </dl>
    <p class="dl-all">All versions: <a href="https://github.com/VaradCoder/Trackie/releases" target="_blank" rel="noopener">github.com/VaradCoder/Trackie/releases</a></p>
  </section>

  <footer class="lp-foot">
    <span>© <?= date('Y') ?> Trackie</span>
    <a href="<?= APP_BASE ?>/">Home</a>
    <a href="<?= APP_BASE ?>/pages/privacy.php">Privacy</a>
    <a href="<?= APP_BASE ?>/pages/terms.php">Terms</a>
  </footer>
</main>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" defer></script>
<script>
(function () {
  const ua = navigator.userAgent || '';
  const $ = id => document.getElementById(id);

  // Inside the installed app: nothing to download.
  if (/TrackieApp\//.test(ua)) {
    $('dlMain').hidden = true;
    $('dlInApp').hidden = false;
    const App = window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.App;
    if (App && App.getInfo) App.getInfo().then(i => { $('dlInAppVer').textContent = 'v' + i.version; }).catch(() => {});
    return;
  }

  const isAndroid = /Android/i.test(ua);
  const isIos = /iPhone|iPad|iPod/i.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
  if (isIos) {
    $('dlIos').hidden = false;
    $('dlBtnText').textContent = 'Android download';
    $('dlBtn').classList.replace('btn-primary', 'btn-secondary');
  } else if (!isAndroid) {
    $('dlQr').hidden = false;
    window.addEventListener('load', () => {
      if (!window.QRCode) { $('dlQr').hidden = true; return; }
      new QRCode($('dlQrCode'), { text: <?= json_encode($pageUrl) ?>, width: 152, height: 152,
        colorDark: '#111111', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.M });
    });
  }

  // Newest release → direct .apk link + version, size and date.
  fetch('https://api.github.com/repos/VaradCoder/Trackie/releases/latest', { headers: { Accept: 'application/vnd.github+json' } })
    .then(r => (r.ok ? r.json() : null))
    .then(rel => {
      if (!rel) return;
      const apk = (rel.assets || []).find(a => /\.apk$/i.test(a.name));
      if (!apk) return;
      const ver = (String(rel.name || '').match(/(\d+\.\d+\.\d+)/) || [])[1] || '';
      const mb = (apk.size / 1048576).toFixed(1);
      const date = new Date(rel.published_at).toLocaleDateString([], { day: 'numeric', month: 'short', year: 'numeric' });
      $('dlBtn').href = apk.browser_download_url;
      if (isAndroid) $('dlBtnText').textContent = 'Download' + (ver ? ' v' + ver : '') + ' (' + mb + ' MB)';
      $('dlMeta').textContent = (ver ? 'Version ' + ver + ' · ' : '') + mb + ' MB · updated ' + date + ' · Android 7.0 or newer';
    })
    .catch(() => { /* keep the /releases/latest link */ });
})();
</script>
</body>
</html>
