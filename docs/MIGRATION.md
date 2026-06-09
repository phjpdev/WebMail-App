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

## Hostinger (subfolder deploy)

If the project is uploaded to `public_html/webmail/` and the domain root shows a placeholder page:

1. Copy `deploy/default.php` to `public_html/default.php` (domain root, next to the `webmail/` folder).
2. Set `webmail/.env`:

```env
APP_URL=https://mailbox.djgroupllc.net/webmail
APP_DEBUG=false
```

3. Create the MySQL database in hPanel and import your `dj_webmail` dump.
4. Update `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` in `webmail/.env` (Hostinger often uses `localhost` and a prefixed DB name).
5. Ensure PHP **imap** extension is enabled in hPanel → PHP Configuration.
6. Visit `https://mailbox.djgroupllc.net` — it should redirect to `/webmail/` and show the login page.

**Folder layout:**

```text
public_html/
  default.php          ← from deploy/default.php
  webmail/
    .env
    index.php
    .htaccess
    public/
    src/
    ...
```
