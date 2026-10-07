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
    ['Todos',        adminCount("SELECT COUNT(*) c FROM todos WHERE deleted_at IS NULL"),     'fa-check-square', '#ef4444'],
    ['Habits',       adminCount("SELECT COUNT(*) c FROM habits"),                             'fa-heart',        '#a855f7'],
    ['Goals',        adminCount("SELECT COUNT(*) c FROM goals"),                              'fa-bullseye',     '#22c55e'],
    ['Study tasks',  adminCount("SELECT COUNT(*) c FROM study_plan"),                         'fa-book-open',    '#f59e0b'],
    ['Transactions', adminCount("SELECT COUNT(*) c FROM transactions"),                       'fa-wallet',       '#06b6d4'],
    ['Reminders',    adminCount("SELECT COUNT(*) c FROM reminders"),                          'fa-bell',         '#ec4899'],
    ['XP events',    adminCount("SELECT COUNT(*) c FROM xp_events"),                          'fa-bolt',         '#eab308'],
];

// ── Users & activity ───────────────────────────────────────────
// Presence (user_presence) is recorded on every signed-in request since it
// shipped; "actions" (habits, todos, XP, activity log) cover the time before.
// A user counts as active in a window if either source saw them.
$hasPresence = tableExists('user_presence');
$trackingSince = $hasPresence ? (fetchOne("SELECT MIN(day) d FROM user_presence")['d'] ?? null) : null;
$safeAll = static function (string $sql, array $p = []): array { try { return fetchAll($sql, $p); } catch (Throwable $e) { return []; } };

/** Users who did something (not just visited) since $days ago. */
$actedSince = static function (int $days) use ($safeAll): array {
    $rows = $safeAll(
        "SELECT user_id FROM xp_events WHERE created_at >= CURDATE() - INTERVAL ? DAY
         UNION SELECT user_id FROM activity_log WHERE created_at >= CURDATE() - INTERVAL ? DAY
         UNION SELECT user_id FROM logs WHERE date_completed >= CURDATE() - INTERVAL ? DAY
         UNION SELECT user_id FROM todos WHERE completed_at >= CURDATE() - INTERVAL ? DAY",
        [$days, $days, $days, $days]
    );
    return array_map('intval', array_column($rows, 'user_id'));
};
$seenSince = static function (int $days) use ($safeAll, $hasPresence): array {
    if (!$hasPresence) return [];
    return array_map('intval', array_column($safeAll("SELECT DISTINCT user_id FROM user_presence WHERE day >= CURDATE() - INTERVAL ? DAY", [$days]), 'user_id'));
};
$activeIn = static fn(int $days) => count(array_unique(array_merge($actedSince($days), $seenSince($days))));

$totalUsers = adminCount("SELECT COUNT(*) c FROM users");
$newToday   = adminCount("SELECT COUNT(*) c FROM users WHERE DATE(created_at)=CURDATE()");
$new7d      = adminCount("SELECT COUNT(*) c FROM users WHERE created_at >= CURDATE() - INTERVAL 6 DAY");
$new30d     = adminCount("SELECT COUNT(*) c FROM users WHERE created_at >= CURDATE() - INTERVAL 29 DAY");
$onlineNow  = $hasPresence ? adminCount("SELECT COUNT(DISTINCT user_id) c FROM user_presence WHERE last_at >= NOW() - INTERVAL 5 MINUTE") : 0;
$dau = $activeIn(0); $wau = $activeIn(6); $mau = $activeIn(29);

// Per user: presence (all time + last 30 days), last action, app installs, sign-in.
$presAll = $hasPresence ? array_column($safeAll(
    "SELECT user_id, MAX(last_at) last_at, MIN(first_at) first_at FROM user_presence GROUP BY user_id"), null, 'user_id') : [];
$pres30 = $hasPresence ? array_column($safeAll(
    "SELECT user_id, COUNT(*) days, SUM(web_hits) web, SUM(app_hits) app,
            SUM(web_hits > 0) web_days, SUM(app_hits > 0) app_days
       FROM user_presence WHERE day >= CURDATE() - INTERVAL 29 DAY GROUP BY user_id"), null, 'user_id') : [];
$lastLive = $hasPresence ? array_column($safeAll(
    "SELECT p.user_id, p.last_client FROM user_presence p
       JOIN (SELECT user_id, MAX(day) d FROM user_presence GROUP BY user_id) m ON m.user_id = p.user_id AND m.d = p.day"), 'last_client', 'user_id') : [];
$lastAction = array_column($safeAll(
    "SELECT user_id, MAX(t) t FROM (
        SELECT user_id, MAX(created_at) t FROM xp_events GROUP BY user_id
        UNION ALL SELECT user_id, MAX(created_at) FROM activity_log GROUP BY user_id
     ) x GROUP BY user_id"), 't', 'user_id');
$devices = [];
foreach ($safeAll("SELECT user_id, device_name, app_version, last_seen_at, created_at FROM native_devices WHERE revoked_at IS NULL ORDER BY last_seen_at DESC") as $d) {
    $devices[(int)$d['user_id']][] = $d;
}
$googleUsers = array_flip(array_map('intval', array_column($safeAll("SELECT user_id FROM user_identities WHERE provider='google'"), 'user_id')));

// Website vs app (last 30 days, from presence) + installed apps (any time).
$split = ['web' => 0, 'app' => 0, 'both' => 0];
foreach ($pres30 as $p) {
    $w = (int)$p['web'] > 0; $a = (int)$p['app'] > 0;
    if ($w && $a) $split['both']++; elseif ($a) $split['app']++; elseif ($w) $split['web']++;
}
$appInstalls = count($devices);

// Daily chart: last 30 days — website-only, app-only, both, and sign-ups.
$daily = [];
for ($i = 29; $i >= 0; $i--) $daily[date('Y-m-d', strtotime("-{$i} day"))] = ['web' => 0, 'app' => 0, 'both' => 0, 'new' => 0];
foreach ($safeAll("SELECT day, SUM(web_hits > 0 AND app_hits = 0) web, SUM(app_hits > 0 AND web_hits = 0) app, SUM(web_hits > 0 AND app_hits > 0) both_
                     FROM user_presence WHERE day >= CURDATE() - INTERVAL 29 DAY GROUP BY day") as $r) {
    if (isset($daily[$r['day']])) $daily[$r['day']] = ['web' => (int)$r['web'], 'app' => (int)$r['app'], 'both' => (int)$r['both_'], 'new' => 0];
}
foreach ($safeAll("SELECT DATE(created_at) d, COUNT(*) n FROM users WHERE created_at >= CURDATE() - INTERVAL 29 DAY GROUP BY d") as $r) {
    if (isset($daily[$r['d']])) $daily[$r['d']]['new'] = (int)$r['n'];
}
$dailyMax = max(1, ...array_values(array_map(static fn($d) => $d['web'] + $d['app'] + $d['both'], $daily)));

$users = fetchAll(
    "SELECT u.id, u.name, u.email, u.created_at, u.is_admin, u.password,
            (SELECT COUNT(*) FROM todos t  WHERE t.user_id=u.id AND t.deleted_at IS NULL) todos,
            (SELECT COUNT(*) FROM habits h WHERE h.user_id=u.id) habits,
            (SELECT COUNT(*) FROM goals g  WHERE g.user_id=u.id) goals,
            (SELECT COALESCE(total_xp,0) FROM user_xp x WHERE x.user_id=u.id) xp
     FROM users u ORDER BY u.id DESC"
);
$now = time();
foreach ($users as &$u) {
    $id = (int)$u['id'];
    $seen = $presAll[$id]['last_at'] ?? null;
    $act  = $lastAction[$id] ?? null;
    $u['last_seen'] = max((string)$seen, (string)$act) ?: null;
    $u['online']    = $seen && strtotime($seen) >= $now - 300;
    $p = $pres30[$id] ?? null;
    $u['uses'] = !$p ? (isset($devices[$id]) ? 'app' : null)
               : (((int)$p['web'] && (int)$p['app']) ? 'both' : ((int)$p['app'] ? 'app' : 'web'));
    $u['days30']   = (int)($p['days'] ?? 0);
    $u['web_days'] = (int)($p['web_days'] ?? 0);
    $u['app_days'] = (int)($p['app_days'] ?? 0);
    $u['last_client'] = $lastLive[$id] ?? null;
    $u['devices']  = $devices[$id] ?? [];
    $u['google']   = isset($googleUsers[$id]);
    $u['has_pw']   = ($u['password'] ?? '') !== '' && $u['password'][0] !== '!';
    $days = $u['last_seen'] ? (int)floor(($now - strtotime($u['last_seen'])) / 86400) : null;
    $u['segment'] = trim(implode(' ', array_filter([
        $u['online'] ? 'online' : '',
        $days !== null && $days <= 6 ? 'active' : '',
        $days === null || $days >= 30 ? 'inactive' : '',
        strtotime($u['created_at']) >= strtotime('-6 days', strtotime('today')) ? 'new' : '',
        in_array($u['uses'], ['app', 'both'], true) || $u['devices'] ? 'app' : '',
        in_array($u['uses'], ['web', 'both'], true) ? 'web' : '',
    ])));
    unset($u['password']);
}
unset($u);
$ago = static function (?string $ts) use ($now): string {
    if (!$ts) return 'never';
    $d = $now - strtotime($ts);
    if ($d < 300)   return 'online now';
    if ($d < 3600)  return floor($d / 60) . ' min ago';
    if ($d < 86400) return floor($d / 3600) . ' h ago';
    if ($d < 172800) return 'yesterday';
    return floor($d / 86400) . ' days ago';
};

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

<!-- Users & activity -->
<h2 class="ad-h2"><i class="fas fa-users" aria-hidden="true"></i> Users &amp; activity</h2>
<div class="grid-stats ad-kpis">
  <?php foreach ([
    [$totalUsers, 'Total users', 'fa-users', '#3b82f6', ''],
    [$onlineNow, 'Online now', 'fa-circle', '#22c55e', 'Used Trackie in the last 5 minutes'],
    [$newToday, 'New today', 'fa-user-plus', '#a855f7', $new7d . ' this week · ' . $new30d . ' in 30 days'],
    [$dau, 'Active today', 'fa-bolt', '#f59e0b', 'Visited or logged something today'],
    [$wau, 'Active · 7 days', 'fa-calendar-week', '#ef4444', $totalUsers ? round($wau / $totalUsers * 100) . '% of all users' : ''],
    [$mau, 'Active · 30 days', 'fa-calendar', '#06b6d4', $totalUsers ? round($mau / $totalUsers * 100) . '% of all users' : ''],
  ] as [$v, $l, $icon, $color, $hint]): ?>
    <div class="stat-card" title="<?= h($hint) ?>">
      <div class="ad-kpi-top"><i class="fas <?= $icon ?>" style="color:<?= $color ?>"></i><span class="stat-label"><?= $l ?></span></div>
      <div class="stat-val"><?= number_format($v) ?></div>
      <?php if ($hint): ?><div class="ad-kpi-hint"><?= h($hint) ?></div><?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<div class="grid-2" style="margin:1rem 0 1.25rem">
  <div class="card card-body">
    <div class="ad-card-title">Website vs app <span class="form-hint" style="margin:0">— users active in the last 30 days</span></div>
    <?php $splitTotal = max(1, array_sum($split)); ?>
    <div class="ad-split" role="img" aria-label="Website only <?= $split['web'] ?>, app only <?= $split['app'] ?>, both <?= $split['both'] ?>">
      <?php foreach (['web' => '#3b82f6', 'both' => '#a855f7', 'app' => '#22c55e'] as $k => $c): ?>
        <?php if ($split[$k]): ?><span style="flex:<?= $split[$k] ?>;background:<?= $c ?>"></span><?php endif; ?>
      <?php endforeach; ?>
    </div>
    <?php foreach ([['web', 'Website only', '#3b82f6', 'fa-globe'], ['app', 'Android app only', '#22c55e', 'fa-mobile-screen'], ['both', 'Both', '#a855f7', 'fa-repeat']] as [$k, $l, $c, $i]): ?>
      <div class="ad-row"><span><i class="fas <?= $i ?>" style="color:<?= $c ?>"></i> <?= $l ?></span>
        <strong><?= number_format($split[$k]) ?> <small><?= round($split[$k] / $splitTotal * 100) ?>%</small></strong></div>
    <?php endforeach; ?>
    <div class="ad-row"><span><i class="fab fa-android" style="color:#22c55e"></i> App installed (signed in)</span><strong><?= number_format($appInstalls) ?></strong></div>
    <p class="form-hint" style="margin-top:.5rem">
      <?php if (!$hasPresence): ?>Run migration <code>2026-10-07_user_presence.sql</code> to start tracking website vs app use.
      <?php elseif ($trackingSince): ?>Visit tracking started <?= h(formatDate($trackingSince)) ?>; earlier days show sign-ups and logged activity only.
      <?php else: ?>Visit tracking is on — numbers appear as people use Trackie.<?php endif; ?>
    </p>
  </div>
  <div class="card card-body">
    <div class="ad-card-title">Daily active users · 30 days</div>
    <div class="ad-chart" role="img" aria-label="Daily active users for the last 30 days">
      <?php foreach ($daily as $d => $v): $t = $v['web'] + $v['app'] + $v['both']; ?>
        <div class="ad-bar" title="<?= h(formatDate($d, 'M j')) ?>: <?= $t ?> active (web <?= $v['web'] ?>, app <?= $v['app'] ?>, both <?= $v['both'] ?>)<?= $v['new'] ? ' · ' . $v['new'] . ' new' : '' ?>">
          <span style="height:<?= round($v['app'] / $dailyMax * 100, 1) ?>%;background:#22c55e"></span>
          <span style="height:<?= round($v['both'] / $dailyMax * 100, 1) ?>%;background:#a855f7"></span>
          <span style="height:<?= round($v['web'] / $dailyMax * 100, 1) ?>%;background:#3b82f6"></span>
          <?php if ($v['new']): ?><i class="ad-new" title="<?= $v['new'] ?> new"><?= $v['new'] ?></i><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="ad-chart-axis"><span><?= h(formatDate(array_key_first($daily), 'M j')) ?></span><span>today</span></div>
    <div class="ad-legend"><span><i style="background:#3b82f6"></i>Website</span><span><i style="background:#22c55e"></i>App</span><span><i style="background:#a855f7"></i>Both</span><span><i class="ad-new-dot"></i>New sign-ups</span></div>
  </div>
</div>

<div class="ad-users-head">
  <div class="ad-card-title" style="margin:0">Users <span class="form-hint" style="margin:0" id="adCount"><?= count($users) ?></span></div>
  <div class="ad-filters" role="group" aria-label="Filter users">
    <?php foreach (['' => 'All', 'online' => 'Online', 'active' => 'Active 7d', 'new' => 'New 7d', 'app' => 'App', 'web' => 'Website', 'inactive' => 'Inactive 30d+'] as $k => $l): ?>
      <button type="button" class="filter-tab<?= $k === '' ? ' active' : '' ?>" data-seg="<?= $k ?>"><?= $l ?></button>
    <?php endforeach; ?>
  </div>
  <input type="search" class="form-input ad-search" id="adSearch" placeholder="Search name or email…" aria-label="Search users">
</div>
<div class="table-wrap" style="margin-bottom:1.5rem">
  <table class="data-table ad-table">
    <thead>
      <tr><th>User</th><th>Last seen</th><th>Uses</th><th title="Days active in the last 30">Days · 30d</th><th>Phone app</th><th>Joined</th><th>Data</th><th>XP</th><th>Role</th><th></th></tr>
    </thead>
    <tbody id="adUsers">
      <?php foreach ($users as $u): ?>
        <tr id="user-<?= $u['id'] ?>" data-seg="<?= h($u['segment']) ?>" data-q="<?= h(strtolower($u['name'] . ' ' . $u['email'])) ?>">
          <td>
            <div class="ad-user">
              <span class="ad-avatar"><?= h(mb_strtoupper(mb_substr($u['name'], 0, 1))) ?><?php if ($u['online']): ?><i class="ad-dot" title="Online now"></i><?php endif; ?></span>
              <div style="min-width:0"><div class="ad-name"><?= h($u['name']) ?> <small>#<?= (int)$u['id'] ?></small></div>
                <div class="ad-email"><?= h($u['email']) ?>
                  <?php if ($u['google']): ?><i class="fab fa-google" title="Signs in with Google"></i><?php endif; ?>
                  <?php if (!$u['has_pw']): ?><span class="badge badge-gray" title="No password — Google only">no password</span><?php endif; ?></div></div>
            </div>
          </td>
          <td class="ad-nowrap" title="<?= h($u['last_seen'] ?? '') ?>">
            <?php if ($u['online']): ?><span class="ad-online">● online</span><?php else: ?><?= h($ago($u['last_seen'])) ?><?php endif; ?>
            <?php if ($u['last_client']): ?><small class="ad-sub">via <?= $u['last_client'] === 'app' ? 'app' : 'website' ?></small><?php endif; ?>
          </td>
          <td>
            <?php if ($u['uses'] === 'both'): ?><span class="badge badge-purple" title="<?= $u['web_days'] ?> website days, <?= $u['app_days'] ?> app days">Web + App</span>
            <?php elseif ($u['uses'] === 'app'): ?><span class="badge badge-green"><i class="fab fa-android"></i> App</span>
            <?php elseif ($u['uses'] === 'web'): ?><span class="badge badge-blue"><i class="fas fa-globe"></i> Website</span>
            <?php else: ?><span class="ad-sub">—</span><?php endif; ?>
          </td>
          <td><?= $u['days30'] ?: '<span class="ad-sub">0</span>' ?></td>
          <td class="ad-nowrap">
            <?php if (!$u['devices']): ?><span class="ad-sub">—</span>
            <?php else: foreach (array_slice($u['devices'], 0, 2) as $d): ?>
              <div class="ad-device" title="Installed <?= h(formatDate($d['created_at'])) ?> · background sync <?= h($ago($d['last_seen_at'])) ?>">
                <i class="fab fa-android"></i> <?= h($d['device_name'] ?: 'Android') ?> <small><?= h(preg_replace('/\s*\(.*$/', '', (string)$d['app_version']) ?: '') ?></small></div>
            <?php endforeach; if (count($u['devices']) > 2): ?><small class="ad-sub">+<?= count($u['devices']) - 2 ?> more</small><?php endif; endif; ?>
          </td>
          <td class="ad-nowrap ad-sub"><?= formatDate($u['created_at'], 'M j, Y') ?></td>
          <td class="ad-nowrap ad-sub" title="Todos · habits · goals"><?= (int)$u['todos'] ?> · <?= (int)$u['habits'] ?> · <?= (int)$u['goals'] ?></td>
          <td><?= number_format((int)$u['xp']) ?></td>
          <td><span class="badge <?= $u['is_admin'] ? 'badge-red' : 'badge-gray' ?>" id="role-<?= $u['id'] ?>"><?= $u['is_admin'] ? 'Admin' : 'User' ?></span></td>
          <td>
            <?php if ((int)$u['id'] !== $uid): ?>
              <button class="btn btn-ghost btn-sm" onclick="toggleAdmin(<?= $u['id'] ?>)"><?= $u['is_admin'] ? 'Revoke' : 'Make admin' ?></button>
            <?php else: ?><span style="font-size:.75rem;color:var(--subtle)">you</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="card card-body hb-empty-line hidden" id="adNone">No users match this filter.</div>
</div>

<h2 class="ad-h2"><i class="fas fa-chart-simple" aria-hidden="true"></i> Platform</h2>
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

<!-- Health -->
<div style="margin:1.25rem 0">
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
/* ── Users: segment filter + search ─────────────────────────── */
(function adminUsers() {
  const rows = [...document.querySelectorAll('#adUsers tr')];
  let seg = '';
  function apply() {
    const q = document.getElementById('adSearch').value.trim().toLowerCase();
    let n = 0;
    rows.forEach(r => { const ok = (!seg || r.dataset.seg.split(' ').includes(seg)) && (!q || r.dataset.q.includes(q)); r.hidden = !ok; if (ok) n++; });
    document.getElementById('adCount').textContent = n === rows.length ? rows.length : `${n} of ${rows.length}`;
    document.getElementById('adNone').classList.toggle('hidden', n > 0);
  }
  document.querySelectorAll('[data-seg]').forEach(b => { if (b.tagName !== 'BUTTON') return;
    b.addEventListener('click', () => { seg = b.dataset.seg; document.querySelectorAll('button[data-seg]').forEach(x => x.classList.toggle('active', x === b)); apply(); }); });
  document.getElementById('adSearch').addEventListener('input', apply);
})();

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
