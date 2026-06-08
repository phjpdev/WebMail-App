# Milestone 3 — Client Demo Script

## Before demo

1. WAMP running (Apache + MySQL)
2. `.env` configured with working mailbox credentials
3. Database imported: `schema.sql`, `seed.sql`, `seed-aliases.sql`, `seed-folders-rules.sql`
4. Log in as `admin` / `admin123`

## Demo steps

1. **Filter on login** — Log out, send a test email to `support@bebenailsmd.com`, log in as `employee`. Watch flash: "Organized X messages, Y moved."
2. **Verify routing** — Open `INBOX.support`; confirm the test email arrived there (not Inbox).
3. **Spam rule** — In Admin → Filter rules, show spam rules. Explain spam mail moves to `INBOX.Spam`.
4. **Add client folder** — Admin → Folders → Add folder. Create `INBOX.testclient` with a subject-contains rule. No code deploy.
5. **Add employee** — Admin → Users → Add user with alias email. Show auto-created folder + filter rule.
6. **Reply-as-alias** — Open filtered mail → Reply → verify Send-as matches delivered alias.
7. **Manual move + trash** — Still work from read view.
8. **Sync now** — Admin → Dashboard → Sync now to re-run filter.
9. **Migration** — Show `docs/MIGRATION.md`: copy PHP + SQL only.

## Exit criteria checklist

- [ ] Login triggers filter pass
- [ ] Spam moved to Spam folder
- [ ] Mail routed to employee/client folders without opening them in UI
- [ ] Admin can add employee + alias + folder + rules via UI
- [ ] Reply uses received alias
- [ ] Manual move and Trash still work
- [ ] Migration doc complete
