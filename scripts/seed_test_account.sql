-- Trackie v2 — local test account for the regression harness.
-- Idempotent: safe to re-run. Password hash = bcrypt('Test1234').
-- Usage: "C:/xampp/mysql/bin/mysql.exe" -u root trackie < scripts/seed_test_account.sql
INSERT INTO users (name, email, password, hobbies, created_at)
VALUES ('Test User', 'test@trackie.local',
        '$2y$10$22uG39h1lujM7It6lkdQOuHQM7ARYQqvLYqCbbNeAKWP9b0v19J/C',
        'Fitness,Coding,Reading,Gaming', NOW())
ON DUPLICATE KEY UPDATE
        password = VALUES(password),
        hobbies  = VALUES(hobbies);
