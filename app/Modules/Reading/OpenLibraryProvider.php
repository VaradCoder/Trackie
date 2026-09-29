<?php
/**
 * Open Library (openlibrary.org) — free, no API key.
 *   - Metadata is CC0; covers are served by covers.openlibrary.org.
 *   - Open Library asks API clients to send a descriptive User-Agent and to
 *     keep request rates low, so searches are cached in provider_cache
 *     (24h for results, 5 min after a failure) and only run server-side.
 */

require_once __DIR__ . '/BookProvider.php';

final class OpenLibraryProvider implements BookProvider
{
    private const TTL_OK    = 86400;
    private const TTL_ERROR = 300;

    public function search(string $query, int $limit = 8): ?array
    {
        $query = trim(preg_replace('/\s+/', ' ', $query));
        if (mb_strlen($query) < 2) return [];
        $limit = max(1, min(10, $limit));
        $key   = 'ol:search:' . md5(mb_strtolower($query) . "|{$limit}");

        $useCache = tableExists('provider_cache');
        if ($useCache) {
            $row = fetchOne("SELECT status, payload, UNIX_TIMESTAMP(fetched_at) t FROM provider_cache WHERE cache_key=?", [$key]);
            if ($row) {
                $age = time() - (int)$row['t'];
                if ($row['status'] === 'ok' && $age < self::TTL_OK) return json_decode((string)$row['payload'], true) ?: [];
                if ($row['status'] === 'error' && $age < self::TTL_ERROR) return null;
            }
        }

        $url = 'https://openlibrary.org/search.json?' . http_build_query([
            'q' => $query, 'limit' => $limit,
            'fields' => 'key,title,author_name,first_publish_year,isbn,cover_i,number_of_pages_median,subject',
        ]);
        $ctx = stream_context_create(['http' => [
            'timeout' => 8, 'ignore_errors' => true,
            'header'  => "Accept: application/json\r\nUser-Agent: Trackie/1.0 (" . (defined('APP_URL') ? APP_URL : 'trackie') . ")\r\n",
        ]]);
        $raw  = @file_get_contents($url, false, $ctx);
        $json = $raw ? json_decode($raw, true) : null;
        $results = is_array($json) && isset($json['docs']) ? array_values(array_filter(array_map([$this, 'normalize'], $json['docs']))) : null;
        if ($results === null) error_log('OpenLibrary: search failed');

        if ($useCache) {
            update(
                "INSERT INTO provider_cache (cache_key, status, payload) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE status=VALUES(status), payload=VALUES(payload), fetched_at=CURRENT_TIMESTAMP",
                [$key, $results === null ? 'error' : 'ok', $results === null ? null : json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
            );
        }
        return $results;
    }

    private function normalize(array $d): ?array
    {
        $title = trim((string)($d['title'] ?? ''));
        if ($title === '') return null;
        $olKey = preg_match('#^/works/(OL\d+W)$#', (string)($d['key'] ?? ''), $m) ? $m[1] : null;
        $pages = (int)($d['number_of_pages_median'] ?? 0);
        $year  = (int)($d['first_publish_year'] ?? 0);
        return [
            'title'        => mb_substr($title, 0, 200),
            'author'       => mb_substr(implode(', ', array_slice($d['author_name'] ?? [], 0, 2)), 0, 150) ?: null,
            'pages_total'  => $pages > 0 && $pages < 20000 ? $pages : null,
            'cover_url'    => !empty($d['cover_i']) ? 'https://covers.openlibrary.org/b/id/' . (int)$d['cover_i'] . '-M.jpg' : null,
            'isbn'         => self::pickIsbn($d['isbn'] ?? []),
            'ol_key'       => $olKey,
            'publish_year' => $year > 0 && $year <= (int)date('Y') + 1 ? $year : null,
            'subjects'     => self::subjects($d['subject'] ?? []),
        ];
    }

    /** Prefer a valid ISBN-13, then ISBN-10. The raw list contains junk entries. */
    private static function pickIsbn(array $list): ?string
    {
        $ten = null;
        foreach ($list as $i) {
            $i = preg_replace('/[^0-9Xx]/', '', (string)$i);
            if (preg_match('/^97[89]\d{10}$/', $i)) return $i;
            if (!$ten && preg_match('/^\d{9}[\dXx]$/', $i)) $ten = strtoupper($i);
        }
        return $ten;
    }

    /** A few short, human subjects ("Self-help", "Habit"), not catalogue codes. */
    private static function subjects(array $list): ?string
    {
        $out = [];
        foreach ($list as $s) {
            $s = trim((string)$s);
            if ($s === '' || mb_strlen($s) > 30 || preg_match('/[0-9:=]|nyt:|accessible|protected|lending/i', $s)) continue;
            $k = mb_strtolower($s);
            if (!isset($out[$k])) $out[$k] = mb_convert_case($s, MB_CASE_TITLE);
            if (count($out) >= 5) break;
        }
        return $out ? mb_substr(implode(', ', $out), 0, 255) : null;
    }
}
