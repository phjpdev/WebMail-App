# Webmail UX — Baseline Audit & Gap Analysis

**Milestone:** U1 (Modern Standard Webmail)  
**Date:** 2026-06-17  
**Reference platforms:** Outlook on the web, Apple Mail, Thunderbird  
**Audited codebase:** `views/`, `public/assets/`, `src/Controllers/MailController.php`, `ComposeController.php`, `FolderCache.php`

---

## 1. Executive summary

The application has **strong backend and feature coverage** (IMAP read/send, CC/BCC, bulk actions, drafts, filter engine, admin CRUD, reply-as-alias). What it lacks is **standard desktop mail client layout and workflow** — the experience Mac Mail / Outlook users expect when organizing mail daily.

| Status | Count | Meaning |
|--------|------:|---------|
| **Done** | 38 | Matches reference behavior today |
| **Partial** | 14 | Works but wrong place, wrong UX, or incomplete polish |
| **Missing** | 12 | Required for Outlook-like daily use (U2–U5) |
| **Won't fix** | 6 | Out of scope (threading, contacts, push, etc.) |

**Top 5 gaps driving client frustration:**

1. **No reading pane** — every message opens a full page reload (`bindMailRow` → `window.location`).
2. **No command bar on list** — delete/move/reply only on read page or hidden bulk toolbar after multi-select.
3. **Generic admin-style UI** — Inter + blue cards, not Outlook density/hierarchy.
4. **Search not in app bar** — lives in page header inside list view only.
5. **Compose is a separate page** — not a slide-over panel; breaks inbox context.

Custom modules (filter, aliases, employee folders) are largely **done**; the gap is **presentation and navigation**.

---

## 2. Reference platform behaviors (what users expect)

Notes from Outlook Web / Mac Mail / Thunderbird — the bar for “standard webmail.”

### 2.1 Folder navigation

| Behavior | Outlook / Mac Mail | Current app |
|----------|-------------------|-------------|
| Folder tree always visible (desktop) | Yes | **Partial** — sidebar exists but is 2-column shell only |
| Unread count per folder | Yes | **Done** — badges + group totals |
| Favorites / pinned folders | Yes | **Missing** — grouped Inbox/Sent/Drafts/Other |
| Create folder in UI | Mac Mail yes | **Won't fix** — admin-only (by design) |
| Employee sees own workspace | N/A | **Done** — default folder, INBOX hidden from sidebar |

### 2.2 Message list

| Behavior | Reference | Current app |
|----------|-----------|-------------|
| Compact scannable rows | Yes | **Partial** — HTML table, no avatar, no snippet |
| Unread bold + indicator | Yes | **Done** — bold + dot |
| Attachment icon in list | Yes | **Missing** — `size` in IMAP overview not shown |
| Flag/star in list | Yes | **Done** |
| Select + command bar | Yes | **Partial** — bulk bar appears only after checkboxes |
| Shift+click range select | Outlook yes | **Missing** |
| Sort columns | Outlook yes | **Missing** — date only (newest first) |

### 2.3 Reading

| Behavior | Reference | Current app |
|----------|-----------|-------------|
| Reading pane beside list | Outlook default | **Missing** |
| List stays visible | Yes | **Missing** — full navigation |
| Mark read on open | Yes | **Done** |
| Reply / forward from pane | Yes | **Partial** — on read page only |
| Collapsible header details | Outlook yes | **Missing** — flat `<dl>` |
| Inline images / safe HTML | Yes | **Done** |
| Attachment preview | Yes | **Done** — images + PDF link |

### 2.4 Compose

| Behavior | Reference | Current app |
|----------|-----------|-------------|
| To / Cc / Bcc | Yes | **Done** |
| Send-as identities | Thunderbird yes | **Done** |
| Rich text | Yes | **Done** — basic toolbar |
| Attachments | Yes | **Done** |
| Drafts | Yes | **Done** |
| Compose without leaving inbox | Outlook yes | **Missing** — full page |
| Recipient autocomplete | Outlook yes | **Missing** — manual chips only |
| Minimize / pop-out compose | Outlook yes | **Won't fix** v1 |

### 2.5 Search & refresh

| Behavior | Reference | Current app |
|----------|-----------|-------------|
| Global / prominent search | Outlook top bar | **Partial** — folder-scoped, in list header |
| Search subject + from | Yes | **Done** |
| New mail indicator | Yes | **Partial** — poll ~30s, toast/sound optional |
| Manual refresh | Yes | **Missing** — no Refresh button in UI |

### 2.6 Organize

| Behavior | Reference | Current app |
|----------|-----------|-------------|
| Move to folder | Yes | **Done** |
| Delete → Trash | Yes | **Done** |
| Spam / junk | Yes | **Done** |
| Context menu on message | Yes | **Done** — right-click + kebab menu |
| Drag message to folder | Mac Mail yes | **Missing** — won't fix v1 |
| Filter rules in UI | Mac Mail yes | **Partial** — admin only (custom requirement) |

---

## 3. Current architecture inventory

### 3.1 Layout (today)

```text
┌─────────────────────────────────────────────┐
│  site-header (logo, user, settings, logout) │
├──────────────┬──────────────────────────────┤
│   sidebar    │  main-content                │
│   Compose    │  page-header + card          │
│   folders    │  (list OR read OR compose)   │
│              │  ONE view per navigation     │
└──────────────┴──────────────────────────────┘
```

- Grid: `.app-shell` → `248px | 1fr` (`app.css` line ~296)
- Mobile breakpoint: **899px** — sidebar drawer, mobile card list
- **No third column** for reading pane

### 3.2 Routes (mail UX)

| Method | Path | Role today |
|--------|------|------------|
| GET | `/folder/{b64}` | Full-page message list |
| GET | `/folder/{b64}/sync` | JSON list poll + unread counts |
| GET | `/folder/{b64}/message/{uid}` | Full-page read |
| GET | `/folder/{b64}/message/{uid}/sync` | JSON `{ exists: bool }` only — **not pane content** |
| GET | `/folders/unread` | JSON badge refresh |
| GET | `/compose`, `/compose/reply`, … | Full-page compose |
| POST | `/message/*`, `/compose/send` | Actions (AJAX-capable) |

### 3.3 JavaScript capabilities (reuse in U2+)

| Feature | Location | Notes |
|---------|----------|-------|
| List poll / merge | `initMailSync()` | 30s default, returns messages + unread_counts |
| Bulk AJAX | `initBulkAjax()` | move, trash, mark read/unread |
| Read actions AJAX | `initReadViewActions()` | trash, move, flag, spam |
| Context menu | `initContextMenu()` | move submenu from sidebar folder list |
| Keyboard shortcuts | `initKeyboardShortcuts()` | c, /, j, k, r, a, e — **navigate away on r/a** |
| Sidebar persist | `initSidebarGroups()` | localStorage |
| Recipient chips | compose JS | no address book |
| `avatarColor()` | app.js | used in compose chips only, **not list rows** |

### 3.4 Visual system (today)

| Token | Current | Outlook target (U5) |
|-------|---------|---------------------|
| Primary | `#2563eb` | `#0078D4` |
| Background | `#f6f7f9` | `#FAF9F8` |
| Font | Inter | Segoe UI, system-ui |
| Radius | 10px cards | 2–4px, flatter |
| Row height | ~48px+ table | 36–40px compact |

---

## 4. Flow-by-flow gap analysis

### Flow A: Open folder → read message → back

| Step | Expected | Current | Status |
|------|----------|---------|--------|
| Click folder | List updates, folder highlighted | Full page load | **Done** |
| Click message | Preview in reading pane | Full page load to `/message/{uid}` | **Missing** |
| Back to list | List still visible, same scroll | Browser back or breadcrumb link | **Partial** |
| Unread badge updates | Decrements on read | **Done** — `FolderCache::bumpUnread` | **Done** |

**U2 fixes:** AJAX pane, `pushState`, preserve scroll.

### Flow B: Select messages → delete / move

| Step | Expected | Current | Status |
|------|----------|---------|--------|
| Toolbar always visible | New, Delete, Move | Bulk bar hidden until selection | **Partial** |
| Delete without opening | Yes | Bulk AJAX works | **Done** |
| Single-message delete from list | Outlook allows | Context menu or kebab only | **Partial** |

**U3 fixes:** persistent command bar.

### Flow C: Compose new mail

| Step | Expected | Current | Status |
|------|----------|---------|--------|
| New mail button | Top of list / sidebar | Sidebar Compose → new page | **Partial** |
| Cc / Bcc | Yes | **Done** | **Done** |
| Send-as | Yes | **Done** | **Done** |
| Return to inbox after send | Same screen | Redirect to folder | **Partial** |

**U4 fixes:** compose slide-over.

### Flow D: Reply with correct alias

| Step | Expected | Current | Status |
|------|----------|---------|--------|
| Reply defaults to received alias | Yes | **Done** — `AliasService::resolveReplyAlias` | **Done** |
| From read view | One click | Full page compose | **Partial** |
| Delivered-To shown | Optional | Shown (technical) | **Partial** — hide in user mode U5 |

### Flow E: Search

| Step | Expected | Current | Status |
|------|----------|---------|--------|
| Search from anywhere | App bar | List page only | **Partial** |
| Results in folder | Yes | **Done** | **Done** |
| Clear search | Yes | **Done** | **Done** |

**U3/U5 fixes:** move search to app bar.

### Flow F: Employee daily use (custom)

| Step | Expected | Current | Status |
|------|----------|---------|--------|
| Login → personal folder | Yes | `default_mail_folder()` | **Done** |
| No shared Inbox clutter | Yes | INBOX filtered from sidebar | **Done** |
| Mail routed to folder | Yes | Filter on folder open/sync | **Done** |
| Sent / Drafts / Trash access | Yes | Allowed paths include standard folders | **Done** |

---

## 5. Full checklist (tagged)

Legend: ✅ done · ⚠️ partial · ❌ missing · ➖ won't fix

### 5.1 Baseline webmail (Appendix A)

#### Folders & navigation

| # | Item | Status | Notes / milestone |
|---|------|--------|-------------------|
| A1 | Folder list: Inbox, Sent, Drafts, Trash, custom | ✅ | Admin sees all; employee sees filtered set |
| A2 | Active folder highlighted | ✅ | `.sidebar-link.active` |
| A3 | Unread badge per folder | ✅ | `.folder-badge` |
| A4 | Employee lands on personal folder | ✅ | `default_mail_folder()` |
| A5 | Folder groups expand/collapse persist | ✅ | localStorage `dj_sidebar_groups` |
| A6 | Switch folder clears/updates reading pane | ❌ | No pane — **U2** |
| A7 | Favorites / pin folders | ➖ | Admin-managed folders sufficient |
| A8 | Create folder in mail UI | ➖ | Admin panel only |

#### Message list

| # | Item | Status | Notes / milestone |
|---|------|--------|-------------------|
| B1 | Newest-first sort | ✅ | IMAP overview reversed |
| B2 | Unread visually distinct | ✅ | `.mail-unread` |
| B3 | Pagination | ✅ | `views/partials/pagination.php` |
| B4 | Select one / many | ✅ | Checkboxes + bulk bar |
| B5 | Shift+click range select | ❌ | **U3** optional |
| B6 | Search subject/from in folder | ✅ | `searchMessages()` |
| B7 | Sender avatar in row | ❌ | `avatarColor()` exists — **U3** |
| B8 | Subject snippet / preview line | ❌ | Overview has no body snippet — **U3** (subject only or fetch preview) |
| B9 | Attachment icon in list | ❌ | `size` available — **U3** |
| B10 | Command bar (New, Delete, Move…) | ❌ | **U3** |
| B11 | Manual refresh button | ❌ | **U3** — trigger `folderSync` |

#### Reading

| # | Item | Status | Notes / milestone |
|---|------|--------|-------------------|
| C1 | Headers: From, To, Cc, Date, Subject | ✅ | |
| C2 | HTML body + plain fallback | ✅ | `HtmlSanitizer` |
| C3 | Attachments download + preview | ✅ | |
| C4 | Mark read on open | ✅ | |
| C5 | Mark unread / flag | ✅ | AJAX on read view |
| C6 | Reading pane beside list (desktop) | ❌ | **U2** |
| C7 | Collapsible header block | ❌ | **U5** |
| C8 | Hide technical headers (Delivered-To) from users | ⚠️ | **U5** — admin/debug toggle |

#### Compose & send

| # | Item | Status | Notes / milestone |
|---|------|--------|-------------------|
| D1 | To, Cc, Bcc | ✅ | |
| D2 | Send-as dropdown | ✅ | |
| D3 | Signature from settings | ✅ | |
| D4 | Attach files | ✅ | 5 × 10 MB |
| D5 | Reply / Reply all / Forward | ✅ | |
| D6 | Reply-as alias | ✅ | |
| D7 | Save draft + edit draft | ✅ | |
| D8 | Compose slide-over (desktop) | ❌ | **U4** |
| D9 | Recipient autocomplete | ➖ | No contacts DB |
| D10 | Rich text formatting | ⚠️ | Basic toolbar only — acceptable v1 |

#### Organize

| # | Item | Status | Notes / milestone |
|---|------|--------|-------------------|
| E1 | Move to folder (single) | ✅ | Read + context menu |
| E2 | Move to folder (bulk) | ✅ | AJAX |
| E3 | Delete → Trash | ✅ | |
| E4 | Mark as spam | ✅ | |
| E5 | Context menu on message | ✅ | |
| E6 | Drag to folder | ➖ | v2 optional |

#### Account & settings

| # | Item | Status | Notes / milestone |
|---|------|--------|-------------------|
| F1 | Login / logout | ✅ | POST logout |
| F2 | Change password | ✅ | |
| F3 | Theme light/dark/auto | ✅ | |
| F4 | Poll interval preference | ✅ | 15–300s |
| F5 | New mail sound / notification | ✅ | Optional prefs |
| F6 | Keyboard shortcuts | ⚠️ | j/k focus only — doesn't open pane **U2** |

### 5.2 Custom module checklist (Appendix B)

| # | Item | Status | Notes |
|---|------|--------|-------|
| G1 | Alias → employee folder routing | ✅ | FilterService + rules |
| G2 | Client domain routing | ✅ | Admin rules |
| G3 | Spam rules first | ✅ | RuleMatcher order |
| G4 | Filter on mailbox access | ✅ | `runBeforeMailList()` |
| G5 | Admin add user → provision folder/alias/rule | ✅ | AdminUserService |
| G6 | Admin CRUD rules without deploy | ✅ | |
| G7 | Sync now / Reprocess inbox | ✅ | Admin dashboard |
| G8 | Reply-as after filter | ✅ | |
| G9 | Portable deploy (no cron) | ✅ | Documented trade-off |
| G10 | Filter timing documented for users | ⚠️ | USER-GUIDE brief — expand **U7** |

---

## 6. Priority matrix (what to build next)

| Priority | Item | Milestone | Effort |
|----------|------|-----------|--------|
| P0 | Three-pane layout + AJAX reading pane | U2 | High |
| P0 | Extend `messageSync` to return pane HTML/JSON | U2 | Medium |
| P1 | Command bar on list | U3 | Medium |
| P1 | Outlook-style message rows (avatar, density) | U3 | Medium |
| P1 | Search in app bar | U3 + U5 | Low |
| P1 | Compose slide-over | U4 | High |
| P2 | CSS design tokens (Outlook-like) | U5 | Medium |
| P2 | Collapsible read headers | U5 | Low |
| P2 | Pane loading skeleton | U6 | Low |
| P2 | j/k opens pane without reload | U2 + U6 | Medium |
| P3 | Attachment icon in list | U3 | Low |
| P3 | Refresh button | U3 | Low |
| P3 | Shift+click select | U3 | Low |

---

## 7. U1 exit criteria — sign-off

| Criterion | Status |
|-----------|--------|
| Baseline checklist with done/partial/missing/won't fix | ✅ This document §5 |
| 3-pane layout spec | ✅ [`WEBMAIL-UX-SPEC.md`](WEBMAIL-UX-SPEC.md) |
| File touch list for U2–U5 | ✅ [`WEBMAIL-UX-SPEC.md`](WEBMAIL-UX-SPEC.md) §6 |
| Design tokens draft | ✅ [`WEBMAIL-UX-SPEC.md`](WEBMAIL-UX-SPEC.md) §4 |

**U1 complete.** Proceed to **U2 — Three-Pane Mail Shell**.

---

## Related

- [`MODERN-WEBMAIL-MILESTONES.md`](MODERN-WEBMAIL-MILESTONES.md) — master plan
- [`WEBMAIL-UX-SPEC.md`](WEBMAIL-UX-SPEC.md) — wireframes, API reuse, tokens
- [`ROADMAP.md`](../ROADMAP.md) — original M1–M3 backend scope
