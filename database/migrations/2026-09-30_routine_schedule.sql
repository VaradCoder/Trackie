-- ============================================================
--  Trackie — Routines on specific days (2026-09-30)
--  schedule_days: comma list of weekdays (0 = Sunday … 6 = Saturday) the
--  routine is due. NULL = every day (existing behaviour). Same format and
--  helpers as habits.schedule_days (includes/habit_schedule.php).
--  Additive + idempotent; safe on the live database.
-- ============================================================
ALTER TABLE routines ADD COLUMN IF NOT EXISTS schedule_days VARCHAR(13) DEFAULT NULL AFTER time_slot;
