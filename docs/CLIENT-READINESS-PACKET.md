# D&J Webmail — Client Readiness Packet

**Send this package to the client when requesting structured UAT** — not for open-ended exploratory testing.

**Prepared:** 2026-06-17 · **Phase:** Modern Webmail UX (Milestones U1–U7)

---

## Message to client

> The webmail upgrade is ready for **structured user acceptance testing**.
>
> Please use the attached **UAT script** (~30 minutes, 15 steps). Known limitations are documented upfront so there are no surprises about intentional design choices (filter-on-access, no conversation threading, etc.).
>
> We recommend one **admin** and one **employee** tester. Report any **Fail** steps with screenshot, browser, and step number.

---

## Contents of this packet

| # | Document | Purpose |
|---|----------|---------|
| 1 | [`USER-GUIDE.md`](USER-GUIDE.md) | End-user guide — 3-pane layout, filter timing, daily workflows |
| 2 | [`CLIENT-UAT-SCRIPT.md`](CLIENT-UAT-SCRIPT.md) | 15-step acceptance test (~30 min) |
| 3 | [`KNOWN-LIMITATIONS.md`](KNOWN-LIMITATIONS.md) | Honest trade-offs (filter-on-access, no cron, etc.) |
| 4 | Demo video | See below |
| 5 | Test accounts | See below |
| 6 | [`QA-SIGNOFF.md`](QA-SIGNOFF.md) | Developer internal sign-off (for your records) |

---

## Demo video

**Target length:** 5–10 minutes  
**Recording script:** [`DEMO-SCRIPT.md`](DEMO-SCRIPT.md)

| Item | Value |
|------|-------|
| **Video link** | _Paste URL after recording (Loom, Drive, etc.)_ |
| **Recorded by** | _Name_ |
| **Date** | _Date_ |

**Suggested scenes:** login → three-pane browse → command bar delete → reply with alias → compose slide-over → mobile full read → admin filter sync.

---

## Test accounts

Provide credentials securely (not in this public doc). Suggested setup:

| Account | Role | Purpose |
|---------|------|---------|
| `uat-admin@…` or admin user | Admin | Full folders, filter verification, admin panel |
| `uat-employee@…` or employee user | Employee | Personal folder, alias reply, mobile flow |

**Pre-flight for admin:**

- [ ] Employee user has **linked folder** + **alias** in admin panel
- [ ] At least one **filter rule** active for UAT mail routing test (Step 12)
- [ ] Mail cache migration applied on production (`database/migrations-mail-cache.sql`)

---

## What was delivered (UX phase summary)

| Milestone | Delivered |
|-----------|-----------|
| **U1** | UX spec + gap analysis |
| **U2** | Three-pane shell + AJAX reading pane |
| **U3** | Command bar + Outlook-style message list |
| **U4** | Compose slide-over + organize from pane |
| **U5** | Outlook-like visual design system |
| **U6** | Performance (MySQL cache), accessibility, mobile polish |
| **U7** | QA docs + client readiness packet |

**Unchanged custom features:** PHP filter engine, admin rules, employee folders, reply-as-alias, portable PHP+SQL deploy.

---

## UAT scheduling

| Field | Value |
|-------|-------|
| **Suggested duration** | 30–45 minutes (15 steps + questions) |
| **Participants** | 1 admin + 1 employee (or proxy) |
| **Environment** | Production URL: ________________________ |
| **UAT window** | ________________________ |
| **Feedback to** | ________________________ |

---

## Acceptance criteria

UAT is **accepted** when:

1. All **critical** steps (1–12) pass or fail only on documented limitations.
2. No unresolved **P0/P1** defects (data loss, cannot send/receive, security, wrong alias routing).
3. Client sign-off on [`CLIENT-UAT-SCRIPT.md`](CLIENT-UAT-SCRIPT.md) sign-off section.

**P2 items** (cosmetic, nice-to-have) may be deferred to a future phase by mutual agreement.

---

## Explicit scope boundary

**Ready for structured UAT — not full exploratory testing.**

Items **out of scope** for this UAT (documented in KNOWN-LIMITATIONS):

- Gmail-style threading
- Contacts / autocomplete
- Real-time push notifications
- Drag-and-drop move
- Cron-based background filtering

---

## Support during UAT

| Issue type | Action |
|------------|--------|
| Step fails unexpectedly | Screenshot + step # → developer |
| “Is this a bug?” vs limitation | Check KNOWN-LIMITATIONS first |
| New feature request | Log for Phase 3 discussion |

---

*Internal reference: [`MODERN-WEBMAIL-MILESTONES.md`](MODERN-WEBMAIL-MILESTONES.md) · [`ROADMAP.md`](../ROADMAP.md)*
