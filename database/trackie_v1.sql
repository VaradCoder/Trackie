-- ============================================================
--  Trackie v1.0 — Complete Database Schema
--  Run this on a fresh database called `trackie`
--  mysql -u root trackie < trackie_v1.sql
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS study_plan;
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
