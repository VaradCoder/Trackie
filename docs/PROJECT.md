# Trackie — Project Overview

## At a glance

- **Type:** Personal productivity & life-tracking Progressive Web App (PWA)
- **Local path:** `C:\xampp\htdocs\Trackie` (XAMPP)
- **Live:** `trackie.free.nf` (InfinityFree hosting)
- **Architecture:** Plain PHP 8, no framework, no build step, server-rendered pages + a vanilla-JS SPA-style navigation layer on top
- **Users:** Single-user app with full auth (register/login/reset), not currently multi-tenant in spirit

## Module map

**Productivity**
Todos · Habits · Goals · Routines · Calendar (Microsoft Planner-style: Schedule + Board views) · Study Plan · Focus (Pomodoro-style sessions) · Reminders

**Lifestyle**
Finance (budgets/transactions) · Gym (workout plans, sessions, exercise library, progress, AI coach) · Cooking · Gardening · Sports · Meditation

**Hobbies / Media**
Gaming (Steam integration) · Music (Spotify integration) · Art · Photography · Writing · Library (reading)

**Cross-cutting**
Dashboard (aggregated view across all modules) · Analytics · Progress (Trackie Score) · Projects (coding activity via GitHub) · Settings · Admin · Profile

## How a page works (the pattern every module follows)

1. `pages/<module>.php` — server-rendered page. Requires auth, loads shared head/sidebar/header/footer includes, queries the DB directly via PDO helpers, and renders HTML using shared component functions (`renderPageHeader()`, `renderStatCard()`, `renderEmptyState()`, `renderInsight()`).
2. `api/<module>.php` — a single action-dispatch endpoint (`?action=create|update|delete|toggle|...`) that the page's JS calls via `Trackie.API.post()`. Returns JSON.
3. Client JS (inline `<script>` in the page, or `assets/js/app.js` helpers) wires up interactions and calls `refreshFragments([...ids])` to re-render specific DOM containers without a full page reload — the app's "no page refresh" primitive.
4. Any DB schema changes needed for the module are added to `pages/setup.php` as an idempotent migration (`IF NOT EXISTS` / `INSERT IGNORE` only — never destructive) and mirrored into `database/final.sql` for fresh installs.

## Cross-module systems

- **Gamification (`includes/gamification.php`):** XP, levels, streaks, and a badge-style achievement system (`awardXp`, `awardXpOnce`, `checkAchievements`, `calculateStreaks`, `achievementDefs`). Nearly every module awards XP for meaningful actions (completing a todo, finishing a workout, hitting a streak).
- **Trackie Score:** a single rolled-up daily "how am I doing" number derived across modules, tracked historically (`recordDailySnapshot`, `scoreTrend`, `scoreBand`) and shown on Progress/Analytics.
- **Integrations registry (`includes/integrations.php`):** single source of truth for every third-party connector (Spotify, Weather/Open-Meteo, AI Coach/Gemini, GitHub, Google Calendar/Tasks/Books, Steam, RAWG, Strava, Unsplash, Chess.com). Each entry declares its required credentials, auth type, and setup instructions; status (`active` / `connect` / `coming_soon`) is derived automatically from whether credentials are configured — never hand-set.
- **Auth (`includes/auth.php`):** session-based login, `requireAuth()` guards on every page/API, password reset flow.

## Data & migrations

- MySQL via PDO, no ORM.
- `pages/setup.php` — visited once (logged-in) to apply schema migrations; every migration is safe to re-run.
- `database/final.sql` — canonical fresh-install schema, kept in sync with `setup.php`.
- Environment auto-detects local XAMPP vs. live InfinityFree DB credentials from the request host (`config/env.php`) — the same codebase runs unmodified in both places.

## Tooling

- `scripts/build_assets.php` — regenerates minified CSS/JS bundles (served only when the `.min.` file is newer than its source).
- `scripts/regression.sh` — one-shot health check: JS syntax, PHP syntax, auth flow, authenticated render of every page, and nav-integrity checks (manifest shortcuts + sidebar links all resolve). Run before considering any change "done."

## Current state (high-level)

Actively developed feature-by-feature: recent work has covered a full dashboard/theme redesign, a from-scratch Gym module (planner, live workout sessions, exercise video library, AI coach), Steam game-library sync, and a Microsoft Planner-style Calendar rebuild with a free (keyless) Open-Meteo weather integration. See git history / commit messages for the detailed changelog.
