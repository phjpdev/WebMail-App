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

Create empty writable folders on the server (do not upload local `storage/` contents):

```text
storage/logs/
storage/post_send/
storage/thread_replies/
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

**Error 1045 Access denied** with `db_password_mode: base64` — parsing works; **hPanel password ≠ .env password**.

Re-enter the **same** complex password in hPanel, encode it, update `.env`:

Use **base64** in `.env` (complex passwords are fine):

```env
DB_PASSWORD_B64=UnAzJjsjSStvNWw=
```

Encode any new password locally:

```bash
php deploy/encode-secret.php "Rp3&;#I+o5l"
```

1. hPanel → confirm DB user is assigned to the database
2. Upload `deploy/.env.production` as `public_html/.env` (uses `_B64` keys)
3. Visit `/check-setup.php` — `db_password_mode: base64`, `db_password_length: 12`, `database: connected OK`
4. Login: `admin` / `admin123`

### Password typo: `I` vs `l`

These look the same in many fonts but are **different passwords**:

| Wrong (fails) | Right (works) |
|---------------|---------------|
| `Rp3&;#I+o5l` | `Rp3&;#l+o5l` |
| uppercase **I** | lowercase **l** |

Correct `DB_PASSWORD_B64=UnAzJjsjbCtvNWw=`

## Local vs production

| | Local (XAMPP) | Production (Hostinger) |
|--|---------------|------------------------|
| Config file | `.env` | `.env` (from `deploy/.env.production`) |
| URL | `http://localhost/webmail` | `https://mailbox.djgroupllc.net` |
| Database | `dj_webmail` / `root` | `u321724939_mailbox` |
