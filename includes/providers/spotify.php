<?php
/**
 * Spotify provider. Tokens live ENCRYPTED in user_integrations (previously
 * only in the PHP session, which is why Spotify had to be reconnected after
 * every logout / session expiry). Access tokens last 1 hour and are refreshed
 * automatically with the stored refresh token.
 */
class TrackieSpotifyProvider extends TrackieProvider
{
    public function key(): string   { return 'spotify'; }
    public function label(): string { return 'Spotify'; }

    public function requiredCredentials(): array {
        return ['SPOTIFY_CLIENT_ID', 'SPOTIFY_CLIENT_SECRET'];
    }

    /**
     * Everything the Music page and dashboard widget actually call. The old
     * list lacked playback / recently-played / private-playlist / streaming
     * scopes, so those features answered 403 ("API error").
     */
    public function scopes(): array {
        return [
            'user-read-private', 'user-read-email',
            'user-read-currently-playing', 'user-read-playback-state', 'user-modify-playback-state',
            'user-read-recently-played', 'user-top-read', 'playlist-read-private', 'streaming',
            // Liked Songs: read (Favorites tab) + save/unsave (the heart button).
            'user-library-read', 'user-library-modify',
        ];
    }

    public function capabilities(): array {
        return ['sync' => true, 'webhook' => false, 'disconnect' => true, 'refresh' => true];
    }

    public function refresh(int $uid): bool
    {
        $refresh = $this->refreshTokenValue($uid);
        if (!$refresh) return false;
        $ch = curl_init('https://accounts.spotify.com/api/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['grant_type' => 'refresh_token', 'refresh_token' => $refresh]),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . base64_encode(env('SPOTIFY_CLIENT_ID') . ':' . env('SPOTIFY_CLIENT_SECRET')),
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_TIMEOUT => 8,
        ]);
        $resp = json_decode((string)curl_exec($ch), true);
        curl_close($ch);
        if (empty($resp['access_token'])) {
            // invalid_grant = the user revoked access or the app credentials changed.
            update("UPDATE user_integrations SET sync_status='error', last_error=? WHERE user_id=? AND provider='spotify'",
                   ['Spotify access expired or was revoked — please reconnect.', $uid]);
            return false;
        }
        $this->updateTokens($uid, $resp['access_token'], $resp['refresh_token'] ?? null, (int)($resp['expires_in'] ?? 3600));
        return true;
    }

    /** Sync = confirm the connection works and keep the profile fresh. */
    public function sync(int $uid): int
    {
        $token = $this->validAccessToken($uid);
        if (!$token) throw new RuntimeException('Spotify access expired — please reconnect.');
        $ch = curl_init('https://api.spotify.com/v1/me');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
                                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token]]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 401) throw new RuntimeException('Spotify rejected the token — please reconnect.');
        if ($code === 403) throw new RuntimeException('Spotify refused access. If the app is in Development mode, add your Spotify account under "Users and Access" in the Spotify developer dashboard.');
        if ($code >= 400 || !$body) throw new RuntimeException("Spotify returned HTTP {$code}.");
        $me = json_decode($body, true) ?: [];
        $this->store($uid, 'profile', (string)($me['id'] ?? 'me'), [
            'id' => $me['id'] ?? null, 'name' => $me['display_name'] ?? null, 'product' => $me['product'] ?? null,
        ]);
        return 1;
    }
}
