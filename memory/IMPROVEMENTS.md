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

### LP-002 — QBO sandbox keys [SHIPPED 2026-02]
- **What**: Added `QBO_CLIENT_ID` / `QBO_CLIENT_SECRET` /
  `QBO_REDIRECT_URI` / `QBO_ENV` / `QBO_SCOPES` to
  `/app/core/config.local.php` so `qboConfigured()` returns true.
  Sandbox keys (Development) from Intuit dashboard pasted in;
  redirect URI: `https://www.corefluxapp.com/api/qbo.php?action=oauth_callback`.
- **Verification**: `qbo_config_check_smoke.php` 25/25 ✓.
  `GET /api/qbo.php?action=config_check` now returns the
  configured state (booleans + redirect_uri + last-4 of client id)
  so operators can verify on the live pod without shelling in.
- **Status**: SHIPPED — operator should now hit the connect flow
  from Admin → QBO. Rotate the secret after the chat session in
  which it was pasted; replace in `config.local.php`.
- **Follow-up**: pasted client_secret needs rotation post-session
  (per the security note in chat).

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
