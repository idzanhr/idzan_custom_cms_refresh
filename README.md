# domain.com — small PHP/MySQL CMS

> **In a hurry? Follow [SETUP.md](SETUP.md)** — the step-by-step guide for CloudPanel, done entirely in the browser (File Manager, Vhost editor, Varnish Cache, PageSpeed, Cron Jobs). **No SSH needed**: the `tools/*.php` command-line scripts are optional extras, and every task they do is also in the admin (setup page, Import, Publish & export → Tidy up / Rebuild).
> Files for CloudPanel are in `deploy/cloudpanel/`; configs for a plain nginx + Varnish server are in `deploy/generic/`.

A replacement for the WordPress + Elementor setup: a PHP/MySQL admin for content, and a public site that outputs plain HTML, CSS and JavaScript. There is no analytics, no tracking, no contact form, and no third-party scripts or fonts. A self-hosted GDPR consent banner is included. The site and the admin share one dark color scheme.

## How it runs on your VPS (CloudPanel / nginx)
```
/var/www/domain.com/            the CMS: config.php, admin/, inc/, tools/, import/ … (never served)
/var/www/domain.com/public/     web root: the generated HTML site, assets and uploads
```
- **Visitors** only get plain files from `public/`. No PHP runs for the public site, there's nothing to hack there, and it's as fast as nginx can serve files.
- **The admin** lives at a secret address on the same domain (e.g. `https://domain.com/studio-7q4mzk/`). It's the only place PHP runs, and `/admin/` returns 404.
- **Publishing:** every save, publish, unpublish or delete in the CMS rebuilds the HTML in `public/` within a second. Scheduled posts go live through a cron job.
- **Varnish** sits in front of the public site and caches it. The CMS clears Varnish's cache after every change, so edits show up immediately. On CloudPanel it sends the same tagged PURGE request as CloudPanel's own "Purge Entire Cache" button, reading the site's `.varnish-cache/settings.json`. **Google PageSpeed** (ngx_pagespeed) minifies and converts images to WebP before Varnish caches the result. It only uses filters that don't inject inline scripts, so the strict security policy stays intact. The admin bypasses both.

### Requirements
- nginx, PHP 8.1+ (FPM) with `pdo_mysql`, `gd`, `zip` and `intl`; MySQL 5.7+ or MariaDB 10.3+
- Varnish 6+/7+ (`apt install varnish`); nginx built with ngx_pagespeed (optional — remove the `pagespeed` lines if your nginx doesn't have it)

### Install
1. Create the database:
   ```sql
   CREATE DATABASE `DB_NAME` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'DB_USER'@'localhost' IDENTIFIED BY 'a-long-random-password';
   GRANT ALL PRIVILEGES ON `DB_NAME`.* TO 'DB_USER'@'localhost';
   ```
2. Upload this folder to `/var/www/domain.com/` and copy `config.sample.php` to `config.php`. Fill in the database details and pick a secret `admin_path` such as `/studio-7q4mzk`. `static` stays `true`, and `varnish` points at Varnish on `127.0.0.1:6081`.
3. Set permissions:
   ```
   sudo chown -R www-data:www-data /var/www/domain.com
   sudo chmod 640 /var/www/domain.com/config.php
   ```
4. Install, as the web user:
   ```
   cd /var/www/domain.com
   sudo -u www-data php tools/install.php --seed
   ```
5. Import your WordPress content. Put the export `.xml` in `import/`, and optionally `wp-content/uploads` as `import/uploads/` or `import/uploads.zip`:
   ```
   sudo -u www-data php tools/import-wxr.php import/markoidan.WordPress.2026-10-13.xml --download
   sudo -u www-data php tools/build.php --force
   ```
   `--download` fetches images that aren't in the backup from the live site, so run it while WordPress is still online.
6. Plain servers without CloudPanel — Varnish: copy `deploy/generic/default.vcl` to `/etc/varnish/` and make Varnish listen on `127.0.0.1:6081`.
   nginx: copy `deploy/generic/nginx-domain.com.conf` to `/etc/nginx/sites-available/`. Replace `studio-CHANGE-ME` with your `admin_path` and check the PHP-FPM socket and certificate paths. Then:
   ```
   sudo ln -s /etc/nginx/sites-available/domain.com.conf /etc/nginx/sites-enabled/
   sudo nginx -t && sudo systemctl reload nginx
   ```
7. Cron for scheduled posts (`sudo crontab -u www-data -e`):
   ```
   */10 * * * * php /var/www/domain.com/tools/build.php
   ```
8. Open `https://domain.com/<admin_path>/`, log in, and set up **two-factor login** with Proton Authenticator. Tick **Keep me signed in for 10 days** on your own devices.

### Apache / shared hosting instead
Set `'static' => false` and `'public_dir' => __DIR__` in `config.php`, upload everything to the web root, and open `/install.php`. The included `.htaccess` files handle routing and protection.

## What the CMS manages
| Section | Fields |
|---|---|
| **Blog posts** | title, slug, category (pick one or add new), featured image + alt, rich text with images, excerpt, status, publish date (a future date schedules the post) |
| **Projects** | title, slug, client (for whom), start date, URL (opens in a new tab), services provided (multi-select), tools used (multi-select), featured image, description |
| **Work experience** | company, role, start date, end date **or** "Ongoing" (ticking it disables the end date), type of work (On-site / Hybrid / Remote), description |
| **Education** | institution, program, location, dates / ongoing, notes, order |
| **Certifications** | name, issued by, issued on, credential URL, badge image |
| **Affiliates & sponsors** | title, company, company website, affiliate/sponsorship link (“Visit Site”, new tab), type (Affiliate / Referral / Sponsored), promo code (**NO CODE**, or **Has a promo code** + the code exactly as typed), featured image, description, date |
| **Pages** | contact, imprint, privacy and other pages, with an option to link each one in the footer |
| **Settings** | name, tagline, profile picture, About text, **4 site colors**, footer links, Mastodon link, posts per page |

Blog categories, Services and Tools each have their own screen where you can rename or delete entries.

## Colors
Set under **Settings → Colors**. Defaults:

| Color | Hex | Used for |
|---|---|---|
| Primary | `#1F2022` | page background, header |
| Secondary | `#2B2B2B` | cards, bands, footer |
| Text | `#FDFBFB` | all text |
| Accent | `#FFB900` | buttons, links, highlights |

The CMS admin uses the same palette.

## URLs (same as WordPress, so links and Google rankings keep working)
`/about-me/` · `/blog/` · `/blog/page/2/` · `/category/slug/` · `/{post-slug}/` · `/projects/` · `/project/{slug}/` · `/work-experience/{slug}/` · `/affiliates-and-sponsorships/` · `/affiliate/{slug}/` · `/{page-slug}/` · `/feed.xml` · `/sitemap.xml`

## Signing in
- **Two-factor login is required.** Every login asks for your password, then a 6-digit code from your authenticator app. Codes are standard TOTP (SHA-1, 6 digits, 30 s), each code works only once, and the QR code is drawn on your own server.
- **Keep me signed in:** after the code, you can keep a device signed in for `remember_days` (10) days. This uses a secure, HttpOnly cookie that only goes to the admin address, and its secret changes on every use. If an old copy of the cookie is ever replayed, every remembered device is signed out. Admin → Two-factor login lists your remembered devices so you can sign any of them out. Changing your password or authenticator signs them all out.
- **Recovery is admin-only.** The login page has no self-service recovery. An administrator resets someone's 2FA under **Users**, which needs the administrator's own current code, and that person scans a new QR code at their next login. If you're the only admin and lose your phone, run `php tools/reset-2fa.php your-username` over SSH.
- **Roles:** Administrators manage everything. Editors only manage content and can't open Users, Settings, Import or Export.
- **Moving to a new phone:** go to Admin → Two-factor login → Replace authenticator.

## Cookie consent (GDPR)
The banner is self-hosted. It loads nothing from outside, and nothing is set until you choose. It offers **Functional** (always active), **Preferences**, **Statistics** and **Marketing**, with Accept, Deny and View preferences buttons, and a **Manage consent** link in the footer reopens it. The choice is stored in a first-party `cookie_consent` cookie. Accessibility settings are only remembered if Preferences is allowed. Texts, categories and policy links are under **Settings → Cookie consent**, and the Cookie Policy (EU) and Privacy Statement (EU) pages are ready to edit under Pages.
If you add a statistics or marketing script later, include it as `<script type="text/plain" data-consent="statistics" data-src="/assets/js/stats.js"></script>`. It only loads after the visitor allows that category.

## Fonts
Your self-hosted brand fonts come over from WordPress unchanged: Roboto, Roboto Slab and Noto Sans, in every file format WordPress had (woff2, woff, ttf, eot, svg). They're stored in `public/assets/fonts/` with the same `@font-face` rules. As in the Elementor kit, the site uses Roboto: weight 700 for headings, 400 for text and 500 for buttons. You can change this under **Settings → Fonts**.

## Icons
**Settings → Icons** (administrators) holds your own SVG icons: project and affiliate tiles, cards, and social icons. The importer fills it automatically from your WordPress icon set. Every SVG is cleaned of scripts, links and embedded files, stored in `storage/icons/` outside the public folder, and drawn straight into the pages, so the licensed files are never published as downloads. SVG icons inside page content (like the Contact page) are cleaned the same way.

## Importing from WordPress
1. In WordPress go to **Tools → Export → All content** and download the `.xml` file.
2. For images, upload the contents of `wp-content/uploads` to `/import/uploads/` by FTP/SFTP (or upload it zipped as `/import/uploads.zip`). Otherwise, tick "Download images" and they are fetched from the old site while it's still online.
3. In Admin → Import from WordPress, choose the `.xml` file. If it's too big for the upload limit, put it in `/import/` by FTP and pick it from the list.

`tools/clean-wxr.php` makes a slim copy of an export without translated pages (Croatian, Bosnian, Slovenian, Serbian Latin and Cyrillic), page-builder templates, menus, fonts and unused attachments. The importer skips translations even in an uncleaned file, and `tools/cleanup.php --apply` removes any that are already in the database.
With WordPress on the same server, point the importer at its uploads folder: `--uploads=/var/www/domain.com-wordpress/wp-content/uploads`.

The importer brings over posts (with categories, drafts and scheduled posts), pages, projects (services, tools, client, URL, start date), work experience, certifications and affiliates. Featured and in-text images come in at full size, links to your old addresses are rewritten, YouTube embeds become privacy-friendly players, and Elementor-only pages are converted to plain content. Running it again updates items instead of duplicating them. The log lists any custom fields it didn't use, so they can be mapped. Delete the contents of `/import/` when you're done.

## Static export
**Admin → Static export** downloads the whole public site as a .zip of plain `.html` files, CSS, JS and images, which you can host anywhere. Tick **Relative links** when the copy will live in a sub-folder, a preview host, or be opened straight from your computer. From the command line: `php tools/export-static.php site.zip [--relative]`.

## Updating the CMS
Upload the new files over the old ones, keeping `config.php`, `uploads/` and `import/`. Database changes are applied automatically the next time you open the admin.

## Adding new sections later
Content types are defined in `inc/schema.php`. To add one (for example Skills or Music releases):
1. Add a `CREATE TABLE` to `install/schema.sql` and run it on your database.
2. Add an entry in `entities()` in `inc/schema.php`. The admin list and edit screens are generated from it automatically.
3. Render it on the public site in `inc/render.php`.


## Security notes
- The admin address is secret and the public site is static files only.
- Sessions end automatically when the account's password or 2FA changes. Admin cookies are scoped to the admin path.
- Passwords are hashed with bcrypt, and two-factor login is mandatory. Failed passwords and codes together are limited to 8 per 15 minutes per IP.
- Every admin form carries a CSRF token, and sessions expire after 4 hours idle.
- Rich text runs through a whitelist sanitizer: scripts, inline event handlers and `javascript:` links are removed. Video embeds are limited to youtube-nocookie and Vimeo.
- Uploads must be real images, are re-encoded (which strips EXIF/GPS data), and nothing in `uploads/` can execute.
- Pages are sent with a strict Content-Security-Policy (`'self'` only).

- Update security.txt with correct data and import into folder .well-data
