# Hostinger deployment (domain root)

App URL: **https://mailbox.djgroupllc.net/** (no `/webmail` subfolder)

## Server folder layout

Upload the **project files directly into `public_html/`** — not into a `webmail/` subfolder.

```text
public_html/
  .env              ← MUST be named exactly .env (not .env.production)
  .htaccess
  index.php
  composer.json
  config/
  database/
  public/
  src/
  storage/
  vendor/
  views/
```

## Do NOT upload

- Hostinger's placeholder `default.php` — delete it from `public_html/` if it exists
- Your local `.env` — use `deploy/.env.production` on the server instead
- `deploy/` folder (reference only; not needed on the server)
- `storage/c.txt`, `storage/p.html`, `storage/logs/*.log` (local temp files)

## Steps

1. **Delete** `public_html/default.php` and remove `public_html/webmail/` if you created it earlier.
2. Upload all project files to `public_html/`.
3. Upload `deploy/.env.production` and **rename to `.env`** on the server (exact name, no extension).
4. Enable PHP **imap** and **pdo_mysql** in hPanel → PHP Configuration.
5. Visit `https://mailbox.djgroupllc.net/check-setup.php` — all checks should pass.
6. Delete `check-setup.php` from the server after setup.
7. Visit `https://mailbox.djgroupllc.net/` — login with `admin` / `admin123`.

## Login fails / database error?

The login page loads without a database. Login POST needs MySQL.

**Error 1045 Access denied** (see `storage/logs/app.log`):

1. hPanel → **Databases** → confirm `u321724939_mailboxUser` is **assigned** to `u321724939_mailbox`
2. **Change password** on the DB user in hPanel (use letters+numbers only, e.g. `Mailbox2026Xk9`)
3. Update `public_html/.env`:
   ```env
   DB_PASSWORD=Mailbox2026Xk9
   ```
4. Visit `/check-setup.php` — `database` should say `connected OK`
5. Login: `admin` / `admin123`

- File must be named exactly `.env`
- `db_password_length` on check-setup should match your password length
- `storage/logs/` must be writable

## Local vs production

| | Local (XAMPP) | Production (Hostinger) |
|--|---------------|------------------------|
| Config file | `.env` | `.env` (from `deploy/.env.production`) |
| URL | `http://localhost/webmail` | `https://mailbox.djgroupllc.net` |
| Database | `dj_webmail` / `root` | `u321724939_mailbox` |
