<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

require_once '../includes/webpush.php';
requireAdmin();

$uid         = currentUserId();
$pageTitle   = 'Admin';
$currentPage = 'admin';

/** Safe COUNT helper — returns 0 if a table is missing. */
function adminCount(string $sql): int {
    try { return (int)(fetchOne($sql)['c'] ?? 0); }
    catch (Throwable $e) { return 0; }
}

// ── Platform overview ──────────────────────────────────────────
$stats = [
    ['Users',        adminCount("SELECT COUNT(*) c FROM users"),                              'fa-users',        '#3b82f6'],
    ['Todos',        adminCount("SELECT COUNT(*) c FROM todos WHERE deleted_at IS NULL"),     'fa-check-square', '#ef4444'],
    ['Habits',       adminCount("SELECT COUNT(*) c FROM habits"),                             'fa-heart',        '#a855f7'],
    ['Goals',        adminCount("SELECT COUNT(*) c FROM goals"),                              'fa-bullseye',     '#22c55e'],
    ['Study tasks',  adminCount("SELECT COUNT(*) c FROM study_plan"),                         'fa-book-open',    '#f59e0b'],
    ['Transactions', adminCount("SELECT COUNT(*) c FROM transactions"),                       'fa-wallet',       '#06b6d4'],
    ['Reminders',    adminCount("SELECT COUNT(*) c FROM reminders"),                          'fa-bell',         '#ec4899'],
    ['XP events',    adminCount("SELECT COUNT(*) c FROM xp_events"),                          'fa-bolt',         '#eab308'],
];

// ── Retention proxy: signups + active users ────────────────────
$newToday = adminCount("SELECT COUNT(*) c FROM users WHERE DATE(created_at)=CURDATE()");
$new7d    = adminCount("SELECT COUNT(*) c FROM users WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
// "active" = logged a habit, completed a todo, or earned XP in last 7 days
$active7d = adminCount(
    "SELECT COUNT(DISTINCT uid) c FROM (
        SELECT user_id uid FROM logs WHERE date_completed >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        UNION SELECT user_id FROM xp_events WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        UNION SELECT user_id FROM todos WHERE completed_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
     ) a"
);

// ── Users with per-user counts ─────────────────────────────────
$users = fetchAll(
    "SELECT u.id, u.name, u.email, u.created_at, u.is_admin,
            (SELECT COUNT(*) FROM todos t  WHERE t.user_id=u.id AND t.deleted_at IS NULL) todos,
            (SELECT COUNT(*) FROM habits h WHERE h.user_id=u.id) habits,
            (SELECT COUNT(*) FROM goals g  WHERE g.user_id=u.id) goals,
            (SELECT COALESCE(total_xp,0) FROM user_xp x WHERE x.user_id=u.id) xp
     FROM users u ORDER BY u.id DESC"
);

// ── System health ──────────────────────────────────────────────
$dbVersion = '—';
try { $dbVersion = fetchOne("SELECT VERSION() v")['v']; } catch (Throwable $e) {}
$tableCount = adminCount("SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema = DATABASE()");

// ── Configuration (presence only — values are never shown) ─────
$isSet = static fn(string ...$keys) => array_reduce($keys, fn($ok, $k) => $ok && (string)env($k) !== '', true);
$mailVia = env('BREVO_API_KEY') !== '' ? 'Brevo API' : ((env('SMTP_HOST') !== '' && env('SMTP_USER') !== '') ? 'SMTP' : null);
$config = [
    ['Email (password reset)', $mailVia ? "Configured · {$mailVia}" : 'Not configured — reset links cannot be emailed', (bool)$mailVia],
    ['Sender address (MAIL_FROM)', $isSet('MAIL_FROM') ? 'Set' : 'Missing', $isSet('MAIL_FROM')],
    ['Support email', $isSet('SUPPORT_EMAIL') ? 'Set' : 'Missing (shown on Privacy/Terms)', $isSet('SUPPORT_EMAIL')],
    ['App URL (APP_URL)', $isSet('APP_URL') ? 'Set' : 'Missing — canonical links use the request host', $isSet('APP_URL')],
    ['Web Push keys (keys/vapid.php)', (function_exists('pushEnabled') && pushEnabled()) ? 'Installed' : 'Missing — reminders cannot reach closed browsers', function_exists('pushEnabled') && pushEnabled()],
    ['Cron token', $isSet('CRON_TOKEN') ? 'Set' : 'Missing — web cron disabled', $isSet('CRON_TOKEN')],
    ['Token encryption key', $isSet('TRACKIE_ENCRYPTION_KEY') ? 'Set' : 'Missing — connecting GitHub/Google/Spotify will fail', $isSet('TRACKIE_ENCRYPTION_KEY')],
    ['GitHub sign-in', $isSet('GITHUB_CLIENT_ID', 'GITHUB_CLIENT_SECRET') ? 'Configured' : 'Off', $isSet('GITHUB_CLIENT_ID', 'GITHUB_CLIENT_SECRET')],
    ['Google sign-in', $isSet('GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET') ? 'Configured' : 'Off', $isSet('GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET')],
    ['Spotify', $isSet('SPOTIFY_CLIENT_ID', 'SPOTIFY_CLIENT_SECRET') ? 'Configured' : 'Off', $isSet('SPOTIFY_CLIENT_ID', 'SPOTIFY_CLIENT_SECRET')],
    ['HTTPS', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'On' : 'Off (this request)', !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'],
    ['Content-Security-Policy', defined('TRACKIE_CSP') ? 'Enforced' : 'Not sent', defined('TRACKIE_CSP')],
];

// ── Migrations: derived from the schema itself ─────────────────
// Each file's CREATE TABLE / ADD COLUMN statements are checked against
// information_schema, so this is accurate without a tracking table.
$schemaCols = [];
try {
    foreach (fetchAll("SELECT table_name t, column_name c FROM information_schema.columns WHERE table_schema = DATABASE()") as $r) {
        $schemaCols[strtolower($r['t'])][strtolower($r['c'])] = true;
    }
} catch (Throwable $e) {}
$migrations = [];
foreach (glob(__DIR__ . '/../database/migrations/*.sql') ?: [] as $file) {
    $sql = preg_replace('/--[^\n]*/', '', (string)file_get_contents($file));
    $need = $have = 0;
    if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $sql, $m)) {
        foreach ($m[1] as $t) { $need++; if (isset($schemaCols[strtolower($t)])) $have++; }
    }
    if (preg_match_all('/ALTER\s+TABLE\s+`?(\w+)`?(.*?);/is', $sql, $m, PREG_SET_ORDER)) {
        foreach ($m as [, $t, $body]) {
            preg_match_all('/ADD\s+COLUMN\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $body, $cm);
            foreach ($cm[1] as $c) { $need++; if (isset($schemaCols[strtolower($t)][strtolower($c)])) $have++; }
        }
    }
    $migrations[] = ['file' => basename($file), 'need' => $need, 'have' => $have,
                     'state' => $need === 0 ? 'n/a' : ($have === $need ? 'applied' : ($have === 0 ? 'missing' : 'partial'))];
}
$migrations = array_reverse($migrations);   // newest first
$pendingMig = count(array_filter($migrations, fn($m) => in_array($m['state'], ['missing', 'partial'], true)));

// ── Security log: recent login rate-limit activity ─────────────
$secLog = [];
try {
    $secLog = fetchAll("SELECT identifier, action, attempts, window_start FROM rate_limits ORDER BY window_start DESC LIMIT 15");
} catch (Throwable $e) {}

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<div class="page-toolbar">
  <h1 style="font-size:1.125rem;font-weight:600;margin:0;color:var(--text)"><i class="fas fa-shield-halved" style="color:var(--accent);margin-right:.375rem" aria-hidden="true"></i>
    Admin <span class="badge badge-red">restricted</span>
  </h1>
  <span style="font-size:.8125rem;color:var(--muted)">System overview &amp; user management</span>
</div>

<!-- Overview -->
<div class="grid-stats">
  <?php foreach ($stats as [$label, $val, $icon, $color]): ?>
    <div class="stat-card">
      <div style="display:flex;align-items:center;gap:.625rem">
        <i class="fas <?= $icon ?>" style="font-size:1.25rem;color:<?= $color ?>"></i>
        <div>
          <div class="stat-val" style="font-size:1.375rem"><?= number_format($val) ?></div>
          <div class="stat-label"><?= $label ?></div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<!-- Retention + health -->
<div class="grid-2" style="margin:1.25rem 0">
  <div class="card card-body">
    <div style="font-size:.9375rem;font-weight:600;margin-bottom:1rem">Growth &amp; retention</div>
    <?php foreach ([
      ['New users today', $newToday],
      ['New users (7 days)', $new7d],
      ['Active users (7 days)', $active7d],
    ] as [$l, $v]): ?>
      <div style="display:flex;justify-content:space-between;padding:.5rem 0;border-bottom:1px solid var(--border);font-size:.875rem">
        <span style="color:var(--muted)"><?= $l ?></span><strong style="color:var(--text)"><?= number_format($v) ?></strong>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="card card-body">
    <div style="font-size:.9375rem;font-weight:600;margin-bottom:1rem">System health</div>
    <?php foreach ([
      ['Status', '<span style="color:var(--ok)">● Operational</span>'],
      ['PHP version', h(PHP_VERSION)],
      ['Database', h(DB_NAME)],
      ['MySQL/MariaDB', h($dbVersion)],
      ['Tables', $tableCount],
    ] as [$l, $v]): ?>
      <div style="display:flex;justify-content:space-between;padding:.5rem 0;border-bottom:1px solid var(--border);font-size:.875rem">
        <span style="color:var(--muted)"><?= $l ?></span><strong style="color:var(--text)"><?= $v ?></strong>
      </div>
    <?php endforeach; ?>
    <p class="form-hint" style="margin-top:.625rem">Backups: use vPanel → MySQL → Backups (InfinityFree), or export <code>database/final.sql</code>.</p>
  </div>
</div>

<!-- Configuration + migrations -->
<div class="grid-2" style="margin-bottom:1.5rem">
  <div class="card card-body">
    <div style="font-size:.9375rem;font-weight:600;margin-bottom:1rem">Configuration <span class="form-hint" style="margin:0">— values are never displayed</span></div>
    <?php foreach ($config as [$l, $v, $ok]): ?>
      <div style="display:flex;justify-content:space-between;gap:1rem;padding:.5rem 0;border-bottom:1px solid var(--border);font-size:.875rem">
        <span style="color:var(--muted)"><?= h($l) ?></span>
        <strong style="color:<?= $ok ? 'var(--ok)' : 'var(--warn)' ?>;text-align:right"><?= $ok ? '●' : '○' ?> <?= h($v) ?></strong>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="card card-body">
    <div style="font-size:.9375rem;font-weight:600;margin-bottom:1rem">
      Migrations
      <?= $pendingMig ? '<span class="badge badge-red">' . $pendingMig . ' pending</span>' : '<span class="badge badge-gray">all applied</span>' ?>
    </div>
    <div style="max-height:360px;overflow:auto">
    <?php foreach ($migrations as $m): ?>
      <div style="display:flex;justify-content:space-between;gap:1rem;padding:.4rem 0;border-bottom:1px solid var(--border);font-size:.8125rem">
        <code style="font-size:.75rem"><?= h($m['file']) ?></code>
        <span style="white-space:nowrap;color:<?= ['applied' => 'var(--ok)', 'partial' => 'var(--warn)', 'missing' => 'var(--accent)'][$m['state']] ?? 'var(--muted)' ?>">
          <?= h($m['state']) ?><?= $m['need'] ? " ({$m['have']}/{$m['need']})" : '' ?>
        </span>
      </div>
    <?php endforeach; ?>
    </div>
    <p class="form-hint" style="margin-top:.625rem">Pending? Run the file in phpMyAdmin (vPanel → MySQL). Every migration is additive and safe to re-run.</p>
  </div>
</div>

<!-- Users -->
<div style="font-size:.9375rem;font-weight:600;margin:0 0 .75rem">Users (<?= count($users) ?>)</div>
<div class="table-wrap" style="margin-bottom:1.5rem">
  <table class="data-table">
    <thead>
      <tr><th>ID</th><th>Name</th><th>Email</th><th>Joined</th><th>Todos</th><th>Habits</th><th>Goals</th><th>XP</th><th>Role</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($users as $u): ?>
        <tr id="user-<?= $u['id'] ?>">
          <td><?= $u['id'] ?></td>
          <td><?= h($u['name']) ?></td>
          <td style="color:var(--muted)"><?= h($u['email']) ?></td>
          <td style="color:var(--muted);white-space:nowrap"><?= formatDate($u['created_at'], 'M j, Y') ?></td>
          <td><?= (int)$u['todos'] ?></td>
          <td><?= (int)$u['habits'] ?></td>
          <td><?= (int)$u['goals'] ?></td>
          <td><?= number_format((int)$u['xp']) ?></td>
          <td><span class="badge <?= $u['is_admin'] ? 'badge-red' : 'badge-gray' ?>" id="role-<?= $u['id'] ?>"><?= $u['is_admin'] ? 'Admin' : 'User' ?></span></td>
          <td>
            <?php if ((int)$u['id'] !== $uid): ?>
              <button class="btn btn-ghost btn-sm" onclick="toggleAdmin(<?= $u['id'] ?>)">
                <?= $u['is_admin'] ? 'Revoke' : 'Make admin' ?>
              </button>
            <?php else: ?>
              <span style="font-size:.75rem;color:var(--subtle)">you</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Security log -->
<div style="font-size:.9375rem;font-weight:600;margin:0 0 .75rem">
  Security log <span class="form-hint" style="margin:0">— recent rate-limited actions</span>
</div>
<div class="card" style="margin-bottom:1.5rem">
  <?php if (empty($secLog)): ?>
    <div class="empty-state" style="padding:1.5rem"><p>No rate-limit activity recorded.</p></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Identifier (IP)</th><th>Action</th><th>Attempts</th><th>Window</th></tr></thead>
        <tbody>
          <?php foreach ($secLog as $s): ?>
            <tr>
              <td><?= h($s['identifier']) ?></td>
              <td><?= h($s['action']) ?></td>
              <td><span class="badge <?= $s['attempts'] >= 5 ? 'badge-red' : 'badge-gray' ?>"><?= (int)$s['attempts'] ?></span></td>
              <td style="color:var(--muted);white-space:nowrap"><?= h($s['window_start']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

</div>
<?php include '../includes/footer.php'; ?>

<script>
async function toggleAdmin(id) {
  const ok = await Trackie.confirmDialog('Change this user\'s admin role?', { confirmText: 'Confirm' });
  if (!ok) return;
  try {
    const res = await Trackie.API.post('<?= APP_BASE ?>/api/admin.php', { action: 'toggle_admin', user_id: id });
    if (res.success) {
      const badge = document.getElementById('role-' + id);
      if (badge) {
        badge.textContent = res.is_admin ? 'Admin' : 'User';
        badge.className = 'badge ' + (res.is_admin ? 'badge-red' : 'badge-gray');
      }
      Trackie.Toast.success('Role updated.');
    } else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
</script>
