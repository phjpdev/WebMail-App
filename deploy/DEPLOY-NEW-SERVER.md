# Deploy to the new server (Windows / WAMP) — checklist

Moving **mailbox.djgroupllc.net** off Hostinger onto the client's own server
(XEON, WAMP in a VM). Work top to bottom. The whole thing is ~30–45 min.

> **The one sentence that matters:** copying the files is only 1 of 3 parts.
> You must also **migrate the MySQL database** and **write a new `.env`**, and on
> a self-hosted box you must **enable the imap extension** and **let Apache read
> `.htaccess`** — or the app breaks / leaks its passwords.

---

## Part A — Prepare the new server (WAMP)

- [ ] **1. PHP ≥ 8.0.** WAMP menu → PHP → Version → pick 8.0+.
- [ ] **2. Enable PHP extensions:** WAMP → PHP → PHP extensions → tick **`imap`**
      and **`pdo_mysql`** (openssl / mbstring / curl are usually already on).
      This is the #1 gotcha — the app is 100% IMAP and won't run without it.
- [ ] **3. Enable Apache `rewrite_module`:** WAMP → Apache → Apache modules →
      tick **`rewrite_module`**.
- [ ] **4. Point the site at the project root with `AllowOverride All`.**
      Edit the WAMP vhost (WAMP → Apache → httpd-vhosts.conf) so the site's
      `DocumentRoot` is the folder that contains `index.php` and `.htaccess`,
      and inside its `<Directory>` block set **`AllowOverride All`**. Restart
      Apache (WAMP tray → Restart DNS/Apache).
      ⚠️ If `AllowOverride` is left at `None`, Apache **ignores `.htaccess`** →
      your `.env` (with all passwords) becomes downloadable and no page routes.

Example vhost block:
```apache
<VirtualHost *:80>
    ServerName mailbox.djgroupllc.net
    DocumentRoot "C:/wamp64/www/webmail"
    <Directory "C:/wamp64/www/webmail">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

---

## Part B — Migrate the database (the part "copy files" misses)

- [ ] **5. Export from Hostinger:** hPanel → phpMyAdmin → open **`u321724939_mailbox`**
      → **Export** → format **SQL** → Go → download the `.sql` file.
- [ ] **6. Create the DB on the new server:** WAMP phpMyAdmin → **New** → create a
      database (e.g. `dj_webmail`) → create a user → grant it **ALL PRIVILEGES**
      on that database.
- [ ] **7. Import:** select the new database → **Import** → choose the `.sql` from
      step 5 → Go.

*(If the export is large and import times out, increase `max_allowed_packet` /
`upload_max_filesize` in WAMP, or import via `mysql -u user -p db < dump.sql`.)*

---

## Part C — Copy the files

- [ ] **8. Copy the whole project folder** into the site's DocumentRoot
      (e.g. `C:\wamp64\www\webmail`), **except**:
      `.git/`, `deploy/`, the old `.env`, `_cvtest.php`, `probe3.php`,
      `phpmailer.zip`, `composer.phar`, `storage/logs/*.log`,
      `storage/c.txt`, `storage/p.html`.
      Keep `vendor/` (the PHP libraries).
- [ ] **9. Create these empty writable folders** if the copy didn't include them:
      `storage/logs/`, `storage/post_send/`, `storage/thread_replies/`.

---

## Part D — Configure

- [ ] **10. Write `.env`:** copy `deploy/env.newserver.example` into the project
      root, rename to **`.env`**, and fill in:
      - `APP_URL` → the new address
      - `DB_HOST / DB_NAME / DB_USER / DB_PASSWORD` → the new MySQL (step 6)
      - `MAILBOX_PASSWORD` → same mailbox password as before
      - **Leave IMAP/SMTP/MAILBOX_EMAIL unchanged** — the email stays on its
        current mail host; you're only moving the web app.
      - Keep `APP_DEBUG=false`.

---

## Part E — Verify, then go live

- [ ] **11. Upload `check-setup.php`** to the project root, open
      `http://<server>/check-setup.php` — **every row must be green.** It tests
      PHP, imap/pdo, `.env`, the DB connection, writable folders, and rewrite.
      Add `?imap=1` to also test the live mailbox login.
- [ ] **12. Fix any red rows**, re-run until all green, **then DELETE
      `check-setup.php`** from the server (it exposes environment details).
- [ ] **13. Open the app** → log in → open a folder → send a test → receive a
      test. Confirm folders/users all came across from the DB import.
- [ ] **14. DNS + SSL:** point the domain's A record at the new server's public
      IP, install an SSL certificate, and confirm `APP_URL` uses `https://`.
      (Login cookies are marked Secure over HTTPS.)

---

## Quick "is it correct?" answer for the client

> No — don't *only* copy files. Copy the files **and** export/import the MySQL
> database **and** write a new `.env`. On his own server also: turn on PHP
> `imap`, set Apache `AllowOverride All` so `.htaccess` protects the secrets,
> and add SSL. The email account itself doesn't move — it stays where it is;
> we're only relocating the web interface that reads it.

## Notes

- The old host was LiteSpeed; WAMP is Apache. The one place that differs
  (deferred "move message" background ops) falls back to running inline —
  no breakage, just slightly less instant on moves.
- Passwords with `# & ; +` break `.env` parsing — use the `_B64` variant and
  `php deploy/encode-secret.php "the-password"` to encode.
