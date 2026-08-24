# Trackie — Tech Stack

## Philosophy

No framework, no build step, no bundler, no package manager. Every request runs plain PHP directly on the file that matches the URL. The trade-off is deliberate: instant deploys (upload the folder, done), zero dependency-rot risk, and a codebase any single person can hold in their head — at the cost of not having framework conveniences like routing, an ORM, or JSX.

## Backend

- **Language:** PHP 8 (no framework — plain scripts under `pages/` and `api/`)
- **Database:** MySQL, accessed via PDO with parameterized queries (no ORM/query builder)
- **Session/auth:** native PHP sessions, custom auth layer (`includes/auth.php`) — register/login/logout/password-reset, `requireAuth()` guard on every protected page and API endpoint
- **API layer:** each module has one `api/<module>.php` file acting as an action-dispatcher (`switch($_POST['action'])` style) returning JSON — no REST framework, no separate API server
- **Migrations:** hand-written idempotent SQL in `pages/setup.php` (`IF NOT EXISTS` / `INSERT IGNORE`), mirrored to `database/final.sql` for fresh installs — no migration framework (no Doctrine/Phinx/etc.)

## Frontend

- **No framework** — no React/Vue/Svelte. Server-rendered HTML (PHP templating with `<?= ?>` / `<?php ?>`) plus a vanilla-JS layer.
- **JS:** plain ES6+ under a single `Trackie` global namespace (`assets/js/app.js` + per-page inline `<script>` blocks). Key primitives:
  - `Trackie.API.post()` — fetch wrapper with CSRF token injection and offline queueing for mutating `/api/` calls
  - `Trackie.openModal()` / `closeModal()`, `Trackie.confirmDialog()`, `Trackie.Toast.*` — shared UI primitives
  - `refreshFragments(ids)` — re-fetches and swaps specific DOM containers by id, the app's "update without a full reload" mechanism
  - **SpaNav** — intercepts internal link clicks and swaps `#page-main` content for SPA-like navigation without abandoning server rendering
- **CSS:** hand-written, no Tailwind/Bootstrap. A token-based design system in `assets/css/app.css`:
  - Spacing scale: `--sp-1`…`--sp-7`
  - Radii: `--radius`, `--radius-sm/lg/xl`
  - Theme tokens (light + dark): `--bg`, `--surface`, `--surface2`, `--border`, `--text`, `--muted`, `--subtle`, `--accent`/`--accent-h`, `--accent-bg`, `--ok`, `--warn`, `--info`
  - Dark theme uses a slate palette; light theme uses a warm cream palette; accent red (`#ef4444`/`#dc2626`) in both
- **Animation:** motion.dev for page-transition/UI animation flourishes
- **Icons:** Font Awesome

## Build & assets

- No bundler (no Webpack/Vite/esbuild pipeline for app code). `scripts/build_assets.php` is a small custom minifier that produces `.min.css`/`.min.js` versions; `assetUrl()` serves the minified file only when it's newer than the source (mtime-based cache-busting), otherwise falls back to the source file.

## PWA layer

- Web app manifest + service worker for installability and offline-aware behavior
- Capacitor bridge files present (`assets/js/capacitor-bridge.js`) for a possible native-shell wrap, though the primary target is the browser/PWA

## Hosting & environments

- **Local:** XAMPP (Apache + MySQL) on Windows, `C:\xampp\htdocs\Trackie`
- **Live:** InfinityFree shared hosting at `trackie.free.nf`, MySQL on `sql108.infinityfree.com`
- `config/env.php` auto-detects environment from `HTTP_HOST` and switches DB credentials accordingly — the same codebase deploys to both without edits (just re-upload the files)

## Third-party integrations (all credential-gated, registry-driven)

| Provider | Purpose | Auth |
|---|---|---|
| Steam Web API | Game library, playtime, cover art | API key |
| Spotify | Now Playing, playlists | OAuth |
| Open-Meteo | Weather (free, no key) | none |
| Google Gemini | AI Coach (natural-language coaching) | API key |
| GitHub | Commits/repo/streak tracking | OAuth |
| RAWG | Game metadata | API key |
| Unsplash | Reference photography | API key (access key only) |
| Google Calendar/Tasks/Books | Planned/stubbed | OAuth / API key |
| Strava | Planned/stubbed | OAuth |
| Chess.com | Planned/stubbed | public API, no key |

All of the above are declared in one place — `includes/integrations.php` — which derives each provider's live status from whether its `config/env.php` credentials are actually set, rather than being hand-toggled.

## Testing / verification

- No automated unit-test framework. Verification is done via:
  - `php -l` syntax checks
  - `scripts/regression.sh` — scripted end-to-end sanity pass (auth, authenticated render of all pages, nav-link integrity)
  - Manual/automated real-browser verification (Chrome automation) for UI changes — genuine DOM interaction, not just code review
