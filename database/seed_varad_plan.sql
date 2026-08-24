-- ============================================================
--  Trackie — Self-improvement plan seed for Varad
--  Goals · Habits · Routines · Todos (weekly workout split) · Reminders
--
--  Safe to import via phpMyAdmin. Run ONCE (re-running duplicates rows).
--  It finds your user by email, so it works on any user id.
--  ⚠️ Contains personal data (email/phone/password hash) — keep private.
-- ============================================================

START TRANSACTION;

-- Make sure the account exists (ignored if it already does)
INSERT IGNORE INTO users (name, email, phone, password, profile_pic, created_at)
VALUES ('Varad Bhole', 'varadbhole09@gmail.com', '+918160375699',
        '$2y$10$4/elQEmyYD8BffKm5rzWO.SJ8vRJWrhPbdyRemdtq9cjeU.iqu3jm',
        'assets/images/pfp_1_707201ab7ccae10e.jpg', '2026-06-12 08:30:14');

SET @uid      := (SELECT id FROM users WHERE email = 'varadbhole09@gmail.com' LIMIT 1);
SET @deadline := DATE_ADD(CURDATE(), INTERVAL 84 DAY);   -- ~12 weeks

-- ── Goals ────────────────────────────────────────────────────
INSERT INTO goals (user_id, goal_name, description, progress, target_value, deadline) VALUES
(@uid, 'Look taller',
 'Maximize height: 8-9h sleep nightly, daily spinal decompression (dead hang), and tall posture (wall test, chin tucks). Stand and sit fully upright.',
 0, 100, @deadline),
(@uid, 'Look sharper',
 'Debloat the face: 2.5-3L water daily, cut chips/instant-noodles/namkeen/soft-drinks/salty food, add banana, cucumber, watermelon and coconut water. Sleep before 11 PM.',
 0, 100, @deadline),
(@uid, 'Build an athletic V-taper at home',
 'Develop shoulders, chest and upper back with bodyweight training (pushups, pike pushups, dips, planks). No gym needed yet.',
 0, 100, @deadline),
(@uid, 'Get clear skin and reduce face bloating',
 'Keep it simple: AM cleanser + moisturizer + SPF 50, PM cleanser + moisturizer. Biggest wins come from sleep, water and sunscreen.',
 0, 100, @deadline),
(@uid, 'Develop confidence through style and posture',
 'Daily posture work and grooming. Carry yourself tall, dress sharp, and build the habit of confidence.',
 0, 100, @deadline);

-- ── Habits (daily) ───────────────────────────────────────────
INSERT INTO habits (user_id, name, frequency, color, group_name) VALUES
(@uid, 'Sleep 8-9h (lights out by 11 PM)',          'daily', '#6366f1', 'Sleep'),
(@uid, 'Drink 2.5-3 L water',                        'daily', '#3b82f6', 'Diet'),
(@uid, 'Glass of water on waking',                   'daily', '#06b6d4', 'Diet'),
(@uid, 'Debloat eating (cut salt & junk)',           'daily', '#22c55e', 'Diet'),
(@uid, 'Skincare AM (cleanse, moisturize, SPF 50)',  'daily', '#f59e0b', 'Skin'),
(@uid, 'Skincare PM (cleanse, moisturize)',          'daily', '#fb923c', 'Skin'),
(@uid, 'Posture drills (wall, chin tucks, hang)',    'daily', '#a855f7', 'Posture'),
(@uid, 'Home workout',                               'daily', '#ef4444', 'Fitness');

-- ── Routines (daily structure) ───────────────────────────────
INSERT INTO routines (user_id, title, time_slot, category, description) VALUES
(@uid, 'Wake up - water + sunlight', '07:00:00', 'Health',
 'Drink a full glass of water right after waking. Get a few minutes of daylight.'),
(@uid, 'AM skincare', '07:20:00', 'Personal',
 'Gentle cleanser, moisturizer, sunscreen SPF 50.'),
(@uid, 'Posture drills', '07:40:00', 'Health',
 'Wall test 2 min (head, shoulders, butt touching the wall). Chin tucks x15. Doorway stretch 30s x3. Dead hang 30-60s.'),
(@uid, 'Home workout', '18:00:00', 'Fitness',
 'Follow today''s split (see Todos). Warm up first.'),
(@uid, 'Wind down - bed by 11 PM', '22:30:00', 'Health',
 'Screens off. PM skincare (cleanser + moisturizer). In bed for 8-9 hours, same wake time daily.');

-- ── Todos: weekly workout split (recurring weekly) ───────────
--  due_date = the next occurrence of each weekday (Mon=0 ... Sun=6)
INSERT INTO todos (user_id, title, description, due_date, priority, category, recurring, status) VALUES
(@uid, 'Workout - Push + Legs',
 '3 rounds: 15 pushups, 20 squats, 30s plank.',
 DATE_ADD(CURDATE(), INTERVAL ((0 - WEEKDAY(CURDATE()) + 7) % 7) DAY), 'medium', 'Fitness', 'weekly', 'today'),
(@uid, 'Workout - Shoulders + Core',
 '3 rounds: pike pushups x10, lunges x15 each leg, side plank 30s each side.',
 DATE_ADD(CURDATE(), INTERVAL ((1 - WEEKDAY(CURDATE()) + 7) % 7) DAY), 'medium', 'Fitness', 'weekly', 'today'),
(@uid, 'Active recovery - 30 min walk',
 'Easy 30-minute walk.',
 DATE_ADD(CURDATE(), INTERVAL ((2 - WEEKDAY(CURDATE()) + 7) % 7) DAY), 'low', 'Health', 'weekly', 'today'),
(@uid, 'Workout - Push + Dips',
 '4 rounds: pushups x12, chair dips x12, squats x20.',
 DATE_ADD(CURDATE(), INTERVAL ((3 - WEEKDAY(CURDATE()) + 7) % 7) DAY), 'medium', 'Fitness', 'weekly', 'today'),
(@uid, 'Workout - Shoulders + Conditioning',
 '4 rounds: pike pushups x10, mountain climbers x20, plank 45s.',
 DATE_ADD(CURDATE(), INTERVAL ((4 - WEEKDAY(CURDATE()) + 7) % 7) DAY), 'medium', 'Fitness', 'weekly', 'today'),
(@uid, 'Sport or 45 min walk',
 'Play a sport or take a brisk 45-minute walk.',
 DATE_ADD(CURDATE(), INTERVAL ((5 - WEEKDAY(CURDATE()) + 7) % 7) DAY), 'low', 'Health', 'weekly', 'today'),
(@uid, 'Rest & recover',
 'Full rest day. Prep water and meals for the week.',
 DATE_ADD(CURDATE(), INTERVAL ((6 - WEEKDAY(CURDATE()) + 7) % 7) DAY), 'low', 'Health', 'weekly', 'today');

-- ── Reminders (recurring) ────────────────────────────────────
--  next_fire_at = today at the set time if still upcoming, else tomorrow.
INSERT INTO reminders (user_id, title, notes, type, remind_time, repeat_every, repeat_unit, next_fire_at, active) VALUES
(@uid, 'Drink water', 'Aim for 2.5-3 L across the day.', 'recurring', '09:00:00', 2, 'hour',
 IF(NOW() < TIMESTAMP(CURDATE(), '09:00:00'), TIMESTAMP(CURDATE(), '09:00:00'),
    TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 1 DAY), '09:00:00')), 1),
(@uid, 'Morning routine', 'Glass of water, AM skincare, sunscreen SPF 50.', 'recurring', '07:00:00', 1, 'day',
 IF(NOW() < TIMESTAMP(CURDATE(), '07:00:00'), TIMESTAMP(CURDATE(), '07:00:00'),
    TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 1 DAY), '07:00:00')), 1),
(@uid, 'Posture check', 'Sit and stand tall. Quick chin tucks.', 'recurring', '14:00:00', 1, 'day',
 IF(NOW() < TIMESTAMP(CURDATE(), '14:00:00'), TIMESTAMP(CURDATE(), '14:00:00'),
    TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 1 DAY), '14:00:00')), 1),
(@uid, 'Wind down for sleep', 'Screens off, PM skincare, bed by 11 PM.', 'recurring', '22:30:00', 1, 'day',
 IF(NOW() < TIMESTAMP(CURDATE(), '22:30:00'), TIMESTAMP(CURDATE(), '22:30:00'),
    TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 1 DAY), '22:30:00')), 1);

COMMIT;
