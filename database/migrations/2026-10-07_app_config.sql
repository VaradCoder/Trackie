-- ============================================================
--  Trackie — Admin-editable settings (2026-10-07)
--  Non-secret settings set from Admin → Configuration: MAIL_FROM,
--  MAIL_FROM_NAME, SUPPORT_EMAIL, APP_URL. config/env.php still wins when it
--  sets a value. Secrets (API keys, passwords, tokens) never go here.
--  Additive + idempotent; safe on the live database.
-- ============================================================
CREATE TABLE IF NOT EXISTS app_config (
    name       VARCHAR(40) PRIMARY KEY,
    value      VARCHAR(255) NOT NULL DEFAULT '',
    updated_by INT DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
