# Hostinger deployment (domain root)

App URL: **https://mailbox.djgroupllc.net/** (no `/webmail` subfolder)

## Server folder layout

Upload the **project files directly into `public_html/`** — not into a `webmail/` subfolder.

```text
public_html/
  .env              ← from deploy/.env.production (rename on upload)
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
3. Upload `deploy/.env.production` and rename to `.env` on the server.
4. Enable PHP **imap** extension in hPanel.
5. Visit `https://mailbox.djgroupllc.net/` — login page should load.

## Local vs production

| | Local (XAMPP) | Production (Hostinger) |
|--|---------------|------------------------|
| Config file | `.env` | `.env` (from `deploy/.env.production`) |
| URL | `http://localhost/webmail` | `https://mailbox.djgroupllc.net` |
| Database | `dj_webmail` / `root` | `u321724939_mailbox` |
