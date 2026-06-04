# CoreFlux — Potential Improvements Backlog

Running log of suggested enhancements that surfaced during build
sessions but were deferred. Grouped by category so we can pick them up
in batches later.

Format per item:
- **What** — one-line summary
- **Why** — operator pain or value
- **Where** — file(s) / module to touch
- **Estimate** — rough time
- **Surfaced** — session date + context
- **Status** — `OPEN` / `IN PROGRESS` / `SHIPPED`

---

## UX / Performance

### LP-001 — Stale-while-revalidate cache for `useApi` (TimesheetsList snappiness)
- **What**: Wire a 30-second client-side cache (SWR-style) into
  `useApi` so revisiting a list page doesn't re-fetch from scratch.
- **Why**: TimesheetsList still does a full table refetch on every
  nav. Operators bouncing between list ↔ detail hit the loading
  flash every time. The detail page is now snappy after the
  optimistic merge work, so this is the next obvious win.
- **Where**: `dashboard/src/lib/api.js` (extend `useApi`); no
  per-page changes needed — every list page benefits.
- **Estimate**: ~25 min
- **Surfaced**: 2026-02 — after the TimesheetDetail optimistic
  merge session. User: "let's keep track of potential improvements
  by type and come back to them later. we'll add that timesheet
  list snappiness later."
- **Status**: OPEN

---

## Integrations

### LP-002 — QBO sandbox keys missing from production `config.local.php`
- **What**: Production `/app/core/config.local.php` has Plaid +
  Resend + OpenAI keys but no QBO ones, so `qboConfigured()` returns
  false and the OAuth flow short-circuits before redirecting to
  Intuit. Need to add `QBO_CLIENT_ID` / `QBO_CLIENT_SECRET` /
  `QBO_REDIRECT_URI` (and `QBO_ENV='sandbox'` for testing).
- **Why**: Without these, "Connect to QuickBooks" throws
  `"QBO is not configured on this pod"`. We've already shipped
  every downstream feature; the credentials are the only blocker.
- **Where**: `core/config.local.php` on the deploy host (or env
  vars: `QBO_CLIENT_ID`, `QBO_CLIENT_SECRET`, `QBO_REDIRECT_URI`,
  `QBO_ENV`, `QBO_SCOPES`). Reference template is now in
  `core/config.local.example.php` (2026-02 update).
- **Estimate**: ~10 min (after operator pastes their Intuit dev
  keys + registers the redirect URI in the Intuit dashboard).
- **Surfaced**: 2026-02 — user: "need to test QB in intuit
  developer sandbox".
- **Status**: OPEN (operator action — needs Intuit dev account)

---

## Reporting / AI

(none deferred yet)

---

## Schema / Backend

(none deferred yet)

---

## How to use this file

- When you suggest an improvement during a session, capture it here
  before moving on so it doesn't get lost.
- When a user says "come back to it later" → add the item, mark
  OPEN, mention the session it surfaced.
- When shipping, set status to SHIPPED + link the PRD session entry
  that captured the work.
