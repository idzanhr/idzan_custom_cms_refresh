# Quick setup on CloudPanel — no SSH needed

Everything happens in the browser: CloudPanel, its **File Manager**, and the CMS's own setup page. About 25 minutes.
The site is offline for about 10 minutes, from step 3 until step 6. A late-evening slot is ideal.

Write these down first:
- **SITEUSER**: the site's user in CloudPanel (Sites → domain.com → Settings, or the folder name under `/home/`)
- **studio-xxxx**: your secret admin address, for example `studio-7q4mzk`

Folders when done:
```
/home/SITEUSER/htdocs/domain.com/          ← the new CMS (public site is its public/ folder)
/home/SITEUSER/htdocs/wp_old/      ← WordPress, untouched, for rollback and import
```

---

### 1. Safety copies (2 min)
- CloudPanel → **Sites → domain.com → Vhost**: select all, copy it, and save it on your laptop as `vhost-wordpress-backup.txt`.
- CloudPanel → **Databases** → WordPress database → **Export** (or phpMyAdmin → Export). Keep the file.

### 2. Database and Varnish details (2 min)
- Make a password in your own password generator (Proton Pass, Bitwarden, etc.), 24+ characters, and save it there.
  Easiest: leave out the `'` and `\` characters (if one sneaks in, put a `\` before it in step 4).
- CloudPanel → **Databases → Add Database**: database name `DB_NAME`, user name `DB_USER`, and **paste your own password** instead of the generated one.
  (If CloudPanel won't accept the hyphen, use `DB_NAME` and write that in step 4 too.)
- CloudPanel → **Varnish Cache**: turn the toggle on and note the **Cache Tag Prefix**.
  Replace everything in **Excludes** with one line, `^/studio-xxxx/`, then click **Save**.

### 3. Swap the folders (3 min) — the site goes offline here
CloudPanel → **Sites → domain.com → File Manager** → open `htdocs`:
1. Right-click `domain.com` → **Rename** → `wp_old`
2. **Upload** `cms.zip` into `htdocs`, then right-click it → **Extract**. This creates a new `domain.com` folder: the CMS.
3. Delete `cms.zip` (it's no longer needed).

### 4. config.php (3 min)
In File Manager, open `htdocs/domain.com/`:
1. Right-click `config.sample.php` → **Copy** (or Duplicate) → name it `config.php`.
2. Right-click `config.php` → **Edit**, change these things, and **Save**:
   - `'pass' => 'change-me'` → your database password from step 2 (keep the quotes around it)
   - `'name'` and `'user'` must be the database name and user you created above
   - `'admin_path' => '/admin'` → `'/studio-xxxx'`
   - in the `varnish` line, `SITEUSER` → your site user

### 5. Vhost (3 min)
1. On your laptop, open `deploy/cloudpanel/vhost.conf` from the zip in a text editor and use Find & Replace:
   - `SITEUSER` → your site user
   - `studio-CHANGE-ME` → `studio-xxxx`
   - `CACHE-TAG-PREFIX` → your Cache Tag Prefix
2. CloudPanel → **Vhost**: select all, paste, and click **Save**.

### 6. Install in the browser (3 min) — the site comes back here
Open **`https://domain.com/studio-xxxx/setup.php`**
- Database password: the one from step 2 (it proves you own the server)
- Choose your admin username and password (12+ characters)
- Leave "starter content" ticked → **Install**

Log in, scan the QR code with **Proton Authenticator**, enter the code, and tick **Keep me signed in for 10 days**.

### 7. Import WordPress (3 min)
Admin → **Import from WordPress**:
- **…or a file already in the /import/ folder**: choose `your-site.WordPress.xml`
- **WordPress uploads folder on this server**: already filled in with `/home/SITEUSER/htdocs/wp_old/wp-content/uploads`. Check that it's right.
- Click **Import file** and wait (1–3 minutes). The log shows what was copied, including posts, projects, jobs, certificates, affiliates, images, your SVG icons and your brand fonts.

Then go to Admin → **Publish & export → Tidy up → Check what can be removed → Remove these**.

### 8. PageSpeed, cache, cron (3 min)
- **PageSpeed → Settings**: paste `deploy/cloudpanel/pagespeed.txt` (change `studio-CHANGE-ME` to `studio-xxxx`), then **Save** and **Purge Cache**.
- **Varnish Cache → Purge Entire Cache**.
- **Cron Jobs → Add Cron Job**: every 10 minutes (`*/10 * * * *`), command
  `php8.5 /home/SITEUSER/htdocs/domain.com/tools/build.php`

### 9. Check (1 min)
Open in a private browser window:
- `https://domain.com/`: the new site with the cookie banner
- `https://domain.com/about-me` → should land on `/about-me/`
- `https://domain.com/wp-admin/` → 404
- `https://domain.com/admin/` → 404

Done. 🎉

---

### Error 500 or a blank page on setup.php
1. Reload. The setup page now tells you what's wrong in plain words (a typo in `config.php`, a missing PHP extension, or the database error next to "Database connection").
2. Most common: a `'` or `\` inside the password in `config.php`. Put a `\` in front of it, e.g. `'ab\'cd'`. Or a value missing its `'` quotes or a line missing its `,` at the end.
3. Still a bare 500? File Manager → open `logs/php/error.log` (or CloudPanel → Sites → domain.com → **Logs**) and look at the last lines.

### Back to WordPress (2 min)
1. File Manager: rename `domain.com` → `cms`, then `wp_old` → `domain.com`.
2. Vhost: paste `vhost-wordpress-backup.txt` back and click **Save**.

### Lost your phone? (no SSH needed)
CloudPanel → **Databases → phpMyAdmin** → database `DB_NAME` → **SQL** tab → run:
```sql
UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_last_step = 0 WHERE username = 'YOUR-ADMIN-USERNAME';
DELETE FROM remember_tokens;
```
Log in with your password and scan a new QR code. If you have more than one admin, another admin can do this under **Users** instead.

### Later
- File Manager: empty `htdocs/domain.com/import/` (the export file isn't needed any more).
- When you're sure everything is fine: archive and delete `htdocs/wp_old/` and the WordPress database.
- **Rebuild + clear cache** at any time: Admin → Publish & export.

### Languages (optional): Croatian, Bosnian, Serbian Cyrillic, Slovenian
1. Get an API key for at least one service and put it in `config.php` under `'translate'` (the lines are in `config.sample.php`):
   - **Gemini (free tier):** https://aistudio.google.com/apikey → `gemini_api_key`
   - **Claude:** https://platform.claude.com → API keys → `claude_api_key`
   - **Google Translate basic:** Google Cloud console → enable *Cloud Translation API* → Credentials → API key → `google_api_key`
2. Admin → **Settings → Languages & translation**: tick the languages, pick the service, **Save**.
3. Click **Translate everything missing** and leave the page open. It waits by itself at free-tier limits, and you can continue later.
4. Purge Bunny. Visitors now get a language menu in the header; their choice is kept in the `site_lang` cookie (functional, no consent needed). Mention it in your cookie policy.
Editing a post later marks its translations "outdated": run **… and update outdated**, or fix the text by hand under *Check or edit a translation*.
