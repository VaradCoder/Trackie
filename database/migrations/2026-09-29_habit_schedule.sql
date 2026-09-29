-- ============================================================
--  Trackie — Habit schedules (2026-09-29)
--  schedule_days: comma list of weekdays (0 = Sunday … 6 = Saturday)
--  a daily habit is due on. NULL = every day (existing behaviour).
--  Additive + idempotent; safe on the live database.
-- ============================================================
ALTER TABLE habits ADD COLUMN IF NOT EXISTS schedule_days VARCHAR(13) DEFAULT NULL AFTER frequency;
