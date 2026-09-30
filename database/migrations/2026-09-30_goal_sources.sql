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
    ADD COLUMN IF NOT EXISTS source       VARCHAR(20) DEFAULT NULL AFTER kind,
    ADD COLUMN IF NOT EXISTS source_ref   INT DEFAULT NULL AFTER source,
    ADD COLUMN IF NOT EXISTS source_since DATE DEFAULT NULL AFTER source_ref;
