-- ============================================================
--  Trackie — Gaming V2 migration (2026-09-25)
--  Next Up · Wrapped · Co-op, built on the existing Steam link.
--
--  SAFE FOR THE LIVE DATABASE (InfinityFree / phpMyAdmin):
--    * additive only — no DROP, no data deleted or rewritten
--    * idempotent   — every statement can be re-run safely
--    * no CREATE DATABASE / USE — import into the selected DB
--    * no triggers / procedures / DELIMITER
--
--  How: phpMyAdmin → select the trackie database → SQL tab → run this.
--  Or open pages/setup.php once while logged in (same migrations).
--
--  NEVER import database/final.sql on the live site (it DROPs tables).
-- ============================================================

-- Earlier Steam columns, repeated here in case the live DB predates them.
ALTER TABLE games
    ADD COLUMN IF NOT EXISTS steam_appid INT          DEFAULT NULL AFTER platform,
    ADD COLUMN IF NOT EXISTS cover_url   VARCHAR(255) DEFAULT NULL AFTER steam_appid,
    ADD COLUMN IF NOT EXISTS last_played DATETIME     DEFAULT NULL AFTER hours_played;
ALTER TABLE games ADD UNIQUE INDEX IF NOT EXISTS uniq_user_steamapp (user_id, steam_appid);

-- Values exactly as Steam reports them (minutes / counts), plus the date the
-- user moved a game to "Completed" in Trackie (NULL for older completions).
ALTER TABLE games
    ADD COLUMN IF NOT EXISTS playtime_2weeks INT      DEFAULT NULL AFTER last_played,
    ADD COLUMN IF NOT EXISTS ach_done        SMALLINT DEFAULT NULL AFTER playtime_2weeks,
    ADD COLUMN IF NOT EXISTS ach_total       SMALLINT DEFAULT NULL AFTER ach_done,
    ADD COLUMN IF NOT EXISTS ach_synced_at   DATETIME DEFAULT NULL AFTER ach_total,
    ADD COLUMN IF NOT EXISTS completed_at    DATETIME DEFAULT NULL AFTER ach_synced_at;

-- Cumulative Steam playtime recorded at each sync. Steam has no per-day
-- history, so time patterns can only start from the first snapshot.
-- steam_appid = 0 is a per-day "a sync happened" marker (total minutes).
CREATE TABLE IF NOT EXISTS steam_playtime_snapshots (
    user_id      INT  NOT NULL,
    steam_appid  INT  NOT NULL,
    snap_date    DATE NOT NULL,
    playtime_min INT  NOT NULL,
    PRIMARY KEY (user_id, steam_appid, snap_date),
    INDEX idx_user_date (user_id, snap_date),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Steam Store genres/categories per app (global catalogue cache, not user data).
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

-- Short-lived external API response cache shared by hobby providers
-- (Steam friend lists/libraries, Open Library search, ...). Keys are prefixed.
CREATE TABLE IF NOT EXISTS provider_cache (
    cache_key  VARCHAR(191) NOT NULL PRIMARY KEY,
    status     VARCHAR(8)   NOT NULL,
    payload    MEDIUMTEXT   DEFAULT NULL,
    fetched_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
