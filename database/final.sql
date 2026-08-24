-- ============================================================
--  Trackie v1.0 — Complete Database Schema
--  Run this on a fresh database called `trackie`
--  mysql -u root trackie < trackie_v1.sql
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS study_plan;
DROP TABLE IF EXISTS habit_status_log;
DROP TABLE IF EXISTS logs;
DROP TABLE IF EXISTS habits;
DROP TABLE IF EXISTS todos;
DROP TABLE IF EXISTS routines;
DROP TABLE IF EXISTS goals;
DROP TABLE IF EXISTS remember_tokens;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;

-- ── Users ────────────────────────────────────────────────────
CREATE TABLE users (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100)  NOT NULL,
    email       VARCHAR(150)  NOT NULL UNIQUE,
    phone       VARCHAR(20),
    password    VARCHAR(255)  NOT NULL,
    profile_pic VARCHAR(255),
    is_admin    TINYINT(1) DEFAULT 0,
    hobbies     VARCHAR(300) DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Remember-me tokens ───────────────────────────────────────
CREATE TABLE remember_tokens (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT          NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME     NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token  (token_hash),
    INDEX idx_user   (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Habits ───────────────────────────────────────────────────
CREATE TABLE habits (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT          NOT NULL,
    name       VARCHAR(100) NOT NULL,
    frequency  ENUM('daily','weekly') DEFAULT 'daily',
    color      VARCHAR(7)   DEFAULT '#ef4444',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Habit completion logs ─────────────────────────────────────
CREATE TABLE logs (
    id             INT  AUTO_INCREMENT PRIMARY KEY,
    user_id        INT  NOT NULL,
    habit_id       INT  NOT NULL,
    date_completed DATE NOT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
    FOREIGN KEY (habit_id) REFERENCES habits(id) ON DELETE CASCADE,
    UNIQUE KEY unique_log (habit_id, date_completed),
    INDEX idx_user_date (user_id, date_completed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Habit fail/skip log (Done lives in `logs` above; this covers the other two states) ──
DROP TABLE IF EXISTS habit_status_log;
CREATE TABLE habit_status_log (
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

-- ── Daily Routines ───────────────────────────────────────────
CREATE TABLE routines (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT         NOT NULL,
    title       VARCHAR(150) NOT NULL,
    time_slot   TIME         NOT NULL,
    category    ENUM('Fitness','Work','Study','Personal','Health','Break') DEFAULT 'Personal',
    description TEXT,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Todos ────────────────────────────────────────────────────
CREATE TABLE todos (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT          NOT NULL,
    title        VARCHAR(150) NOT NULL,
    description  TEXT,
    due_date     DATE,
    priority     ENUM('low','medium','high') DEFAULT 'medium',
    location     VARCHAR(255),
    recurring    ENUM('none','daily','weekly','monthly') DEFAULT 'none',
    completed    TINYINT(1)   DEFAULT 0,
    completed_at DATETIME,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at   DATETIME DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_active  (user_id, deleted_at),
    INDEX idx_user_due     (user_id, due_date, deleted_at),
    INDEX idx_user_status  (user_id, completed, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Goals ────────────────────────────────────────────────────
CREATE TABLE goals (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT          NOT NULL,
    goal_name    VARCHAR(150) NOT NULL,
    description  TEXT,
    progress     INT          DEFAULT 0,
    target_value INT          DEFAULT 100,
    deadline     DATE,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Study Plan ───────────────────────────────────────────────
CREATE TABLE study_plan (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT          NOT NULL,
    title        VARCHAR(150) NOT NULL,
    description  TEXT,
    subject      VARCHAR(100),
    due_date     DATE,
    type         ENUM('study','homework','practice','project','exam','reading','revision','other') DEFAULT 'study',
    priority     ENUM('high','medium','low') DEFAULT 'medium',
    resource     VARCHAR(500),
    completed    TINYINT(1)   DEFAULT 0,
    completed_at DATETIME,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_date      (user_id, due_date),
    INDEX idx_user_completed (user_id, completed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- ── Reminders ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS reminders (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    title         VARCHAR(200) NOT NULL,
    notes         VARCHAR(500) DEFAULT NULL,
    type          ENUM('once','recurring','smart') DEFAULT 'once',
    remind_date   DATE DEFAULT NULL,
    remind_time   TIME NOT NULL,
    repeat_every  INT DEFAULT 1,
    repeat_unit   ENUM('hour','day','week') DEFAULT 'day',
    habit_id      INT DEFAULT NULL,
    next_fire_at  DATETIME NOT NULL,
    last_fired_at DATETIME DEFAULT NULL,
    active        TINYINT(1) DEFAULT 1,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
    FOREIGN KEY (habit_id) REFERENCES habits(id) ON DELETE CASCADE,
    INDEX idx_user_active (user_id, active, next_fire_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Finance: transactions ────────────────────────────────────
CREATE TABLE IF NOT EXISTS transactions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    type          ENUM('income','expense') NOT NULL,
    title         VARCHAR(150) NOT NULL,
    amount        DECIMAL(10,2) NOT NULL,
    category      VARCHAR(80) DEFAULT 'Other',
    expense_class ENUM('need','want') DEFAULT NULL,
    tx_date       DATE NOT NULL,
    notes         VARCHAR(500) DEFAULT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_date (user_id, tx_date),
    INDEX idx_user_type (user_id, type, tx_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Finance: subscriptions ───────────────────────────────────
CREATE TABLE IF NOT EXISTS subscriptions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    name          VARCHAR(100) NOT NULL,
    amount        DECIMAL(10,2) NOT NULL,
    billing_cycle ENUM('monthly','yearly') DEFAULT 'monthly',
    next_renewal  DATE NOT NULL,
    active        TINYINT(1) DEFAULT 1,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id, active, next_renewal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Finance: budgets ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS budgets (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    category      VARCHAR(80) NOT NULL,
    monthly_limit DECIMAL(10,2) NOT NULL,
    UNIQUE KEY uniq_user_cat (user_id, category),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
--  Hobby modules (personalized from Profile → Your hobbies)
-- ================================================================

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

CREATE TABLE IF NOT EXISTS workout_plan_items (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    plan_id       INT NOT NULL,
    exercise_name VARCHAR(100) NOT NULL,
    target_sets   INT DEFAULT 3,
    target_reps   INT DEFAULT 10,
    target_weight DECIMAL(6,2) DEFAULT NULL,
    rest_seconds  INT DEFAULT NULL,
    notes         VARCHAR(255) DEFAULT NULL,
    sort_order    INT DEFAULT 0,
    FOREIGN KEY (plan_id) REFERENCES workout_plans(id) ON DELETE CASCADE,
    INDEX idx_plan (plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS workout_logs (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    plan_id       INT DEFAULT NULL,
    session_id    INT DEFAULT NULL,
    exercise_name VARCHAR(100) NOT NULL,
    sets          INT DEFAULT NULL,
    reps          INT DEFAULT NULL,
    weight_kg     DECIMAL(6,2) DEFAULT NULL,
    log_date      DATE NOT NULL,
    notes         VARCHAR(255) DEFAULT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES workout_plans(id) ON DELETE SET NULL,
    INDEX idx_user_date (user_id, log_date),
    INDEX idx_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per guided Active Workout session (start → finish).
CREATE TABLE IF NOT EXISTS workout_sessions (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    plan_id      INT DEFAULT NULL,
    plan_name    VARCHAR(100) DEFAULT NULL,
    session_date DATE NOT NULL,
    started_at   DATETIME NOT NULL,
    ended_at     DATETIME DEFAULT NULL,
    duration_sec INT DEFAULT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES workout_plans(id) ON DELETE SET NULL,
    INDEX idx_user_date (user_id, session_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Real per-set data (what each individual set weighed/reps'd) — a
-- workout_logs row can exist without any workout_sets children (the
-- quick one-off "Log Workout" modal writes only the aggregate row).
CREATE TABLE IF NOT EXISTS workout_sets (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    log_id     INT NOT NULL,
    set_number INT NOT NULL,
    reps       INT DEFAULT NULL,
    weight_kg  DECIMAL(6,2) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (log_id) REFERENCES workout_logs(id) ON DELETE CASCADE,
    INDEX idx_log (log_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Exercise library: user_id NULL = built-in (seeded via setup.php with
-- fixed IDs 1-42), non-NULL = a user's own custom exercise.
CREATE TABLE IF NOT EXISTS exercise_library (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT DEFAULT NULL,
    name         VARCHAR(100) NOT NULL,
    muscle_group VARCHAR(40) DEFAULT NULL,
    equipment    VARCHAR(40) DEFAULT NULL,
    category     VARCHAR(40) DEFAULT NULL,
    video_path   VARCHAR(255) DEFAULT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_muscle (muscle_group),
    INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Optional body/fitness stats — one entry per user per day.
CREATE TABLE IF NOT EXISTS body_stats (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    log_date     DATE NOT NULL,
    weight_kg    DECIMAL(5,2) DEFAULT NULL,
    body_fat_pct DECIMAL(4,1) DEFAULT NULL,
    notes        VARCHAR(255) DEFAULT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_user_date (user_id, log_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- exercise_library seed lives in setup.php (INSERT IGNORE, fixed IDs 1-42)
-- rather than here, since final.sql is schema-only elsewhere in this file.

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

-- ── Generic journal (reusable by any hobby module) ─────────────
CREATE TABLE IF NOT EXISTS hobby_journal (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    hobby      VARCHAR(40) NOT NULL,
    entry_date DATE NOT NULL,
    body       TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_hobby (user_id, hobby, entry_date)
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

-- ── Gaming: game library ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS games (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    title        VARCHAR(150) NOT NULL,
    platform     VARCHAR(60) DEFAULT NULL,
    steam_appid  INT DEFAULT NULL,
    cover_url    VARCHAR(255) DEFAULT NULL,
    status       ENUM('wishlist','backlog','playing','completed') DEFAULT 'backlog',
    hours_played DECIMAL(6,1) DEFAULT 0,
    last_played  DATETIME DEFAULT NULL,
    rating       TINYINT DEFAULT NULL,
    notes        VARCHAR(500) DEFAULT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_status (user_id, status),
    UNIQUE KEY uniq_user_steamapp (user_id, steam_appid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Cooking: recipe box ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS recipes (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    title         VARCHAR(150) NOT NULL,
    category      VARCHAR(60) DEFAULT NULL,
    cook_time_min INT DEFAULT NULL,
    status        ENUM('want_to_try','tried','favorite') DEFAULT 'want_to_try',
    rating        TINYINT DEFAULT NULL,
    notes         VARCHAR(500) DEFAULT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_status (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Art: sketchbook / gallery log ─────────────────────────────────
CREATE TABLE IF NOT EXISTS artworks (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    title      VARCHAR(150) NOT NULL,
    medium     VARCHAR(60) DEFAULT NULL,
    status     ENUM('in_progress','completed') DEFAULT 'in_progress',
    notes      VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_status (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Sports: training/match log ────────────────────────────────────
CREATE TABLE IF NOT EXISTS sports_sessions (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    sport        VARCHAR(60) NOT NULL,
    session_type ENUM('training','match','practice') DEFAULT 'training',
    duration_min INT DEFAULT NULL,
    notes        VARCHAR(500) DEFAULT NULL,
    session_date DATE NOT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_date (user_id, session_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Writing: pieces tracker ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS writings (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    title      VARCHAR(150) NOT NULL,
    type       ENUM('draft','article','story','book','idea') DEFAULT 'draft',
    word_count INT DEFAULT 0,
    status     ENUM('idea','drafting','editing','published') DEFAULT 'idea',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_status (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Meditation: session log ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS meditation_sessions (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    duration_min INT NOT NULL,
    mood_before  TINYINT DEFAULT NULL,
    mood_after   TINYINT DEFAULT NULL,
    notes        VARCHAR(500) DEFAULT NULL,
    session_date DATE NOT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_date (user_id, session_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Photography: shoot log ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS photos (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    title      VARCHAR(150) NOT NULL,
    location   VARCHAR(150) DEFAULT NULL,
    camera     VARCHAR(100) DEFAULT NULL,
    status     ENUM('to_edit','edited') DEFAULT 'to_edit',
    taken_date DATE DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_status (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Gardening: plant collection ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS plants (
    id                    INT AUTO_INCREMENT PRIMARY KEY,
    user_id               INT NOT NULL,
    name                  VARCHAR(100) NOT NULL,
    species               VARCHAR(100) DEFAULT NULL,
    water_frequency_days  INT DEFAULT 7,
    last_watered          DATE DEFAULT NULL,
    status                ENUM('healthy','needs_attention','dormant') DEFAULT 'healthy',
    created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_status (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
