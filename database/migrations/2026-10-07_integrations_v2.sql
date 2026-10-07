-- ============================================================
--  Trackie — Integrations v2 (2026-10-07)
--  • games: 'dropped' status + when it was dropped (Gaming Wrap counts it).
--  • user_identities: external sign-in identities (Google) linked to an
--    existing Trackie account. `subject` is the provider's stable user id
--    (Google `sub`), never the email — emails can change hands.
--  • auth_handoffs: one-time codes that carry a Google sign-in done in the
--    phone's browser back into the Android app (Google blocks sign-in inside
--    app WebViews). Only the SHA-256 of a code is stored; codes live 2 min.
--  Additive + idempotent; safe on the live database.
-- ============================================================
ALTER TABLE games MODIFY COLUMN status ENUM('wishlist','backlog','playing','completed','dropped') DEFAULT 'backlog';
ALTER TABLE games ADD COLUMN IF NOT EXISTS dropped_at DATETIME DEFAULT NULL AFTER completed_at;

CREATE TABLE IF NOT EXISTS user_identities (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    provider      VARCHAR(20) NOT NULL,
    subject       VARCHAR(190) NOT NULL,
    email         VARCHAR(150) DEFAULT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at DATETIME DEFAULT NULL,
    UNIQUE KEY uniq_identity (provider, subject),
    UNIQUE KEY uniq_user_provider (user_id, provider),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_handoffs (
    code_hash  CHAR(64) PRIMARY KEY,
    user_id    INT NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at    DATETIME DEFAULT NULL,
    INDEX idx_expires (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
