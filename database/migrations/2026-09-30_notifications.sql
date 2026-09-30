-- ============================================================
--  Trackie — Notification preferences (2026-09-30)
--  timezone     IANA name (e.g. 'Europe/London'); NULL = server default.
--               Applied per request to PHP + the MySQL session, so "today",
--               streaks and reminder times follow the user's local midnight.
--  quiet_start / quiet_end  local times; reminders during quiet hours still
--               land in the bell list but send no push / pop-up.
--  Additive + idempotent; safe on the live database.
-- ============================================================
ALTER TABLE user_settings
    ADD COLUMN IF NOT EXISTS timezone    VARCHAR(64) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS quiet_start TIME DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS quiet_end   TIME DEFAULT NULL;
