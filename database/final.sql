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
    schedule_days VARCHAR(13) DEFAULT NULL,
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
    target_muscle     VARCHAR(60)  DEFAULT NULL,
    secondary_muscles VARCHAR(255) DEFAULT NULL,
    equipment    VARCHAR(40) DEFAULT NULL,
    category     VARCHAR(40) DEFAULT NULL,
    difficulty   VARCHAR(20) DEFAULT NULL,
    instructions TEXT        DEFAULT NULL,
    video_path   VARCHAR(255) DEFAULT NULL,
    video_url    VARCHAR(500) DEFAULT NULL,
    thumbnail_url VARCHAR(500) DEFAULT NULL,
    source       VARCHAR(20)  NOT NULL DEFAULT 'library',
    external_id  VARCHAR(100) DEFAULT NULL,
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
    pages_total  INT DEFAULT NULL,
    current_page INT NOT NULL DEFAULT 0,
    cover_url    VARCHAR(255) DEFAULT NULL,
    isbn         VARCHAR(20) DEFAULT NULL,
    ol_key       VARCHAR(40) DEFAULT NULL,
    publish_year SMALLINT DEFAULT NULL,
    subjects     VARCHAR(255) DEFAULT NULL,
    status      ENUM('want','reading','finished','paused') DEFAULT 'want',
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
    status       ENUM('wishlist','backlog','playing','completed','dropped') DEFAULT 'backlog',
    hours_played DECIMAL(6,1) DEFAULT 0,
    last_played  DATETIME DEFAULT NULL,
    playtime_2weeks INT DEFAULT NULL,
    ach_done     SMALLINT DEFAULT NULL,
    ach_total    SMALLINT DEFAULT NULL,
    ach_synced_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
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
    duration_min INT DEFAULT NULL,
    notes      VARCHAR(1000) DEFAULT NULL,
    project_id INT DEFAULT NULL,
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

-- ── WorkoutDB lookup cache (includes/workoutdb.php) ─────────────────
-- Global catalogue cache, not user data: keyed by normalized exercise
-- name; status 'hit' | 'miss' | 'error' decides the TTL.
CREATE TABLE IF NOT EXISTS workoutdb_cache (
    query_key  VARCHAR(191) NOT NULL PRIMARY KEY,
    status     VARCHAR(8)   NOT NULL,
    payload    TEXT         DEFAULT NULL,
    fetched_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Fitness V2: goals (progress computed from logs, never stored) ───
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

-- ── Fitness V2: nutrition (calories EATEN as entered; nothing estimated)
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

-- ── Fitness V2: recovery check-ins (raw inputs only, no derived score)
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

-- ── Gaming V2: Steam playtime snapshots, store metadata, API cache ──
-- Cumulative playtime per sync day; steam_appid 0 = "synced that day" marker.
CREATE TABLE IF NOT EXISTS steam_playtime_snapshots (
    user_id      INT  NOT NULL,
    steam_appid  INT  NOT NULL,
    snap_date    DATE NOT NULL,
    playtime_min INT  NOT NULL,
    PRIMARY KEY (user_id, steam_appid, snap_date),
    INDEX idx_user_date (user_id, snap_date),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS steam_app_meta (
    appid        INT          NOT NULL PRIMARY KEY,
    status       VARCHAR(8)   NOT NULL,
    genres       VARCHAR(255) DEFAULT NULL,
    categories   VARCHAR(600) DEFAULT NULL,
    multiplayer  TINYINT(1)   NOT NULL DEFAULT 0,
    coop         TINYINT(1)   NOT NULL DEFAULT 0,
    online_coop  TINYINT(1)   NOT NULL DEFAULT 0,
    crossplay    TINYINT(1)   NOT NULL DEFAULT 0,
    fetched_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS provider_cache (
    cache_key  VARCHAR(191) NOT NULL PRIMARY KEY,
    status     VARCHAR(8)   NOT NULL,
    payload    MEDIUMTEXT   DEFAULT NULL,
    fetched_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Reading V2: sessions, notes & quotes, yearly goals ──
CREATE TABLE IF NOT EXISTS reading_sessions (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT  NOT NULL,
    book_id      INT  NOT NULL,
    session_date DATE NOT NULL,
    minutes      INT  NOT NULL,
    start_page   INT  DEFAULT NULL,
    end_page     INT  DEFAULT NULL,
    pages        INT  NOT NULL DEFAULT 0,
    note         VARCHAR(500) DEFAULT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
    INDEX idx_user_date (user_id, session_date),
    INDEX idx_book (book_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS book_notes (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    book_id    INT NOT NULL,
    kind       ENUM('note','quote') NOT NULL DEFAULT 'note',
    body       TEXT NOT NULL,
    page       INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
    INDEX idx_user_kind (user_id, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS reading_goals (
    user_id      INT NOT NULL,
    year         SMALLINT NOT NULL,
    books_target INT DEFAULT NULL,
    pages_target INT DEFAULT NULL,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, year),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Photography V2: projects, uploaded images (EXIF), gear, goals ──
CREATE TABLE IF NOT EXISTS photo_projects (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    name        VARCHAR(120) NOT NULL,
    description VARCHAR(500) DEFAULT NULL,
    status      ENUM('active','done') NOT NULL DEFAULT 'active',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS photo_images (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    shoot_id     INT DEFAULT NULL,
    project_id   INT DEFAULT NULL,
    file         VARCHAR(120) NOT NULL,
    thumb        VARCHAR(120) NOT NULL,
    width        INT DEFAULT NULL,
    height       INT DEFAULT NULL,
    bytes        INT DEFAULT NULL,
    title        VARCHAR(150) DEFAULT NULL,
    caption      VARCHAR(1000) DEFAULT NULL,
    taken_at     DATETIME     DEFAULT NULL,
    camera       VARCHAR(100) DEFAULT NULL,
    lens         VARCHAR(100) DEFAULT NULL,
    iso          INT          DEFAULT NULL,
    shutter      VARCHAR(20)  DEFAULT NULL,
    aperture     DECIMAL(4,1) DEFAULT NULL,
    focal_mm     DECIMAL(6,1) DEFAULT NULL,
    exif_source  VARCHAR(8)   DEFAULT NULL,
    favorite     TINYINT(1)   NOT NULL DEFAULT 0,
    edit_status  ENUM('raw','editing','edited') NOT NULL DEFAULT 'raw',
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)    REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (shoot_id)   REFERENCES photos(id) ON DELETE SET NULL,
    FOREIGN KEY (project_id) REFERENCES photo_projects(id) ON DELETE SET NULL,
    INDEX idx_user_created (user_id, created_at),
    INDEX idx_user_taken (user_id, taken_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS photo_gear (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    kind       ENUM('camera','lens','accessory') NOT NULL,
    name       VARCHAR(100) NOT NULL,
    notes      VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_kind (user_id, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS photo_goals (
    user_id          INT PRIMARY KEY,
    photos_per_month INT DEFAULT NULL,
    shoots_per_month INT DEFAULT NULL,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Core engine: user settings + central activity log ──
CREATE TABLE IF NOT EXISTS user_settings (
    user_id               INT PRIMARY KEY,
    currency              CHAR(3)    NOT NULL DEFAULT 'INR',
    week_start            TINYINT(1) NOT NULL DEFAULT 0,   -- 0 = Sunday, 1 = Monday
    notify_reminders      TINYINT(1) NOT NULL DEFAULT 1,   -- reminder pop-ups / push
    notify_achievements   TINYINT(1) NOT NULL DEFAULT 1,   -- achievement + streak notifications
    activity_backfilled_at DATETIME  DEFAULT NULL,         -- set once history is copied into activity_log
    updated_at            TIMESTAMP  DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS activity_log (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT          NOT NULL,
    module      VARCHAR(30)  NOT NULL,
    action      VARCHAR(40)  NOT NULL,
    ref_type    VARCHAR(50)  NOT NULL DEFAULT '',
    ref_id      INT          DEFAULT NULL,
    occurred_on DATE         NOT NULL,
    xp          INT          NOT NULL DEFAULT 0,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_activity (user_id, action, ref_type, ref_id),
    INDEX idx_user_day (user_id, occurred_on),
    INDEX idx_user_module_day (user_id, module, occurred_on),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Life V2 ──
ALTER TABLE sports_sessions ADD COLUMN result VARCHAR(5) DEFAULT NULL AFTER session_type,
    ADD COLUMN score VARCHAR(40) DEFAULT NULL AFTER result, ADD COLUMN intensity TINYINT DEFAULT NULL AFTER score;
ALTER TABLE meditation_sessions ADD COLUMN technique VARCHAR(40) DEFAULT NULL AFTER duration_min;
ALTER TABLE goals ADD COLUMN kind VARCHAR(20) NOT NULL DEFAULT 'general' AFTER goal_name;

-- ── Hobbies V2 ──
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
    meal      VARCHAR(10) NOT NULL,            
    recipe_id INT DEFAULT NULL,
    note      VARCHAR(120) DEFAULT NULL,
    PRIMARY KEY (user_id, plan_date, meal),
    FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
    FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

ALTER TABLE plants
    ADD COLUMN IF NOT EXISTS location VARCHAR(80)  DEFAULT NULL AFTER species,
    ADD COLUMN IF NOT EXISTS notes    VARCHAR(500) DEFAULT NULL AFTER status;

CREATE TABLE IF NOT EXISTS plant_logs (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    plant_id   INT NOT NULL,
    log_date   DATE NOT NULL,
    kind       VARCHAR(12) NOT NULL,           
    note       VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
    FOREIGN KEY (plant_id) REFERENCES plants(id) ON DELETE CASCADE,
    INDEX idx_plant_date (plant_id, log_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

-- ============================================================
--  Trackie — Notification preferences (2026-09-30)
--  timezone     IANA name (e.g. 'Europe/London'); NULL = server default.
--               Applied per request to PHP + the MySQL session, so "today",
--               streaks and reminder times follow the user's local midnight.
--  quiet_start / quiet_end  local times; reminders during quiet hours still
--               land in the bell list but send no push / pop-up.
--  Additive + idempotent; safe on the live database.
-- ============================================================
ALTER TABLE user_settings
    ADD COLUMN IF NOT EXISTS timezone    VARCHAR(64) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS quiet_start TIME DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS quiet_end   TIME DEFAULT NULL;

-- ============================================================
--  Trackie — Routines on specific days (2026-09-30)
--  schedule_days: comma list of weekdays (0 = Sunday … 6 = Saturday) the
--  routine is due. NULL = every day (existing behaviour). Same format and
--  helpers as habits.schedule_days (includes/habit_schedule.php).
--  Additive + idempotent; safe on the live database.
-- ============================================================
ALTER TABLE routines ADD COLUMN IF NOT EXISTS schedule_days VARCHAR(13) DEFAULT NULL AFTER time_slot;

-- ============================================================
--  Trackie — Goals with a linked progress source (2026-09-30)
--  source        NULL = manual progress (existing behaviour); otherwise one
--                of includes/goal_sources.php GOAL_SOURCES (habit, todos,
--                focus, workouts, reading, writing, meditation, coding).
--  source_ref    e.g. the habit id for source='habit'.
--  source_since  count activity from this date (defaults to creation day).
--  Additive + idempotent; safe on the live database.
-- ============================================================
ALTER TABLE goals
    ADD COLUMN IF NOT EXISTS source       VARCHAR(20) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS source_ref   INT DEFAULT NULL AFTER source,
    ADD COLUMN IF NOT EXISTS source_since DATE DEFAULT NULL AFTER source_ref;

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

-- ============================================================
--  Trackie — User presence (2026-10-07)
--  One row per user per day they used Trackie, split by client:
--  web_hits = website (browser / installed PWA), app_hits = Android app
--  (User-Agent "TrackieApp/"). A hit is counted at most once per 5 minutes
--  per session, so the numbers read as "active 5-minute slots".
--  Powers Admin → Users: online now, DAU/WAU/MAU, web vs app.
--  Additive + idempotent; safe on the live database.
-- ============================================================
CREATE TABLE IF NOT EXISTS user_presence (
    user_id   INT NOT NULL,
    day       DATE NOT NULL,
    web_hits  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    app_hits  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    first_at  DATETIME NOT NULL,
    last_at   DATETIME NOT NULL,
    last_client VARCHAR(8) NOT NULL DEFAULT 'web',
    PRIMARY KEY (user_id, day),
    INDEX idx_day (day),
    INDEX idx_last (last_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  Trackie — Admin-editable settings (2026-10-07)
--  Non-secret settings set from Admin → Configuration: MAIL_FROM,
--  MAIL_FROM_NAME, SUPPORT_EMAIL, APP_URL. config/env.php still wins when it
--  sets a value. Secrets (API keys, passwords, tokens) never go here.
--  Additive + idempotent; safe on the live database.
-- ============================================================
CREATE TABLE IF NOT EXISTS app_config (
    name       VARCHAR(40) PRIMARY KEY,
    value      VARCHAR(255) NOT NULL DEFAULT '',
    updated_by INT DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
