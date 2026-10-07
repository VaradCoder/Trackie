<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/providers.php';
require_once '../includes/integrations.php';
require_once '../includes/settings.php';

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Settings';
$currentPage = 'settings';

$prefs    = userSettings($uid);
$registry = integrationsRegistry();
$summary  = integrationsSummary();

// Group providers by category for a scannable layout.
$byCategory = [];
foreach ($registry as $key => $p) {
    $byCategory[$p['category']][$key] = $p;
}

$statusMeta = [
    'active'      => ['label' => 'Active',           'badge' => 'badge-green'],
    'connected'   => ['label' => 'Connected',        'badge' => 'badge-green'],
    'connect'     => ['label' => 'Ready to connect', 'badge' => 'badge-blue'],
    'coming_soon' => ['label' => 'Coming soon',      'badge' => 'badge-gray'],
    'unavailable' => ['label' => 'Unavailable',      'badge' => 'badge-red'],
];

/** "3 minutes ago" for the last-sync line. */
function syncAgo(?string $ts): string {
    if (!$ts) return 'never';
    $d = time() - strtotime($ts);
    if ($d < 60)    return 'just now';
    if ($d < 3600)  return floor($d / 60) . 'm ago';
    if ($d < 86400) return floor($d / 3600) . 'h ago';
    return floor($d / 86400) . 'd ago';
}

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<?= renderPageHeader('Settings', [
  'icon' => 'fa-gear',
  'sub'  => 'Your preferences, and how Trackie connects to your world.',
]) ?>

<h3 class="settings-h3">Preferences</h3>
<div class="card card-body settings-prefs" id="prefsCard">
  <div class="settings-row">
    <div><label for="prefCurrency" class="settings-label">Currency</label>
      <p class="settings-help">Used for every amount in Finance.</p></div>
    <select id="prefCurrency" class="form-input settings-control">
      <?php foreach (CURRENCIES as $code => [$sym, $label]): ?>
        <option value="<?= h($code) ?>" <?= $prefs['currency'] === $code ? 'selected' : '' ?>><?= h(trim($sym)) ?> · <?= h($label) ?> (<?= h($code) ?>)</option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="settings-row">
    <div><label for="prefWeekStart" class="settings-label">Week starts on</label>
      <p class="settings-help">Calendar grids and "this week" groupings.</p></div>
    <select id="prefWeekStart" class="form-input settings-control">
      <option value="0" <?= $prefs['week_start'] === 0 ? 'selected' : '' ?>>Sunday</option>
      <option value="1" <?= $prefs['week_start'] === 1 ? 'selected' : '' ?>>Monday</option>
    </select>
  </div>
  <div class="settings-row">
    <div><label for="prefNotifyReminders" class="settings-label">Reminder notifications</label>
      <p class="settings-help">Pop-ups and push notifications when a reminder is due. Reminders still appear in the bell list.</p></div>
    <label class="settings-switch"><input type="checkbox" id="prefNotifyReminders" <?= $prefs['notify_reminders'] ? 'checked' : '' ?>><span></span></label>
  </div>
  <div class="settings-row">
    <div><label for="prefNotifyAchievements" class="settings-label">Achievement notifications</label>
      <p class="settings-help">Level-ups, unlocked achievements and streak milestones in the bell list.</p></div>
    <label class="settings-switch"><input type="checkbox" id="prefNotifyAchievements" <?= $prefs['notify_achievements'] ? 'checked' : '' ?>><span></span></label>
  </div>
  <div class="settings-row">
    <div><label for="prefTimezone" class="settings-label">Time zone</label>
      <p class="settings-help">When your day starts and ends: Today, streaks and reminder times follow it.
        <button type="button" class="btn-link" id="prefTzDetect" hidden></button></p></div>
    <select id="prefTimezone" class="form-input settings-control">
      <option value="" <?= $prefs['timezone'] === '' ? 'selected' : '' ?>>Server default (<?= h(serverTimezone()) ?>)</option>
      <?php foreach (timezone_identifiers_list() as $tz): ?>
        <option value="<?= h($tz) ?>" <?= $prefs['timezone'] === $tz ? 'selected' : '' ?>><?= h(str_replace('_', ' ', $tz)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="settings-row">
    <div><span class="settings-label" id="prefQuietLbl">Quiet hours</span>
      <p class="settings-help">No reminder pop-ups or push notifications in this window. They still land in the bell list. Leave empty to turn off.</p></div>
    <div class="settings-control settings-quiet" role="group" aria-labelledby="prefQuietLbl">
      <input type="time" id="prefQuietStart" class="form-input" value="<?= h($prefs['quiet_start']) ?>" aria-label="Quiet hours start">
      <span aria-hidden="true">to</span>
      <input type="time" id="prefQuietEnd" class="form-input" value="<?= h($prefs['quiet_end']) ?>" aria-label="Quiet hours end">
    </div>
  </div>
  <p class="settings-help" id="prefsStatus" aria-live="polite" style="margin:.25rem 0 0"></p>
</div>

<h3 class="settings-h3" style="margin-top:2rem">This device</h3>
<div class="card card-body settings-prefs" id="deviceCard">
  <div class="settings-row">
    <div><label for="prefTheme" class="settings-label">Theme</label>
      <p class="settings-help">Saved on this device. System follows your phone or computer.</p></div>
    <select id="prefTheme" class="form-input settings-control">
      <option value="system">System</option>
      <option value="light">Light</option>
      <option value="dark">Dark</option>
    </select>
  </div>
  <div class="settings-row" id="pushRow">
    <div><label for="prefPush" class="settings-label">Push notifications on this device</label>
      <p class="settings-help" id="pushHelp">Reminders reach this device even when Trackie is closed.</p></div>
    <div class="settings-control" style="display:flex;align-items:center;gap:.5rem;justify-content:flex-end">
      <button type="button" class="btn btn-secondary btn-sm" id="pushTest" hidden>Send test</button>
      <label class="settings-switch"><input type="checkbox" id="prefPush" disabled><span></span></label>
    </div>
  </div>
</div>

<h3 class="settings-h3" style="margin-top:2rem">Phone app</h3>
<div class="card card-body settings-prefs" id="phoneAppCard">
  <div class="settings-row">
    <div><span class="settings-label">Trackie for Android</span>
      <p class="settings-help">Reminders arrive as phone notifications, even with the app closed. Website changes reach the app instantly; the app tells you when a new version is available.</p></div>
    <a class="btn btn-secondary btn-sm settings-control" href="<?= APP_BASE ?>/pages/download.php" data-no-spa>
      <i class="fab fa-android" aria-hidden="true"></i> Download
    </a>
  </div>
  <div class="settings-row hidden" id="bgRow">
    <div><span class="settings-label">Run in background</span>
      <p class="settings-help" id="bgHelp">Checking…</p></div>
    <button type="button" class="btn btn-secondary btn-sm settings-control hidden" id="bgAllow">Allow</button>
  </div>
  <div id="appDevices" aria-live="polite"><p class="settings-help" style="margin:0">Loading your devices…</p></div>
</div>

<h3 class="settings-h3" style="margin-top:2rem">Getting started</h3>
<div class="card card-body settings-prefs">
  <div class="settings-row">
    <div><span class="settings-label">Guided tour</span>
      <p class="settings-help">A 30-second walk through the menu, search, quick add and reminders.</p></div>
    <button type="button" class="btn btn-secondary btn-sm settings-control" onclick="Trackie.startTour ? Trackie.startTour() : Trackie.Toast.info('The tour is still loading. Try again in a moment.')">Replay tour</button>
  </div>
  <div class="settings-row">
    <div><span class="settings-label">Setup</span>
      <p class="settings-help">Pick hobbies, your focus and daily rhythm again. Nothing you already have is removed or duplicated.</p></div>
    <a href="<?= APP_BASE ?>/pages/onboarding.php?again=1" class="btn btn-secondary btn-sm settings-control" data-no-spa>Redo setup</a>
  </div>
</div>

<h3 class="settings-h3" style="margin-top:2rem">Integrations</h3>

<div class="grid-stats" style="margin-bottom:1.5rem">
  <?= renderStatCard($summary['active'], 'Active integrations', 'fa-plug', 'var(--ok)') ?>
  <?= renderStatCard($summary['total'] - $summary['active'], 'Coming soon', 'fa-clock', 'var(--muted)') ?>
  <?= renderStatCard($summary['total'], 'Total providers', 'fa-layer-group', 'var(--info)') ?>
</div>

<?= renderInsight(
  $summary['active'] > 0
    ? "You have {$summary['active']} of {$summary['total']} integrations active. Each one you connect makes Trackie's insights sharper."
    : "Connect an integration to unlock richer insights — Spotify for focus music, Weather for your dashboard, and more.",
  'fa-plug'
) ?>


<div id="integrationsGrid">
<?php foreach ($byCategory as $category => $providers): ?>
  <div class="section-label" style="margin:1.25rem 0 .625rem"><?= h($category) ?></div>
  <div class="grid-cards">
    <?php foreach ($providers as $key => $p):
      $sm = $statusMeta[$p['status']];
    ?>
      <div class="habit-card integration-card" data-status="<?= $p['status'] ?>">
        <div style="display:flex;align-items:flex-start;gap:.75rem;margin-bottom:.75rem">
          <div class="integration-icon" style="color:<?= h($p['color']) ?>;background:<?= h($p['color']) ?>18">
            <i class="<?= strpos($p['icon'],'fa-brands')===0 ? '' : 'fas ' ?><?= h($p['icon']) ?>"></i>
          </div>
          <div style="flex:1;min-width:0">
            <div style="font-weight:600;font-size:.9375rem;color:var(--text)"><?= h($p['name']) ?></div>
            <span class="badge <?= $sm['badge'] ?>" style="margin-top:.25rem"><?= $sm['label'] ?></span>
          </div>
        </div>
        <p style="font-size:.8125rem;color:var(--muted);margin:0 0 .875rem;line-height:1.45"><?= h($p['desc']) ?></p>

        <?php if ($p['status'] === 'connected'): ?>
          <div class="integration-meta">
            <span><i class="fas fa-rotate" aria-hidden="true"></i> Synced <?= h(syncAgo($p['lastSync'])) ?></span>
            <?php if ($p['syncStatus'] === 'error'): ?>
              <span class="badge badge-red">Sync failed</span>
            <?php elseif ($p['syncStatus'] === 'ok'): ?>
              <span class="badge badge-green">Healthy</span>
            <?php endif; ?>
          </div>
          <?php if (!empty($p['lastError'])): ?>
            <p class="integration-error"><?= h($p['lastError']) ?></p>
          <?php endif; ?>
          <div style="display:flex;gap:.5rem">
            <button class="btn btn-secondary btn-sm" style="flex:1" onclick="syncProvider('<?= h($p['impl']) ?>', this)">
              <i class="fas fa-rotate"></i> Sync now
            </button>
            <button class="btn btn-ghost btn-sm" style="color:var(--accent)"
                    onclick="disconnectProvider('<?= h($p['impl']) ?>')" aria-label="Disconnect <?= h($p['name']) ?>">
              <i class="fas fa-link-slash"></i>
            </button>
          </div>
        <?php elseif ($p['status'] === 'active'): ?>
          <button class="btn btn-secondary btn-sm" style="width:100%" disabled>
            <i class="fas fa-circle-check" style="color:var(--ok)"></i> Active
          </button>
        <?php elseif ($p['status'] === 'unavailable'): ?>
          <p class="integration-error"><?= h($p['lastError'] ?? '') ?></p>
          <button class="btn btn-secondary btn-sm" style="width:100%" disabled>
            <i class="fas fa-triangle-exclamation" style="color:var(--warn)"></i> Unavailable
          </button>
        <?php elseif ($p['status'] === 'connect'): ?>
          <a href="<?= APP_BASE . h($p['connect']) ?>" class="btn btn-primary btn-sm" style="width:100%">
            <i class="fas fa-link"></i> Connect
          </a>
        <?php else: ?>
          <button class="btn btn-secondary btn-sm" style="width:100%" disabled title="<?= h($p['setup']) ?>">
            <i class="fas fa-clock"></i> Coming soon
          </button>
          <div class="integration-setup">
            <i class="fas fa-key" style="font-size:.6875rem"></i>
            <a href="<?= h($p['docs']) ?>" target="_blank" rel="noopener">Get API key</a>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>
</div>

<div class="card card-body" style="margin-top:1.5rem">
  <div style="font-size:.875rem;font-weight:600;margin-bottom:.375rem">
    <i class="fas fa-circle-info" style="color:var(--info)"></i> For the developer
  </div>
  <p class="form-hint" style="margin:0">
    API keys live in <code>config/env.php</code> (web-blocked, gitignored). Each
    “Coming soon” card above turns active automatically once its key is filled in
    — no code change needed. Full key-placement map: <code>MD/INTEGRATIONS.md</code>.
  </p>
</div>

</div>
<p class="legal-links" style="margin-top:2rem">
  <a href="<?= APP_BASE ?>/pages/privacy.php" data-no-spa>Privacy Policy</a> ·
  <a href="<?= APP_BASE ?>/pages/terms.php" data-no-spa>Terms of Service</a>
</p>
<?php include '../includes/footer.php'; ?>

<script>
const API_BASE = '<?= APP_BASE ?>/api';

/* Preferences save as soon as they change — no Save button to forget. */
async function savePrefs() {
  const status = document.getElementById('prefsStatus');
  status.textContent = 'Saving…';
  try {
    const res = await Trackie.API.post(`${API_BASE}/settings.php`, {
      action: 'save',
      currency: document.getElementById('prefCurrency').value,
      week_start: document.getElementById('prefWeekStart').value,
      notify_reminders: document.getElementById('prefNotifyReminders').checked ? 1 : 0,
      notify_achievements: document.getElementById('prefNotifyAchievements').checked ? 1 : 0,
      timezone: document.getElementById('prefTimezone').value,
      quiet_start: document.getElementById('prefQuietStart').value,
      quiet_end: document.getElementById('prefQuietEnd').value,
    }, { button: null });
    status.textContent = res.success ? 'Saved.' : '';
    if (!res.success) Trackie.Toast.error(res.error || 'Could not save.');
  } catch (e) { status.textContent = ''; Trackie.Toast.error(e.message || 'Could not save.'); }
}
document.getElementById('prefsCard').addEventListener('change', e => {
  // Quiet hours need both ends — wait until the pair is complete (or both cleared).
  if (e.target.id === 'prefQuietStart' || e.target.id === 'prefQuietEnd') {
    const a = document.getElementById('prefQuietStart').value, b = document.getElementById('prefQuietEnd').value;
    if (!!a !== !!b) { document.getElementById('prefsStatus').textContent = 'Set both times to turn quiet hours on.'; return; }
  }
  savePrefs();
});

// Offer the device's own zone when it differs from what's saved.
(function tzSuggest() {
  const sel = document.getElementById('prefTimezone'), btn = document.getElementById('prefTzDetect');
  let dev = ''; try { dev = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch {}
  if (!dev || sel.value === dev || ![...sel.options].some(o => o.value === dev)) return;
  btn.textContent = `Use this device's zone (${dev.replace(/_/g, ' ')})`;
  btn.hidden = false;
  btn.addEventListener('click', () => { sel.value = dev; btn.hidden = true; savePrefs(); });
})();

/* ── This phone: battery optimisation (Android app 1.1.4+ only) ── */
(function backgroundRow() {
  const row = document.getElementById('bgRow');
  if (!row || !window.TrackieNative?.batteryStatus) return;
  const help = document.getElementById('bgHelp'), btn = document.getElementById('bgAllow');
  async function refresh() {
    const s = await TrackieNative.batteryStatus();
    if (!s) return;                                   // older APK without the native helper
    row.classList.remove('hidden');
    help.textContent = s.ignoring
      ? 'Allowed. Reminders, timers and background sync keep working when the app is closed.'
      : 'Restricted by battery saver. Reminders and timers may be late or missed after you swipe Trackie away.';
    btn.classList.toggle('hidden', s.ignoring);
  }
  btn.addEventListener('click', () => TrackieNative.allowBackground());
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') refresh(); });
  refresh();
})();

/* ── Phone app installs (api/device.php) ───────────────────── */
(function phoneDevices() {
  const box = document.getElementById('appDevices');
  const when = d => d ? new Date(d.replace(' ', 'T')).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : 'never';
  async function load() {
    try {
      const r = await Trackie.API.post(`${API_BASE}/device.php`, { action: 'list' }, { button: null, quiet: true });
      if (!r.success) { box.innerHTML = `<p class="settings-help" style="margin:0">${escHtml(r.error || 'Could not load devices.')}</p>`; return; }
      if (!r.devices.length) { box.innerHTML = '<p class="settings-help" style="margin:0">No phones signed in yet. Install the app and sign in to get reminder notifications.</p>'; return; }
      box.innerHTML = r.devices.map(d => `
        <div class="settings-row">
          <div><span class="settings-label"><i class="fas fa-mobile-screen" aria-hidden="true"></i> ${escHtml(d.name || 'Phone')}</span>
            <p class="settings-help">App ${escHtml(d.version || '')} · last synced ${escHtml(when(d.last_seen))}${d.push ? ' · push on' : ''}</p></div>
          <button type="button" class="btn btn-secondary btn-sm settings-control" data-revoke="${+d.id}">Sign out</button>
        </div>`).join('');
    } catch (e) { box.innerHTML = `<p class="settings-help" style="margin:0">${escHtml(e.message || 'Could not load devices.')}</p>`; }
  }
  box.addEventListener('click', async e => {
    const b = e.target.closest('[data-revoke]');
    if (!b) return;
    if (!await Trackie.confirmDialog('Sign this phone out? It stops receiving reminders until you sign in on it again.', { confirmText: 'Sign out', danger: true })) return;
    const r = await Trackie.API.post(`${API_BASE}/device.php`, { action: 'revoke', device_id: b.dataset.revoke }).catch(e => ({ success: false, error: e.message }));
    r.success ? (Trackie.Toast.success('Phone signed out.'), load()) : Trackie.Toast.error(r.error || 'Could not sign it out.');
  });
  load();
})();

/* ── This device: theme + push ─────────────────────────────── */
(function deviceSettings() {
  const theme = document.getElementById('prefTheme');
  theme.value = Trackie.Theme.mode();
  theme.addEventListener('change', () => Trackie.Theme.set(theme.value));

  const box = document.getElementById('prefPush'), help = document.getElementById('pushHelp'), testBtn = document.getElementById('pushTest');
  const say = t => { help.textContent = t; };
  async function refresh() {
    try {
      const st = await Trackie.Push.status();
      if (!st.supported) {
        say(st.native ? 'The Trackie app delivers reminders as system notifications on this phone.' : "This browser can't receive push notifications. Reminders still appear in the bell list.");
        box.disabled = true; box.checked = false; return;
      }
      if (!st.configured) { say('Push is not set up on the server yet (an admin needs to add VAPID keys).'); box.disabled = true; return; }
      box.disabled = st.permission === 'denied' && !st.subscribed;
      box.checked = st.subscribed;
      testBtn.hidden = !st.subscribed;
      say(st.permission === 'denied' ? 'Notifications are blocked for Trackie in this browser. Allow them in the site settings to turn this on.'
        : st.subscribed ? `On. Reminders reach this device even when Trackie is closed${st.devices > 1 ? ` (${st.devices} devices in total)` : ''}.`
        : 'Reminders reach this device even when Trackie is closed.');
    } catch (e) { say(e.message || 'Could not check push status.'); }
  }
  box.addEventListener('change', async () => {
    box.disabled = true;
    try {
      if (box.checked) { await Trackie.Push.enable(); Trackie.Toast.success('Push notifications are on for this device.'); }
      else { await Trackie.Push.disable(); Trackie.Toast.info('Push notifications are off for this device.'); }
    } catch (e) { box.checked = !box.checked; Trackie.Toast.error(e.message || 'Could not change push notifications.'); }
    await refresh();
  });
  testBtn.addEventListener('click', async () => {
    const r = await Trackie.Push.test().catch(e => ({ success: false, error: e.message }));
    if (r.success) Trackie.Toast.success(r.sent ? 'Test sent. It should appear in a moment.' : 'No device accepted the push. Try turning it off and on again.');
    else Trackie.Toast.error(r.error || 'Could not send a test.');
  });
  refresh();
})();

async function syncProvider(key, btn) {
  const original = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-rotate fa-spin"></i> Syncing…';
  try {
    const res = await Trackie.API.post(`${API_BASE}/integrations.php`, { action: 'sync', provider: key });
    if (res.success) {
      Trackie.Toast.success(`Synced ${res.records} record${res.records === 1 ? '' : 's'}.`);
      await Trackie.refreshFragments(['integrationsGrid']);
    } else {
      // A provider failure is reported, never fatal — the page stays usable.
      Trackie.Toast.error(res.error || 'Sync failed.');
      await Trackie.refreshFragments(['integrationsGrid']);
    }
  } catch {
    Trackie.Toast.error('Network error.');
  }
  btn.disabled = false;
  btn.innerHTML = original;
}

async function disconnectProvider(key) {
  const ok = await Trackie.confirmDialog(
    'Disconnect this integration? Synced data for it will be removed.',
    { confirmText: 'Disconnect', danger: true }
  );
  if (!ok) return;
  try {
    const res = await Trackie.API.post(`${API_BASE}/integrations.php`, { action: 'disconnect', provider: key });
    if (res.success) {
      Trackie.Toast.success('Disconnected.');
      await Trackie.refreshFragments(['integrationsGrid']);
    } else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
</script>
</div>
</div>
</body>
</html>
