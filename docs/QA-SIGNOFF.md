# D&J Webmail — Internal QA Sign-Off (U7)

**Developer checklist** — run on **production** with admin + employee test accounts before client UAT.  
**Date:** ____________ · **Tester:** ____________ · **Environment URL:** ____________

---

## Defect log

| ID | Priority | Step / area | Description | Status |
|----|----------|-------------|-------------|--------|
| | P0 / P1 / P2 | | | Open / Fixed / Won't fix |

**Sign-off rule:** Zero open **P0** or **P1** defects before sending the readiness packet.

---

## Appendix A — Baseline webmail checklist

*Developer runs this — not the client.*

### Folders & navigation

| # | Item | Result | Notes |
|---|------|:------:|-------|
| A1 | Folder list shows Inbox, Sent, Drafts, Trash, custom folders | ☐ P ☐ F | `folder-sidebar.php`, `FolderCache.php` |
| A2 | Active folder highlighted; unread badge per folder | ☐ P ☐ F | Badges + `/folders/unread` poll |
| A3 | Employee lands on personal folder (not shared Inbox) | ☐ P ☐ F | `default_mail_folder()` |
| A4 | Folder groups expand/collapse; state persists | ☐ P ☐ F | Custom **Folders** group only; `localStorage` |
| A5 | Switch folder updates list; pane clears or empty state | ☐ P ☐ F | AJAX `loadFolderAjax()` |

### Message list

| # | Item | Result | Notes |
|---|------|:------:|-------|
| A6 | Messages sorted by date (newest first) | ☐ P ☐ F | IMAP overview reversed |
| A7 | Unread visually distinct (bold + indicator) | ☐ P ☐ F | Blue accent bar |
| A8 | Pagination works | ☐ P ☐ F | `pagination.php` |
| A9 | Select one / select many | ☐ P ☐ F | Checkboxes + select-all |
| A10 | Search by subject or from in current folder | ☐ P ☐ F | Folder-scoped search |

### Reading

| # | Item | Result | Notes |
|---|------|:------:|-------|
| A11 | Open message shows From, To, Cc, Date, Subject, body | ☐ P ☐ F | Subject in header block + pane title |
| A12 | HTML body safe; plain text fallback | ☐ P ☐ F | `HtmlSanitizer` |
| A13 | Attachments download and preview (images/PDF) | ☐ P ☐ F | Images inline; PDF new-tab preview |
| A14 | Open marks read; mark unread works | ☐ P ☐ F | Auto on open + manual toggle |
| A15 | Flag / unflag works | ☐ P ☐ F | List + read + bulk |

### Compose & send

| # | Item | Result | Notes |
|---|------|:------:|-------|
| A16 | New message: To, Cc, Bcc, Subject, body | ☐ P ☐ F | |
| A17 | Send-as dropdown (aliases) | ☐ P ☐ F | |
| A18 | Signature appended from settings | ☐ P ☐ F | |
| A19 | Attach files (size limit enforced) | ☐ P ☐ F | 5 × 10 MB |
| A20 | Reply, Reply all, Forward pre-fill correctly | ☐ P ☐ F | |
| A21 | Reply-as uses alias message was received on | ☐ P ☐ F | `AliasService` |
| A22 | Draft save and resume | ☐ P ☐ F | |

### Organize

| # | Item | Result | Notes |
|---|------|:------:|-------|
| A23 | Move to folder (single and bulk) | ☐ P ☐ F | |
| A24 | Delete moves to Trash | ☐ P ☐ F | |
| A25 | Spam action moves to Spam folder | ☐ P ☐ F | Read pane; not bulk in command bar |
| A26 | Manual move between employee folders | ☐ P ☐ F | ACL enforced |

### Account & settings

| # | Item | Result | Notes |
|---|------|:------:|-------|
| A27 | Login / logout | ☐ P ☐ F | |
| A28 | Change password | ☐ P ☐ F | |
| A29 | Theme light/dark/auto | ☐ P ☐ F | |
| A30 | Session timeout acceptable | ☐ P ☐ F | Default 8 h |

**Appendix A totals:** ___ / 30 Pass · ___ Fail · ___ Partial (document in Notes)

---

## Appendix B — Custom module checklist

| # | Item | Result | Notes |
|---|------|:------:|-------|
| B1 | Alias → employee folder routing after filter | ☐ P ☐ F | |
| B2 | Client address routing per admin rules | ☐ P ☐ F | |
| B3 | Spam rules run first | ☐ P ☐ F | Rule order in DB |
| B4 | Filter timing documented for users | ☐ P ☐ F | `USER-GUIDE.md` + `KNOWN-LIMITATIONS.md` |
| B5 | Admin add user → folder + alias + rule | ☐ P ☐ F | |
| B6 | Admin CRUD rules without deploy | ☐ P ☐ F | |
| B7 | Admin Sync now / Reprocess Inbox | ☐ P ☐ F | |
| B8 | Reply from filtered mail uses correct alias | ☐ P ☐ F | |
| B9 | Migration PHP + SQL only (no cron) | ☐ P ☐ F | Run `migrations-mail-cache.sql` on prod |

**Appendix B totals:** ___ / 9 Pass · ___ Fail

---

## Production infrastructure checks

| Check | Result | Notes |
|-------|:------:|-------|
| `mail_index`, `mail_bodies`, `mail_sync_state` tables exist | ☐ P ☐ F | `database/migrations-mail-cache.sql` |
| `.env` mail credentials correct after migration | ☐ P ☐ F | |
| `APP_DEBUG=false` on production | ☐ P ☐ F | |
| HTTPS enabled | ☐ P ☐ F | |
| Test employee account has linked folder | ☐ P ☐ F | Avoid misconfigured onboarding |

---

## Developer sign-off

| Criterion | Met |
|-----------|:---:|
| Appendix A — 100% pass (or partials documented in KNOWN-LIMITATIONS) | ☐ |
| Appendix B — 100% pass | ☐ |
| Zero open P0/P1 defects | ☐ |
| Demo video recorded and link ready | ☐ |
| Readiness packet prepared for client | ☐ |

**Signed:** ________________________ **Date:** ____________

---

*Related: [`CLIENT-READINESS-PACKET.md`](CLIENT-READINESS-PACKET.md) · [`CLIENT-UAT-SCRIPT.md`](CLIENT-UAT-SCRIPT.md)*
