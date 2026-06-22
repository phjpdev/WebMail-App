# D&J Webmail — Demo Recording Script (5–10 min)

Use this script to record the client demo video referenced in [`CLIENT-READINESS-PACKET.md`](CLIENT-READINESS-PACKET.md).

**Setup before recording**

- Production or staging with real mail (not empty folders)
- Browser at **1280×720** or wider for desktop scenes
- Phone ready for one mobile clip (optional)
- Admin + employee test accounts logged in in separate browser profiles (or switch users between scenes)

---

## Scene 1 — Intro (30 sec)

**Say:**  
“This is D&J Webmail — the browser replacement for Thunderbird. Same shared mailbox, same filter rules, but with an Outlook-style layout your team already knows.”

**Show:** Login page → log in as employee.

---

## Scene 2 — Employee landing & folders (45 sec)

**Say:**  
“Employees land on their own folder, not the shared Inbox noise. Folders show unread counts; Sent, Drafts, and Trash work like any mail client.”

**Show:** Sidebar with badges → click Sent → back to personal folder.

---

## Scene 3 — Three-pane desktop (1 min)

**Say:**  
“On desktop, folder list, message list, and reading pane are on one screen — no full page reload when you read mail.”

**Show:** Widen browser → click 3–4 messages in a row → pane updates each time → list scroll position stays.

**Optional:** Press **j** / **k** to move between messages in pane.

---

## Scene 4 — Command bar (1 min)

**Say:**  
“You can organize mail from the list without opening it — select messages, delete, move, mark read, flag.”

**Show:** Check two rows → Delete (confirm) → Refresh button → Shift+click range select.

---

## Scene 5 — Reply with alias (1 min)

**Say:**  
“When you reply, Send-as defaults to the alias the mail was received on — critical for your client-facing addresses.”

**Show:** Open filtered mail → Reply → highlight Send-as → send test (or cancel if live).

---

## Scene 6 — Compose slide-over (1 min)

**Say:**  
“New mail opens beside your inbox — send and you’re right back in the same folder.”

**Show:** Compose → fill To/Subject → mention Cc/Bcc toggle → Send or Save draft → panel closes, toast appears.

---

## Scene 7 — Automatic filter (1 min)

**Say:**  
“Filtering still runs entirely in PHP when someone opens the mailbox — no cron, no cPanel filters. Mail to an employee alias routes to their folder when Inbox is refreshed.”

**Show (admin):** Inbox → Refresh → mail moves (or show already-sorted mail in employee folder) → employee view of same mail.

---

## Scene 8 — Mobile (45 sec)

**Say:**  
“On phone, it stacks like Outlook mobile — list, then full read, compact icon actions.”

**Show:** Narrow window or phone → menu → message → icon toolbar → swipe back.

---

## Scene 9 — Settings & wrap (30 sec)

**Say:**  
“Light and dark theme, signature, refresh interval. Full user guide and a 30-minute UAT script are included — known limitations are documented upfront.”

**Show:** Settings → theme toggle → logout.

**Say:**  
“Ready for structured UAT — thank you.”

---

## Recording tips

- Hide bookmarks bar; use clean test data (no sensitive client content on screen).
- Pause between scenes if editing later.
- Upload to Loom / Google Drive / SharePoint and paste link in readiness packet.
- Target **under 10 minutes** — cut Scene 8 if short on time.

---

*End of script*
