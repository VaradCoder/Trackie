-- ============================================================
--  Trackie — Core engine migration (2026-09-29)
--  User settings + the central activity log (XP / streaks /
--  analytics all read from here). See includes/activity.php.
--
--  SAFE FOR THE LIVE DATABASE (InfinityFree / phpMyAdmin):
--    * additive only — no DROP, no data deleted or rewritten
--    * idempotent   — every statement can be re-run safely
--    * no CREATE DATABASE / USE / triggers / DELIMITER
--  Existing history is copied into activity_log automatically, per
--  user, the first time the app needs it (includes/activity.php) —
--  so this file never fails on a database missing an older table.
-- ============================================================

-- Per-user preferences. Defaults reproduce today's behaviour.
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

-- One row per real completion. The unique key makes every activity count
-- once, whatever retries or double-clicks happen.
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
