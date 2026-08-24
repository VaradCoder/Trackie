<?php
/**
 * GitHub provider — reference implementation of TrackieProvider.
 *
 * Syncs the signed-in user's repositories and recent push activity into
 * integration_data, so the Coding module renders from Trackie's own database
 * and never calls GitHub during a page render.
 *
 * Scopes: `read:user` + `public_repo` only. Deliberately NOT full `repo` —
 * that would grant write access to private code, which Trackie never needs.
 *
 * Rate limits: 5,000 req/hr authenticated. A sync costs ~3 requests, so the
 * "sync if stale" guard in syncIfStale() is about politeness, not necessity.
 */
class TrackieGithubProvider extends TrackieProvider
{
    private const API = 'https://api.github.com';

    public function key(): string   { return 'github'; }
    public function label(): string { return 'GitHub'; }

    public function requiredCredentials(): array {
        return ['GITHUB_CLIENT_ID', 'GITHUB_CLIENT_SECRET'];
    }

    public function scopes(): array { return ['read:user', 'public_repo']; }

    public function capabilities(): array {
        // GitHub OAuth tokens don't expire and there's no refresh grant,
        // so refresh() stays unsupported — reconnecting is the recovery path.
        return ['sync' => true, 'webhook' => false, 'disconnect' => true, 'refresh' => false];
    }

    /** Small JSON GET helper. Throws on transport or API error. */
    private function get(string $path, string $token): array {
        $ch = curl_init(self::API . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/vnd.github+json',
                'Authorization: Bearer ' . $token,
                'User-Agent: Trackie',
                'X-GitHub-Api-Version: 2022-11-28',
            ],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false)  throw new RuntimeException("GitHub request failed: {$err}");
        if ($code === 401)    throw new RuntimeException('GitHub rejected the token — please reconnect.');
        if ($code === 403)    throw new RuntimeException('GitHub rate limit reached. Try again later.');
        if ($code >= 400)     throw new RuntimeException("GitHub returned HTTP {$code}.");

        $data = json_decode($body, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Pulls repositories + recent push events.
     * @return int records upserted
     */
    public function sync(int $uid): int
    {
        $token = $this->accessToken($uid);
        if (!$token) throw new RuntimeException('No usable GitHub token — please reconnect.');

        $n = 0;

        // ── Profile ───────────────────────────────────────────────
        $me = $this->get('/user', $token);
        $login = $me['login'] ?? '';
        if ($login !== '') {
            $this->store($uid, 'profile', $login, [
                'login'       => $login,
                'name'        => $me['name']         ?? null,
                'avatar'      => $me['avatar_url']   ?? null,
                'public_repos'=> $me['public_repos'] ?? 0,
                'followers'   => $me['followers']    ?? 0,
                'html_url'    => $me['html_url']     ?? null,
            ]);
            $n++;
        }

        // ── Repositories (most recently pushed first) ─────────────
        foreach ($this->get('/user/repos?per_page=50&sort=pushed&affiliation=owner', $token) as $r) {
            if (empty($r['id'])) continue;
            $this->store($uid, 'repo', (string)$r['id'], [
                'name'        => $r['name']             ?? '',
                'full_name'   => $r['full_name']        ?? '',
                'description' => $r['description']      ?? null,
                'language'    => $r['language']         ?? null,
                'stars'       => $r['stargazers_count'] ?? 0,
                'forks'       => $r['forks_count']      ?? 0,
                'open_issues' => $r['open_issues_count']?? 0,
                'private'     => (bool)($r['private']   ?? false),
                'html_url'    => $r['html_url']         ?? null,
                'pushed_at'   => $r['pushed_at']        ?? null,
            ], !empty($r['pushed_at']) ? date('Y-m-d H:i:s', strtotime($r['pushed_at'])) : null);
            $n++;
        }

        // ── Recent push events → commit activity ──────────────────
        if ($login !== '') {
            foreach ($this->get("/users/{$login}/events/public?per_page=100", $token) as $ev) {
                if (($ev['type'] ?? '') !== 'PushEvent' || empty($ev['id'])) continue;
                $commits = $ev['payload']['commits'] ?? [];
                $this->store($uid, 'push', (string)$ev['id'], [
                    'repo'    => $ev['repo']['name'] ?? '',
                    'commits' => count($commits),
                    'messages'=> array_slice(array_column($commits, 'message'), 0, 5),
                ], !empty($ev['created_at']) ? date('Y-m-d H:i:s', strtotime($ev['created_at'])) : null);
                $n++;
            }
        }

        return $n;
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
