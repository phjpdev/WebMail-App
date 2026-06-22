# D&J Webmail — Client UAT Script

**Purpose:** Structured acceptance test (~30 minutes, 15 steps).  
**Not:** Open-ended exploratory testing — follow the steps and record Pass / Fail / Notes.

**Before you start**

- [ ] Production URL: `________________________`
- [ ] Test date: `________________________`
- [ ] Tester name: `________________________`
- [ ] Read [`KNOWN-LIMITATIONS.md`](KNOWN-LIMITATIONS.md) (5 min)

**Test accounts** (request from administrator if not provided)

| Role | Username | Notes |
|------|----------|-------|
| Admin | | Full folder access |
| Employee | | Personal folder only |

---

## Step 1 — Login & landing folder (2 min)

1. Log in as **employee**.
2. Confirm you land on your **personal folder** (not shared Inbox).
3. Log out. Log in as **admin** — confirm **Inbox** opens.

| Pass | Fail | Notes |
|:----:|:----:|-------|
| ☐ | ☐ | |

---

## Step 2 — Folder navigation (2 min)

1. As admin, open **Sent**, **Drafts**, **Trash**.
2. Confirm **active folder** is highlighted and **unread badges** update when mail is unread.
3. Expand/collapse the **Folders** group (custom folders) — refresh page; state should persist.

| Pass | Fail | Notes |
|:----:|:----:|-------|
| ☐ | ☐ | |

---

## Step 3 — Three-pane layout (desktop only) (2 min)

1. On a screen **≥ 1024px** wide, open any folder with messages.
2. Confirm: folders | list | reading pane on **one screen**.
3. Click a message — it loads in the **right pane** without full page reload.

| Pass | Fail | N/A (mobile) | Notes |
|:----:|:----:|:------------:|-------|
| ☐ | ☐ | ☐ | |

---

## Step 4 — Message list & unread styling (2 min)

1. Find an **unread** message — bold sender/subject, blue left bar.
2. Confirm list is **newest first**.
3. Use **pagination** at the bottom if more than one page.

| Pass | Fail | Notes |
|:----:|:----:|-------|
| ☐ | ☐ | |

---

## Step 5 — Search (2 min)

1. Enter a word from a known **subject** in the search box.
2. Confirm results appear from the **current folder only**.
3. Clear search — full list returns.

| Pass | Fail | Notes |
|:----:|:----:|-------|
| ☐ | ☐ | |

---

## Step 6 — Command bar bulk actions (3 min)

1. Select **two messages** (checkboxes; try **Shift+click** for range).
2. Click **Delete** — confirm both move to Trash (check Trash folder).
3. Select one message → **Mark unread** → confirm bold styling returns.

| Pass | Fail | Notes |
|:----:|:----:|-------|
| ☐ | ☐ | |

---

## Step 7 — Read message & headers (2 min)

1. Open a message (pane or full read on mobile).
2. Confirm **Subject, From, To, Date**, and body display.
3. If the message has an attachment, confirm **download** works; image shows preview if applicable.

| Pass | Fail | Notes |
|:----:|:----:|-------|
| ☐ | ☐ | |

---

## Step 8 — Reply with correct alias (3 min)

1. Open mail that arrived on an employee **alias** (e.g. `john@…`).
2. Click **Reply**.
3. Confirm **Send as** defaults to that alias.
4. Send a test reply to yourself — verify From address in received mail.

| Pass | Fail | Notes |
|:----:|:----:|-------|
| ☐ | ☐ | |

---

## Step 9 — Compose new mail (2 min)

1. Click **Compose** (or **c** on desktop).
2. Fill To, Subject, body; choose **Send as**.
3. Send — message appears in **Sent**; compose closes; you remain in mail layout.

| Pass | Fail | Notes |
|:----:|:----:|-------|
| ☐ | ☐ | |

---

## Step 10 — Draft save & resume (2 min)

1. Start compose, enter subject/body, click **Save draft**.
2. Open **Drafts** folder — open the draft.
3. Edit and **Send** (or delete draft).

| Pass | Fail | Notes |
|:----:|:----:|-------|
| ☐ | ☐ | |

---

## Step 11 — Move & delete from reading pane (2 min)

1. Open a message in your folder.
2. **Move** it to another folder (e.g. admin: employee folder).
3. Confirm it **disappears** from current list.
4. Open another message → **Delete** → confirm in Trash.

| Pass | Fail | Notes |
|:----:|:----:|-------|
| ☐ | ☐ | |

---

## Step 12 — Automatic filter (custom module) (3 min)

1. Send test mail to an **employee alias** (or use existing unfiltered Inbox mail).
2. As admin, open **Inbox** (or click **Refresh**).
3. Confirm mail **moves to the employee folder** within ~1 minute.
4. Log in as that **employee** — mail is in their folder.

| Pass | Fail | Notes |
|:----:|:----:|-------|
| ☐ | ☐ | |

---

## Step 13 — Mobile flow (2 min)

*Skip if no phone available — mark N/A.*

1. Open webmail on phone (or narrow browser window &lt; 1024px).
2. Menu → folder → tap message → **full read** page.
3. Confirm **icon toolbar** for actions; swipe or Back returns to list.

| Pass | Fail | N/A | Notes |
|:----:|:----:|:---:|-------|
| ☐ | ☐ | ☐ | |

---

## Step 14 — Settings & theme (2 min)

1. Open **Settings**.
2. Change **theme** to dark — confirm UI updates.
3. Add a one-line **signature**, save, send mail — signature appears.

| Pass | Fail | Notes |
|:----:|:----:|-------|
| ☐ | ☐ | |

---

## Step 15 — Admin provisioning (admin only) (3 min)

1. Admin panel → confirm you can view **rules**, **folders**, **aliases**.
2. Optional: add a test rule or run **Sync now** — no error.
3. Confirm changes do **not** require code deploy.

| Pass | Fail | N/A | Notes |
|:----:|:----:|:---:|-------|
| ☐ | ☐ | ☐ | |

---

## Sign-off

| Result | Count |
|--------|------:|
| Pass | |
| Fail | |
| N/A | |

**Overall UAT result:** ☐ **Accepted** · ☐ **Accepted with notes** · ☐ **Not accepted**

**Blocking issues (if any):**

```
1.
2.
3.
```

**Tester signature / date:** ________________________

**Administrator / developer follow-up:** ________________________

---

## Escalation

- **Expected limitations** (filter timing, no threading, etc.) → [`KNOWN-LIMITATIONS.md`](KNOWN-LIMITATIONS.md)
- **How to use daily** → [`USER-GUIDE.md`](USER-GUIDE.md)
- **Defects:** send screenshot + step number + browser/device to administrator
