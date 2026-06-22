# Webmail UX — Specification (U1 Deliverable)

**Companion to:** [`WEBMAIL-UX-GAP-ANALYSIS.md`](WEBMAIL-UX-GAP-ANALYSIS.md)  
**Implements milestones:** U2–U5  
**Desktop breakpoint:** `min-width: 1024px` (3-pane)  
**Mobile / tablet:** `max-width: 1023px` (single-pane stack)

> **Note:** Current CSS uses **899px** for sidebar drawer. U2 will align mail workspace to **1024px** for reading pane; sidebar drawer can stay at 899px until U6 consolidates breakpoints.

---

## 1. Target layouts

### 1.1 Desktop (≥ 1024px) — three pane

```text
┌──────────────────────────────────────────────────────────────────────────┐
│ APP BAR  [≡]  D&J Webmail     [ 🔍 Search mail................... ]  👤 │
├─────────────┬────────────────────────────┬───────────────────────────────┤
│ SIDEBAR     │ LIST COLUMN              │ READING PANE                  │
│             │ ┌────────────────────────┐│ ┌───────────────────────────┐ │
│ [+ New mail]│ │ New ▾ Delete Move …   ││ │ Reply  Reply all  Forward │ │
│             │ └────────────────────────┘│ │ Delete  Move ▾  Flag      │ │
│ ▼ Inbox     │ ☐ ● JD  Subject line…  ││ ├───────────────────────────┤ │
│   Inbox (3) │ ☐   Ankesh  Re: Appt…  ││ │ From: …                   │ │
│ ▼ Folders   │ ☑ ● Client  Invoice…   ││ │ Subject: …                │ │
│   User (2)  │ ☐   Support  Welcome…  ││ │                           │ │
│   Client A  │                          │ │ (message body)            │ │
│ Sent        │ « Page 1 of 5 »         │ │                           │ │
│ Drafts      │                          │ └───────────────────────────┘ │
│ Trash       │                          │                               │
└─────────────┴────────────────────────────┴───────────────────────────────┘
     220px              min 340px                    flex 1 (min 360px)
```

**Column CSS (draft):**

```css
.mail-workspace {
    display: grid;
    grid-template-columns: minmax(340px, 42%) minmax(360px, 1fr);
    height: calc(100vh - var(--header-height));
    overflow: hidden;
}
/* Sidebar remains in .app-shell first column; main-content holds .mail-workspace */
```

### 1.2 Tablet / mobile (< 1024px) — single pane stack

```text
Step 1: [Folder drawer]  →  Step 2: [Message list]  →  Step 3: [Full read page]
```

- Reuse existing `.mail-list-mobile` cards below 1024px.
- Row tap → **full navigation** to `/folder/.../message/{uid}` (current behavior).
- Compose → **full page** (current `compose.php`).
- No reading pane on narrow viewports.

### 1.3 Empty states

| State | List column | Reading pane |
|-------|-------------|--------------|
| Folder open, no selection | Show messages | “Select a message to read” |
| Folder empty | Empty state (existing) | Hidden or same placeholder |
| Message deleted | Row removed | Pane cleared + placeholder |
| IMAP error | Inline error | Hidden |

---

## 2. Reading pane modes

| Mode | When | Behavior |
|------|------|----------|
| **`side`** (default desktop) | ≥1024px, on `/folder/{path}` | Pane visible; AJAX load message |
| **`full`** | Direct URL `/folder/.../message/{uid}` | Full page `read.php` (print, share link, no-JS) |
| **`off`** | Optional user pref (U6+) | List only; click opens full page |

### 2.1 Desktop click behavior

1. User clicks row → `preventDefault` on navigation.
2. `GET /folder/{b64}/message/{uid}/pane` (new) or extended `messageSync` returns JSON:
   ```json
   {
     "ok": true,
     "uid": 123,
     "subject": "...",
     "html": "<div class=\"pane-read\">...</div>",
     "seen": true
   }
   ```
3. Inject into `#reading-pane-body`.
4. `history.pushState({ uid, folder }, '', messageUrl)`.
5. Mark row active (`.is-selected`); mark read via existing endpoint or inline in pane action.

### 2.2 Popstate (Back / Forward)

- `popstate` listener: if URL matches message pattern → load pane; if folder only → clear pane.

### 2.3 Keyboard (desktop, U2 + U6)

| Key | Action |
|-----|--------|
| `j` / `k` | Move focus; **load pane** for focused row |
| `Enter` | Open focused message in pane |
| `Escape` | Clear selection / close pane (optional) |
| `r` / `a` | Reply / reply-all — open compose panel with context |
| `e` | Delete selected or pane message |

---

## 3. Compose modes

| Mode | Viewport | Behavior |
|------|----------|----------|
| **Slide-over** | ≥1024px | Panel overlays reading pane (right 480–560px) |
| **Full page** | <1024px | Current `/compose` routes |

### 3.1 Slide-over structure

```text
┌ reading pane ────────────────┬ compose panel ──┐
│ (dimmed or hidden behind)      │ Send as [▼]     │
│                                │ To  [chips]     │
│                                │ Subject         │
│                                │ Body            │
│                                │ [Send] [Draft]  │
└────────────────────────────────┴─────────────────┘
```

- Load via `GET /compose?embed=1&mode=reply&folder=...&uid=...`
- Returns partial HTML without `layout.php` chrome.
- POST to existing `/compose/send` and `/compose/draft` (AJAX + JSON response optional in U4).

---

## 4. Design tokens (draft for U5)

Apply in `app.css` `:root` — **do not implement in U1**; reference only.

### 4.1 Color

```css
:root {
    /* Brand */
    --color-primary: #0078d4;
    --color-primary-hover: #106ebe;
    --color-primary-pressed: #005a9e;
    --color-primary-light: #eff6fc;

    /* Neutrals (Fluent gray) */
    --color-bg: #faf9f8;
    --color-surface: #ffffff;
    --color-surface-alt: #f3f2f1;
    --color-border: #edebe9;
    --color-border-strong: #d2d0ce;
    --color-text: #323130;
    --color-text-secondary: #605e5c;
    --color-text-muted: #a19f9d;

    /* Semantic */
    --color-danger: #d13438;
    --color-success: #107c10;
    --color-unread-accent: #0078d4;

    /* Layout */
    --sidebar-width: 220px;
    --header-height: 48px;
    --list-row-height: 40px;
    --pane-min-width: 360px;

    /* Typography */
    --font: "Segoe UI", -apple-system, BlinkMacSystemFont, system-ui, sans-serif;
    --font-size-base: 14px;
    --font-size-list: 13px;

    /* Shape */
    --radius: 4px;
    --radius-sm: 2px;
}
```

### 4.2 Dark theme (outline)

```css
[data-theme="dark"] {
    --color-bg: #1b1a19;
    --color-surface: #252423;
    --color-border: #3b3a39;
    --color-text: #ffffff;
    --color-text-secondary: #d2d0ce;
}
```

### 4.3 Message list row (U3)

```text
[☐] [avatar] From Name          Attachment Flag    10:42 AM
             Subject line preview text truncated…
```

- Unread: `--color-unread-accent` 3px left border + semibold from/subject
- Selected: `--color-primary-light` background
- Hover: `--color-surface-alt`

### 4.4 App bar (U5)

- Height 48px, white/dark surface, bottom border only (no heavy shadow)
- Search: centered or left-of-center, rounded pill input, 320–480px wide
- Remove role badge from header on mail pages (move to settings) — optional polish

---

## 5. API & endpoints to reuse

### 5.1 Keep unchanged (POST)

| Endpoint | Purpose |
|----------|---------|
| `POST /message/trash` | Delete |
| `POST /message/move` | Move single |
| `POST /message/bulk-*` | Bulk actions |
| `POST /message/mark-read` | Mark read |
| `POST /message/mark-unread` | Mark unread |
| `POST /message/flag` / `unflag` | Flag |
| `POST /message/spam` | Spam |
| `POST /compose/send` | Send |
| `POST /compose/draft` | Save draft |

All support AJAX via `X-Requested-With` + JSON responses (already used in `app.js`).

### 5.2 Extend (GET)

| Endpoint | Today | U2 change |
|----------|-------|-----------|
| `GET /folder/{b64}/sync` | List JSON | No change |
| `GET /folder/{b64}/message/{uid}/sync` | `{ exists: bool }` | Add `?pane=1` → full pane payload **or** new `/pane` route |
| `GET /folders/unread` | Badge counts | No change |

**Recommended new route (cleaner):**

```
GET /folder/{folderB64}/message/{uid}/pane
→ JSON { subject, from, to, cc, date, body_html, attachments[], reply_from, actions{} }
```

Implement in `MailController::messagePane()`; reuse `getMessageByUid()` + `pane-read.php` partial.

### 5.3 New compose embed route (U4)

```
GET /compose?embed=1
GET /compose/reply?embed=1&folder=...&uid=...
```

`ComposeController` detects `embed` → render without layout, set `X-Frame-Options` same-origin.

---

## 6. File touch list by milestone

### U2 — Three-pane shell

| File | Change |
|------|--------|
| `views/mail/list.php` | Add `#reading-pane`, `.mail-workspace` wrapper |
| `views/mail/pane-read.php` | **New** — pane fragment |
| `views/layout.php` | Optional: app-bar search placeholder |
| `src/Controllers/MailController.php` | `messagePane()` method |
| `public/index.php` | Register `/folder/{b64}/message/{uid}/pane` |
| `public/assets/js/app.js` | `openMessageInPane()`, `popstate`, desktop click intercept |
| `public/assets/css/app.css` | `.mail-workspace`, `.reading-pane`, `.is-selected` row |

### U3 — Command bar + list rows

| File | Change |
|------|--------|
| `views/partials/mail-toolbar.php` | **New** |
| `views/mail/list.php` | Include toolbar; row template update |
| `src/Services/ImapService.php` | Optional: expose has-attachment in overview |
| `public/assets/js/app.js` | Toolbar wiring, refresh button |
| `public/assets/css/app.css` | Row density, avatar column |

### U4 — Compose slide-over

| File | Change |
|------|--------|
| `views/partials/compose-panel.php` | **New** |
| `views/mail/compose.php` | Split embed vs full layout |
| `src/Controllers/ComposeController.php` | `embed` mode, JSON send response |
| `public/assets/js/app.js` | `openComposePanel()`, pane reply hooks |
| `public/assets/css/app.css` | `.compose-panel`, overlay |

### U5 — Visual design system

| File | Change |
|------|--------|
| `public/assets/css/app.css` | Tokens §4, flatten cards, app bar |
| `views/layout.php` | Header restructure, search placement |
| `views/partials/folder-sidebar.php` | Icon + density pass |
| `views/login.php` | Match new chrome |
| `views/mail/read.php` | Collapsible headers (full-page mode) |

### U6 — Polish (reference)

| File | Change |
|------|--------|
| `public/assets/js/app.js` | Skeleton loader, focus trap, breakpoint unify |
| `public/assets/css/app.css` | a11y, touch targets |
| `views/mail/pane-read.php` | ARIA live regions |

---

## 7. Reference screenshots checklist (manual)

When implementing U2–U5, compare side-by-side with:

1. Outlook Web — logged in, Inbox, reading pane right
2. Outlook Web — message list density + command bar
3. Outlook Web — compose pop-out panel
4. Mac Mail — three-column layout
5. Mac Mail — unread bold + folder badges

Store reference captures in `docs/reference/` (optional, not committed if large).

---

## 8. Acceptance criteria summary

| Milestone | User-visible outcome |
|-----------|---------------------|
| U2 | Click message → reads on right; list stays |
| U3 | Delete/move from toolbar; rows look like Outlook |
| U4 | Compose/reply without leaving inbox |
| U5 | Overall look matches Outlook/Mac Mail expectations |
| U6 | Fast, accessible, mobile stack works |
| U7 | Client gets UAT script + demo video |

---

## Related

- [`WEBMAIL-UX-GAP-ANALYSIS.md`](WEBMAIL-UX-GAP-ANALYSIS.md)
- [`MODERN-WEBMAIL-MILESTONES.md`](MODERN-WEBMAIL-MILESTONES.md)
