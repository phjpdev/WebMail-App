# Migration Guide — D&J Webmail

Move the application to a new server with **only PHP files and a MySQL backup**. No cron jobs, Windows services, or cPanel filter configuration required.

## What to copy

1. Entire `webmail/` project folder (excluding `.env` if it contains secrets — recreate on new server)
2. MySQL database dump

## Export database

On the old server:

```bash
mysqldump -u root -p dj_webmail > dj_webmail_backup.sql
```

## New server setup

1. Install WAMP (Apache + MySQL + PHP 8.0+)
2. Enable PHP extensions: `imap`, `openssl`, `pdo_mysql`
3. Copy project to `C:\xampp\htdocs\webmail` (or your web root)
4. Create `.env` from `.env.example` with new mail credentials
5. Import database:

```bash
mysql -u root -p < dj_webmail_backup.sql
```

6. Run `composer install` if `vendor/` is not copied
7. Ensure Apache `mod_rewrite` is enabled
8. Set `APP_DEBUG=false` in `.env` for production
9. Use HTTPS in production (recommended)

## Update mail credentials

Edit `.env` on the new server:

```env
MAILBOX_EMAIL=...
MAILBOX_PASSWORD=...
IMAP_HOST=...
SMTP_HOST=...
APP_URL=https://your-domain.com/webmail
```

## Verify after migration

- [ ] Login works
- [ ] IMAP lists folders
- [ ] Filter runs on first login after session
- [ ] Admin panel loads
- [ ] Send test email works

## What is NOT required

- Cron jobs or Task Scheduler
- cPanel email filters
- Thunderbird VM
- Background services

Filtering runs when any employee opens the webmail in a browser.

## Hostinger (domain root deploy)

App runs at `https://mailbox.djgroupllc.net/` — upload project files **directly into `public_html/`**, not a `webmail/` subfolder.

1. **Delete** Hostinger's `public_html/default.php` placeholder (the app uses `index.php` instead).
2. Remove `public_html/webmail/` if you uploaded there earlier.
3. Upload all project files to `public_html/`.
4. Upload `deploy/.env.production` and rename to `public_html/.env`.
5. Import your database dump into `u321724939_mailbox` via phpMyAdmin.
6. Enable PHP **imap** extension in hPanel.
7. Visit `https://mailbox.djgroupllc.net/` — login with `admin` / `admin123`.

See `deploy/README.md` for full layout and local vs production config.
