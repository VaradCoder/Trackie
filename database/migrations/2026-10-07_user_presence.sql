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
