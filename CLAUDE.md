# CMS: notes for Claude Code (and other coding assistants)

Custom PHP/MySQL CMS built to replace a WordPress + Elementor site. The admin writes **static HTML** into `public/`; visitors never run PHP. Current version: see `CMS_VERSION` in `inc/bootstrap.php` (2026.11.11-31 when this file was last updated).

## Project rules (do not break)
- **Two-factor login is mandatory**; resetting someone's 2FA is admin-only.
- **Cookie consent banner stays** (GDPR). Functional cookies only: `cookie_consent`, `site_lang`, `site_theme`.
- **Brand fonts stay exactly as they are** (no subsetting, no swapping files).
- **Paid SVG icons are never published as files**: kept in `storage/icons/`, sanitized (`inc/svg.php`) and drawn inline.
- **No analytics, no tracking, no third-party scripts or fonts.** No generator tags or version headers.
- **API keys and passwords only in `config.php`**: never in the database, the admin, git, zips or chat output.
- No social-media promotion in the header.
- Write user-facing text (admin labels, docs) in plain English.

## Layout
```
admin/       the admin (runs only at the secret admin_path; /admin/ is 404)
inc/         all logic: bootstrap, schema (content types + settings), render (public pages), publish (static build),
             i18n + translate (languages, Gemini/OpenAI/Claude/Google), images (AVIF/WebP), seo, indexnow, varnish (+Bunny),
             svg (icon sanitizer), migrate (schema updates, SCHEMA_VERSION)
install/     schema.sql (MySQL) + seed.php (starter content)
tools/       CLI: build.php (cron), install, import-wxr, reset-2fa, cleanup, export-static
assets/      css/js/admin sources; copied into public/assets on every build (asset_url() also syncs them)
deploy/      cloudpanel/vhost.conf (template with SITEUSER / studio-CHANGE-ME / CACHE-TAG-PREFIX), generic nginx + Varnish
dev/         local development only (router for php -S, SQLite setup, sample config)
public/      GENERATED: never edit by hand, never commit
storage/     runtime data (db for dev, icons, state files): never commit
```

## Run it locally
Needs PHP 8.3+ with `pdo_sqlite`, `gd` (ideally with AVIF), `mbstring`, `intl`, `zip`, `curl`. On Windows: the "VS17 x64 Non Thread Safe" zip from windows.php.net (or `winget search php`), add its folder to PATH, copy `php.ini-development` to `php.ini` and enable those extensions in it.
```
cp dev/config.local.sample.php config.php
php dev/setup-sqlite.php --seed          # creates storage/dev.sqlite, prints the admin login (user dev, random password), builds public/
php -S 127.0.0.1:8080 -t public dev/router.php
```
Site: http://127.0.0.1:8080/ · Admin: http://127.0.0.1:8080/studio-dev/ (login as printed by the setup script; 2FA setup on first login; any TOTP app).
Rebuild after code changes: `php tools/build.php` (or save anything in the admin). Start over: delete `storage/dev.sqlite` and `public/` contents.

## Checks before handing over a change
- `php -l` on every changed PHP file.
- Rebuild, then open the changed pages in a browser: no console errors, especially **CSP violations**. The public CSP allows only `'self'` plus the hash of the inline `assets/js/theme.js`. If `theme.js` changes, the hash changes: update it in `deploy/cloudpanel/vhost.conf` (shown in Admin → Publish & export).
- Mobile first (390 px wide), then desktop. Light and dark mode.
- Translated pages (`/hr/` etc.): links keep the language prefix, files (`/uploads/`, `/_img/`, `/assets/`) never get one.
- Database change → new migration in `inc/migrate.php`, bump `SCHEMA_VERSION`, update `install/schema.sql`. Migrations must work on MySQL and SQLite.

## Versioning and delivery
- Bump `CMS_VERSION` in `inc/bootstrap.php`: `'YYYY.MM.DD-N (short description)'`, N continues from the last one.
- Server upload = zip of this folder **without** `config.php`, `storage/*` (keep `.htaccess`/`.keep`), `public/*` (keep `uploads/.keep`), `import/*.xml`, `dev/` sqlite files.
- After uploading: Admin → Tools → "Refresh after an update" → Publish & export → "Rebuild site & clear cache". Database updates run by themselves.
- Server settings (CloudPanel vhost, PageSpeed) live in `deploy/cloudpanel/` as templates; never commit a filled-in copy.
