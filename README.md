# Trackie

**Your whole life, one dashboard.** A single-user, all-in-one personal productivity and life-tracking PWA — todos, habits, goals, calendar, gym, finance, gaming, reading, and more, all behind one login, one design system, and one gamification layer (XP, levels, streaks, achievements).

Live: [trackie.free.nf](https://trackie.free.nf)

## Why

Most people spread this data across 8–10 different apps with no shared identity and no shared sense of progress. Trackie puts productivity, lifestyle tracking, and hobby/media tracking behind one dashboard so effort in any area visibly contributes to a single score — and it's self-hosted, not a subscription SaaS.

See [docs/IDEA.md](docs/IDEA.md) for the full product philosophy.

## Modules

- **Productivity** — Todos, Habits, Goals, Routines, Calendar (Microsoft Planner–style Schedule + Board views), Study Plan, Focus, Reminders
- **Lifestyle** — Finance, Gym (workout plans, live sessions, exercise library, AI coach), Cooking, Gardening, Sports, Meditation
- **Hobbies / Media** — Gaming (Steam sync), Music (Spotify), Art, Photography, Writing, Library
- **Progress** — one activity log behind everything: Trackie + per-area streaks, XP and levels, achievements, Analytics (7/30/90-day, 26-week heatmap, insights), Weekly Review
- **Cross-cutting** — Dashboard, Today, Projects (GitHub activity), Settings (currency, week start, notifications), Admin (config + migration status)

Full module map: [docs/PROJECT.md](docs/PROJECT.md)

## Tech stack

Plain PHP 8, no framework, no build step, no bundler — server-rendered pages with an SPA-style navigation layer (`SpaNav`) on top, backed by MySQL/PDO with hand-written idempotent migrations. Vanilla JS under a single `Trackie` namespace, a token-based CSS design system (light/dark themes), and motion.dev for animation. Runs unchanged on local XAMPP and live InfinityFree hosting — environment is auto-detected from the request host.

Full breakdown: [docs/TECH_STACK.md](docs/TECH_STACK.md)

## Design system

One consistent visual language across every module — shared cards, buttons, modals, spacing/color tokens, and a small global motion system (page transitions via the View Transitions API, optimistic UI, skeleton loading, a `Ctrl+K` command palette, a mobile bottom-nav app shell).

Full breakdown: [docs/UI.md](docs/UI.md)

## Docs

- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — engines (activity, insights, calendar, settings), adding a tracked action, schema-change rule, security model
- [docs/DEPLOY.md](docs/DEPLOY.md) — deploy bundles, migrations, `config/env.php` keys, host limits

## Local setup

1. Clone into your web server's document root (e.g. XAMPP's `htdocs/`).
2. Copy `config/env.php.example` → `config/env.php` (create it if missing) and fill in your local MySQL credentials and any integration API keys you want active — see [includes/integrations.php](includes/integrations.php) for the full list and where each key goes. `config/env.php` is gitignored and must never be committed.
3. Create the database and import `database/final.sql`.
4. Visit any page while logged in to run pending migrations via `pages/setup.php`.
5. Run `bash scripts/regression.sh` to sanity-check the install (auth, page renders, nav integrity).

## Repo notes

- `config/env.php` (DB credentials + all API keys) is gitignored — never committed.
- The legacy Capacitor iOS Xcode project, a static download-page fragment, and superseded SQL schema snapshots (superseded by `database/final.sql`) are kept locally but excluded from this repo — see `.gitignore`.
