# Time Phase B Slice 1 — Tokenized Client-Approval Email (Resend)

First real outbound-mail use case. Lets a staffing operator click "Request
client approval" on selected pending-review timesheet entries in the Review
Queue; the client approver receives a one-tap magic link, clicks Approve or
Reject, and those entries flip to `approved`/`rejected` with
`approved_via='tokenized_client_email'` — no CoreFlux login required.

## What's in this drop

- New `Core\Mail\ResendDriver` — PHP cURL implementation of `MailDriver`,
  supports idempotency keys and captures HTTP-level errors into
  `mail_outbox.error`
- New `core/mail_bootstrap.php` — registers `ResendDriver` as the default
  outbound driver when `RESEND_API_KEY` is set, installs the `mail_outbox`
  DB writer (idempotent, safe to require multiple times)
- New migration `modules/time/migrations/002_approval_tokens.sql` —
  `time_approval_tokens` table (`utf8mb4_unicode_ci`), SHA-256 hash of token
  stored alongside the raw token (hash-only compare on verify)
- New API `modules/time/api/approval_tokens.php` with 5 actions:
  - `POST ?action=issue` (authed, `time.tokenized_email.issue`)
  - `GET  ?action=verify&t=<raw>` (**public**, no auth)
  - `POST ?action=respond` (**public**, no auth — token IS the credential)
  - `POST ?action=revoke&id=N` (authed, `time.tokenized_email.revoke`)
  - `GET  ?placement_id=&period_id=` (authed list for the Review Queue)
- New lib `modules/time/lib/approval_tokens.php` — token gen, hash compare,
  HTML + text email body builder with per-day breakdown and approve/reject
  buttons
- New public landing page `/time_approve.php` at the site root —
  unauthenticated, renders the timesheet summary + Approve/Reject form
  (inline JS POSTs to the respond API). `<meta name="robots" content="noindex, nofollow">`
- Review Queue UI: per-row checkbox + sticky selection bar + "Request
  client approval" modal (`TokenIssueModal`). Selection is validated
  client-side to ensure all selected entries share the same
  `(placement_id, period_id)` before the modal opens
- Smoke tests: `tests/time_approval_tokens_smoke.php` — 53/53 ✓
  (migration, token gen, hash round-trip, email body, ResendDriver contract
  with injected transport, UI wiring)

## Required config before deploy

Add to your Cloudways Application Settings → Environment Variables (or
equivalent `.env`):

```
RESEND_API_KEY=re_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
RESEND_FROM_EMAIL=no-reply@yourdomain.com
RESEND_FROM_NAME=CoreFlux Notifications
APP_URL=https://www.corefluxapp.com
```

1. **Create a Resend account** — https://resend.com → API Keys → Create API Key
2. **Verify your sending domain** — Resend → Domains → add your apex
   domain, copy the SPF (TXT) + DKIM (CNAME x3) records into Cloudways /
   your DNS host. This is required before emails go to real inboxes.
   Until DNS is verified you can still use Resend's sandbox domain
   `onboarding@resend.dev` to send to the email you signed up with.
3. **Do NOT commit the key to git** — Cloudways env vars only. `git grep
   RESEND` after deploy should match only this playbook file and docs.

## Deploy steps

### 1. Push to GitHub
Use **Save to GitHub** in the chat input.

### 2. Run the migration on Cloudways
```bash
mysql -u <user> -p <db> < /path/to/coreflux/modules/time/migrations/002_approval_tokens.sql
```
Or via your `/update.php` runner. Idempotent.

### 3. Add the env vars
In Cloudways: Application → Application Settings → Environment Variables.
Restart PHP-FPM after saving.

### 4. Enable tokenized approval per-placement
Default is OFF. For any placement the client should approve this way:
- Open Placement Detail → Approval tab
- Set `client_approver_email` (must be a real inbox on the client side)
- Toggle `tokenized_email_approval_enabled = true`
- Save

### 5. Smoke test the flow
1. In Review Queue, tick two or more pending entries that share the
   same placement + period
2. The sticky bar shows "N selected" → click **Request client approval**
3. The modal shows placement + entry count + total hours + TTL days
4. Click **Send approval email**; the UI toast shows `email_status=sent`
   (or a warn-toast with the Resend HTTP error if DNS isn't set up)
5. Open the delivered email, click Approve or Reject
6. `/time_approve.php?t=...` renders the per-day summary; confirm the
   click — the form POSTs to `?action=respond` and the entries flip to
   `approved`/`rejected` with `approved_via='tokenized_client_email'`
7. Back in Review Queue + Period Close Wizard, the entries now count
   toward the bundle preview

### 6. Rollback
- Disable: clear `RESEND_API_KEY` env var → system falls back to
  `LogDriver` (writes to `/app/storage/_dev/mail_outbox.log`) and the
  `issue` endpoint still creates rows but email_status will be `failed`
  with a clear error message
- Schema: `DROP TABLE IF EXISTS time_approval_tokens;`

## Security notes

- Token format enforced with regex `/^[a-f0-9]{64}$/` on both the API and
  the public page — malformed tokens get 400 before any DB lookup
- `token_hash` is a raw 32-byte SHA-256; we only compare hashes server-side
- Expiry is lazy-enforced on both `verify` and `respond` (flips row to
  `expired` on first access after TTL)
- One-time use: `respond` flips `response` from `pending` in a single
  atomic UPDATE; second click gets `409 Token already used`
- Public page has `noindex, nofollow`
- Rate limit (not in this drop; P2 backlog): add IP-based throttling on
  `?action=respond` and `?action=verify`

## Phase B — what's still deferred

- **Slice 2 — AI inbox parsing (M365 Graph driver)**: real inbox polling +
  OpenAI-parsed draft entries → pending_review. Requires Azure AD client
  secret for app `d5d81312-faf4-47ba-a001-d9a090415baa`
- **Slice 3 — Gmail driver**: same as Slice 2 for Google Workspace tenants
- **Period Close Receipt PDF** (P1 backlog) — now unblocked since Resend
  is wired; can email receipt on period close with a PDF attachment
