<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Projects';
$currentPage = 'projects';

if (!tableExists('projects')) renderSetupNeeded('Projects');

$projects = fetchAll(
    "SELECT p.*,
            COUNT(t.id) AS total_tasks,
            SUM(t.status='done') AS done_tasks
     FROM projects p
     LEFT JOIN project_tasks t ON t.project_id = p.id
     WHERE p.user_id=? GROUP BY p.id ORDER BY p.created_at DESC",
    [$uid]
);

// Coding activity + synced GitHub data (read from Trackie's DB, never live).
require_once '../includes/activity.php';
require_once '../includes/providers.php';
$codeStreak = activityReady() ? activityStreak($uid, 'coding') : ['current' => 0, 'best' => 0];
$hasSessions = tableExists('coding_sessions');
$sessions = $hasSessions ? fetchAll(
    "SELECT s.*, p.name project_name FROM coding_sessions s LEFT JOIN projects p ON p.id=s.project_id
     WHERE s.user_id=? ORDER BY s.session_date DESC, s.id DESC LIMIT 50", [$uid]) : [];
$weekMin = $hasSessions ? (int)fetchOne("SELECT COALESCE(SUM(minutes),0) m FROM coding_sessions WHERE user_id=? AND session_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)", [$uid])['m'] : 0;
$langMin = $hasSessions ? fetchAll("SELECT language, SUM(minutes) m FROM coding_sessions WHERE user_id=? AND language IS NOT NULL GROUP BY language ORDER BY m DESC LIMIT 6", [$uid]) : [];
$gh = provider('github');
$ghConfigured = $gh && $gh->isConfigured();
$ghConnected = $ghConfigured && $gh->isConnected($uid);
$ghStatus    = $ghConnected ? $gh->status($uid) : null;
$ghStale     = $ghConnected && $gh->isStale($uid, 6);
$ghPrivate   = $ghConnected && $gh->hasPrivateAccess($uid);
$ghProfile   = $ghConnected ? (syncedData($uid, 'github', 'profile', 1)[0]['data'] ?? null) : null;
$repos  = $ghConnected ? syncedData($uid, 'github', 'repo', 500) : [];
$pushes = $ghConnected ? syncedData($uid, 'github', 'push', 60) : [];
$ghEvents = $ghConnected ? syncedData($uid, 'github', 'event', 30) : [];
usort($repos, static fn($a, $b) => strcmp($b['data']['pushed_at'] ?? '', $a['data']['pushed_at'] ?? ''));
$repoLangs = [];
foreach ($repos as $r) if (!empty($r['data']['language'])) $repoLangs[$r['data']['language']] = ($repoLangs[$r['data']['language']] ?? 0) + 1;
arsort($repoLangs);
$commits7 = 0;
foreach ($pushes as $p) if ($p['occurred_at'] && strtotime($p['occurred_at']) >= strtotime('-7 days')) $commits7 += (int)($p['data']['commits'] ?? 0);

// Repo <-> project links (by URL), both directions.
$normUrl = static fn(?string $u) => strtolower(rtrim((string)$u, '/'));
$repoByUrl = [];
foreach ($repos as $r) if (!empty($r['data']['html_url'])) $repoByUrl[$normUrl($r['data']['html_url'])] = $r['data'];
$trackedUrls = [];
foreach ($projects as $pr) if ($pr['github_url']) $trackedUrls[$normUrl($pr['github_url'])] = (int)$pr['id'];
$ghActivity = ['active' => 0, 'recent' => 0, 'dormant' => 0, 'archived' => 0];
foreach ($repos as $r) $ghActivity[TrackieGithubProvider::activity($r['data'])]++;
$activityMeta = [
    'active'   => ['Active',   'badge-green',  'Pushed in the last 14 days'],
    'recent'   => ['Recent',   'badge-blue',   'Pushed in the last 90 days'],
    'dormant'  => ['Dormant',  'badge-gray',   'No pushes for 90+ days'],
    'archived' => ['Archived', 'badge-yellow', 'Read-only on GitHub'],
];
$ago = static function (?string $iso): string {
    if (!$iso) return '—';
    $d = (int)floor((time() - strtotime($iso)) / 86400);
    return $d <= 0 ? 'today' : ($d === 1 ? 'yesterday' : ($d < 30 ? "{$d} days ago" : ($d < 365 ? floor($d / 30) . ' mo ago' : floor($d / 365) . ' yr ago')));
};
// Activity feed: pushes + PRs/issues/releases, newest first.
$feed = array_merge(
    array_map(static fn($p) => ['at' => $p['occurred_at'], 'kind' => 'push'] + $p['data'], $pushes),
    array_map(static fn($e) => ['at' => $e['occurred_at'], 'kind' => 'event'] + $e['data'], $ghEvents)
);
usort($feed, static fn($a, $b) => strcmp((string)$b['at'], (string)$a['at']));
$initialTab = in_array($_GET['tab'] ?? '', ['projects', 'sessions', 'github'], true) ? $_GET['tab'] : 'projects';
$fmtMin = static fn(int $m) => $m >= 60 ? intdiv($m, 60) . 'h' . ($m % 60 ? ' ' . ($m % 60) . 'm' : '') : $m . 'm';

$statusMeta = [
    'planning' => ['label' => 'Planning', 'badge' => 'badge-gray',   'icon' => 'fa-lightbulb'],
    'active'   => ['label' => 'Active',   'badge' => 'badge-blue',   'icon' => 'fa-bolt'],
    'paused'   => ['label' => 'Paused',   'badge' => 'badge-yellow', 'icon' => 'fa-pause'],
    'done'     => ['label' => 'Done',     'badge' => 'badge-green',  'icon' => 'fa-check'],
];

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<div class="rd-head">
  <h1><i class="fas fa-code" style="color:var(--accent)"></i> Coding</h1>
  <div class="rd-head-actions">
    <button class="btn btn-secondary btn-sm" onclick="openCodeSession()"><i class="fas fa-stopwatch"></i> Log session</button>
    <button class="btn btn-primary btn-sm" onclick="openAddProject()"><i class="fas fa-plus"></i> New project</button>
  </div>
</div>
<div class="grid-stats" style="margin-bottom:1.25rem">
  <div class="stat-card"><div class="stat-val">🔥 <?= (int)$codeStreak['current'] ?></div><div class="stat-label">Coding streak<?= $codeStreak['best'] > $codeStreak['current'] ? ' · best ' . (int)$codeStreak['best'] : '' ?></div></div>
  <div class="stat-card"><div class="stat-val"><?= $fmtMin($weekMin) ?></div><div class="stat-label">Coded this week</div></div>
  <div class="stat-card"><div class="stat-val"><?= count(array_filter($projects, fn($p) => $p['status'] === 'active')) ?></div><div class="stat-label">Active projects</div></div>
  <div class="stat-card"><div class="stat-val"><?= $ghConnected ? $commits7 : '—' ?></div><div class="stat-label"><?= $ghConnected ? 'Commits pushed · 7 days' : 'GitHub not connected' ?></div></div>
</div>
<div class="filter-tabs" style="margin-bottom:1.25rem" id="codeTabs" role="tablist">
  <?php foreach (['projects' => 'Projects', 'sessions' => 'Sessions', 'github' => '<i class="fab fa-github"></i> GitHub' . ($repos ? ' <span class="cd-count">' . count($repos) . '</span>' : '')] as $t => $label): ?>
    <button class="filter-tab<?= $initialTab === $t ? ' active' : '' ?>" data-tab="<?= $t ?>" role="tab" aria-selected="<?= $initialTab === $t ? 'true' : 'false' ?>"<?= $initialTab === $t ? '' : ' tabindex="-1"' ?>><?= $label ?></button>
  <?php endforeach; ?>
</div>
<div id="ctab-projects"<?= $initialTab === 'projects' ? '' : ' class="hidden"' ?>>

<div id="projectsListWrap">
<?php if (empty($projects)): ?>
  <div class="card">
    <div class="empty-state">
      <div class="empty-state-icon"><i class="fas fa-diagram-project"></i></div>
      <div class="empty-state-title">No projects yet</div>
      <p>Track what you're building — link a GitHub repo and break it into tasks.</p>
      <button class="btn btn-primary" style="margin-top:.75rem" onclick="openAddProject()"><i class="fas fa-plus"></i> Add your first project</button>
    </div>
  </div>
<?php else: ?>
  <div class="grid-cards-lg">
    <?php foreach ($projects as $p): $sm = $statusMeta[$p['status']]; $pct = $p['total_tasks'] > 0 ? round($p['done_tasks'] / $p['total_tasks'] * 100) : 0; ?>
      <div class="habit-card" id="project-<?= $p['id'] ?>">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:.625rem">
          <div style="min-width:0">
            <div style="font-weight:600;font-size:.9375rem;color:var(--text)"><?= h($p['name']) ?></div>
            <?php if ($p['description']): ?><div style="font-size:.8125rem;color:var(--muted);margin-top:.125rem"><?= h($p['description']) ?></div><?php endif; ?>
          </div>
          <span style="display:flex;flex-shrink:0">
          <button aria-label="Edit project" class="btn btn-icon btn-ghost btn-sm" onclick="openEditProject(<?= $p['id'] ?>)" title="Edit"><i class="fas fa-pen"></i></button>
          <button aria-label="Delete project" class="btn btn-icon btn-ghost btn-sm" style="color:var(--accent);flex-shrink:0" onclick="deleteProject(<?= $p['id'] ?>)" title="Delete">
            <i class="fas fa-trash"></i>
          </button>
          </span>
        </div>

        <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:.75rem">
          <select class="form-input" style="width:auto;font-size:.75rem;padding:.25rem .5rem" onchange="setProjectStatus(<?= $p['id'] ?>, this.value)">
            <?php foreach ($statusMeta as $k => $m): ?>
              <option value="<?= $k ?>" <?= $p['status']===$k?'selected':'' ?>><?= $m['label'] ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($p['github_url']): ?>
            <a href="<?= h($p['github_url']) ?>" target="_blank" rel="noopener" class="btn btn-ghost btn-sm" style="font-size:.75rem">
              <i class="fab fa-github"></i> Repo
            </a>
          <?php endif; ?>
        </div>
        <?php if ($p['github_url'] && ($lr = $repoByUrl[$normUrl($p['github_url'])] ?? null)): $la = TrackieGithubProvider::activity($lr); ?>
          <div class="cd-linked">
            <span class="badge <?= $activityMeta[$la][1] ?>"><?= $activityMeta[$la][0] ?></span>
            <span title="Last push"><i class="fas fa-code-commit"></i> <?= h($ago($lr['pushed_at'] ?? null)) ?></span>
            <?php if (!empty($lr['language'])): ?><span><?= h($lr['language']) ?></span><?php endif; ?>
            <span title="Stars">★ <?= (int)($lr['stars'] ?? 0) ?></span>
            <?php if ((int)($lr['open_issues'] ?? 0) > 0): ?><span title="Open issues + pull requests"><i class="far fa-circle-dot"></i> <?= (int)$lr['open_issues'] ?></span><?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($p['total_tasks'] > 0): ?>
          <div style="margin-bottom:.75rem">
            <div style="display:flex;justify-content:space-between;font-size:.75rem;margin-bottom:.25rem;color:var(--muted)">
              <span><?= (int)$p['done_tasks'] ?>/<?= (int)$p['total_tasks'] ?> tasks</span><span><?= $pct ?>%</span>
            </div>
            <div class="progress-track"><div class="progress-fill <?= $pct>=100?'green':'' ?>" style="width:<?= $pct ?>%"></div></div>
          </div>
        <?php endif; ?>

        <button class="btn btn-ghost btn-sm" style="width:100%;font-size:.75rem" onclick="toggleTasks(<?= $p['id'] ?>)">
          <i class="fas fa-list-check"></i> Tasks
        </button>
        <div id="tasks-<?= $p['id'] ?>" class="hidden" style="margin-top:.625rem"></div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
</div>

</div>

<div id="ctab-sessions"<?= $initialTab === 'sessions' ? '' : ' class="hidden"' ?>>
  <?php if ($langMin): ?>
    <div class="hb-chips"><span class="hb-chips-label">Time by language</span>
      <?php foreach ($langMin as $l): ?><span class="hb-chip"><?= h($l['language']) ?> · <?= $fmtMin((int)$l['m']) ?></span><?php endforeach; ?></div>
  <?php endif; ?>
  <?php if (!$sessions): ?>
    <div class="card card-body hb-empty-line">No coding sessions yet. Log time spent coding — on a project or just practice.</div>
  <?php else: ?>
    <div class="card">
      <?php foreach ($sessions as $s): ?>
        <div class="todo-row" id="csess-<?= (int)$s['id'] ?>">
          <div style="flex:1;min-width:0">
            <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
              <span class="todo-title"><?= $fmtMin((int)$s['minutes']) ?></span>
              <?php if ($s['language']): ?><span class="category-badge"><?= h($s['language']) ?></span><?php endif; ?>
              <?php if ($s['project_name']): ?><span class="badge badge-blue"><?= h($s['project_name']) ?></span><?php endif; ?>
            </div>
            <div class="todo-meta"><span><?= h(formatDate($s['session_date'])) ?></span><?php if ($s['notes']): ?><span><?= h($s['notes']) ?></span><?php endif; ?></div>
          </div>
          <button class="btn btn-icon btn-ghost btn-sm" style="color:var(--accent)" onclick="deleteCodeSession(<?= (int)$s['id'] ?>)" aria-label="Delete session"><i class="fas fa-trash"></i></button>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div id="ctab-github"<?= $initialTab === 'github' ? '' : ' class="hidden"' ?>>
  <?php if (!$ghConfigured): ?>
    <div class="card card-body hb-empty-line">GitHub isn't set up on this server yet (GITHUB_CLIENT_ID / GITHUB_CLIENT_SECRET in config/env.php).</div>
  <?php elseif (!$ghConnected): ?>
    <div class="card"><div class="empty-state"><div class="empty-state-icon"><i class="fab fa-github"></i></div>
      <div class="empty-state-title">Connect GitHub</div>
      <p>Trackie finds every repository you can access — yours, collaborations and organisations — with languages, stars, issues and recent activity.</p>
      <label class="cd-private"><input type="checkbox" id="ghWantPrivate"> Include private repositories
        <small>GitHub only offers this as read <em>and</em> write access. Trackie only ever reads.</small></label>
      <a class="btn btn-primary" style="margin-top:.75rem" id="ghConnectBtn" data-no-spa href="<?= APP_BASE ?>/pages/github_callback.php?from=projects"><i class="fab fa-github"></i> Connect GitHub</a></div></div>
  <?php else: ?>
    <div class="card card-body cd-gh-head">
      <?php if (!empty($ghProfile['avatar']) && preg_match('#^https://#', $ghProfile['avatar'])): ?><img src="<?= h($ghProfile['avatar']) ?>" alt="" width="44" height="44" class="cd-avatar"><?php endif; ?>
      <div style="flex:1;min-width:0">
        <div class="cd-gh-name"><?= h($ghProfile['name'] ?? $ghProfile['login'] ?? 'GitHub') ?>
          <?php if (!empty($ghProfile['login'])): ?><a href="https://github.com/<?= h(rawurlencode($ghProfile['login'])) ?>" target="_blank" rel="noopener" class="cd-login">@<?= h($ghProfile['login']) ?></a><?php endif; ?></div>
        <div class="cd-gh-sub" id="ghSyncLine">
          <?= count($repos) ?> repositories · <?= $ghPrivate ? 'public + private' : 'public only' ?>
          · <?= $ghStatus['lastSync'] ? 'synced ' . h($ago(date('c', strtotime($ghStatus['lastSync'])))) : 'never synced' ?>
        </div>
        <?php if ($ghStatus['syncStatus'] === 'error' && $ghStatus['lastError']): ?><div class="cd-gh-err"><i class="fas fa-triangle-exclamation"></i> <?= h($ghStatus['lastError']) ?></div><?php endif; ?>
      </div>
      <div class="cd-gh-actions">
        <?php if (!$ghPrivate): ?><a class="btn btn-ghost btn-sm" data-no-spa href="<?= APP_BASE ?>/pages/github_callback.php?from=projects&amp;private=1" title="Reconnect with access to private repositories (GitHub grants read + write; Trackie only reads)"><i class="fas fa-lock"></i> Add private repos</a><?php endif; ?>
        <button class="btn btn-secondary btn-sm" id="ghSyncBtn" onclick="ghSync(false)"><i class="fas fa-rotate"></i> Sync now</button>
      </div>
    </div>

    <?php if (!$repos): ?>
      <div class="card card-body hb-empty-line" id="ghEmpty"><?= $ghStatus['syncStatus'] === 'error' ? 'The last sync failed — see the message above, then try "Sync now".' : '<i class="fas fa-spinner fa-spin"></i> Fetching your repositories…' ?></div>
    <?php else: ?>
      <div class="grid-stats cd-gh-stats">
        <?php foreach ($activityMeta as $k => [$label, $badge, $hint]): ?>
          <button type="button" class="stat-card cd-stat" data-filter-activity="<?= $k ?>" title="<?= h($hint) ?>"><div class="stat-val"><?= (int)$ghActivity[$k] ?></div><div class="stat-label"><?= $label ?></div></button>
        <?php endforeach; ?>
      </div>
      <?php if ($repoLangs): ?>
        <div class="hb-chips"><span class="hb-chips-label">Languages</span>
          <?php foreach (array_slice($repoLangs, 0, 10, true) as $l => $n): ?><button type="button" class="hb-chip cd-lang" data-lang="<?= h($l) ?>"><?= h($l) ?> · <?= (int)$n ?></button><?php endforeach; ?></div>
      <?php endif; ?>

      <div class="cd-filters">
        <input type="search" class="form-input" id="ghSearch" placeholder="Search repositories, topics, descriptions…" aria-label="Search repositories">
        <select class="form-input" id="ghActivity" aria-label="Activity">
          <option value="">All activity</option><?php foreach ($activityMeta as $k => [$label]): ?><option value="<?= $k ?>"><?= $label ?></option><?php endforeach; ?>
        </select>
        <select class="form-input" id="ghOwner" aria-label="Owner">
          <option value="">All owners</option><option value="mine">Mine</option><option value="shared">Collaborations &amp; orgs</option><option value="fork">Forks</option><option value="tracked">Tracked as projects</option>
        </select>
        <select class="form-input" id="ghSort" aria-label="Sort">
          <option value="pushed">Last push</option><option value="stars">Stars</option><option value="issues">Open issues</option><option value="name">Name</option>
        </select>
      </div>

      <div class="cd-repos" id="ghRepos">
        <?php foreach ($repos as $r): $d = $r['data']; $act = TrackieGithubProvider::activity($d);
              $url = preg_match('#^https://github\.com/#', $d['html_url'] ?? '') ? $d['html_url'] : null;
              $tracked = $url ? ($trackedUrls[$normUrl($url)] ?? null) : null;
              $vis = $d['visibility'] ?? (!empty($d['private']) ? 'private' : 'public');
              $search = strtolower(implode(' ', [$d['full_name'] ?? '', $d['description'] ?? '', implode(' ', $d['topics'] ?? []), $d['language'] ?? ''])); ?>
          <article class="card cd-repo" data-activity="<?= $act ?>" data-mine="<?= !empty($d['mine']) || !isset($d['mine']) ? 1 : 0 ?>" data-fork="<?= !empty($d['fork']) ? 1 : 0 ?>"
                   data-tracked="<?= $tracked ? 1 : 0 ?>" data-lang="<?= h($d['language'] ?? '') ?>" data-search="<?= h($search) ?>"
                   data-pushed="<?= h($d['pushed_at'] ?? '') ?>" data-stars="<?= (int)($d['stars'] ?? 0) ?>" data-issues="<?= (int)($d['open_issues'] ?? 0) ?>" data-name="<?= h(strtolower($d['name'] ?? '')) ?>">
            <div class="cd-repo-top">
              <div style="min-width:0">
                <a class="cd-repo-name" href="<?= h($url ?? '#') ?>" target="_blank" rel="noopener"><?= isset($d['mine']) && empty($d['mine']) ? '<span class="cd-owner">' . h($d['owner'] ?? '') . '/</span>' : '' ?><?= h($d['name'] ?? '') ?></a>
                <div class="cd-badges">
                  <span class="badge <?= $activityMeta[$act][1] ?>" title="<?= h($activityMeta[$act][2]) ?>"><?= $activityMeta[$act][0] ?></span>
                  <span class="badge <?= $vis === 'public' ? 'badge-gray' : 'badge-purple' ?>"><i class="fas <?= $vis === 'public' ? 'fa-globe' : 'fa-lock' ?>"></i> <?= h(ucfirst($vis)) ?></span>
                  <?php if (!empty($d['fork'])): ?><span class="badge badge-gray"><i class="fas fa-code-fork"></i> Fork</span><?php endif; ?>
                  <?php if (!empty($d['is_template'])): ?><span class="badge badge-gray">Template</span><?php endif; ?>
                  <?php if (($d['owner_type'] ?? '') === 'Organization'): ?><span class="badge badge-blue"><i class="fas fa-building"></i> Org</span><?php endif; ?>
                </div>
              </div>
              <?php if ($tracked): ?>
                <a class="btn btn-ghost btn-sm cd-track" href="?tab=projects#project-<?= (int)$tracked ?>" title="Open the project"><i class="fas fa-check"></i> Tracked</a>
              <?php elseif ($url): ?>
                <button class="btn btn-secondary btn-sm cd-track" onclick="ghTrack('<?= h($r['external_id']) ?>', this)" title="Create a Trackie project for this repo (tasks, sessions, status)"><i class="fas fa-plus"></i> Track</button>
              <?php endif; ?>
            </div>
            <?php if (!empty($d['description'])): ?><p class="cd-desc"><?= h($d['description']) ?></p><?php endif; ?>
            <?php if (!empty($d['topics'])): ?><div class="cd-topics"><?php foreach ($d['topics'] as $t): ?><span class="gm-tag"><?= h($t) ?></span><?php endforeach; ?></div><?php endif; ?>
            <div class="cd-meta">
              <?php if (!empty($d['language'])): ?><span><i class="fas fa-circle cd-lang-dot" style="--h:<?= abs(crc32($d['language'])) % 360 ?>"></i><?= h($d['language']) ?></span><?php endif; ?>
              <span title="Stars">★ <?= (int)($d['stars'] ?? 0) ?></span>
              <span title="Forks"><i class="fas fa-code-fork"></i> <?= (int)($d['forks'] ?? 0) ?></span>
              <?php if ((int)($d['open_issues'] ?? 0) > 0): ?><span title="Open issues + pull requests"><i class="far fa-circle-dot"></i> <?= (int)$d['open_issues'] ?></span><?php endif; ?>
              <span title="Last push<?= !empty($d['default_branch']) ? ' · default branch ' . h($d['default_branch']) : '' ?>"><i class="fas fa-code-commit"></i> <?= h($ago($d['pushed_at'] ?? null)) ?></span>
              <?php if (!empty($d['homepage']) && preg_match('#^https?://#', $d['homepage'])): ?><a href="<?= h($d['homepage']) ?>" target="_blank" rel="noopener" title="Homepage"><i class="fas fa-arrow-up-right-from-square"></i> Site</a><?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
      <div class="card card-body hb-empty-line hidden" id="ghNoMatch">No repositories match these filters.</div>

      <div class="fit-section-head" style="margin-top:1.5rem"><h2 class="hb-h2">Recent activity</h2></div>
      <div class="card card-body">
        <?php if (!$feed): ?><p class="hb-empty-line">No recent activity on GitHub.</p><?php endif; ?>
        <?php foreach (array_slice($feed, 0, 15) as $f): ?>
          <div class="hb-row cd-feed">
            <?php if ($f['kind'] === 'push'): ?>
              <i class="fas fa-code-commit cd-feed-ic"></i>
              <span style="flex:1;min-width:0">Pushed <b><?= (int)($f['commits'] ?? 0) ?> commit<?= (int)($f['commits'] ?? 0) === 1 ? '' : 's' ?></b> to <?= h($f['repo'] ?? '') ?><?= !empty($f['branch']) ? ' <small class="rd-author">(' . h($f['branch']) . ')</small>' : '' ?>
                <?php if (!empty($f['messages'][0])): ?><small class="rd-author" style="display:block"><?= h(mb_strimwidth((string)$f['messages'][0], 0, 90, '…')) ?></small><?php endif; ?></span>
            <?php else: $icon = ['PullRequestEvent' => 'fa-code-pull-request', 'IssuesEvent' => 'fa-circle-dot', 'ReleaseEvent' => 'fa-tag', 'CreateEvent' => 'fa-plus'][$f['type']] ?? 'fa-bolt';
                  $verb = $f['type'] === 'PullRequestEvent' && !empty($f['merged']) ? 'merged' : ($f['action'] ?? ''); ?>
              <i class="fas <?= $icon ?> cd-feed-ic"></i>
              <span style="flex:1;min-width:0"><?= h(ucfirst((string)$verb)) ?> <?= h(['PullRequestEvent' => 'pull request', 'IssuesEvent' => 'issue', 'ReleaseEvent' => 'release', 'CreateEvent' => ''][$f['type']] ?? '') ?>
                <?php if (!empty($f['url']) && preg_match('#^https://github\.com/#', $f['url'])): ?><a href="<?= h($f['url']) ?>" target="_blank" rel="noopener"><?= h($f['title'] ?? '') ?></a><?php else: ?><?= h($f['title'] ?? '') ?><?php endif; ?>
                <small class="rd-author" style="display:block"><?= h($f['repo'] ?? '') ?></small></span>
            <?php endif; ?>
            <small class="rd-author"><?= h($ago($f['at'] ? date('c', strtotime($f['at'])) : null)) ?></small>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<!-- Coding session modal -->
<div id="codeSessionModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header"><span class="modal-title">Log coding session</span><button class="btn btn-icon btn-ghost btn-sm" data-close-modal="codeSessionModal" aria-label="Close dialog">&times;</button></div>
    <div class="modal-body">
      <div class="rd-form-row">
        <div class="form-group"><label for="csMin" class="form-label">Minutes <span style="color:var(--accent)">*</span></label><input id="csMin" type="number" min="1" max="1440" class="form-input" value="45"></div>
        <div class="form-group"><label for="csDate" class="form-label">Date</label><input id="csDate" type="date" class="form-input" max="<?= date('Y-m-d') ?>"></div>
      </div>
      <div class="rd-form-row">
        <div class="form-group"><label for="csLang" class="form-label">Language</label>
          <input id="csLang" class="form-input" maxlength="40" list="csLangs" placeholder="e.g. PHP, Python">
          <datalist id="csLangs"><?php foreach (array_unique(array_merge(array_column($langMin, 'language'), array_keys($repoLangs))) as $l): ?><option value="<?= h($l) ?>"><?php endforeach; ?></datalist></div>
        <div class="form-group"><label for="csProject" class="form-label">Project</label>
          <select id="csProject" class="form-input"><option value="">None</option><?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h($p['name']) ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="form-group"><label for="csNotes" class="form-label">What did you work on?</label><textarea id="csNotes" class="form-input" rows="2" maxlength="500"></textarea></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary btn-sm" data-close-modal="codeSessionModal">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="saveCodeSession()"><i class="fas fa-save"></i> Save</button>
    </div>
  </div>
</div>

<!-- Add project modal -->
<div id="addProjectModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header">
      <input type="hidden" id="projId"><span class="modal-title" id="projModalTitle">New Project</span>
      <button class="btn btn-icon btn-ghost btn-sm" data-close-modal="addProjectModal" aria-label="Close dialog">&times;</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label for="projName" class="form-label">Project name <span style="color:var(--accent)">*</span></label>
        <input id="projName" class="form-input" placeholder="e.g. Trackie v3">
      </div>
      <div class="form-group">
        <label for="projDesc" class="form-label">Description</label>
        <input id="projDesc" class="form-input" placeholder="One-line summary (optional)">
      </div>
      <div class="form-group">
        <label for="projGithub" class="form-label"><i class="fab fa-github"></i> GitHub repo URL</label>
        <input id="projGithub" class="form-input" placeholder="https://github.com/you/repo (optional)">
      </div>
      <div class="form-group">
        <label for="projStatus" class="form-label">Status</label>
        <select id="projStatus" class="form-input">
          <?php foreach ($statusMeta as $k => $m): ?><option value="<?= $k ?>"><?= $m['label'] ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary btn-sm" data-close-modal="addProjectModal">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="saveProject()"><i class="fas fa-save"></i> Create</button>
    </div>
  </div>
</div>

</div>
<?php include '../includes/footer.php'; ?>

<script>
const API_BASE = '<?= APP_BASE ?>/api';
document.getElementById('codeTabs').addEventListener('click', e => {
  const b = e.target.closest('[data-tab]'); if (!b) return;
  document.querySelectorAll('#codeTabs [data-tab]').forEach(x => { const on = x === b; x.classList.toggle('active', on); x.setAttribute('aria-selected', on); });
  ['projects', 'sessions', 'github'].forEach(t => document.getElementById(`ctab-${t}`).classList.toggle('hidden', t !== b.dataset.tab));
});

/* ── GitHub: filters, track, sync ─────────────────────────────────── */
(function ghFilters() {
  const list = document.getElementById('ghRepos');
  if (!list) return;
  const $ = id => document.getElementById(id);
  const cards = [...list.children];
  function apply() {
    const q = $('ghSearch').value.trim().toLowerCase(), act = $('ghActivity').value, own = $('ghOwner').value, sort = $('ghSort').value;
    const lang = list.dataset.lang || '';
    let shown = 0;
    cards.forEach(c => {
      const ok = (!q || c.dataset.search.includes(q)) && (!act || c.dataset.activity === act) && (!lang || c.dataset.lang === lang)
        && (!own || (own === 'mine' && c.dataset.mine === '1') || (own === 'shared' && c.dataset.mine === '0')
                 || (own === 'fork' && c.dataset.fork === '1') || (own === 'tracked' && c.dataset.tracked === '1'));
      c.hidden = !ok; if (ok) shown++;
    });
    const key = { pushed: c => c.dataset.pushed, stars: c => +c.dataset.stars, issues: c => +c.dataset.issues, name: c => c.dataset.name }[sort];
    cards.sort((a, b) => sort === 'name' ? key(a).localeCompare(key(b)) : (key(a) < key(b) ? 1 : key(a) > key(b) ? -1 : 0)).forEach(c => list.appendChild(c));
    $('ghNoMatch').classList.toggle('hidden', shown > 0);
    document.querySelectorAll('[data-filter-activity]').forEach(b => b.classList.toggle('active', b.dataset.filterActivity === act));
    document.querySelectorAll('.cd-lang').forEach(b => b.classList.toggle('active', b.dataset.lang === lang));
  }
  ['ghSearch', 'ghActivity', 'ghOwner', 'ghSort'].forEach(id => $(id).addEventListener(id === 'ghSearch' ? 'input' : 'change', apply));
  document.querySelectorAll('[data-filter-activity]').forEach(b => b.addEventListener('click', () => {
    $('ghActivity').value = $('ghActivity').value === b.dataset.filterActivity ? '' : b.dataset.filterActivity; apply();
  }));
  document.querySelectorAll('.cd-lang').forEach(b => b.addEventListener('click', () => {
    list.dataset.lang = list.dataset.lang === b.dataset.lang ? '' : b.dataset.lang; apply();
  }));
})();
document.getElementById('ghWantPrivate')?.addEventListener('change', e => {
  const a = document.getElementById('ghConnectBtn');
  a.href = a.href.replace(/&private=1$/, '') + (e.target.checked ? '&private=1' : '');
});
async function ghTrack(repoId, btn) {
  try {
    const res = await Trackie.API.post(`${API_BASE}/projects.php`, { action: 'track_repo', repo_id: repoId }, { button: btn });
    if (!res.success) { Trackie.Toast.error(res.error || 'Could not track that repository.'); return; }
    Trackie.Toast.success(res.existing ? 'Already tracked as a project.' : 'Added to your projects.');
    btn.closest('.cd-repo').dataset.tracked = '1';
    btn.outerHTML = `<a class="btn btn-ghost btn-sm cd-track" href="?tab=projects#project-${+res.id}"><i class="fas fa-check"></i> Tracked</a>`;
  } catch (e) { Trackie.Toast.error(e.message || 'Could not track that repository.'); }
}
async function ghSync(quiet) {
  const btn = document.getElementById('ghSyncBtn');
  try {
    const res = await Trackie.API.post(`${API_BASE}/integrations.php`, { action: 'sync', provider: 'github' }, { button: quiet ? null : btn, quiet });
    if (res.success) {
      if (!quiet) Trackie.Toast.success(`GitHub synced — ${res.records} records.`);
      if (Trackie.SpaNav?.refresh) Trackie.SpaNav.refresh(); else location.reload();
    } else if (!quiet) Trackie.Toast.error(res.error || 'GitHub sync failed.');
    else if (res.error) {
      const line = document.getElementById('ghSyncLine');
      if (line) line.insertAdjacentHTML('afterend', `<div class="cd-gh-err"><i class="fas fa-triangle-exclamation"></i> ${escProj(res.error)}</div>`);
      document.getElementById('ghEmpty')?.replaceChildren('The last sync failed — see the message above, then try "Sync now".');
    }
  } catch (e) { if (!quiet) Trackie.Toast.error(e.message || 'GitHub sync failed.'); }
}
<?php if ($ghStale): ?>
// Data older than 6 h (or never synced): refresh in the background — the page
// already shows the cached copy, so nothing waits on GitHub.
ghSync(true);
<?php endif; ?>

function openCodeSession() {
  document.getElementById('csDate').value = '<?= date('Y-m-d') ?>';
  ['csLang', 'csNotes', 'csProject'].forEach(i => document.getElementById(i).value = '');
  Trackie.openModal('codeSessionModal');
}
async function saveCodeSession() {
  try {
    const res = await Trackie.API.post(`${API_BASE}/projects.php`, { action: 'session_log', minutes: document.getElementById('csMin').value,
      session_date: document.getElementById('csDate').value, language: document.getElementById('csLang').value,
      project_id: document.getElementById('csProject').value, notes: document.getElementById('csNotes').value });
    if (res.success) { Trackie.Toast.success('Session logged' + (res.xp?.ok ? ` · +${res.xp.gained} XP` : '')); Trackie.SpaNav.refresh(); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function deleteCodeSession(id) {
  if (!await Trackie.confirmDialog('Delete this session?', { confirmText: 'Delete', danger: true })) return;
  const res = await Trackie.API.post(`${API_BASE}/projects.php`, { action: 'session_delete', session_id: id });
  if (res.success) document.getElementById(`csess-${id}`)?.remove();
}
async function openEditProject(id) {
  const res = await Trackie.API.post(`${API_BASE}/projects.php`, { action: 'get', project_id: id });
  if (!res.success) { Trackie.Toast.error(res.error || 'Not found.'); return; }
  const p = res.project;
  document.getElementById('projId').value = p.id;
  document.getElementById('projName').value = p.name;
  document.getElementById('projDesc').value = p.description || '';
  document.getElementById('projGithub').value = p.github_url || '';
  document.getElementById('projStatus').closest('.form-group').classList.add('hidden');
  document.getElementById('projModalTitle').textContent = 'Edit Project';
  Trackie.openModal('addProjectModal');
}

function openAddProject() {
  document.getElementById('projId').value = '';
  document.getElementById('projModalTitle').textContent = 'New Project';
  document.getElementById('projStatus').closest('.form-group').classList.remove('hidden');
  document.getElementById('projName').value = '';
  document.getElementById('projDesc').value = '';
  document.getElementById('projGithub').value = '';
  document.getElementById('projStatus').value = 'planning';
  Trackie.openModal('addProjectModal');
}
async function saveProject() {
  const name = document.getElementById('projName').value.trim();
  if (!name) { Trackie.Toast.warning('Project name is required.'); return; }
  const id = document.getElementById('projId').value;
  try {
    const res = await Trackie.API.post(`${API_BASE}/projects.php`, { action: id ? 'edit' : 'add', project_id: id, name,
      description: document.getElementById('projDesc').value.trim(), github_url: document.getElementById('projGithub').value.trim(),
      status: document.getElementById('projStatus').value });
    if (res.success) { Trackie.Toast.success(id ? 'Project updated.' : 'Project created!'); Trackie.closeModal('addProjectModal'); Trackie.SpaNav.refresh(); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function deleteProject(id) {
  const ok = await Trackie.confirmDialog('Delete this project and its tasks?', {confirmText:'Delete', danger:true});
  if (!ok) return;
  try {
    const res = await Trackie.API.post(`${API_BASE}/projects.php`, {action:'delete', project_id:id});
    if (res.success) { document.getElementById(`project-${id}`)?.remove(); Trackie.Toast.success('Project deleted.'); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function setProjectStatus(id, status) {
  try {
    const res = await Trackie.API.post(`${API_BASE}/projects.php`, {action:'update_status', project_id:id, status});
    if (res.success) Trackie.Toast.success('Status updated.');
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}

async function toggleTasks(projectId) {
  const panel = document.getElementById(`tasks-${projectId}`);
  const wasHidden = panel.classList.contains('hidden');
  panel.classList.toggle('hidden');
  if (wasHidden) await loadTasks(projectId);
}

const taskStatusIcon = { todo: 'fa-circle', doing: 'fa-spinner', done: 'fa-circle-check' };
const nextStatus = { todo: 'doing', doing: 'done', done: 'todo' };

async function loadTasks(projectId) {
  const panel = document.getElementById(`tasks-${projectId}`);
  panel.innerHTML = '<div style="font-size:.75rem;color:var(--muted)"><i class="fas fa-spinner fa-spin"></i> Loading…</div>';
  try {
    const res = await Trackie.API.post(`${API_BASE}/projects.php`, {action:'tasks', project_id:projectId});
    if (!res.success) { panel.innerHTML = '<div style="font-size:.75rem;color:var(--muted)">Could not load tasks.</div>'; return; }
    renderTasks(projectId, res.tasks);
  } catch { panel.innerHTML = '<div style="font-size:.75rem;color:var(--muted)">Network error.</div>'; }
}
function renderTasks(projectId, tasks) {
  const panel = document.getElementById(`tasks-${projectId}`);
  const rows = tasks.map(t => `
    <div style="display:flex;align-items:center;gap:.5rem;padding:.375rem 0;border-bottom:1px solid var(--border)">
      <button class="btn btn-icon btn-ghost btn-sm" style="width:1.75rem;height:1.75rem" onclick="cycleTaskStatus(${t.id}, '${t.status}', ${projectId})" title="Cycle status" aria-label="Cycle status">
        <i class="fas ${taskStatusIcon[t.status]}" style="font-size:.8125rem;color:${t.status==='done'?'#16a34a':t.status==='doing'?'#f59e0b':'var(--muted)'}"></i>
      </button>
      <span style="flex:1;font-size:.8125rem;${t.status==='done'?'text-decoration:line-through;color:var(--muted)':''}">${escProj(t.title)}</span>
      <button aria-label="Delete task" class="btn btn-icon btn-ghost btn-sm" style="width:1.75rem;height:1.75rem" onclick="deleteTask(${t.id}, ${projectId})" aria-label="Delete task"><i class="fas fa-xmark" style="font-size:.75rem"></i></button>
    </div>`).join('');
  panel.innerHTML = rows + `
    <div style="display:flex;gap:.5rem;margin-top:.5rem">
      <input class="form-input" style="font-size:.8125rem" placeholder="Add a task…" id="newTaskInput-${projectId}"
             onkeydown="if(event.key==='Enter') addTask(${projectId})">
      <button class="btn btn-secondary btn-sm" onclick="addTask(${projectId})" aria-label="Add task"><i class="fas fa-plus" aria-hidden="true"></i></button>
    </div>`;
}
function escProj(s) {
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
async function addTask(projectId) {
  const input = document.getElementById(`newTaskInput-${projectId}`);
  const title = input.value.trim();
  if (!title) return;
  try {
    const res = await Trackie.API.post(`${API_BASE}/projects.php`, {action:'add_task', project_id:projectId, title});
    if (res.success) { input.value = ''; await loadTasks(projectId); }
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function cycleTaskStatus(taskId, current, projectId) {
  try {
    const res = await Trackie.API.post(`${API_BASE}/projects.php`, {action:'update_task_status', task_id:taskId, status: nextStatus[current]});
    if (res.success) await loadTasks(projectId);
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
async function deleteTask(taskId, projectId) {
  try {
    const res = await Trackie.API.post(`${API_BASE}/projects.php`, {action:'delete_task', task_id:taskId});
    if (res.success) await loadTasks(projectId);
    else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}
</script>
