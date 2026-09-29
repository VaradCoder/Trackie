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
  <p class="settings-help" id="prefsStatus" aria-live="polite" style="margin:.25rem 0 0"></p>
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
    });
    status.textContent = res.success ? 'Saved.' : '';
    if (!res.success) Trackie.Toast.error(res.error || 'Could not save.');
  } catch { status.textContent = ''; Trackie.Toast.error('Network error.'); }
}
document.getElementById('prefsCard').addEventListener('change', savePrefs);

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
