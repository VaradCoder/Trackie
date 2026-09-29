# Architecture

Plain PHP 8 + MySQL, no framework. Pages are server-rendered; `SpaNav`
(assets/js/app.js) swaps page content without full reloads.

```
pages/*.php      one file per screen: loads data, renders HTML + an inline <script>
api/*.php        JSON endpoints: requireAuth() + verify_csrf(), POST action=…
includes/        shared PHP (below)
config/          app.php (constants, security headers, CSP, session), env.php (secrets, git-ignored)
database/        final.sql (full schema) + migrations/*.sql (additive, idempotent)
cron/            dispatch.php (reminders; token-protected)
storage/         private uploads (photos, art) — denied to the web, served by api/*_file.php to the owner only
```

## Core engines

| File | Responsibility |
| --- | --- |
| `includes/activity.php` | **Activity engine.** Every completion calls `recordActivity($uid, $action, $refType, $refId)`. One row per real completion (unique key), XP from `ACTIVITY_TYPES` only, `undoActivity()` on un-tick. Streaks (`activityStreak`, `moduleStreaks`), analytics and achievements all read `activity_log`. |
| `includes/gamification.php` | XP (`awardXpOnce`), levels, achievements (`achievementDefs` / `checkAchievements`), streak milestones. `recordActivity` runs the checks after each new activity and buffers unlocks so the calling API can still toast them. |
| `includes/insights.php` | Read models on the activity log: `progressOverview` (Analytics), `weeklyReview` (Weekly Review), `crossInsights` (rule-based, with minimum-data thresholds). |
| `includes/settings.php` | Per-user preferences: currency (`money()`), week start, notification switches. |
| `includes/calendar.php` | `calendarEvents()` — every dated item (todos, study, goals, reminders, habits, workouts, hobbies…) in one feed. |
| `includes/habit_schedule.php` | Habits on chosen weekdays; schedule-aware due checks and streaks. |
| `includes/providers.php` + `includes/oauth.php` | GitHub / Google / Spotify OAuth, token storage encrypted with AES-256-GCM (`includes/crypto.php`, fails closed without the key), refresh. |
| `includes/mailer.php` | Brevo HTTP API or SMTP (hosts without `mail()`). |

## Adding a tracked action

1. Add it to `ACTIVITY_TYPES` in `includes/activity.php`: `'my_action' => ['module', xp]`.
2. Call `recordActivity($uid, 'my_action', 'my_ref_type', $id)` when it happens,
   and `undoActivity(...)` if the user can reverse it.
3. That's it: XP, the module streak, Analytics, the Weekly Review and the
   heatmap pick it up. Add an achievement in `achievementDefs()` +
   `checkAchievements()` if it deserves one.

## Schema changes

Every schema change goes in **three places**: `pages/setup.php` (fresh
installs), `database/final.sql` (full schema) and a new
`database/migrations/YYYY-MM-DD_name.sql` (existing installs). Migrations are
additive and idempotent (`CREATE TABLE IF NOT EXISTS`, `ADD COLUMN IF NOT
EXISTS`). Admin → Migrations verifies them against `information_schema`.

## Security model

- Sessions + remember-me tokens (hashed); rate-limited login; CSRF token on every POST.
- Every query is scoped by `user_id`; ownership is checked before updates.
- CSP (`config/app.php`): script origins allowlisted, `object-src 'none'`,
  `base-uri 'self'`, `frame-ancestors 'self'`, `form-action` limited to Trackie
  and the OAuth providers. `'unsafe-inline'` remains until inline scripts move
  to nonces.
- Secrets live only in `config/env.php`; OAuth tokens are encrypted with
  `TRACKIE_ENCRYPTION_KEY`; uploads live outside the web root's reach.
