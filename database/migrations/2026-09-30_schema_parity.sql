-- ============================================================
--  Trackie — Schema parity (2026-09-30)
--  database/final.sql was missing tables/columns that the app uses and that
--  existing installs got from pages/setup.php. This brings every install to
--  the same schema. Additive + idempotent; safe on the live database.
--
--  Also repairs goal_checkins: it was created (by hand, never by a script)
--  without a PRIMARY KEY / AUTO_INCREMENT, so every check-in got id 0/1 and
--  inserts fail outright on MySQL servers running in strict mode.
-- ============================================================

CREATE TABLE IF NOT EXISTS daily_snapshots (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    snapshot_date DATE NOT NULL,
    score         TINYINT UNSIGNED DEFAULT 0,
    habits_done   SMALLINT UNSIGNED DEFAULT 0,
    habits_total  SMALLINT UNSIGNED DEFAULT 0,
    todos_done    SMALLINT UNSIGNED DEFAULT 0,
    todos_total   SMALLINT UNSIGNED DEFAULT 0,
    focus_minutes SMALLINT UNSIGNED DEFAULT 0,
    streak        SMALLINT UNSIGNED DEFAULT 0,
    mood          TINYINT UNSIGNED DEFAULT NULL,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_day (user_id, snapshot_date),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS goal_checkins (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    goal_id           INT NOT NULL,
    user_id           INT NOT NULL,
    note              TEXT DEFAULT NULL,
    progress_snapshot INT DEFAULT NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_goal (goal_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS integration_data (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    provider    VARCHAR(40) NOT NULL,
    kind        VARCHAR(40) NOT NULL,
    external_id VARCHAR(190) NOT NULL,
    payload     TEXT NOT NULL,
    occurred_at DATETIME DEFAULT NULL,
    synced_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_record (user_id, provider, kind, external_id),
    INDEX idx_lookup (user_id, provider, kind, occurred_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS routine_logs (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    routine_id INT NOT NULL,
    log_date   DATE NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_routine_day (routine_id, log_date),
    INDEX idx_user_date (user_id, log_date),
    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
    FOREIGN KEY (routine_id) REFERENCES routines(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_integrations (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    provider      VARCHAR(40) NOT NULL,
    access_token  TEXT DEFAULT NULL,
    refresh_token TEXT DEFAULT NULL,
    expires_at    DATETIME DEFAULT NULL,
    scopes        VARCHAR(500) DEFAULT NULL,
    external_id   VARCHAR(190) DEFAULT NULL,
    sync_status   ENUM('never','ok','syncing','error') DEFAULT 'never',
    last_sync     DATETIME DEFAULT NULL,
    last_error    VARCHAR(500) DEFAULT NULL,
    connected_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_provider (user_id, provider),
    INDEX idx_sync (sync_status, last_sync),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE focus_sessions ADD COLUMN IF NOT EXISTS mode VARCHAR(40) DEFAULT NULL AFTER type;
ALTER TABLE todos ADD COLUMN IF NOT EXISTS outcome ENUM('pending','in_progress','completed','skipped','failed','rescheduled','cancelled','archived') DEFAULT NULL;
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS onboarding_completed_at DATETIME DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS primary_focus VARCHAR(40) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS experience_level VARCHAR(20) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS daily_reminder_time TIME DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS sleep_goal_hours DECIMAL(3,1) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS water_goal_ml INT DEFAULT NULL;

-- goal_checkins repair: only when the primary key is missing.
SET @gc_has_pk := (SELECT COUNT(*) FROM information_schema.table_constraints
                   WHERE table_schema = DATABASE() AND table_name = 'goal_checkins' AND constraint_type = 'PRIMARY KEY');
SET @gc_n := 0;
UPDATE goal_checkins SET id = (@gc_n := @gc_n + 1) WHERE @gc_has_pk = 0 ORDER BY created_at, goal_id;
SET @gc_sql := IF(@gc_has_pk = 0,
    'ALTER TABLE goal_checkins MODIFY id INT NOT NULL AUTO_INCREMENT PRIMARY KEY',
    'DO 0');
PREPARE gc_stmt FROM @gc_sql;
EXECUTE gc_stmt;
DEALLOCATE PREPARE gc_stmt;
ALTER TABLE goal_checkins ADD INDEX IF NOT EXISTS idx_goal (goal_id, created_at);
ALTER TABLE focus_sessions ADD INDEX IF NOT EXISTS idx_user_mode (user_id, type, started_at);
