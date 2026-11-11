# Security

## Reporting a problem
Please don't open a public issue for a security problem. Use GitHub's private report instead:
**Security → Report a vulnerability** on this repository. Say what you found, how to reproduce it and which version
(`CMS_VERSION` in `inc/bootstrap.php`, also shown at the bottom of every admin page). You'll get an answer as soon as possible.

## What the CMS does to stay safe
See "Security notes" in [README.md](README.md).

## Checklist for your own installation
- `config.php`, `storage/` and `import/` are never reachable from the web: the web root is `public/` only.
- Pick your own secret `admin_path` and put the same value in the vhost.
- Keep API keys and the database password only in `config.php`. If one was ever pasted somewhere (chat, email, a ticket), replace it.
- Use the vhost from `deploy/cloudpanel/` (security headers, Content-Security-Policy, bot blocking) and check the result on securityheaders.com.
- Port 8080 (the backend) must not answer from outside the server.
- Delete `install.php` after installing if your web root is the CMS folder (Apache layout).
- Never upload the `dev/` folder's SQLite database or a development `config.php` to a server.
- Keep PHP up to date.

## Review history
- **2026-11-11 (version 2026.11.11-31):** full review before publishing. Fixed: an inline SVG placed inside an attribute could
  add attributes to a link (stored XSS for editors, blocked by the CSP); addresses with control characters; unlimited guessing of
  2FA codes across addresses and inside a signed-in session; the importer could be pointed at internal addresses or local files
  by a crafted export file; XML entity tricks in export files; decompression-bomb images; database password could appear in an
  error page on servers that show PHP errors; the legacy `install.php` let the first visitor create the admin account.
