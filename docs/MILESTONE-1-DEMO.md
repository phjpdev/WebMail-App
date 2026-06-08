# Milestone 1 — Client Demo Script

Use this checklist when demoing to the client.

## Before demo

1. WAMP running (Apache + MySQL)
2. `extension=imap` enabled in php.ini
3. `.env` configured with mailbox credentials
4. Database imported: `schema.sql` + `seed.sql`
5. Site URL opens (e.g. `http://localhost/mail/public`)

## Demo steps

1. **Login page** — Show clean login form (no credentials visible in page source)
2. **Log in as admin** — username: `admin`, password: `admin123`
3. **Dashboard** — Show "IMAP connected successfully"
4. **Folder list** — Scroll table; folders match their mail server structure
5. **Header test** — Show latest INBOX message headers (From, Delivered-To, Subject)
6. **Send test email** — Click button (admin only); confirm email arrived
7. **Log out** — Return to login page
8. **Employee login** — username: `employee`; note no "Send test email" button
9. **Explain Milestone 2** — Read, compose, reply, send-as, move, trash

## Exit criteria checklist

- [ ] Login / logout works
- [ ] Wrong password shows error
- [ ] IMAP lists real folders
- [ ] SMTP test send works
- [ ] Credentials only in `.env`
- [ ] Admin vs employee roles behave correctly

## Troubleshooting during demo

| Issue | Quick fix |
|-------|-----------|
| IMAP failed | Check `.env` password; set `IMAP_VALIDATE_CERT=false` |
| Blank page | Check `storage/logs/app.log`; enable `APP_DEBUG=true` |
| 404 routes | Enable Apache `mod_rewrite`; check DocumentRoot is `public/` |
| Database error | Re-import `schema.sql` and `seed.sql` |
