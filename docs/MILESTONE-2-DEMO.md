# Milestone 2 — Client Demo Script

Use this checklist when demoing core webmail features.

## Before demo

1. WAMP running (Apache + MySQL)
2. `.env` configured with working mailbox credentials
3. Database imported: `schema.sql`, `seed.sql`, `seed-aliases.sql`
4. Site opens at `http://localhost/webmail/`

## Demo steps

1. **Login** — Log in as `employee` / `employee123`
2. **Inbox** — Land on Inbox message list with folder sidebar
3. **Browse folder** — Click `INBOX.support` (or another folder)
4. **Read message** — Click a row; verify headers and body display
5. **Reply** — Click Reply; verify **Send as** defaults to the alias the mail was delivered to
6. **Send reply** — Send (or cancel if demo-only)
7. **Move** — Open a message; use **Move to…** to move to another folder (e.g. `INBOX.test1`)
8. **Delete** — Delete a message; confirm it appears in `INBOX.Trash`
9. **Compose** — Click **Compose**; send new mail with send-as dropdown
10. **Admin status** — Log in as `admin`; open **Connection status**; optional SMTP test

## Exit criteria checklist

- [ ] Read mail in any folder
- [ ] Compose and send with chosen send-as identity
- [ ] Reply defaults to alias the message was received on
- [ ] Manual move between folders works
- [ ] Delete moves mail to `INBOX.Trash`
- [ ] No filter pass on login (Milestone 3)

## Adding send-as aliases (until M3 admin UI)

```sql
USE dj_webmail;
INSERT INTO aliases (email, display_name, active) VALUES
('alias@example.com', 'Display Name', 1);
```
