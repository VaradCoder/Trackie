-- ============================================================
--  Trackie — Routine skips and planned rest days (2026-10-11)
--
--  1. routine_logs.status — the outcome of ONE routine on ONE day:
--       'done'    completed (every existing row; the DEFAULT keeps them so)
--       'skipped' deliberately not done that day (a one-day exception)
--     Done and skipped are mutually exclusive for a routine-day, so the
--     existing UNIQUE (routine_id, log_date) stays exactly right.
--
--  2. routine_rest_days — a planned rest day for the whole routine plan.
--     A DAY-level plan, not a per-routine outcome, so it is not written into
--     routine_logs: routines added later are covered too, and "rest" never
--     looks like a completion. The weekly template stays routines.schedule_days.
--
--  Additive + idempotent (safe to run twice). Existing rows are untouched:
--  every row becomes status='done', which is what it already meant.
--
--  Rollback (only if needed — loses skip and rest-day records):
--    DELETE FROM routine_logs WHERE status = 'skipped';  -- MUST run first: older
--                                                         -- code reads any row as done
--    ALTER TABLE routine_logs DROP COLUMN status;
--    DROP TABLE routine_rest_days;
-- ============================================================
ALTER TABLE routine_logs
    ADD COLUMN IF NOT EXISTS status ENUM('done','skipped') NOT NULL DEFAULT 'done' AFTER log_date;

CREATE TABLE IF NOT EXISTS routine_rest_days (
    user_id    INT  NOT NULL,
    rest_date  DATE NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, rest_date),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
