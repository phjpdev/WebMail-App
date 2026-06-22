# D&J Webmail — User Guide

Quick reference for daily mail use. For admin setup (users, folders, filter rules), see your administrator.

---

## Logging in

Open your webmail URL in a browser (Chrome, Edge, Firefox, or Safari). Enter the **username** and **password** provided by your administrator.

- After login, **employees** land on their **personal folder** (not the shared Inbox).
- **Administrators** land on **Inbox**.
- You may be asked to **change your password** on first login.

---

## Layout

### Desktop (wide screen)

Three areas on one screen — similar to Outlook or Mac Mail:

```text
┌────────────┬─────────────────────┬──────────────────────┐
│  Folders   │  Message list       │  Reading pane        │
│  Compose   │  Command bar        │  Message + actions   │
└────────────┴─────────────────────┴──────────────────────┘
```

- **Folder list** (left) — Inbox, Sent, Drafts, Trash, Spam, and custom folders.
- **Message list** (center) — scan, search, select, and act on mail without opening each message.
- **Reading pane** (right) — read the selected message; reply without leaving the folder.

Click a message row to load it in the reading pane. The list stays visible.

### Mobile (phone / narrow screen)

Single-pane flow:

1. **Folder list** — tap the menu (☰) to open folders.
2. **Message list** — tap a message to open the **full read** page.
3. **Back** — use “← Back to folder” or swipe from the left edge to return to the list.

Compose opens as a **full page** on mobile (not a slide-over panel).

---

## Reading mail

- **Unread** messages are **bold** with a blue accent on the left.
- **Attachments** show a paperclip in the list; open the message to download or preview images/PDFs.
- **Important** (flagged) messages show a star in the list.
- Opening a message **marks it as read** automatically.
- Use **Mark unread** in the message toolbar if you want to read it again later.

### Message actions (toolbar)

| Action | What it does |
|--------|----------------|
| **Reply** | Reply to sender |
| **Reply all** | Reply to all recipients |
| **Forward** | Forward the message |
| **Print** | Print the message |
| **Mark unread / read** | Toggle read state |
| **Flag / Unflag** | Mark or remove importance |
| **Spam** | Move to Spam folder |
| **Delete** | Move to Trash |
| **Move to…** | Move to another folder |

On mobile, actions appear as a **compact icon row** you can scroll horizontally.

---

## Automatic mail sorting (filter)

The system **sorts incoming mail into folders automatically** using rules managed by your administrator (spam, employee aliases, client addresses, etc.).

**When sorting runs:**

| Trigger | What happens |
|---------|----------------|
| **You log in** | A filter pass runs for the shared mailbox |
| **You open Inbox** (or the filter source folder) | Rules are applied to new mail |
| **List refreshes** (manual Refresh or automatic poll) | Filter may run again, but **not more often than about once per minute** |
| **Background poll** (every 15–300 seconds, your setting) | Updates the list from cache; **does not** run a heavy filter pass every time |

**Important:** Sorting runs **only while someone has the webmail open**. If no one logs in overnight, new mail stays in Inbox until the **first login the next day** (or until someone opens the mailbox). This is by design — there is no background cron job.

After sorting, mail appears in the correct employee or client folder on the server, even if that folder was never opened in the browser.

---

## Replying

1. Open the message (pane or full read).
2. Click **Reply** (or press **r** on desktop).
3. **Send as** defaults to the alias the email was received on — change only if instructed.
4. Write your message and click **Send**.

**Reply all** includes all original recipients. **Forward** sends a copy to new recipients.

On desktop, compose opens in a **slide-over panel** on the right; the folder list stays visible.

---

## Composing new mail

1. Click **Compose** (or press **c**).
2. Choose **Send as** identity (your aliases).
3. Fill in **To**, **Subject**, and message body.
4. Optional: **Cc** / **Bcc** (click “Show Cc/Bcc”), **attachments**, rich formatting.
5. Click **Send** or **Save draft**.

Your **signature** from Settings is appended automatically to outgoing mail.

**Attachments:** up to 5 files, 10 MB each.

---

## Organizing mail

### From the message list (command bar)

Select one or more messages (checkboxes), then use:

- **Delete** — moves to Trash
- **Move to…** — pick a folder and click Move
- **Mark read / unread**
- **Flag / remove importance**
- **Refresh** — reload the folder and run filter if due

**Shift+click** checkboxes to select a range of messages.

Right-click a row (or use the ⋮ menu on mobile) for the same actions.

### From the reading pane or read page

Use the message toolbar for single-message move, delete, spam, and flag actions.

---

## Search

Use the search box above the message list. Search runs **in the current folder only** (subject, sender, and body text).

Press **/** to focus search quickly.

---

## Settings

Open **Settings** from the top bar.

| Setting | Description |
|---------|-------------|
| **Display name** | Shown on outgoing mail |
| **Email signature** | Appended to sent messages |
| **Inbox refresh interval** | 15–300 seconds between automatic list updates |
| **Theme** | Light, dark, or match system |
| **Sound / notifications** | Optional alerts for new mail |

**Change password** is available from Settings or when prompted after login.

---

## Keyboard shortcuts (desktop)

| Key | Action |
|-----|--------|
| **c** | Compose |
| **/** | Focus search |
| **j** / **k** | Next / previous message (opens in reading pane) |
| **Enter** | Open focused message in pane |
| **r** | Reply |
| **a** | Reply all |
| **e** | Delete |
| **Escape** | Close compose panel |
| **?** | Show shortcuts |

---

## Tips

- **Direct links** — bookmarking or sharing a message URL opens that message (full page on mobile, pane on desktop).
- **Print** — use Print on the full read page; headers and body print cleanly.
- **Need a new folder or alias?** — contact your administrator; employees cannot create folders in the mail UI.

---

## Related documents

- [`KNOWN-LIMITATIONS.md`](KNOWN-LIMITATIONS.md) — what the app does not do (threading, contacts, etc.)
- [`CLIENT-UAT-SCRIPT.md`](CLIENT-UAT-SCRIPT.md) — structured acceptance test (for client UAT)
