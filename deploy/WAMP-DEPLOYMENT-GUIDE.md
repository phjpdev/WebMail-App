# D&J Webmail — WAMP Deployment & Performance Guide

This is the exact procedure that produced a **fast** webmail install on a WAMP box.
The four items in **Section A** are what determine speed — the previous slow
production was slow because these weren't all correct.

> Paths below assume WAMP at `C:\wamp64`. Adjust if yours differs.
> Replace every `<...>` placeholder with your real value. Never commit real
> mailbox passwords into shared files.

---

## A. The 4 things that determine speed (get these right!)

| # | Item | Why it matters | Where |
|---|------|----------------|-------|
| 1 | **`PHP_CLI_PATH`** points at the **installed** php.exe | It's what launches the background sync worker. A wrong path = the cache never refreshes = every click hits the slow mailbox | `.env` (Step 4) |
| 2 | **opcache ON** | Without it PHP recompiles the whole app on every request | PHP settings (Step 1) |
| 3 | **Backfill has been run** | Populates the local database so folders load from MySQL, not a live IMAP call | Step 7 |
| 4 | **A scheduled task keeps the sync running** | Keeps the cache warm + catches new mail when no one's looking | Step 8 |

If it's slow after deploy, it's almost always one of these four.

---

## B. Fresh deployment (full steps)

### Step 1 — PHP configuration
1. Left-click the green **WAMP tray icon** → **PHP → PHP extensions** → check **`imap`** (wait for the icon to go green again).
2. **PHP → PHP settings** → ensure **opcache** is enabled. *(If there's no toggle, set `opcache.enable=1` in `php.ini`.)*
3. Confirm the **exact PHP version folder** and that the command-line PHP has imap:
   ```
   dir C:\wamp64\bin\php
   C:\wamp64\bin\php\php<ver>\php.exe -m | findstr /i imap
   ```
   Note the full path, e.g. `C:\wamp64\bin\php\php8.3.28\php.exe` — you need it for Steps 4, 7, 8.

### Step 2 — App files
- Put the app at `C:\wamp64\www\webmail` so that `C:\wamp64\www\webmail\public\index.php` exists.
- Confirm `vendor\autoload.php` is present (bundled — no Composer needed).
- The `storage\` folder must be writable (logs, cache).

### Step 3 — Database
**Fresh install:** in phpMyAdmin → **Import** → `database\_install_schema.sql` (creates `dj_webmail` + all tables) → then import `database\seed.sql` (creates the `admin` login = `admin` / `admin123`).

**Migrating an existing DB (recommended for production):** export the current DB (phpMyAdmin → Export → **Custom**, all tables, **Add DROP TABLE** on, **gzipped**), import it into `dj_webmail`, then reset the admin password:
```sql
UPDATE users SET password_hash='$2b$10$9tb6vHRvc1sxQkCsFDx5ueeBSUfavzBZUrwoYv/bppIxbdSsTHfvO', must_change_password=0 WHERE username='admin';
```
*(That hash = password `admin123`.)*

### Step 4 — `.env`  (create `C:\wamp64\www\webmail\.env`)
```
APP_NAME="D&J Webmail"
APP_URL=http://<your-vhost-or-localhost>
APP_DEBUG=false

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=dj_webmail
DB_USER=root
DB_PASSWORD=

IMAP_HOST=<real mail host, e.g. imap.hostinger.com>
IMAP_PORT=993
IMAP_ENCRYPTION=ssl
IMAP_VALIDATE_CERT=false
SMTP_HOST=<real smtp host>
SMTP_PORT=465
SMTP_ENCRYPTION=ssl
SMTP_VALIDATE_CERT=false
MAILBOX_EMAIL=<real mailbox address>
MAILBOX_PASSWORD=<real password>
# If the password has special characters (| + # etc.), leave the line above blank
# and use base64 instead:  MAILBOX_PASSWORD_B64=<base64 of the password>

# CRITICAL: the exact php.exe from Step 1.3 — this runs the background sync.
PHP_CLI_PATH=C:\wamp64\bin\php\php<ver>\php.exe
```
> In Notepad: **Save As** → **All Files** → filename `".env"` (with quotes) so it isn't saved as `.env.txt`.

### Step 5 — Serve `public/`
- WAMP homepage → **Add a Virtual Host** → path = `C:\wamp64\www\webmail\public` → create → **Restart DNS**.
- Ensure Apache **`rewrite_module`** is enabled (WAMP tray → Apache → Apache modules).

### Step 6 — Verify
- Open the app → log in as `admin` → **Connection status → Test connection now** → must connect to the mailbox.

### Step 7 — Warm the cache (the big speed step)
Run the full-history index so **all** folders serve from the local DB instead of a live IMAP call:
```
cd C:\wamp64\www\webmail
C:\wamp64\bin\php\php<ver>\php.exe scripts\backfill-history.php
```
- Safe to re-run; it's resumable and idempotent. Run it **off-hours** for a big mailbox.
- For a very large mailbox, cap each run and repeat until done:
  ```
  C:\wamp64\bin\php\php<ver>\php.exe scripts\backfill-history.php --max-runtime=1800
  ```

### Step 8 — Keep it warm (scheduled background sync)
Create a **Windows Task Scheduler** job so the index stays fresh and new mail appears even when the webmail isn't open:
- **Program/script:** `C:\wamp64\bin\php\php<ver>\php.exe`
- **Arguments:** `C:\wamp64\www\webmail\scripts\backfill-history.php --max-runtime=1800`
- **Start in:** `C:\wamp64\www\webmail`
- **Trigger:** every 1–2 hours (Run whether user is logged on or not).

### Step 9 — Confirm speed
Open several folders, including old ones — they should open instantly (from cache). If any are slow, the backfill (Step 7) hasn't reached them yet, or the scheduled task (Step 8) isn't running.

---

## C. Updating an EXISTING production install (the common case)

Their production already has the app + DB running (just slow). You don't need to
rebuild the DB — just bring it up to the working setup:

1. **Back up first:** copy the current `www\webmail` folder and export the DB.
2. **Update the code** — replace `src\`, `public\`, `views\`, `config\`, `vendor\`, `scripts\`, `database\`, `helpers.php` with the new version. **Do not overwrite their `.env`.**
3. **Fix `.env`** — set `PHP_CLI_PATH` to the real installed php.exe (Step 1.3). Remove any stale `LIVE_SYNC_INTERVAL` / `MAIL_POLL_INTERVAL` overrides.
4. **Turn on opcache** (Step 1.2) and confirm **imap** is enabled for web + CLI.
5. **Run the backfill** (Step 7) to warm the cache.
6. **Add the scheduled task** (Step 8).
7. Restart WAMP (Apache) and verify (Step 9).

---

## D. Rollback
If anything goes wrong: stop WAMP, restore the backed-up `www\webmail` folder and re-import the backed-up DB, restart WAMP. Because Step C.1 backed both up, this is a clean revert.
