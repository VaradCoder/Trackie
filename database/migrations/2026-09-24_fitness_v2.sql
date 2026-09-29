-- ============================================================
--  Trackie — Fitness V2 migration (2026-09-24)
--
--  SAFE FOR THE LIVE DATABASE (InfinityFree / phpMyAdmin):
--    * additive only — no DROP, no data deleted or rewritten
--    * idempotent   — every statement can be re-run safely
--    * no CREATE DATABASE / USE — import into the selected DB
--    * no triggers / procedures / DELIMITER (not allowed on shared hosting)
--
--  How: phpMyAdmin → select the trackie database → Import (or SQL tab)
--  → run this file. Or, equivalently, open pages/setup.php once while
--  logged in — it contains the same migrations.
--
--  NEVER import database/final.sql on the live site: it begins with
--  DROP TABLE statements and is only for creating a brand-new database.
-- ============================================================

-- Exercise metadata for the provider layer (app/Modules/Fitness).
ALTER TABLE exercise_library
    ADD COLUMN IF NOT EXISTS target_muscle     VARCHAR(60)  DEFAULT NULL AFTER muscle_group,
    ADD COLUMN IF NOT EXISTS secondary_muscles VARCHAR(255) DEFAULT NULL AFTER target_muscle,
    ADD COLUMN IF NOT EXISTS difficulty        VARCHAR(20)  DEFAULT NULL AFTER category,
    ADD COLUMN IF NOT EXISTS instructions      TEXT         DEFAULT NULL AFTER difficulty,
    ADD COLUMN IF NOT EXISTS video_url         VARCHAR(500) DEFAULT NULL AFTER video_path,
    ADD COLUMN IF NOT EXISTS thumbnail_url     VARCHAR(500) DEFAULT NULL AFTER video_url,
    ADD COLUMN IF NOT EXISTS source            VARCHAR(20)  NOT NULL DEFAULT 'library' AFTER thumbnail_url,
    ADD COLUMN IF NOT EXISTS external_id       VARCHAR(100) DEFAULT NULL AFTER source;

-- Demo videos for built-in exercises, matched by NAME (ids differ between
-- databases). Only fills empty built-in rows; never overrides a video a user
-- assigned. Harmless if the files or rows don't exist.
UPDATE exercise_library SET video_path = CASE name
        WHEN 'Chest Press Machine' THEN 'assets/vids/Chest press or fly.mp4'
        WHEN 'Leg Press'           THEN 'assets/vids/leg press.mp4'
        ELSE video_path END
  WHERE user_id IS NULL AND video_path IS NULL
    AND name IN ('Chest Press Machine', 'Leg Press');

-- Fitness goals: progress is computed from logs, never stored.
CREATE TABLE IF NOT EXISTS fitness_goals (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    goal_type     VARCHAR(20)  NOT NULL,
    target_value  DECIMAL(8,2) NOT NULL,
    exercise_name VARCHAR(100) DEFAULT NULL,
    start_date    DATE NOT NULL,
    deadline      DATE DEFAULT NULL,
    completed_at  DATETIME DEFAULT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Nutrition: calories EATEN as entered; nothing estimated.
CREATE TABLE IF NOT EXISTS nutrition_entries (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    entry_date DATE NOT NULL,
    meal       VARCHAR(12)  NOT NULL DEFAULT 'snack',
    name       VARCHAR(120) DEFAULT NULL,
    calories   INT          DEFAULT NULL,
    protein_g  DECIMAL(6,1) DEFAULT NULL,
    water_ml   INT          DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_date (user_id, entry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS nutrition_targets (
    user_id    INT PRIMARY KEY,
    calories   INT          DEFAULT NULL,
    protein_g  DECIMAL(6,1) DEFAULT NULL,
    water_ml   INT          DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Recovery check-ins: raw inputs only, no derived score.
CREATE TABLE IF NOT EXISTS recovery_logs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    log_date    DATE NOT NULL,
    sleep_hours DECIMAL(3,1) DEFAULT NULL,
    energy      TINYINT      DEFAULT NULL,
    soreness    TINYINT      DEFAULT NULL,
    notes       VARCHAR(255) DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_user_date (user_id, log_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- WorkoutDB lookup cache (global catalogue cache, not user data).
CREATE TABLE IF NOT EXISTS workoutdb_cache (
    query_key  VARCHAR(191) NOT NULL PRIMARY KEY,
    status     VARCHAR(8)   NOT NULL,
    payload    TEXT         DEFAULT NULL,
    fetched_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
