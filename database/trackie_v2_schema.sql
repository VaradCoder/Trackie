-- ============================================================
--  Trackie V2 — Schema additions
--  Run after trackie_v1.sql + trackie_v1_additions.sql
--  Or visit /pages/setup.php which handles everything.
-- ============================================================

-- ── Subtasks (self-referential todos) ────────────────────────
ALTER TABLE todos
    ADD COLUMN IF NOT EXISTS parent_id INT DEFAULT NULL AFTER id,
    ADD COLUMN IF NOT EXISTS status ENUM('backlog','today','in_progress','done') DEFAULT 'today' AFTER recurring;

ALTER TABLE todos
    ADD INDEX IF NOT EXISTS idx_parent (parent_id);

-- ── Habit groups ──────────────────────────────────────────────
ALTER TABLE habits
    ADD COLUMN IF NOT EXISTS group_name VARCHAR(80) DEFAULT NULL AFTER frequency;

ALTER TABLE habits
    ADD INDEX IF NOT EXISTS idx_group (user_id, group_name);

-- ── Focus / Pomodoro sessions ──────────────────────────────────
CREATE TABLE IF NOT EXISTS focus_sessions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT          NOT NULL,
    todo_id         INT          DEFAULT NULL,
    duration_min    INT          DEFAULT 25,
    type            ENUM('focus','short_break','long_break') DEFAULT 'focus',
    started_at      DATETIME     NOT NULL,
    completed_at    DATETIME     DEFAULT NULL,
    completed       TINYINT(1)   DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (todo_id) REFERENCES todos(id) ON DELETE SET NULL,
    INDEX idx_user_date (user_id, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── XP pool per user ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS user_xp (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT  NOT NULL UNIQUE,
    total_xp   INT  DEFAULT 0,
    level      INT  DEFAULT 1,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── XP event ledger ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS xp_events (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT         NOT NULL,
    action     VARCHAR(80) NOT NULL,
    xp         INT         NOT NULL,
    ref_type   VARCHAR(50) DEFAULT NULL,
    ref_id     INT         DEFAULT NULL,
    created_at TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_user_date (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Achievements (unlocked milestones) ────────────────────────
CREATE TABLE IF NOT EXISTS achievements (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT         NOT NULL,
    key_name    VARCHAR(80) NOT NULL,
    unlocked_at TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_achievement (user_id, key_name),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
