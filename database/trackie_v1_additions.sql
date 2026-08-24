-- ============================================================
--  Trackie V1.0 — Additive migrations
--  Run AFTER trackie_v1.sql on an existing database.
--  Each block is safe to run multiple times (IF NOT EXISTS / IGNORE).
-- ============================================================

-- ── password_resets ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS password_resets (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT          NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME     NOT NULL,
    used       TINYINT(1)   DEFAULT 0,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── rate_limits ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS rate_limits (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    identifier   VARCHAR(100) NOT NULL,
    action       VARCHAR(50)  NOT NULL,
    attempts     INT          DEFAULT 1,
    window_start DATETIME     NOT NULL,
    INDEX idx_id_action (identifier, action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── notifications ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS notifications (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT          NOT NULL,
    type       VARCHAR(50)  NOT NULL,
    title      VARCHAR(200) NOT NULL,
    message    TEXT,
    link       VARCHAR(255),
    is_read    TINYINT(1)   DEFAULT 0,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_read (user_id, is_read),
    INDEX idx_user_created (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Add category + tags columns to todos ─────────────────────
ALTER TABLE todos
    ADD COLUMN IF NOT EXISTS category VARCHAR(80)  DEFAULT NULL AFTER priority,
    ADD COLUMN IF NOT EXISTS tags      VARCHAR(500) DEFAULT NULL AFTER category;

-- ── Index for category filter ─────────────────────────────────
ALTER TABLE todos ADD INDEX IF NOT EXISTS idx_category (user_id, category);
