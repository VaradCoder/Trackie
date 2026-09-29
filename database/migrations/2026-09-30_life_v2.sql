-- ============================================================
--  Trackie — Life V2 (2026-09-30): Sports, Meditation, savings goals.
--  Additive + idempotent; safe on the live database.
-- ============================================================
ALTER TABLE sports_sessions
    ADD COLUMN IF NOT EXISTS result    VARCHAR(5)  DEFAULT NULL AFTER session_type,   -- win / loss / draw (matches)
    ADD COLUMN IF NOT EXISTS score     VARCHAR(40) DEFAULT NULL AFTER result,
    ADD COLUMN IF NOT EXISTS intensity TINYINT     DEFAULT NULL AFTER score;          -- 1–5, user-rated
ALTER TABLE meditation_sessions
    ADD COLUMN IF NOT EXISTS technique VARCHAR(40) DEFAULT NULL AFTER duration_min;
-- 'savings' goals appear in Finance; everything else stays 'general'.
ALTER TABLE goals
    ADD COLUMN IF NOT EXISTS kind VARCHAR(20) NOT NULL DEFAULT 'general' AFTER goal_name;
