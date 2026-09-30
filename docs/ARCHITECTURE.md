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
| `includes/notify.php` | **Reminder engine** — the one place reminders fire. `fireDueReminders()` is called by `cron/dispatch.php` (all users) and the in-tab poll (one user). Each fire is claimed with a conditional `UPDATE`, so concurrent runs deliver exactly once. Judges "due" in each user's zone, respects `notify_reminders` + quiet hours, writes the bell entry and sends Web Push (`includes/webpush.php`, VAPID + aes128gcm). |
| `includes/goal_sources.php` | Goals that fill themselves from activity (`goals.source`): habit check-ins, todos, focus minutes, workouts, pages, words, meditation, coding. `syncLinkedGoals()` runs after every new activity and writes `goals.progress`. |
| `api/me.php` | Live app-shell state (level, Trackie streak, unread count) for the sidebar/bell after changes. |

## Frontend (assets/js/app.js)

| Module | Responsibility |
| --- | --- |
| `SpaNav` | PJAX-style navigation: swaps `#page-main`, keeps the shell, restores scroll on Back, cancels superseded loads, handles GET forms, `SpaNav.refresh()` re-renders in place after a save (no `location.reload()` anywhere). Page scripts' `document`/`window` listeners and intervals are released between pages. |
| `Trackie.API` / `trackieFetch()` | One request helper: CSRF, JSON, timeouts, readable errors, session expiry (401 → sign-in), double-submit dedupe, automatic loading state on the clicked button, offline write queue. Error JSON (`{success:false,error}`) is returned, not thrown. |
| `Live` | After any successful change, refreshes sidebar level/streak and the bell (`api/me.php`); fires `trackie:changed`. |
| `Push` | Web Push on this device (Settings → This device). Not offered inside the native app. |
| `Updates` | Offers "A new version is ready · Reload" after a deploy (new service worker, or a newer `app.js` build seen during navigation). |
| `Toast` | The only notification UI (`success/error/info/warning/action`). |

API responses: `{ "success": bool, "error"?: string, …fields }`, with a matching HTTP status on errors (401 session, 403/404 ownership, 409 conflict, 422 validation, 503 not configured).

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

- Sessions + remember-me tokens (hashed); rate-limited login (per IP and per account), registration and password reset; CSRF token on every POST (the one exception, `push.php?action=resubscribe` from the service worker, proves ownership of the old subscription instead).
- XP can't be farmed: completions are unique per item/day in `activity_log`, future-dated routine completions are rejected, and focus sessions can't overlap in time.
- `setup.php` is admin-only once any user exists.
- Every query is scoped by `user_id`; ownership is checked before updates.
- CSP (`config/app.php`): script origins allowlisted, `object-src 'none'`,
  `base-uri 'self'`, `frame-ancestors 'self'`, `form-action` limited to Trackie
  and the OAuth providers. `'unsafe-inline'` remains until inline scripts move
  to nonces.
- Secrets live only in `config/env.php`; OAuth tokens are encrypted with
  `TRACKIE_ENCRYPTION_KEY`; uploads live outside the web root's reach.
