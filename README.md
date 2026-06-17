# D&J Group Webmail

PHP + MySQL webmail application for shared team mailbox access.

**Milestone 1:** Login, IMAP folder listing, SMTP test send, dashboard shell.

## Requirements

- WAMP (Windows + Apache + MySQL + PHP 8.0+)
- PHP extensions: `imap`, `openssl`, `pdo_mysql`
- Composer (optional — PHPMailer is included in `vendor/`)

## WAMP setup

### 1. Enable PHP IMAP extension

Edit `php.ini` (WAMP tray → PHP → php.ini) and uncomment or add:

```ini
extension=imap
extension=openssl
extension=pdo_mysql
```

Restart Apache.

Verify:

```bash
php -m | findstr imap
```

### 2. Configure Apache

**Option A — DocumentRoot to `public/` (recommended)**

Point virtual host DocumentRoot to:

```
d:/Work/Projects/FREELANGER/Mail/public
```

**Option B — Subfolder**

Copy or alias project under `www/mail/` and set `APP_URL` in `.env`:

```
APP_URL=http://localhost/mail/public
```

### 3. Environment file

```bash
copy .env.example .env
```

Edit `.env` with your values:

| Variable | Example |
|----------|---------|
| `DB_*` | MySQL credentials |
| `IMAP_HOST` | Mail server hostname |
| `IMAP_PORT` | 993 |
| `SMTP_HOST` | Mail server hostname |
| `SMTP_PORT` | 465 |
| `MAILBOX_EMAIL` | Shared mailbox address |
| `MAILBOX_PASSWORD` | Mailbox password |
| `TEST_EMAIL_TO` | Optional test recipient |

**Never commit `.env` to git.**

### 4. Database

In phpMyAdmin or MySQL CLI:

```bash
mysql -u root -p < database/schema.sql
mysql -u root -p < database/seed.sql
mysql -u root -p < database/seed-aliases.sql
mysql -u root -p < database/seed-folders-rules.sql
```

### 5. Composer (optional)

If you need to reinstall dependencies:

```bash
composer install
```

Or:

```bash
php composer.phar install
```

## Default login (change after setup)

| Username | Password | Role |
|----------|----------|------|
| `admin` | `admin123` | admin |
| `employee` | `employee123` | employee |

## Routes

| Method | Path | Description |
|--------|------|-------------|
| GET | `/login` | Login page |
| POST | `/login` | Authenticate |
| GET | `/logout` | Log out |
| GET | `/` | Redirect to Inbox (auth required) |
| GET | `/folder/{path}` | Message list for folder |
| GET | `/folder/{path}/message/{uid}` | Read message |
| GET | `/attachment` | Download attachment |
| POST | `/message/move` | Move message to folder |
| POST | `/message/trash` | Move message to Trash |
| GET | `/compose` | New email |
| GET | `/compose/reply` | Reply to message |
| GET | `/compose/forward` | Forward message |
| POST | `/compose/send` | Send email |
| GET | `/status` | Connection diagnostics (admin) |
| POST | `/test-email` | Send SMTP test (admin only) |
| GET | `/admin` | Admin dashboard |
| POST | `/admin/sync` | Re-run filter pass |
| GET/POST | `/admin/users/*` | Manage users |
| GET/POST | `/admin/aliases/*` | Manage send-as aliases |
| GET/POST | `/admin/folders/*` | Manage folder registry |
| GET/POST | `/admin/rules/*` | Manage filter rules |

## Filter behavior

Mail is delivered to the shared `INBOX`, then **moved** to the correct folder by PHP (rules in MySQL). **No cron required** — works on Hostinger from the zip alone.

1. **Opening mail** — filter runs server-side on folder page loads (throttled).
2. **While webmail is open** — filter runs inside the existing list-sync poll (~30s); no separate filter AJAX.
3. **Admin → Sync now** — forces an immediate pass.

## Milestone 3 exit criteria

- [ ] Filter pass on login
- [ ] Admin CRUD for users, aliases, folders, rules
- [ ] Employee onboarding creates IMAP folder + alias + rule
- [ ] Migration doc: PHP + SQL only

See [`docs/MILESTONE-3-DEMO.md`](docs/MILESTONE-3-DEMO.md), [`docs/MIGRATION.md`](docs/MIGRATION.md), [`docs/USER-GUIDE.md`](docs/USER-GUIDE.md).

## Project structure

```
Mail/
  public/index.php       Front controller
  config/                App, database, mail config
  src/                   PHP classes (Auth, Services, Controllers)
  views/                 HTML templates
  database/schema.sql    Full DB schema
  database/seed.sql      Default users
  storage/logs/          Application logs
```

## Troubleshooting

**IMAP extension not enabled**

Enable `extension=imap` in php.ini and restart Apache.

**IMAP connection failed**

- Check `MAILBOX_EMAIL` and `MAILBOX_PASSWORD` in `.env`
- For GoDaddy/dev, set `IMAP_VALIDATE_CERT=false`

**Database connection failed**

- Confirm MySQL is running
- Create database via `database/schema.sql`

**404 on routes**

- Enable `mod_rewrite` in Apache
- Ensure `.htaccess` is allowed (`AllowOverride All`)

## Documentation

- [`docs/MILESTONE-2-DEMO.md`](docs/MILESTONE-2-DEMO.md) — Core webmail demo
- [`docs/MILESTONE-3-DEMO.md`](docs/MILESTONE-3-DEMO.md) — Filter + admin demo
- [`docs/MIGRATION.md`](docs/MIGRATION.md) — Server migration guide
- [`docs/USER-GUIDE.md`](docs/USER-GUIDE.md) — End-user guide
