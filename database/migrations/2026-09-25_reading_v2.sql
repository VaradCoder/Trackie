-- ============================================================
--  Trackie — Reading V2 migration (2026-09-25)
--  Progress, reading sessions, notes & quotes, yearly goals,
--  Open Library metadata. Run AFTER 2026-09-25_gaming_v2.sql
--  (that file creates provider_cache, used for book search).
--
--  SAFE FOR THE LIVE DATABASE (InfinityFree / phpMyAdmin):
--    * additive only — no DROP, no data deleted or rewritten
--    * idempotent   — every statement can be re-run safely
--    * no CREATE DATABASE / USE / triggers / DELIMITER
--
--  How: phpMyAdmin → select the trackie database → SQL tab → run this.
--  Or open pages/setup.php once while logged in (same migrations).
--  NEVER import database/final.sql on the live site (it DROPs tables).
-- ============================================================

-- New shelf "Paused". Only ADDS an enum value; existing rows keep theirs.
ALTER TABLE books MODIFY COLUMN status ENUM('want','reading','finished','paused') DEFAULT 'want';

-- Progress + Open Library metadata (title/author stay user-editable).
ALTER TABLE books
    ADD COLUMN IF NOT EXISTS pages_total  INT          DEFAULT NULL AFTER author,
    ADD COLUMN IF NOT EXISTS current_page INT          NOT NULL DEFAULT 0 AFTER pages_total,
    ADD COLUMN IF NOT EXISTS cover_url    VARCHAR(255) DEFAULT NULL AFTER current_page,
    ADD COLUMN IF NOT EXISTS isbn         VARCHAR(20)  DEFAULT NULL AFTER cover_url,
    ADD COLUMN IF NOT EXISTS ol_key       VARCHAR(40)  DEFAULT NULL AFTER isbn,
    ADD COLUMN IF NOT EXISTS publish_year SMALLINT     DEFAULT NULL AFTER ol_key,
    ADD COLUMN IF NOT EXISTS subjects     VARCHAR(255) DEFAULT NULL AFTER publish_year;

-- One row per reading session. Pages = end_page - start_page as logged.
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

-- Notes and quotes, optionally pinned to a page.
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

-- Yearly reading goal, set by the user (NULL = no target).
CREATE TABLE IF NOT EXISTS reading_goals (
    user_id      INT NOT NULL,
    year         SMALLINT NOT NULL,
    books_target INT DEFAULT NULL,
    pages_target INT DEFAULT NULL,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, year),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
