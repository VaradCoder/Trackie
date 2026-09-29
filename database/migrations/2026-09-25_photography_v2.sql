-- ============================================================
--  Trackie — Photography V2 migration (2026-09-25)
--  Photo uploads with real EXIF, shoots, projects, equipment,
--  editing queue, monthly goals.
--
--  The existing `photos` table was always a SHOOT log (title, location,
--  camera, to_edit/edited, date) and stays exactly that; it gains a few
--  columns. Uploaded images live in the new `photo_images` table.
--
--  SAFE FOR THE LIVE DATABASE (InfinityFree / phpMyAdmin):
--    * additive only — no DROP, no data deleted or rewritten
--    * idempotent   — every statement can be re-run safely
--    * no CREATE DATABASE / USE / triggers / DELIMITER
--  How: phpMyAdmin → select the trackie database → SQL tab → run this.
--  Or open pages/setup.php once while logged in (same migrations).
--  NEVER import database/final.sql on the live site (it DROPs tables).
-- ============================================================

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

-- Shoots (existing table): duration + notes are user-entered, never derived.
ALTER TABLE photos
    ADD COLUMN IF NOT EXISTS duration_min INT          DEFAULT NULL AFTER taken_date,
    ADD COLUMN IF NOT EXISTS notes        VARCHAR(1000) DEFAULT NULL AFTER duration_min,
    ADD COLUMN IF NOT EXISTS project_id   INT          DEFAULT NULL AFTER notes;

-- Uploaded photos. EXIF columns stay NULL when the file had no EXIF —
-- the UI shows "Not available", never a guess. GPS is never stored.
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

-- Gear the user owns.
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

-- Monthly targets set by the user (NULL = no target).
CREATE TABLE IF NOT EXISTS photo_goals (
    user_id          INT PRIMARY KEY,
    photos_per_month INT DEFAULT NULL,
    shoots_per_month INT DEFAULT NULL,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
