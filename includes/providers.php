<?php
/**
 * Trackie — provider architecture for external integrations.
 *
 * THE RULE THIS EXISTS TO ENFORCE:
 *   Modules read synced data from Trackie's own database. They never call a
 *   third-party API during a page render. A provider being slow, rate-limited
 *   or down must never slow down or break a page.
 *
 * Flow:
 *   connect()  → OAuth callback stores encrypted tokens in user_integrations
 *   sync()     → background job pulls data into integration_data
 *   modules    → read integration_data (fast, local, always available)
 *
 * Adding a provider is: subclass TrackieProvider, register it in
 * providerRegistry(), add credentials to config/env.php. No core changes.
 *
 * Security posture:
 *   - tokens encrypted at rest (includes/crypto.php), never logged
 *   - request the minimum scopes needed
 *   - a provider that throws is isolated: sync marks it 'error' and the rest
 *     of the app carries on
 */

require_once __DIR__ . '/crypto.php';

abstract class TrackieProvider
{
    /** Stable slug, e.g. 'github'. Must match integrationsRegistry(). */
    abstract public function key(): string;

    /** Human label for UI. */
    abstract public function label(): string;

    /** env() constant names that must all be non-empty for this to work. */
    abstract public function requiredCredentials(): array;

    /** What this provider can do — lets Settings render without hardcoding. */
    public function capabilities(): array {
        return ['sync' => true, 'webhook' => false, 'disconnect' => true];
    }

    /** OAuth scopes requested. Keep minimal. */
    public function scopes(): array { return []; }

    /** Where the connect flow starts. '' if not OAuth-based. */
    public function connectUrl(): string {
        return APP_BASE . '/pages/' . $this->key() . '_callback.php';
    }

    /** True when the SERVER is configured (credentials present). */
    final public function isConfigured(): bool {
        foreach ($this->requiredCredentials() as $c) {
            if (env($c) === '') return false;
        }
        return true;
    }

    /** True when THIS USER has connected their account. */
    final public function isConnected(int $uid): bool {
        return (bool)fetchOne(
            "SELECT id FROM user_integrations WHERE user_id=? AND provider=?",
            [$uid, $this->key()]
        );
    }

    /** Connection row (tokens still encrypted), or null. */
    final public function connection(int $uid): ?array {
        return fetchOne(
            "SELECT * FROM user_integrations WHERE user_id=? AND provider=?",
            [$uid, $this->key()]
        ) ?: null;
    }

    /**
     * Stores tokens for a user, encrypted. Called from the OAuth callback.
     * Upsert so reconnecting replaces the old grant rather than duplicating.
     */
    final public function storeTokens(
        int $uid, string $accessToken, string $refreshToken = '',
        ?int $expiresIn = null, string $externalId = '', array $scopes = []
    ): void {
        $expiresAt = $expiresIn ? date('Y-m-d H:i:s', time() + $expiresIn) : null;

        insert(
            "INSERT INTO user_integrations
               (user_id, provider, access_token, refresh_token, expires_at, scopes, external_id, sync_status)
             VALUES (?,?,?,?,?,?,?, 'never')
             ON DUPLICATE KEY UPDATE
               access_token  = VALUES(access_token),
               refresh_token = VALUES(refresh_token),
               expires_at    = VALUES(expires_at),
               scopes        = VALUES(scopes),
               external_id   = VALUES(external_id),
               last_error    = NULL",
            [
                $uid, $this->key(),
                tk_encrypt($accessToken),
                tk_encrypt($refreshToken),
                $expiresAt,
                implode(' ', $scopes ?: $this->scopes()),
                $externalId,
            ]
        );
    }

    /**
     * Replace the access token after a refresh, keeping the rest of the
     * connection (scopes, external id, sync state). Spotify and Google only
     * sometimes rotate the refresh token — keep the old one when they don't.
     */
    final protected function updateTokens(int $uid, string $accessToken, ?string $refreshToken, ?int $expiresIn): void {
        update(
            "UPDATE user_integrations SET access_token=?, expires_at=?"
            . ($refreshToken ? ", refresh_token=?" : "") . " WHERE user_id=? AND provider=?",
            array_merge(
                [tk_encrypt($accessToken), $expiresIn ? date('Y-m-d H:i:s', time() + $expiresIn) : null],
                $refreshToken ? [tk_encrypt($refreshToken)] : [],
                [$uid, $this->key()]
            )
        );
    }

    /**
     * A usable access token: refreshed first when expired. Null when the
     * user isn't connected or the refresh failed (→ they must reconnect).
     */
    final public function validAccessToken(int $uid): ?string {
        if (!$this->isConnected($uid)) return null;
        if ($this->isExpired($uid) && !$this->refresh($uid)) return null;
        return $this->accessToken($uid);
    }

    /** Decrypted access token, or null if absent/undecryptable/expired. */
    final public function accessToken(int $uid): ?string {
        $row = $this->connection($uid);
        if (!$row || empty($row['access_token'])) return null;
        return tk_decrypt($row['access_token']);
    }

    final public function refreshTokenValue(int $uid): ?string {
        $row = $this->connection($uid);
        if (!$row || empty($row['refresh_token'])) return null;
        return tk_decrypt($row['refresh_token']);
    }

    /** True when the stored access token is expired (or about to be). */
    final public function isExpired(int $uid, int $skewSeconds = 60): bool {
        $row = $this->connection($uid);
        if (!$row || !$row['expires_at']) return false;
        return strtotime($row['expires_at']) <= (time() + $skewSeconds);
    }

    /** Removes the connection. Synced data is dropped with it. */
    final public function disconnect(int $uid): void {
        delete("DELETE FROM user_integrations WHERE user_id=? AND provider=?", [$uid, $this->key()]);
        delete("DELETE FROM integration_data  WHERE user_id=? AND provider=?", [$uid, $this->key()]);
    }

    /** Status for the Settings UI. Never throws. */
    final public function status(int $uid): array {
        $row = $this->connection($uid);
        return [
            'configured' => $this->isConfigured(),
            'connected'  => (bool)$row,
            'syncStatus' => $row['sync_status'] ?? 'never',
            'lastSync'   => $row['last_sync']   ?? null,
            'lastError'  => $row['last_error']  ?? null,
        ];
    }

    /**
     * Exchange a refresh token for a new access token. Providers that support
     * refresh override this; the default reports "not supported".
     */
    public function refresh(int $uid): bool { return false; }

    /**
     * Pull remote data into integration_data. Implementations should be
     * incremental where the API allows it, and MUST NOT be called during a
     * page render — see runSync().
     *
     * @return int number of records upserted
     */
    abstract public function sync(int $uid): int;

    /** Upsert one synced record. Providers call this from sync(). */
    final protected function store(
        int $uid, string $kind, string $externalId, array $payload, ?string $occurredAt = null
    ): void {
        insert(
            "INSERT INTO integration_data (user_id, provider, kind, external_id, payload, occurred_at)
             VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               payload     = VALUES(payload),
               occurred_at = VALUES(occurred_at),
               synced_at   = CURRENT_TIMESTAMP",
            [$uid, $this->key(), $kind, $externalId,
             json_encode($payload, JSON_UNESCAPED_SLASHES), $occurredAt]
        );
    }
}

/**
 * Runs a provider's sync with full isolation: a throwing provider marks
 * itself 'error' and never propagates the failure to the caller.
 */
function runSync(TrackieProvider $p, int $uid): array {
    if (!$p->isConfigured() || !$p->isConnected($uid)) {
        return ['ok' => false, 'error' => 'Not connected.'];
    }

    update("UPDATE user_integrations SET sync_status='syncing' WHERE user_id=? AND provider=?",
           [$uid, $p->key()]);

    try {
        if ($p->isExpired($uid)) $p->refresh($uid);
        $count = $p->sync($uid);

        update("UPDATE user_integrations
                SET sync_status='ok', last_sync=NOW(), last_error=NULL
                WHERE user_id=? AND provider=?", [$uid, $p->key()]);

        return ['ok' => true, 'records' => $count];
    } catch (Throwable $e) {
        // Never store a token or full URL in last_error — it can surface in UI.
        $msg = substr($e->getMessage(), 0, 480);
        update("UPDATE user_integrations
                SET sync_status='error', last_error=?
                WHERE user_id=? AND provider=?", [$msg, $uid, $p->key()]);

        return ['ok' => false, 'error' => $msg];
    }
}

/**
 * Reads synced records for a module. THIS is what module pages call —
 * fast, local, and unaffected by provider downtime.
 */
function syncedData(int $uid, string $provider, string $kind, int $limit = 50): array {
    $rows = fetchAll(
        "SELECT external_id, payload, occurred_at FROM integration_data
         WHERE user_id=? AND provider=? AND kind=?
         ORDER BY occurred_at DESC, id DESC LIMIT " . max(1, min(500, $limit)),
        [$uid, $provider, $kind]
    );
    foreach ($rows as &$r) {
        $r['data'] = json_decode($r['payload'], true) ?: [];
        unset($r['payload']);
    }
    return $rows;
}

/**
 * Registry of implemented providers, keyed by slug.
 *
 * Deliberately EMPTY of concrete OAuth providers for now: every one of them
 * (GitHub, Google, Steam, Spotify…) needs credentials that don't exist yet,
 * and shipping half-wired providers that fail at connect time would be worse
 * than shipping none. includes/integrations.php continues to drive the
 * Settings UI ("coming soon" per provider) until a provider is registered here.
 */
function providerRegistry(): array {
    static $providers = null;
    if ($providers !== null) return $providers;

    $providers = [];
    foreach (glob(__DIR__ . '/providers/*.php') as $file) {
        require_once $file;
        $class = 'Trackie' . ucfirst(basename($file, '.php')) . 'Provider';
        if (class_exists($class)) {
            $p = new $class();
            $providers[$p->key()] = $p;
        }
    }
    return $providers;
}

/** One provider by slug, or null. */
function provider(string $key): ?TrackieProvider {
    return providerRegistry()[$key] ?? null;
}
