<?php
/**
 * GitHub provider — reference implementation of TrackieProvider.
 *
 * Syncs the signed-in user's repositories and recent activity into
 * integration_data, so the Coding module renders from Trackie's own database
 * and never calls GitHub during a page render.
 *
 * What gets discovered: EVERY repository the token can see — owned,
 * collaborator and organisation repos — paging through /user/repos (100 per
 * page) instead of the first 50 owned ones. Repos that disappear on GitHub
 * (deleted, transferred, access removed) are dropped at the next sync.
 *
 * Scopes: `read:user` + `public_repo` by default. Private repositories need
 * the classic `repo` scope, which GitHub only offers as read AND write — so
 * it is opt-in at connect time (privateScopes()), never the default.
 *
 * Rate limits: 5,000 req/hr authenticated. A sync costs ~2 + repos/100 +
 * up to 3 event pages; providerHttp() turns an exhausted limit into a
 * "try again in N min" message from X-RateLimit-Reset.
 */
class TrackieGithubProvider extends TrackieProvider
{
    private const API = 'https://api.github.com';
    private const MAX_REPO_PAGES = 10;   // 1,000 repositories
    private const MAX_EVENT_PAGES = 3;   // GitHub serves at most 300 events

    public function key(): string   { return 'github'; }
    public function label(): string { return 'GitHub'; }

    public function requiredCredentials(): array {
        return ['GITHUB_CLIENT_ID', 'GITHUB_CLIENT_SECRET'];
    }

    public function scopes(): array { return ['read:user', 'public_repo']; }

    /** Opt-in: adds private repositories (GitHub has no read-only variant of this scope). */
    public function privateScopes(): array { return ['read:user', 'repo']; }

    /** Did the user grant private-repo access? */
    public function hasPrivateAccess(int $uid): bool {
        $row = $this->connection($uid);
        return $row && in_array('repo', preg_split('/[\s,]+/', (string)$row['scopes']), true);
    }

    public function capabilities(): array {
        // GitHub OAuth tokens don't expire and there's no refresh grant,
        // so refresh() stays unsupported — reconnecting is the recovery path.
        return ['sync' => true, 'webhook' => false, 'disconnect' => true, 'refresh' => false];
    }

    /** JSON GET through the shared client. Returns [data, nextUrl|null]. */
    private function get(string $pathOrUrl, string $token): array {
        // GITHUB_API_URL: test hook (a local mock); unset in production.
        $base = rtrim(env('GITHUB_API_URL', self::API), '/');
        $url = preg_match('#^https?://#', $pathOrUrl) ? $pathOrUrl : $base . $pathOrUrl;
        $r = providerHttp('GitHub', 'GET', $url, $token, [
            'accept'  => 'application/vnd.github+json',
            'headers' => ['X-GitHub-Api-Version: 2022-11-28'],
            'timeout' => 15,
        ]);
        if ($r['code'] === 401) throw new RuntimeException('GitHub rejected the token — please reconnect.');
        if ($r['code'] === 403) throw new RuntimeException('GitHub refused the request (403). Reconnect GitHub to refresh its permissions.');
        if ($r['code'] >= 400) throw new RuntimeException("GitHub returned HTTP {$r['code']}.");
        $next = null;
        if (preg_match('/<([^>]+)>;\s*rel="next"/', $r['headers']['link'] ?? '', $m)) $next = $m[1];
        return [$r['body'] ?? [], $next];
    }

    /**
     * Pulls profile, every accessible repository, and recent activity.
     * @return int records upserted
     */
    public function sync(int $uid): int
    {
        $token = $this->accessToken($uid);
        if (!$token) throw new RuntimeException('No usable GitHub token — please reconnect.');

        $n = 0;
        $seen = [];

        // ── Profile ───────────────────────────────────────────────
        [$me] = $this->get('/user', $token);
        $login = $me['login'] ?? '';
        if ($login !== '') {
            $this->store($uid, 'profile', $login, [
                'login'        => $login,
                'name'         => $me['name']         ?? null,
                'avatar'       => $me['avatar_url']   ?? null,
                'public_repos' => $me['public_repos'] ?? 0,
                'private_repos'=> $me['total_private_repos'] ?? null,   // only present with the repo scope
                'followers'    => $me['followers']    ?? 0,
                'following'    => $me['following']    ?? 0,
                'html_url'     => $me['html_url']     ?? null,
                'private_access' => $this->hasPrivateAccess($uid),
            ]);
            $n++;
        }

        // ── Every repository the token can see, paged ─────────────
        $url = '/user/repos?' . http_build_query([
            'per_page' => 100, 'sort' => 'pushed', 'direction' => 'desc',
            'affiliation' => 'owner,collaborator,organization_member', 'visibility' => 'all',
        ]);
        for ($page = 0; $url && $page < self::MAX_REPO_PAGES; $page++) {
            [$repos, $url] = $this->get($url, $token);
            foreach ($repos as $r) {
                if (empty($r['id'])) continue;
                $seen[] = (string)$r['id'];
                $this->store($uid, 'repo', (string)$r['id'], [
                    'name'           => $r['name']              ?? '',
                    'full_name'      => $r['full_name']         ?? '',
                    'owner'          => $r['owner']['login']    ?? '',
                    'owner_type'     => $r['owner']['type']     ?? 'User',
                    'mine'           => ($r['owner']['login'] ?? '') === $login,
                    'description'    => $r['description']       ?? null,
                    'language'       => $r['language']          ?? null,
                    'topics'         => array_slice($r['topics'] ?? [], 0, 8),
                    'stars'          => $r['stargazers_count']  ?? 0,
                    'forks'          => $r['forks_count']       ?? 0,
                    'watchers'       => $r['subscribers_count'] ?? $r['watchers_count'] ?? 0,
                    'open_issues'    => $r['open_issues_count'] ?? 0,   // GitHub counts open PRs here too
                    'private'        => (bool)($r['private']    ?? false),
                    'visibility'     => $r['visibility']        ?? (!empty($r['private']) ? 'private' : 'public'),
                    'fork'           => (bool)($r['fork']       ?? false),
                    'archived'       => (bool)($r['archived']   ?? false),
                    'is_template'    => (bool)($r['is_template']?? false),
                    'default_branch' => $r['default_branch']    ?? null,
                    'homepage'       => ($r['homepage'] ?? '') ?: null,
                    'html_url'       => $r['html_url']          ?? null,
                    'size_kb'        => $r['size']              ?? 0,
                    'can_push'       => (bool)($r['permissions']['push'] ?? false),
                    'created_at'     => $r['created_at']        ?? null,
                    'updated_at'     => $r['updated_at']        ?? null,
                    'pushed_at'      => $r['pushed_at']         ?? null,
                ], !empty($r['pushed_at']) ? date('Y-m-d H:i:s', strtotime($r['pushed_at'])) : null);
                $n++;
            }
        }
        // Anything not seen this time is gone from GitHub (or no longer visible).
        // Only after a complete listing (not when capped at MAX_REPO_PAGES).
        if ($url === null) {
            $keep = $seen ? implode(',', array_fill(0, count($seen), '?')) : "''";
            delete("DELETE FROM integration_data WHERE user_id=? AND provider='github' AND kind='repo' AND external_id NOT IN ($keep)",
                   array_merge([$uid], $seen));
        }

        // ── Recent activity (pushes, PRs, issues, releases) ───────
        // The authenticated user's own feed: includes private activity when
        // the token has the repo scope; public otherwise.
        if ($login !== '') {
            $evUrl = '/users/' . rawurlencode($login) . '/events?per_page=100';
            for ($page = 0; $evUrl && $page < self::MAX_EVENT_PAGES; $page++) {
                [$events, $evUrl] = $this->get($evUrl, $token);
                foreach ($events as $ev) {
                    $type = $ev['type'] ?? '';
                    if (empty($ev['id'])) continue;
                    $at = !empty($ev['created_at']) ? date('Y-m-d H:i:s', strtotime($ev['created_at'])) : null;
                    if ($type === 'PushEvent') {
                        $commits = $ev['payload']['commits'] ?? [];
                        $this->store($uid, 'push', (string)$ev['id'], [
                            'repo'     => $ev['repo']['name'] ?? '',
                            // `size` is the real commit count; `commits` is capped at 20.
                            'commits'  => (int)($ev['payload']['size'] ?? count($commits)),
                            'branch'   => preg_replace('#^refs/heads/#', '', (string)($ev['payload']['ref'] ?? '')),
                            'messages' => array_slice(array_map(static fn($c) => mb_substr(strtok((string)($c['message'] ?? ''), "\n"), 0, 120), $commits), 0, 5),
                        ], $at);
                        $n++;
                    } elseif (in_array($type, ['PullRequestEvent', 'IssuesEvent', 'ReleaseEvent', 'CreateEvent'], true)) {
                        $pl = $ev['payload'] ?? [];
                        $item = $pl['pull_request'] ?? $pl['issue'] ?? $pl['release'] ?? [];
                        $this->store($uid, 'event', (string)$ev['id'], [
                            'type'   => $type,
                            'action' => $pl['action'] ?? ($type === 'CreateEvent' ? 'created ' . ($pl['ref_type'] ?? '') : null),
                            'repo'   => $ev['repo']['name'] ?? '',
                            'title'  => mb_substr((string)($item['title'] ?? $item['name'] ?? $item['tag_name'] ?? $pl['ref'] ?? ''), 0, 140),
                            'url'    => $item['html_url'] ?? null,
                            'merged' => (bool)($pl['pull_request']['merged'] ?? false),
                        ], $at);
                        $n++;
                    }
                }
            }
        }

        return $n;
    }

    /**
     * Activity bucket for a repo, from data GitHub already gives us.
     * Archived wins; then by last push: ≤14 days active, ≤90 recent, else dormant.
     */
    public static function activity(array $repo): string {
        if (!empty($repo['archived'])) return 'archived';
        $pushed = !empty($repo['pushed_at']) ? strtotime($repo['pushed_at']) : 0;
        $days = $pushed ? (time() - $pushed) / 86400 : PHP_INT_MAX;
        return $days <= 14 ? 'active' : ($days <= 90 ? 'recent' : 'dormant');
    }

    /** Commits per day over the last N days — for the contribution heatmap. */
    public function commitsByDay(int $uid, int $days = 30): array {
        $rows = fetchAll(
            "SELECT DATE(occurred_at) d, SUM(JSON_EXTRACT(payload,'$.commits')) c
             FROM integration_data
             WHERE user_id=? AND provider='github' AND kind='push'
               AND occurred_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             GROUP BY d ORDER BY d",
            [$uid, $days]
        );
        $out = [];
        foreach ($rows as $r) $out[$r['d']] = (int)$r['c'];
        return $out;
    }
}
