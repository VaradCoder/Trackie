-- ============================================================
--  Trackie — Hobbies V2 (2026-09-30): Coding, Cooking, Writing, Art, Gardening.
--  Additive + idempotent; safe on the live database.
-- ============================================================

-- Coding: time spent, optionally on a project.
CREATE TABLE IF NOT EXISTS coding_sessions (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    project_id   INT DEFAULT NULL,
    session_date DATE NOT NULL,
    minutes      INT NOT NULL,
    language     VARCHAR(40)  DEFAULT NULL,
    notes        VARCHAR(500) DEFAULT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_date (user_id, session_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cooking: real recipes, a log of what was cooked, a weekly plan.
ALTER TABLE recipes
    ADD COLUMN IF NOT EXISTS servings    SMALLINT DEFAULT NULL AFTER cook_time_min,
    ADD COLUMN IF NOT EXISTS ingredients TEXT     DEFAULT NULL AFTER servings,
    ADD COLUMN IF NOT EXISTS steps       TEXT     DEFAULT NULL AFTER ingredients;
CREATE TABLE IF NOT EXISTS cook_logs (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    recipe_id  INT NOT NULL,
    cooked_on  DATE NOT NULL,
    rating     TINYINT DEFAULT NULL,
    notes      VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
    FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE,
    INDEX idx_user_date (user_id, cooked_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS meal_plan (
    user_id   INT NOT NULL,
    plan_date DATE NOT NULL,
    meal      VARCHAR(10) NOT NULL,            -- breakfast / lunch / dinner
    recipe_id INT DEFAULT NULL,
    note      VARCHAR(120) DEFAULT NULL,
    PRIMARY KEY (user_id, plan_date, meal),
    FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
    FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Writing: the text itself, and words added per day (from real edits).
ALTER TABLE writings
    ADD COLUMN IF NOT EXISTS content    MEDIUMTEXT DEFAULT NULL AFTER word_count,
    ADD COLUMN IF NOT EXISTS updated_at DATETIME   DEFAULT NULL AFTER created_at;
CREATE TABLE IF NOT EXISTS writing_log (
    user_id     INT  NOT NULL,
    log_date    DATE NOT NULL,
    words_added INT  NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, log_date),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE user_settings ADD COLUMN IF NOT EXISTS writing_goal INT DEFAULT NULL AFTER notify_achievements;

-- Art: an image per piece + practice sessions.
ALTER TABLE artworks
    ADD COLUMN IF NOT EXISTS image_file  VARCHAR(120) DEFAULT NULL AFTER notes,
    ADD COLUMN IF NOT EXISTS image_thumb VARCHAR(120) DEFAULT NULL AFTER image_file;
CREATE TABLE IF NOT EXISTS art_sessions (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    artwork_id   INT DEFAULT NULL,
    session_date DATE NOT NULL,
    minutes      INT NOT NULL,
    notes        VARCHAR(500) DEFAULT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
    FOREIGN KEY (artwork_id) REFERENCES artworks(id) ON DELETE SET NULL,
    INDEX idx_user_date (user_id, session_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Gardening: care log (doubles as the growth journal).
ALTER TABLE plants
    ADD COLUMN IF NOT EXISTS location VARCHAR(80)  DEFAULT NULL AFTER species,
    ADD COLUMN IF NOT EXISTS notes    VARCHAR(500) DEFAULT NULL AFTER status;
CREATE TABLE IF NOT EXISTS plant_logs (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    plant_id   INT NOT NULL,
    log_date   DATE NOT NULL,
    kind       VARCHAR(12) NOT NULL,           -- water / fertilize / repot / prune / note
    note       VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
    FOREIGN KEY (plant_id) REFERENCES plants(id) ON DELETE CASCADE,
    INDEX idx_plant_date (plant_id, log_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
