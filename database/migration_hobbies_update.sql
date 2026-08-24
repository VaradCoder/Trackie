-- ============================================================
--  Trackie — Migration: Hobbies personalization + gym/coding/reading
--  modules + habit fail/skip tracking.
--  Safe to run once on the live DB via phpMyAdmin's SQL tab.
--  (Mirrors what /pages/setup.php already applies automatically —
--  use this only if you prefer running SQL directly instead.)
-- ============================================================

-- ── Habit Done/Fail/Skip tracking ──────────────────────────────
CREATE TABLE IF NOT EXISTS habit_status_log (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    habit_id   INT NOT NULL,
    log_date   DATE NOT NULL,
    status     ENUM('fail','skip') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
    FOREIGN KEY (habit_id) REFERENCES habits(id) ON DELETE CASCADE,
    UNIQUE KEY unique_status (habit_id, log_date),
    INDEX idx_user_date (user_id, log_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Hobbies (Profile personalization) ───────────────────────────
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS hobbies VARCHAR(300) DEFAULT NULL;

-- ── Fitness: gym tracker ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS workout_plans (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    name        VARCHAR(100) NOT NULL,
    day_of_week ENUM('Any','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') DEFAULT 'Any',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE workout_plans
    ADD COLUMN IF NOT EXISTS day_of_week
    ENUM('Any','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') DEFAULT 'Any';

CREATE TABLE IF NOT EXISTS workout_plan_items (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    plan_id       INT NOT NULL,
    exercise_name VARCHAR(100) NOT NULL,
    target_sets   INT DEFAULT 3,
    target_reps   INT DEFAULT 10,
    sort_order    INT DEFAULT 0,
    FOREIGN KEY (plan_id) REFERENCES workout_plans(id) ON DELETE CASCADE,
    INDEX idx_plan (plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS workout_logs (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    plan_id       INT DEFAULT NULL,
    exercise_name VARCHAR(100) NOT NULL,
    sets          INT DEFAULT NULL,
    reps          INT DEFAULT NULL,
    weight_kg     DECIMAL(6,2) DEFAULT NULL,
    log_date      DATE NOT NULL,
    notes         VARCHAR(255) DEFAULT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES workout_plans(id) ON DELETE SET NULL,
    INDEX idx_user_date (user_id, log_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Coding: project manager ──────────────────────────────────
CREATE TABLE IF NOT EXISTS projects (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    name        VARCHAR(120) NOT NULL,
    description VARCHAR(500) DEFAULT NULL,
    github_url  VARCHAR(300) DEFAULT NULL,
    status      ENUM('planning','active','paused','done') DEFAULT 'planning',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_tasks (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    title      VARCHAR(200) NOT NULL,
    status     ENUM('todo','doing','done') DEFAULT 'todo',
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    INDEX idx_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Reading: personal library ────────────────────────────────
CREATE TABLE IF NOT EXISTS books (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    title       VARCHAR(200) NOT NULL,
    author      VARCHAR(150) DEFAULT NULL,
    status      ENUM('want','reading','finished') DEFAULT 'want',
    rating      TINYINT DEFAULT NULL,
    notes       VARCHAR(500) DEFAULT NULL,
    started_at  DATE DEFAULT NULL,
    finished_at DATE DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_status (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
