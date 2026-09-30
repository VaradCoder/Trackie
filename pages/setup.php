<?php
/**
 * Trackie — Database Setup & Migration runner
 * Visit: http://localhost/Trackie/pages/setup.php
 * Safe to run multiple times (IF NOT EXISTS / ADD COLUMN IF NOT EXISTS).
 */
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

/*
 * Access guard: allowed only for an admin, or on a fresh install
 * (users table missing or empty). Blocks anonymous visitors from running
 * migrations on a live deployment.
 */
$freshInstall = false;
try {
    $row = fetchOne("SELECT COUNT(*) c FROM users");
    $freshInstall = ((int)($row['c'] ?? 0)) === 0;
} catch (Throwable $e) {
    $freshInstall = true; // users table doesn't exist yet
}

// Admins only once users exist: migrations change the schema and the runner
// prints raw database errors, neither of which regular users should reach.
if (!$freshInstall && !isAdmin()) {
    http_response_code(403);
    exit(isLoggedIn() ? 'Forbidden — only an admin can run database migrations.' : 'Forbidden — log in as an admin to run database migrations.');
}

// Each migration is a label + SQL pair
$migrations = [

    'users table' => "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(150) NOT NULL UNIQUE,
        phone VARCHAR(20),
        password VARCHAR(255) NOT NULL,
        profile_pic VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'remember_tokens table' => "CREATE TABLE IF NOT EXISTS remember_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token_hash VARCHAR(255) NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_token (token_hash),
        INDEX idx_user  (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'habits table' => "CREATE TABLE IF NOT EXISTS habits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        frequency ENUM('daily','weekly') DEFAULT 'daily',
        color VARCHAR(7) DEFAULT '#ef4444',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'logs table' => "CREATE TABLE IF NOT EXISTS logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        habit_id INT NOT NULL,
        date_completed DATE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (habit_id) REFERENCES habits(id) ON DELETE CASCADE,
        UNIQUE KEY unique_log (habit_id, date_completed),
        INDEX idx_user_date (user_id, date_completed)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'habit_status_log table' => "CREATE TABLE IF NOT EXISTS habit_status_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        habit_id INT NOT NULL,
        log_date DATE NOT NULL,
        status ENUM('fail','skip') NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (habit_id) REFERENCES habits(id) ON DELETE CASCADE,
        UNIQUE KEY unique_status (habit_id, log_date),
        INDEX idx_user_date (user_id, log_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'routines table' => "CREATE TABLE IF NOT EXISTS routines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(150) NOT NULL,
        time_slot TIME NOT NULL,
        category ENUM('Fitness','Work','Study','Personal','Health','Break') DEFAULT 'Personal',
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'todos table' => "CREATE TABLE IF NOT EXISTS todos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(150) NOT NULL,
        description TEXT,
        due_date DATE,
        priority ENUM('low','medium','high') DEFAULT 'medium',
        location VARCHAR(255),
        recurring ENUM('none','daily','weekly','monthly') DEFAULT 'none',
        completed TINYINT(1) DEFAULT 0,
        completed_at DATETIME,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        deleted_at DATETIME DEFAULT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user_active (user_id, deleted_at),
        INDEX idx_user_due    (user_id, due_date, deleted_at),
        INDEX idx_user_status (user_id, completed, deleted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'goals table' => "CREATE TABLE IF NOT EXISTS goals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        goal_name VARCHAR(150) NOT NULL,
        description TEXT,
        progress INT DEFAULT 0,
        target_value INT DEFAULT 100,
        deadline DATE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'study_plan table' => "CREATE TABLE IF NOT EXISTS study_plan (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(150) NOT NULL,
        description TEXT,
        subject VARCHAR(100),
        due_date DATE,
        type ENUM('study','homework','practice','project','exam','reading','revision','other') DEFAULT 'study',
        priority ENUM('high','medium','low') DEFAULT 'medium',
        resource VARCHAR(500),
        completed TINYINT(1) DEFAULT 0,
        completed_at DATETIME,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user_date      (user_id, due_date),
        INDEX idx_user_completed (user_id, completed)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'password_resets table' => "CREATE TABLE IF NOT EXISTS password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token_hash VARCHAR(255) NOT NULL,
        expires_at DATETIME NOT NULL,
        used TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_token (token_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'rate_limits table' => "CREATE TABLE IF NOT EXISTS rate_limits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        identifier VARCHAR(100) NOT NULL,
        action VARCHAR(50) NOT NULL,
        attempts INT DEFAULT 1,
        window_start DATETIME NOT NULL,
        INDEX idx_id_action (identifier, action)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'notifications table' => "CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        type VARCHAR(50) NOT NULL,
        title VARCHAR(200) NOT NULL,
        message TEXT,
        link VARCHAR(255),
        is_read TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user_read    (user_id, is_read),
        INDEX idx_user_created (user_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // Column additions — safe because MySQL ignores duplicate ADD IF NOT EXISTS
    'users.is_admin column' => "ALTER TABLE users
        ADD COLUMN IF NOT EXISTS is_admin TINYINT(1) DEFAULT 0",

    'users.hobbies column' => "ALTER TABLE users
        ADD COLUMN IF NOT EXISTS hobbies VARCHAR(300) DEFAULT NULL",

    'owner is admin' => "UPDATE users SET is_admin=1 WHERE email='varadbhole09@gmail.com'",

    'todos.category column' => "ALTER TABLE todos
        ADD COLUMN IF NOT EXISTS category VARCHAR(80) DEFAULT NULL AFTER priority",

    'todos.tags column' => "ALTER TABLE todos
        ADD COLUMN IF NOT EXISTS tags VARCHAR(500) DEFAULT NULL AFTER category",

    // ── V2 ────────────────────────────────────────────────────
    'todos.parent_id (subtasks)' => "ALTER TABLE todos
        ADD COLUMN IF NOT EXISTS parent_id INT DEFAULT NULL AFTER id",

    'todos.status (kanban)' => "ALTER TABLE todos
        ADD COLUMN IF NOT EXISTS status
        ENUM('backlog','today','in_progress','done') DEFAULT 'today' AFTER recurring",

    'habits.group_name' => "ALTER TABLE habits
        ADD COLUMN IF NOT EXISTS group_name VARCHAR(80) DEFAULT NULL AFTER frequency",

    'focus_sessions table' => "CREATE TABLE IF NOT EXISTS focus_sessions (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        user_id      INT NOT NULL,
        todo_id      INT DEFAULT NULL,
        duration_min INT DEFAULT 25,
        type         ENUM('focus','short_break','long_break') DEFAULT 'focus',
        started_at   DATETIME NOT NULL,
        completed_at DATETIME DEFAULT NULL,
        completed    TINYINT(1) DEFAULT 0,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (todo_id) REFERENCES todos(id) ON DELETE SET NULL,
        INDEX idx_user_date (user_id, started_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'user_xp table' => "CREATE TABLE IF NOT EXISTS user_xp (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        user_id    INT NOT NULL UNIQUE,
        total_xp   INT DEFAULT 0,
        level      INT DEFAULT 1,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'xp_events table' => "CREATE TABLE IF NOT EXISTS xp_events (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        user_id    INT NOT NULL,
        action     VARCHAR(80) NOT NULL,
        xp         INT NOT NULL,
        ref_type   VARCHAR(50) DEFAULT NULL,
        ref_id     INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'reminders table' => "CREATE TABLE IF NOT EXISTS reminders (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'transactions table' => "CREATE TABLE IF NOT EXISTS transactions (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'subscriptions table' => "CREATE TABLE IF NOT EXISTS subscriptions (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'budgets table' => "CREATE TABLE IF NOT EXISTS budgets (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        user_id       INT NOT NULL,
        category      VARCHAR(80) NOT NULL,
        monthly_limit DECIMAL(10,2) NOT NULL,
        UNIQUE KEY uniq_user_cat (user_id, category),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'achievements table' => "CREATE TABLE IF NOT EXISTS achievements (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NOT NULL,
        key_name    VARCHAR(80) NOT NULL,
        unlocked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_achievement (user_id, key_name),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ── Hobby modules (personalized from Profile → Your hobbies) ──────

    // Fitness — gym tracker
    'workout_plans table' => "CREATE TABLE IF NOT EXISTS workout_plans (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        user_id    INT NOT NULL,
        name       VARCHAR(100) NOT NULL,
        day_of_week ENUM('Any','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') DEFAULT 'Any',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'workout_plans.day_of_week column' => "ALTER TABLE workout_plans
        ADD COLUMN IF NOT EXISTS day_of_week
        ENUM('Any','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') DEFAULT 'Any'",

    'workout_plan_items table' => "CREATE TABLE IF NOT EXISTS workout_plan_items (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        plan_id       INT NOT NULL,
        exercise_name VARCHAR(100) NOT NULL,
        target_sets   INT DEFAULT 3,
        target_reps   INT DEFAULT 10,
        sort_order    INT DEFAULT 0,
        FOREIGN KEY (plan_id) REFERENCES workout_plans(id) ON DELETE CASCADE,
        INDEX idx_plan (plan_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'workout_logs table' => "CREATE TABLE IF NOT EXISTS workout_logs (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Coding — project manager
    'projects table' => "CREATE TABLE IF NOT EXISTS projects (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NOT NULL,
        name        VARCHAR(120) NOT NULL,
        description VARCHAR(500) DEFAULT NULL,
        github_url  VARCHAR(300) DEFAULT NULL,
        status      ENUM('planning','active','paused','done') DEFAULT 'planning',
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'project_tasks table' => "CREATE TABLE IF NOT EXISTS project_tasks (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        title      VARCHAR(200) NOT NULL,
        status     ENUM('todo','doing','done') DEFAULT 'todo',
        sort_order INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
        INDEX idx_project (project_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ── Generic journal — reusable by any hobby module ─────────────
    'hobby_journal table' => "CREATE TABLE IF NOT EXISTS hobby_journal (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        user_id    INT NOT NULL,
        hobby      VARCHAR(40) NOT NULL,
        entry_date DATE NOT NULL,
        body       TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user_hobby (user_id, hobby, entry_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Reading — personal library
    'books table' => "CREATE TABLE IF NOT EXISTS books (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        user_id    INT NOT NULL,
        title      VARCHAR(200) NOT NULL,
        author     VARCHAR(150) DEFAULT NULL,
        status     ENUM('want','reading','finished') DEFAULT 'want',
        rating     TINYINT DEFAULT NULL,
        notes      VARCHAR(500) DEFAULT NULL,
        started_at DATE DEFAULT NULL,
        finished_at DATE DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user_status (user_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ── Reading V2 (app/Modules/Reading): progress, sessions, notes, goals ──
    'books.status paused' => "ALTER TABLE books MODIFY COLUMN status ENUM('want','reading','finished','paused') DEFAULT 'want'",
    'books v2 columns' => "ALTER TABLE books
        ADD COLUMN IF NOT EXISTS pages_total  INT          DEFAULT NULL AFTER author,
        ADD COLUMN IF NOT EXISTS current_page INT          NOT NULL DEFAULT 0 AFTER pages_total,
        ADD COLUMN IF NOT EXISTS cover_url    VARCHAR(255) DEFAULT NULL AFTER current_page,
        ADD COLUMN IF NOT EXISTS isbn         VARCHAR(20)  DEFAULT NULL AFTER cover_url,
        ADD COLUMN IF NOT EXISTS ol_key       VARCHAR(40)  DEFAULT NULL AFTER isbn,
        ADD COLUMN IF NOT EXISTS publish_year SMALLINT     DEFAULT NULL AFTER ol_key,
        ADD COLUMN IF NOT EXISTS subjects     VARCHAR(255) DEFAULT NULL AFTER publish_year",
    'reading_sessions table' => "CREATE TABLE IF NOT EXISTS reading_sessions (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'book_notes table' => "CREATE TABLE IF NOT EXISTS book_notes (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'reading_goals table' => "CREATE TABLE IF NOT EXISTS reading_goals (
        user_id      INT NOT NULL,
        year         SMALLINT NOT NULL,
        books_target INT DEFAULT NULL,
        pages_target INT DEFAULT NULL,
        updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, year),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Gaming — game library
    'games table' => "CREATE TABLE IF NOT EXISTS games (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        user_id      INT NOT NULL,
        title        VARCHAR(150) NOT NULL,
        platform     VARCHAR(60) DEFAULT NULL,
        status       ENUM('wishlist','backlog','playing','completed') DEFAULT 'backlog',
        hours_played DECIMAL(6,1) DEFAULT 0,
        rating       TINYINT DEFAULT NULL,
        notes        VARCHAR(500) DEFAULT NULL,
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user_status (user_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ── Steam sync (real owned-games/playtime data via IPlayerService) ──
    // steam_appid nullable + non-unique-alone: manual entries (no Steam
    // link) keep steam_appid NULL, and MySQL treats multiple NULLs as
    // distinct in a unique index, so they never collide with each other.
    'games.steam_appid' => "ALTER TABLE games
        ADD COLUMN IF NOT EXISTS steam_appid INT DEFAULT NULL AFTER platform",
    'games.cover_url' => "ALTER TABLE games
        ADD COLUMN IF NOT EXISTS cover_url VARCHAR(255) DEFAULT NULL AFTER steam_appid",
    'games.last_played' => "ALTER TABLE games
        ADD COLUMN IF NOT EXISTS last_played DATETIME DEFAULT NULL AFTER hours_played",
    'games.uniq_user_steamapp' => "ALTER TABLE games
        ADD UNIQUE INDEX IF NOT EXISTS uniq_user_steamapp (user_id, steam_appid)",

    // ── Gaming V2: Next Up / Wrapped / Co-op (app/Modules/Gaming) ──
    // Raw Steam values (minutes, achievement counts) + Trackie completion date.
    'games v2 columns' => "ALTER TABLE games
        ADD COLUMN IF NOT EXISTS playtime_2weeks INT      DEFAULT NULL AFTER last_played,
        ADD COLUMN IF NOT EXISTS ach_done        SMALLINT DEFAULT NULL AFTER playtime_2weeks,
        ADD COLUMN IF NOT EXISTS ach_total       SMALLINT DEFAULT NULL AFTER ach_done,
        ADD COLUMN IF NOT EXISTS ach_synced_at   DATETIME DEFAULT NULL AFTER ach_total,
        ADD COLUMN IF NOT EXISTS completed_at    DATETIME DEFAULT NULL AFTER ach_synced_at",
    // Cumulative playtime per sync day; appid 0 = "synced that day" marker.
    'steam_playtime_snapshots table' => "CREATE TABLE IF NOT EXISTS steam_playtime_snapshots (
        user_id      INT  NOT NULL,
        steam_appid  INT  NOT NULL,
        snap_date    DATE NOT NULL,
        playtime_min INT  NOT NULL,
        PRIMARY KEY (user_id, steam_appid, snap_date),
        INDEX idx_user_date (user_id, snap_date),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'steam_app_meta table' => "CREATE TABLE IF NOT EXISTS steam_app_meta (
        appid        INT          NOT NULL PRIMARY KEY,
        status       VARCHAR(8)   NOT NULL,
        genres       VARCHAR(255) DEFAULT NULL,
        categories   VARCHAR(600) DEFAULT NULL,
        multiplayer  TINYINT(1)   NOT NULL DEFAULT 0,
        coop         TINYINT(1)   NOT NULL DEFAULT 0,
        online_coop  TINYINT(1)   NOT NULL DEFAULT 0,
        crossplay    TINYINT(1)   NOT NULL DEFAULT 0,
        fetched_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'provider_cache table' => "CREATE TABLE IF NOT EXISTS provider_cache (
        cache_key  VARCHAR(191) NOT NULL PRIMARY KEY,
        status     VARCHAR(8)   NOT NULL,
        payload    MEDIUMTEXT   DEFAULT NULL,
        fetched_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Cooking — recipe box
    'recipes table' => "CREATE TABLE IF NOT EXISTS recipes (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        user_id      INT NOT NULL,
        title        VARCHAR(150) NOT NULL,
        category     VARCHAR(60) DEFAULT NULL,
        cook_time_min INT DEFAULT NULL,
        status       ENUM('want_to_try','tried','favorite') DEFAULT 'want_to_try',
        rating       TINYINT DEFAULT NULL,
        notes        VARCHAR(500) DEFAULT NULL,
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user_status (user_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Art — sketchbook / gallery log
    'artworks table' => "CREATE TABLE IF NOT EXISTS artworks (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        user_id    INT NOT NULL,
        title      VARCHAR(150) NOT NULL,
        medium     VARCHAR(60) DEFAULT NULL,
        status     ENUM('in_progress','completed') DEFAULT 'in_progress',
        notes      VARCHAR(500) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user_status (user_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Sports — training/match log
    'sports_sessions table' => "CREATE TABLE IF NOT EXISTS sports_sessions (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NOT NULL,
        sport       VARCHAR(60) NOT NULL,
        session_type ENUM('training','match','practice') DEFAULT 'training',
        duration_min INT DEFAULT NULL,
        notes       VARCHAR(500) DEFAULT NULL,
        session_date DATE NOT NULL,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user_date (user_id, session_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Writing — pieces tracker
    'writings table' => "CREATE TABLE IF NOT EXISTS writings (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        user_id    INT NOT NULL,
        title      VARCHAR(150) NOT NULL,
        type       ENUM('draft','article','story','book','idea') DEFAULT 'draft',
        word_count INT DEFAULT 0,
        status     ENUM('idea','drafting','editing','published') DEFAULT 'idea',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user_status (user_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Meditation — session log
    'meditation_sessions table' => "CREATE TABLE IF NOT EXISTS meditation_sessions (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Photography — shoot log
    'photos table' => "CREATE TABLE IF NOT EXISTS photos (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ── Photography V2 (app/Modules/Photography): uploads, EXIF, shoots, gear ──
    'photo_projects table' => "CREATE TABLE IF NOT EXISTS photo_projects (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NOT NULL,
        name        VARCHAR(120) NOT NULL,
        description VARCHAR(500) DEFAULT NULL,
        status      ENUM('active','done') NOT NULL DEFAULT 'active',
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'photos v2 columns' => "ALTER TABLE photos
        ADD COLUMN IF NOT EXISTS duration_min INT          DEFAULT NULL AFTER taken_date,
        ADD COLUMN IF NOT EXISTS notes        VARCHAR(1000) DEFAULT NULL AFTER duration_min,
        ADD COLUMN IF NOT EXISTS project_id   INT          DEFAULT NULL AFTER notes",
    'photo_images table' => "CREATE TABLE IF NOT EXISTS photo_images (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'photo_gear table' => "CREATE TABLE IF NOT EXISTS photo_gear (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        user_id    INT NOT NULL,
        kind       ENUM('camera','lens','accessory') NOT NULL,
        name       VARCHAR(100) NOT NULL,
        notes      VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user_kind (user_id, kind)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'photo_goals table' => "CREATE TABLE IF NOT EXISTS photo_goals (
        user_id          INT PRIMARY KEY,
        photos_per_month INT DEFAULT NULL,
        shoots_per_month INT DEFAULT NULL,
        updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ── Core engine (includes/activity.php, includes/settings.php) ──
    'user_settings table' => "CREATE TABLE IF NOT EXISTS user_settings (
        user_id               INT PRIMARY KEY,
        currency              CHAR(3)    NOT NULL DEFAULT 'INR',
        week_start            TINYINT(1) NOT NULL DEFAULT 0,   -- 0 = Sunday, 1 = Monday
        notify_reminders      TINYINT(1) NOT NULL DEFAULT 1,   -- reminder pop-ups / push
        notify_achievements   TINYINT(1) NOT NULL DEFAULT 1,   -- achievement + streak notifications
        activity_backfilled_at DATETIME  DEFAULT NULL,         -- set once history is copied into activity_log
        updated_at            TIMESTAMP  DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'activity_log table' => "CREATE TABLE IF NOT EXISTS activity_log (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'habits.schedule_days' => "ALTER TABLE habits ADD COLUMN IF NOT EXISTS schedule_days VARCHAR(13) DEFAULT NULL AFTER frequency",

    // ── Life V2 ──
    'sports v2 columns' => "ALTER TABLE sports_sessions
        ADD COLUMN IF NOT EXISTS result VARCHAR(5) DEFAULT NULL AFTER session_type,
        ADD COLUMN IF NOT EXISTS score VARCHAR(40) DEFAULT NULL AFTER result,
        ADD COLUMN IF NOT EXISTS intensity TINYINT DEFAULT NULL AFTER score",
    'meditation.technique' => "ALTER TABLE meditation_sessions ADD COLUMN IF NOT EXISTS technique VARCHAR(40) DEFAULT NULL AFTER duration_min",
    'goals.kind' => "ALTER TABLE goals ADD COLUMN IF NOT EXISTS kind VARCHAR(20) NOT NULL DEFAULT 'general' AFTER goal_name",

    // Gardening — plant collection
    'plants table' => "CREATE TABLE IF NOT EXISTS plants (
        id                INT AUTO_INCREMENT PRIMARY KEY,
        user_id           INT NOT NULL,
        name              VARCHAR(100) NOT NULL,
        species           VARCHAR(100) DEFAULT NULL,
        water_frequency_days INT DEFAULT 7,
        last_watered      DATE DEFAULT NULL,
        status            ENUM('healthy','needs_attention','dormant') DEFAULT 'healthy',
        created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user_status (user_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ── Hobbies V2 (Coding, Cooking, Writing, Art, Gardening) ──
    'hobbies v2 #1' => "CREATE TABLE IF NOT EXISTS coding_sessions (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'hobbies v2 #2' => "ALTER TABLE recipes
        ADD COLUMN IF NOT EXISTS servings    SMALLINT DEFAULT NULL AFTER cook_time_min,
        ADD COLUMN IF NOT EXISTS ingredients TEXT     DEFAULT NULL AFTER servings,
        ADD COLUMN IF NOT EXISTS steps       TEXT     DEFAULT NULL AFTER ingredients",
    'hobbies v2 #3' => "CREATE TABLE IF NOT EXISTS cook_logs (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'hobbies v2 #4' => "CREATE TABLE IF NOT EXISTS meal_plan (
        user_id   INT NOT NULL,
        plan_date DATE NOT NULL,
        meal      VARCHAR(10) NOT NULL,            
        recipe_id INT DEFAULT NULL,
        note      VARCHAR(120) DEFAULT NULL,
        PRIMARY KEY (user_id, plan_date, meal),
        FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
        FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'hobbies v2 #5' => "ALTER TABLE writings
        ADD COLUMN IF NOT EXISTS content    MEDIUMTEXT DEFAULT NULL AFTER word_count,
        ADD COLUMN IF NOT EXISTS updated_at DATETIME   DEFAULT NULL AFTER created_at",
    'hobbies v2 #6' => "CREATE TABLE IF NOT EXISTS writing_log (
        user_id     INT  NOT NULL,
        log_date    DATE NOT NULL,
        words_added INT  NOT NULL DEFAULT 0,
        PRIMARY KEY (user_id, log_date),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'hobbies v2 #7' => "ALTER TABLE user_settings ADD COLUMN IF NOT EXISTS writing_goal INT DEFAULT NULL AFTER notify_achievements",
    'hobbies v2 #8' => "ALTER TABLE artworks
        ADD COLUMN IF NOT EXISTS image_file  VARCHAR(120) DEFAULT NULL AFTER notes,
        ADD COLUMN IF NOT EXISTS image_thumb VARCHAR(120) DEFAULT NULL AFTER image_file",
    'hobbies v2 #9' => "CREATE TABLE IF NOT EXISTS art_sessions (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'hobbies v2 #10' => "ALTER TABLE plants
        ADD COLUMN IF NOT EXISTS location VARCHAR(80)  DEFAULT NULL AFTER species,
        ADD COLUMN IF NOT EXISTS notes    VARCHAR(500) DEFAULT NULL AFTER status",
    'hobbies v2 #11' => "CREATE TABLE IF NOT EXISTS plant_logs (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ── Phase 2 Week 1 — task completion-outcome state machine ──────
    // Named `outcome` (not `status`) — todos already has a `status` column
    // for kanban stage (backlog/today/in_progress/done); this is a distinct
    // concept (what actually happened to the task) and must not collide
    // with it. Additive + nullable + backfilled: zero risk to existing
    // completed-boolean logic, which keeps working unchanged this phase.
    'todos.outcome (completion state machine)' => "ALTER TABLE todos
        ADD COLUMN IF NOT EXISTS outcome
        ENUM('pending','in_progress','completed','skipped','failed','rescheduled','cancelled','archived')
        DEFAULT NULL AFTER status",

    'todos.outcome backfill' => "UPDATE todos
        SET outcome = CASE WHEN completed=1 THEN 'completed' ELSE 'pending' END
        WHERE outcome IS NULL",

    // ── Phase 2 Week 2 — onboarding ──────────────────────────────────
    // A single nullable timestamp, not a separate table (the blueprint
    // proposed `onboarding_state(user_id, step, completed_at)` for
    // resumable multi-session onboarding — v1 is a single JS-driven page
    // with no server-side step state to resume, so that table would be
    // unused complexity right now; extending `users` is the right-sized
    // call per the "extend before adding tables" rule. Revisit if
    // onboarding grows into a multi-session flow.)
    'users.onboarding_completed_at' => "ALTER TABLE users
        ADD COLUMN IF NOT EXISTS onboarding_completed_at DATETIME DEFAULT NULL",

    // Existing users shouldn't see the onboarding flow retroactively —
    // treat everyone who existed before this migration as already onboarded.
    'users.onboarding backfill (existing users)' => "UPDATE users
        SET onboarding_completed_at = created_at
        WHERE onboarding_completed_at IS NULL",

    // ── Daily snapshots ──────────────────────────────────────────────
    // trackieScore() was computed live and never stored, so the app could show
    // today's discipline score but could never say "you're improving" — which
    // is the whole emotional point. One row per user per day, upserted through
    // the day so it converges on the real end-of-day value.
    //
    // A MISSING row is meaningful: it means the user did not open Trackie that
    // day. Do not backfill gaps with zeros — the score queries are all
    // CURDATE()-based and cannot be reconstructed retroactively, so an invented
    // row would be a fabricated measurement. Consumers must treat absence as
    // "no data", never as a zero.
    'daily_snapshots table' => "CREATE TABLE IF NOT EXISTS daily_snapshots (
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
        updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_day (user_id, snapshot_date),
        INDEX idx_user_date (user_id, snapshot_date),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // ── Onboarding personalisation ───────────────────────────────────
    // Extending `users` rather than adding a profile table: these are five
    // scalar, one-per-user answers with no history requirement, which is
    // exactly the case the existing "extend before adding tables" note covers.
    // All nullable — an existing user who never answered simply has NULL, and
    // every consumer must treat NULL as "not set" rather than assuming a value.
    'users.primary_focus' => "ALTER TABLE users
        ADD COLUMN IF NOT EXISTS primary_focus VARCHAR(40) DEFAULT NULL",

    'users.experience_level' => "ALTER TABLE users
        ADD COLUMN IF NOT EXISTS experience_level VARCHAR(20) DEFAULT NULL",

    'users.daily_reminder_time' => "ALTER TABLE users
        ADD COLUMN IF NOT EXISTS daily_reminder_time TIME DEFAULT NULL",

    'users.sleep_goal_hours' => "ALTER TABLE users
        ADD COLUMN IF NOT EXISTS sleep_goal_hours DECIMAL(3,1) DEFAULT NULL",

    'users.water_goal_ml' => "ALTER TABLE users
        ADD COLUMN IF NOT EXISTS water_goal_ml INT DEFAULT NULL",

    // ── Routine completion ───────────────────────────────────────────
    // `routines` was pure CRUD: you could create a morning routine but never
    // mark it done, so it fed nothing — no streak, no XP, no Trackie Score.
    // A new table (rather than a column) because completion is per-DAY and
    // repeats, which a flag on `routines` cannot express. UNIQUE keeps the
    // day idempotent so a double-tap can't double-count.
    'routine_logs table' => "CREATE TABLE IF NOT EXISTS routine_logs (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        user_id    INT NOT NULL,
        routine_id INT NOT NULL,
        log_date   DATE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_routine_day (routine_id, log_date),
        INDEX idx_user_date (user_id, log_date),
        FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
        FOREIGN KEY (routine_id) REFERENCES routines(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // Goal check-ins (api/goals.php). Never created by any script before —
    // the table was made by hand, without a primary key. See the repair
    // step just above the runner loop.
    'goal_checkins table' => "CREATE TABLE IF NOT EXISTS goal_checkins (
        id                INT AUTO_INCREMENT PRIMARY KEY,
        goal_id           INT NOT NULL,
        user_id           INT NOT NULL,
        note              TEXT DEFAULT NULL,
        progress_snapshot INT DEFAULT NULL,
        created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_goal (goal_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // Only user-scoped table left without a leading index on user_id.
    'goal_checkins.idx_user' => "ALTER TABLE goal_checkins
        ADD INDEX IF NOT EXISTS idx_user (user_id)",
    'goal_checkins.idx_goal' => "ALTER TABLE goal_checkins
        ADD INDEX IF NOT EXISTS idx_goal (goal_id, created_at)",
    'focus_sessions.idx_user_mode' => "ALTER TABLE focus_sessions
        ADD INDEX IF NOT EXISTS idx_user_mode (user_id, type, started_at)",

    // ── Focus: the session "mode" (Study / Work / Coding / Reading) ──
    // api/focus.php has always written and grouped by this column, but the
    // CREATE TABLE above never declared it — so every save fataled with
    // "Unknown column 'mode'" and the session was lost. Nullable so old rows
    // (there are none, because none could ever be written) stay valid, and so
    // a session logged without picking a mode is still recorded.
    'focus_sessions.mode' => "ALTER TABLE focus_sessions
        ADD COLUMN IF NOT EXISTS mode VARCHAR(40) DEFAULT NULL AFTER type",

    // breakdown groups by mode over a date window; this covers that read.
    'focus_sessions.idx_user_mode' => "ALTER TABLE focus_sessions
        ADD INDEX IF NOT EXISTS idx_user_mode (user_id, type, started_at)",

    // ── Exercise builder: per-exercise targets beyond sets/reps ──────
    // All nullable and additive — existing plans keep working untouched.
    'workout_plan_items.target_weight' => "ALTER TABLE workout_plan_items
        ADD COLUMN IF NOT EXISTS target_weight DECIMAL(6,2) DEFAULT NULL AFTER target_reps",

    'workout_plan_items.rest_seconds' => "ALTER TABLE workout_plan_items
        ADD COLUMN IF NOT EXISTS rest_seconds INT DEFAULT NULL AFTER target_weight",

    'workout_plan_items.notes' => "ALTER TABLE workout_plan_items
        ADD COLUMN IF NOT EXISTS notes VARCHAR(255) DEFAULT NULL AFTER rest_seconds",

    // ── Connected-platform foundation ────────────────────────────────
    // One row per user per provider. Tokens are stored ENCRYPTED
    // (AES-256-GCM, see includes/crypto.php) — never plaintext. TEXT rather
    // than VARCHAR because the base64(iv|tag|ciphertext) blob is longer than
    // the raw token and providers differ wildly in token length.
    'user_integrations table' => "CREATE TABLE IF NOT EXISTS user_integrations (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        user_id        INT NOT NULL,
        provider       VARCHAR(40) NOT NULL,
        access_token   TEXT DEFAULT NULL,
        refresh_token  TEXT DEFAULT NULL,
        expires_at     DATETIME DEFAULT NULL,
        scopes         VARCHAR(500) DEFAULT NULL,
        external_id    VARCHAR(190) DEFAULT NULL,
        sync_status    ENUM('never','ok','syncing','error') DEFAULT 'never',
        last_sync      DATETIME DEFAULT NULL,
        last_error     VARCHAR(500) DEFAULT NULL,
        connected_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY uniq_user_provider (user_id, provider),
        INDEX idx_sync (sync_status, last_sync)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Cache of synced provider data. The rule is: modules read from HERE,
    // never from a live API call during a page render. `payload` is JSON so a
    // new provider needs no schema change; `kind` namespaces record types
    // within a provider (e.g. github/repo, github/commit).
    'integration_data table' => "CREATE TABLE IF NOT EXISTS integration_data (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        user_id      INT NOT NULL,
        provider     VARCHAR(40) NOT NULL,
        kind         VARCHAR(40) NOT NULL,
        external_id  VARCHAR(190) NOT NULL,
        payload      TEXT NOT NULL,
        occurred_at  DATETIME DEFAULT NULL,
        synced_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY uniq_record (user_id, provider, kind, external_id),
        INDEX idx_lookup (user_id, provider, kind, occurred_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ── Fitness Tracker & Workout Planner — full module ───────────────
    // Additive only: workout_plans/workout_plan_items/workout_logs (above)
    // are untouched. workout_logs stays the one-row-per-exercise summary
    // (used by the quick "Log Workout" modal); workout_sessions +
    // workout_sets add real per-set granularity for the guided Active
    // Workout flow, which is what previous-performance/PR/volume tracking
    // actually need — a single aggregated row per exercise can't tell you
    // what each individual set weighed.

    // One row per guided workout session (Active Workout start → finish).
    'workout_sessions table' => "CREATE TABLE IF NOT EXISTS workout_sessions (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Links a workout_logs row (one per exercise) back to the session it was
    // logged in, so a session's total duration/exercise count is a simple
    // join instead of a fragile time-window guess.
    'workout_logs.session_id' => "ALTER TABLE workout_logs
        ADD COLUMN IF NOT EXISTS session_id INT DEFAULT NULL AFTER plan_id",
    'workout_logs.idx_session' => "ALTER TABLE workout_logs
        ADD INDEX IF NOT EXISTS idx_session (session_id)",

    // Real per-set data: what each individual set actually weighed/reps'd.
    // A workout_logs row without any workout_sets children (e.g. the quick
    // one-off "Log Workout" modal) still works — PR/history queries fall
    // back to the aggregate sets/reps/weight_kg on workout_logs itself.
    'workout_sets table' => "CREATE TABLE IF NOT EXISTS workout_sets (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        log_id     INT NOT NULL,
        set_number INT NOT NULL,
        reps       INT DEFAULT NULL,
        weight_kg  DECIMAL(6,2) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (log_id) REFERENCES workout_logs(id) ON DELETE CASCADE,
        INDEX idx_log (log_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Exercise library: user_id NULL = built-in (seeded below with fixed
    // IDs so re-running this file is idempotent via INSERT IGNORE),
    // non-NULL = a user's own custom exercise.
    'exercise_library table' => "CREATE TABLE IF NOT EXISTS exercise_library (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        user_id      INT DEFAULT NULL,
        name         VARCHAR(100) NOT NULL,
        muscle_group VARCHAR(40) DEFAULT NULL,
        equipment    VARCHAR(40) DEFAULT NULL,
        category     VARCHAR(40) DEFAULT NULL,
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user (user_id),
        INDEX idx_muscle (muscle_group),
        INDEX idx_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Built-in exercise seed — explicit IDs 1-42 so INSERT IGNORE is a true
    // no-op on re-run (an AUTO_INCREMENT id would duplicate every time).
    'exercise_library seed' => "INSERT IGNORE INTO exercise_library
        (id, user_id, name, muscle_group, equipment, category) VALUES
        (1,  NULL, 'Bench Press',            'Chest',     'Barbell',   'Strength'),
        (2,  NULL, 'Incline Bench Press',    'Chest',     'Barbell',   'Strength'),
        (3,  NULL, 'Dumbbell Fly',           'Chest',     'Dumbbell',  'Strength'),
        (4,  NULL, 'Push-up',                'Chest',     'Bodyweight','Strength'),
        (5,  NULL, 'Cable Crossover',        'Chest',     'Cable',     'Strength'),
        (6,  NULL, 'Deadlift',               'Back',      'Barbell',   'Strength'),
        (7,  NULL, 'Pull-up',                'Back',      'Bodyweight','Strength'),
        (8,  NULL, 'Barbell Row',            'Back',      'Barbell',   'Strength'),
        (9,  NULL, 'Lat Pulldown',           'Back',      'Cable',     'Strength'),
        (10, NULL, 'Seated Cable Row',       'Back',      'Cable',     'Strength'),
        (11, NULL, 'Squat',                  'Legs',      'Barbell',   'Strength'),
        (12, NULL, 'Leg Press',              'Legs',      'Machine',   'Strength'),
        (13, NULL, 'Lunges',                 'Legs',      'Dumbbell',  'Strength'),
        (14, NULL, 'Leg Curl',               'Legs',      'Machine',   'Strength'),
        (15, NULL, 'Leg Extension',          'Legs',      'Machine',   'Strength'),
        (16, NULL, 'Calf Raise',             'Legs',      'Machine',   'Strength'),
        (17, NULL, 'Romanian Deadlift',      'Legs',      'Barbell',   'Strength'),
        (18, NULL, 'Overhead Press',         'Shoulders', 'Barbell',   'Strength'),
        (19, NULL, 'Lateral Raise',          'Shoulders', 'Dumbbell',  'Strength'),
        (20, NULL, 'Front Raise',            'Shoulders', 'Dumbbell',  'Strength'),
        (21, NULL, 'Rear Delt Fly',          'Shoulders', 'Dumbbell',  'Strength'),
        (22, NULL, 'Shrugs',                 'Shoulders', 'Barbell',   'Strength'),
        (23, NULL, 'Bicep Curl',             'Arms',      'Dumbbell',  'Strength'),
        (24, NULL, 'Hammer Curl',            'Arms',      'Dumbbell',  'Strength'),
        (25, NULL, 'Tricep Pushdown',        'Arms',      'Cable',     'Strength'),
        (26, NULL, 'Skull Crusher',          'Arms',      'Barbell',   'Strength'),
        (27, NULL, 'Dips',                   'Arms',      'Bodyweight','Strength'),
        (28, NULL, 'Plank',                  'Core',      'Bodyweight','Strength'),
        (29, NULL, 'Crunches',               'Core',      'Bodyweight','Strength'),
        (30, NULL, 'Hanging Leg Raise',      'Core',      'Bodyweight','Strength'),
        (31, NULL, 'Russian Twist',          'Core',      'Bodyweight','Strength'),
        (32, NULL, 'Cable Wood Chop',        'Core',      'Cable',     'Strength'),
        (33, NULL, 'Treadmill Run',          'Cardio',    'Machine',   'Cardio'),
        (34, NULL, 'Cycling',                'Cardio',    'Machine',   'Cardio'),
        (35, NULL, 'Rowing Machine',         'Cardio',    'Machine',   'Cardio'),
        (36, NULL, 'Jump Rope',              'Cardio',    'Bodyweight','Cardio'),
        (37, NULL, 'Burpees',                'Cardio',    'Bodyweight','Cardio'),
        (38, NULL, 'Mountain Climbers',      'Cardio',    'Bodyweight','Cardio'),
        (39, NULL, 'Yoga Flow',              'Full Body', 'Bodyweight','Flexibility'),
        (40, NULL, 'Hip Thrust',             'Legs',      'Barbell',   'Strength'),
        (41, NULL, 'Face Pull',              'Shoulders', 'Cable',     'Strength'),
        (42, NULL, 'Chest Press Machine',    'Chest',     'Machine',   'Strength')",

    // Two exercises added specifically for the downloaded demo videos that
    // had no matching entry in the library above (ids continue from 42).
    'exercise_library seed 2' => "INSERT IGNORE INTO exercise_library
        (id, user_id, name, muscle_group, equipment, category) VALUES
        (43, NULL, 'Chest-Supported Dumbbell Row',       'Back', 'Dumbbell', 'Strength'),
        (44, NULL, 'Cable Rope Overhead Tricep Extension','Arms', 'Cable',    'Strength')",

    // ── Exercise demo videos (Fitness Library / Active Workout) ─────────
    // Nullable path relative to the app root, e.g. 'assets/vids/Lat pull.mp4'.
    'exercise_library.video_path' => "ALTER TABLE exercise_library
        ADD COLUMN IF NOT EXISTS video_path VARCHAR(255) DEFAULT NULL AFTER category",

    // Auto-mapped from clearly-named downloaded files. Plain UPDATEs are
    // naturally idempotent here (same value every re-run) — no guard needed.
    'exercise_library.video_path seed' => "UPDATE exercise_library SET video_path = CASE id
        WHEN 9  THEN 'assets/vids/Lat pull.mp4'
        WHEN 10 THEN 'assets/vids/Seated cable row.mp4'
        WHEN 43 THEN 'assets/vids/Chest-Supported Dumbbell Row.mp4'
        WHEN 44 THEN 'assets/vids/Cable Rope Overhead Tricep Extension.mp4'
        ELSE video_path END
        WHERE id IN (9, 10, 43, 44)",

    // Second batch, keyed by NAME (ids can differ between databases) and
    // only filling empty built-in rows — never overrides a video a user has
    // assigned through the Library tab. Ambiguous files are left for that UI.
    'exercise_library.video_path seed 2' => "UPDATE exercise_library SET video_path = CASE name
        WHEN 'Chest Press Machine' THEN 'assets/vids/Chest press or fly.mp4'
        WHEN 'Leg Press'           THEN 'assets/vids/leg press.mp4'
        ELSE video_path END
        WHERE user_id IS NULL AND video_path IS NULL
          AND name IN ('Chest Press Machine', 'Leg Press')",

    // ── Fitness V2 ──────────────────────────────────────────────────────
    // Exercise metadata for the provider layer (app/Modules/Fitness). All
    // nullable/defaulted, so existing rows and code are unaffected. video_path
    // stays the local file; video_url/thumbnail_url hold a provider's remote
    // media when an API exercise is saved to the library.
    'exercise_library fitness v2 columns' => "ALTER TABLE exercise_library
        ADD COLUMN IF NOT EXISTS target_muscle     VARCHAR(60)  DEFAULT NULL AFTER muscle_group,
        ADD COLUMN IF NOT EXISTS secondary_muscles VARCHAR(255) DEFAULT NULL AFTER target_muscle,
        ADD COLUMN IF NOT EXISTS difficulty        VARCHAR(20)  DEFAULT NULL AFTER category,
        ADD COLUMN IF NOT EXISTS instructions      TEXT         DEFAULT NULL AFTER difficulty,
        ADD COLUMN IF NOT EXISTS video_url         VARCHAR(500) DEFAULT NULL AFTER video_path,
        ADD COLUMN IF NOT EXISTS thumbnail_url     VARCHAR(500) DEFAULT NULL AFTER video_url,
        ADD COLUMN IF NOT EXISTS source            VARCHAR(20)  NOT NULL DEFAULT 'library' AFTER thumbnail_url,
        ADD COLUMN IF NOT EXISTS external_id       VARCHAR(100) DEFAULT NULL AFTER source",

    // Fitness goals. Progress is never stored — it is computed from logged
    // data (workout days, best sets, streak, body_stats) on every read.
    'fitness_goals table' => "CREATE TABLE IF NOT EXISTS fitness_goals (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Nutrition: what the user logged eating/drinking. Calories here are
    // calories EATEN as entered — Trackie never estimates calories burned.
    'nutrition_entries table' => "CREATE TABLE IF NOT EXISTS nutrition_entries (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'nutrition_targets table' => "CREATE TABLE IF NOT EXISTS nutrition_targets (
        user_id    INT PRIMARY KEY,
        calories   INT          DEFAULT NULL,
        protein_g  DECIMAL(6,1) DEFAULT NULL,
        water_ml   INT          DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Recovery check-ins: raw, user-entered inputs only (no derived score).
    'recovery_logs table' => "CREATE TABLE IF NOT EXISTS recovery_logs (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // WorkoutDB lookups (includes/workoutdb.php). Global catalogue cache, not
    // user data — keyed by normalized exercise name; status 'hit' | 'miss' |
    // 'error' decides the TTL, payload is the trimmed demo JSON for hits.
    'workoutdb_cache table' => "CREATE TABLE IF NOT EXISTS workoutdb_cache (
        query_key  VARCHAR(191) NOT NULL PRIMARY KEY,
        status     VARCHAR(8)   NOT NULL,
        payload    TEXT         DEFAULT NULL,
        fetched_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Optional body/fitness stats — one entry per user per day.
    'body_stats table' => "CREATE TABLE IF NOT EXISTS body_stats (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        user_id      INT NOT NULL,
        log_date     DATE NOT NULL,
        weight_kg    DECIMAL(5,2) DEFAULT NULL,
        body_fat_pct DECIMAL(4,1) DEFAULT NULL,
        notes        VARCHAR(255) DEFAULT NULL,
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY uniq_user_date (user_id, log_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

$results = [];
$pdo     = db();

// goal_checkins made by hand had no PRIMARY KEY/AUTO_INCREMENT (every row
// id 0/1; inserts fail under strict SQL mode). Renumber, then add the key —
// only when it's actually missing, so re-running setup is harmless.
try {
    $gcExists = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'goal_checkins'")->fetchColumn();
    $gcHasPk  = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'goal_checkins' AND constraint_type = 'PRIMARY KEY'")->fetchColumn();
    if ($gcExists && !$gcHasPk) {
        $migrations['goal_checkins renumber ids'] = "UPDATE goal_checkins g JOIN (SELECT @gc_n := 0) init SET g.id = (@gc_n := @gc_n + 1)";
        $migrations['goal_checkins primary key']  = "ALTER TABLE goal_checkins MODIFY id INT NOT NULL AUTO_INCREMENT PRIMARY KEY";
    }
} catch (PDOException $e) { /* reported by the runner if the table is unusable */ }

foreach ($migrations as $label => $sql) {
    try {
        $pdo->exec($sql);
        $results[] = ['ok' => true, 'label' => $label];
    } catch (PDOException $e) {
        $results[] = ['ok' => false, 'label' => $label, 'err' => $e->getMessage()];
    }
}

$allOk = !array_filter($results, fn($r) => !$r['ok']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Trackie — Database Setup</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    body { font-family: system-ui, sans-serif; max-width: 680px; margin: 3rem auto; padding: 0 1rem; background: #f8fafc; color: #0f172a; }
    h1   { font-size: 1.5rem; font-weight: 800; margin-bottom: .25rem; }
    p    { color: #64748b; margin-bottom: 1.5rem; }
    .row { display: flex; align-items: center; gap: .625rem; padding: .5rem .75rem; border-radius: .5rem; margin-bottom: .25rem; font-size: .9rem; }
    .ok  { background: #f0fdf4; color: #15803d; }
    .err { background: #fef2f2; color: #dc2626; }
    .icon { width: 1.25rem; text-align: center; }
    .label { flex: 1; font-weight: 500; }
    .errmsg { font-size: .8rem; opacity: .75; }
    .btn { display: inline-block; margin-top: 1.5rem; padding: .625rem 1.25rem; background: #ef4444; color: #fff; border-radius: 999px; text-decoration: none; font-weight: 600; font-size: .9rem; }
    .banner { padding: 1rem 1.25rem; border-radius: .75rem; margin-bottom: 1.5rem; font-weight: 600; }
    .banner.ok  { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
    .banner.err { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
  </style>
</head>
<body>
  <h1>🚀 Trackie — Database Setup</h1>
  <p>Run this page once after installing or updating Trackie. Safe to re-run — all operations use <code>IF NOT EXISTS</code>.</p>

  <div class="banner <?= $allOk ? 'ok' : 'err' ?>">
    <?= $allOk
      ? '✅ All migrations completed successfully. Your database is ready.'
      : '⚠️ Some migrations failed. Check the errors below.' ?>
  </div>

  <?php foreach ($results as $r): ?>
    <div class="row <?= $r['ok'] ? 'ok' : 'err' ?>">
      <span class="icon">
        <i class="fas <?= $r['ok'] ? 'fa-check' : 'fa-times' ?>"></i>
      </span>
      <span class="label"><?= htmlspecialchars($r['label']) ?></span>
      <?php if (!$r['ok']): ?>
        <span class="errmsg"><?= htmlspecialchars($r['err']) ?></span>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <div style="margin-top:1.5rem; display:flex; gap:1rem; flex-wrap:wrap">
    <?php if ($allOk): ?>
      <a href="<?= APP_BASE ?>/pages/login.php" class="btn">
        <i class="fas fa-rocket"></i> Go to Trackie
      </a>
    <?php else: ?>
      <a href="<?= APP_BASE ?>/pages/setup.php" class="btn" style="background:#64748b">
        <i class="fas fa-redo"></i> Re-run Setup
      </a>
    <?php endif; ?>
  </div>

  <p style="margin-top:2rem;font-size:.8rem;color:#94a3b8">
    Access is restricted: only logged-in users (or a fresh, empty install) can run this page.
  </p>
</body>
</html>
