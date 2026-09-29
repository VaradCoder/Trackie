# Deploying Trackie

Trackie runs on any PHP 8.1+ / MySQL (MariaDB 10.3+) host. The live site is on
InfinityFree (`trackie.free.nf`); these steps are written for it, but nothing
is host-specific.

## 1. Build a bundle

```bash
php scripts/build_assets.php            # refresh *.min.css / *.min.js
php scripts/deploy_bundle.php <ref>     # changed files since <ref> → dist/
php scripts/deploy_bundle.php --mark    # after a successful upload: remember this commit
```

`deploy_bundle.php` writes three things into `dist/` (git-ignored):

| File | What it is |
| --- | --- |
| `trackie-deploy-*.zip` | Every file changed since `<ref>`, with folders preserved. Never contains `config/env.php`, `keys/`, `storage/` or `dist/`. |
| `trackie-migrations-*.sql` | The migration files added since `<ref>`, concatenated in order. |
| `trackie-deploy-*.txt` | The file list and upload steps. |

## 2. Upload

1. **Back up first**: vPanel → MySQL Databases → phpMyAdmin → Export.
2. Upload the zip to `htdocs/` in the file manager and extract it (overwrite).
3. Run the `.sql` file in phpMyAdmin → SQL. Every migration is additive and
   idempotent (`IF NOT EXISTS`), so re-running is safe.
4. Open **Admin → Migrations** and check every file shows `applied`.

## 3. Configuration (`config/env.php`)

`config/env.php` holds all secrets. It is git-ignored; edit it on the server
only. `config/env.php.example` lists every key with where to get it.

| Key | Needed for |
| --- | --- |
| `DB_*` | Always |
| `APP_URL` | Canonical / share links (`https://trackie.free.nf`) |
| `BREVO_API_KEY` *or* `SMTP_*`, plus `MAIL_FROM` | Password-reset emails (free hosts disable `mail()`) |
| `SUPPORT_EMAIL` | Contact address on Privacy / Terms |
| `TRACKIE_ENCRYPTION_KEY` | Encrypting connected-account tokens at rest |
| `GITHUB_*`, `GOOGLE_*`, `SPOTIFY_*`, `STRAVA_*` | Optional sign-in / sync. Each provider's redirect URI must be `APP_URL` + `/pages/<provider>_callback.php`. |
| `CRON_TOKEN` | `cron/dispatch.php?token=…` for an external scheduler |

**Admin → Configuration** shows which keys are set (never their values).

## 4. After deploying

- Hard-refresh once: the service worker version (`sw.js` → `VERSION`) was
  bumped, so installed PWAs pick up the new shell on next launch.
- Smoke test: sign in, tick a habit, open Analytics and the Weekly Review.

## Known host limits (InfinityFree)

- A browser check blocks most external cron services, so web reminders fire
  while a Trackie tab is open. The Android app schedules reminders natively
  (`@capacitor/local-notifications`) after an app rebuild.
- No `mail()`: use Brevo or SMTP as above.
- `.htaccess` security headers may not apply; `config/app.php` sends them from PHP.

## Local development

XAMPP: clone into `htdocs/Trackie`, create `config/env.php` from the example,
import `database/final.sql`, then:

```bash
bash scripts/regression.sh      # JS/PHP syntax, auth, 27 page renders, nav integrity
```
