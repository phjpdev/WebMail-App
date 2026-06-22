# D&J Webmail — Known Limitations

Honest trade-offs for UAT and daily use. These are **intentional design choices**, not bugs waiting to be fixed — unless noted as a future enhancement.

---

## Filtering & delivery

| Limitation | Detail |
|------------|--------|
| **Filter on access, not on schedule** | Mail is sorted by PHP rules when someone opens the webmail or refreshes the Inbox — **not** by a cron job or mail-server filter. If nobody logs in overnight, new mail stays in Inbox until the next session. |
| **Filter throttle** | Automatic filter passes are limited to about **once per minute** (configurable) to avoid overloading IMAP during polling. |
| **Light poll skips filter** | Background list refresh (every 15–300 s) usually reads from a local cache and does **not** run a full filter pass every time. Use **Refresh** in the command bar for an immediate filter + sync. |
| **Admin rules only** | End users cannot create or edit filter rules in the mail UI. Administrators manage rules in the Admin panel. |

---

## Mail client features (out of scope v1)

| Feature | Status |
|---------|--------|
| **Conversation threading** (Gmail-style) | Not implemented — messages are shown as individual rows |
| **Contacts / address book** | Not implemented — type addresses manually; chips validate format only |
| **Calendar / tasks** | Not implemented |
| **Real-time push** | Polling only — new mail appears after the next refresh interval |
| **Drag-and-drop move** | Not implemented — use **Move to…** dropdown |
| **Message preview snippet** in list | Subject + sender only — IMAP overview does not include body preview |
| **Recipient autocomplete** | No contact database to suggest names |
| **Pop-out / minimize compose** | Compose is slide-over (desktop) or full page (mobile) only |
| **Create folders in mail UI** | Admin panel only — by design for shared mailbox control |

---

## Reading & attachments

| Limitation | Detail |
|------------|--------|
| **PDF preview** | PDFs open in a **new browser tab**, not inline in the message body. Images preview inline. |
| **Delivered-To header** | Shown in message details for alias routing debug — may be hidden in a future polish pass for end users. |
| **Collapsible headers** | From/To/Cc block is always expanded — not collapsible like Outlook. |

---

## Layout & folders

| Limitation | Detail |
|------------|--------|
| **Not a pixel clone of Outlook** | Outlook-inspired layout and colors; density and icons are similar, not identical. |
| **Fixed folder groups** | Inbox, Sent, Drafts, and Trash groups stay expanded. Only the custom **Folders** group collapses; state is saved in the browser. |
| **No favorites / pinned folders** | Folders are grouped (Inbox, Sent, Drafts, Trash, Other) — no user-defined pin list. |
| **Employee Inbox hidden** | Employees do not see the shared Inbox in the sidebar — they work from their personal folder. Admins see all folders. |
| **Desktop breakpoint** | Three-pane layout at **≥ 1024px** width. Below that, mobile single-pane stack. |

---

## Performance & infrastructure

| Limitation | Detail |
|------------|--------|
| **MySQL mail cache** | Folder lists are cached locally for speed. Requires `mail_index` / `mail_bodies` / `mail_sync_state` tables (see `database/migrations-mail-cache.sql`). Without migration, the app falls back to direct IMAP (slower). |
| **No cron for cache warm** | Cache is warmed on login and folder access via the browser — not by a server scheduler. |
| **Shared session filter** | One filter pass benefits all users, but timing depends on who has the site open. |
| **IMAP dependency** | All read/send/move operations require a live connection to the mail server. |

---

## Security & accounts

| Limitation | Detail |
|------------|--------|
| **Session timeout** | Default **8 hours** of inactivity (`SESSION_LIFETIME` in server config). |
| **Send-as restricted** | Users can only send from aliases assigned to them. |
| **Employee folder access** | Employees see their linked folder plus Sent, Drafts, Trash, and Spam — not other employees’ folders or Inbox. |

---

## Admin & deployment

| Limitation | Detail |
|------------|--------|
| **Portable deploy** | PHP files + MySQL backup only — no Node build step, no cron setup. Mail server credentials updated in `.env` after migration. |
| **Reprocess Inbox** | Admin can force a full re-filter; use sparingly on large mailboxes. |

---

## Future enhancements (not promised)

These may be considered in a later phase if requested:

- Drag-and-drop move
- Bulk spam from list command bar
- Inline PDF viewer
- Collapsible read headers
- Favorites / pinned folders
- Optional body snippet in list (with performance cost)

---

*Last updated: U7 client readiness (2026-06-17)*
