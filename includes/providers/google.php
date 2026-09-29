<?php
/**
 * Google provider — one OAuth connection shared by Google Calendar and
 * Google Tasks (registry entries google_calendar / google_tasks map to it).
 * Tokens are stored ENCRYPTED in user_integrations and refreshed
 * automatically (access tokens last 1 hour).
 *
 * sync() pulls the next 14 days of primary-calendar events and open tasks
 * from the default list into integration_data (kinds 'event' / 'task').
 */
class TrackieGoogleProvider extends TrackieProvider
{
    public function key(): string   { return 'google'; }
    public function label(): string { return 'Google'; }

    public function requiredCredentials(): array {
        return ['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET'];
    }

    public function scopes(): array {
        return ['https://www.googleapis.com/auth/calendar.readonly', 'https://www.googleapis.com/auth/tasks'];
    }

    public function capabilities(): array {
        return ['sync' => true, 'webhook' => false, 'disconnect' => true, 'refresh' => true];
    }

    public function refresh(int $uid): bool
    {
        $refresh = $this->refreshTokenValue($uid);
        if (!$refresh) return false;
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'client_id' => env('GOOGLE_CLIENT_ID'), 'client_secret' => env('GOOGLE_CLIENT_SECRET'),
                'refresh_token' => $refresh, 'grant_type' => 'refresh_token',
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT    => 8,
        ]);
        $resp = json_decode((string)curl_exec($ch), true);
        curl_close($ch);
        if (empty($resp['access_token'])) {
            // invalid_grant: revoked, or the OAuth app is in "Testing" mode
            // (Google expires those refresh tokens after 7 days).
            update("UPDATE user_integrations SET sync_status='error', last_error=? WHERE user_id=? AND provider='google'",
                   ['Google access expired or was revoked — please reconnect. (Apps in "Testing" mode lose access after 7 days.)', $uid]);
            return false;
        }
        $this->updateTokens($uid, $resp['access_token'], $resp['refresh_token'] ?? null, (int)($resp['expires_in'] ?? 3600));
        return true;
    }

    private function get(string $url, string $token): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12,
                                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token]]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = $body ? json_decode($body, true) : null;
        if ($code === 401) throw new RuntimeException('Google rejected the token — please reconnect.');
        if ($code === 403) {
            // Usually "API not enabled" — Google's message names the API.
            $msg = $data['error']['message'] ?? 'Access denied.';
            throw new RuntimeException('Google refused the request: ' . mb_substr(strip_tags($msg), 0, 200));
        }
        if ($code >= 400 || !is_array($data)) throw new RuntimeException("Google returned HTTP {$code}.");
        return $data;
    }

    public function sync(int $uid): int
    {
        $token = $this->validAccessToken($uid);
        if (!$token) throw new RuntimeException('Google access expired — please reconnect.');
        $n = 0;

        $events = $this->get('https://www.googleapis.com/calendar/v3/calendars/primary/events?' . http_build_query([
            'timeMin' => date('c'), 'timeMax' => date('c', strtotime('+14 days')),
            'singleEvents' => 'true', 'orderBy' => 'startTime', 'maxResults' => 50,
        ]), $token);
        foreach ($events['items'] ?? [] as $e) {
            $start = $e['start']['dateTime'] ?? $e['start']['date'] ?? null;
            $this->store($uid, 'event', (string)$e['id'], [
                'title'  => $e['summary'] ?? '(No title)',
                'start'  => $start,
                'end'    => $e['end']['dateTime'] ?? $e['end']['date'] ?? null,
                'allDay' => isset($e['start']['date']),
                'link'   => $e['htmlLink'] ?? null,
            ], $start ? date('Y-m-d H:i:s', strtotime($start)) : null);
            $n++;
        }

        $tasks = $this->get('https://tasks.googleapis.com/tasks/v1/lists/@default/tasks?' . http_build_query([
            'showCompleted' => 'false', 'maxResults' => 50,
        ]), $token);
        foreach ($tasks['items'] ?? [] as $t) {
            $due = $t['due'] ?? null;
            $this->store($uid, 'task', (string)$t['id'], [
                'title' => $t['title'] ?? '', 'notes' => $t['notes'] ?? null, 'due' => $due, 'status' => $t['status'] ?? null,
            ], $due ? date('Y-m-d H:i:s', strtotime($due)) : null);
            $n++;
        }
        return $n;
    }
}
