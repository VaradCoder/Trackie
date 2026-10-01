-- ============================================================
--  Trackie — Native app devices (2026-10-01)
--  One row per installed Android/iOS app. token_hash is the SHA-256 of a
--  random device token the app uses for background sync (Authorization:
--  Bearer …) — revocable, never stored in plain text. fcm_token is the
--  Firebase Cloud Messaging registration token for server pushes.
--  Additive + idempotent; safe on the live database.
-- ============================================================
CREATE TABLE IF NOT EXISTS native_devices (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    token_hash   CHAR(64) NOT NULL,
    platform     VARCHAR(16) NOT NULL DEFAULT 'android',
    device_name  VARCHAR(80) DEFAULT NULL,
    app_version  VARCHAR(32) DEFAULT NULL,
    fcm_token    VARCHAR(255) DEFAULT NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME DEFAULT NULL,
    revoked_at   DATETIME DEFAULT NULL,
    UNIQUE KEY uniq_token (token_hash),
    INDEX idx_user (user_id, revoked_at),
    INDEX idx_fcm (fcm_token),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
