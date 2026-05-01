# Billing Module — Cloudways Deploy Notes (Phase A0)

First subledger module. Closes the Time → revenue loop: closed time period
→ approved AR bundles → draft invoice → approve → send → customer click-through
→ payment → AR aging shrinks. End-to-end in <60 seconds.

## What's in this drop

- **Schema** (`modules/billing/migrations/001_init.sql`):
  - 5 new tables: `billing_invoices`, `billing_invoice_lines`,
    `billing_payments`, `billing_payment_allocations`,
    `billing_invoice_tokens` (all `utf8mb4_unicode_ci`)
  - 5 idempotent `ALTER TABLE tenants ADD COLUMN` (information_schema-guarded):
    `billing_tax_rate_pct`, `billing_invoice_prefix`,
    `billing_next_invoice_seq`, `billing_invoice_terms`,
    `billing_payment_instructions`
  - FK cascades on lines / allocations / tokens — voiding an invoice is
    safe; full delete cascades cleanly if ever needed
- **Library** (`modules/billing/lib/billing.php`): pure functions
  `billingNextInvoiceNumber`, `billingBuildDraftFromBundle`,
  `billingComputeTax`, `billingTransitionAllowed`,
  `billingIssueViewToken`, `billingTokenFindByRaw`,
  `billingAllocatePayment`, `billingComputeAging`, `billingAudit`
- **API** (3 endpoints, all SPEC §5):
  - `modules/billing/api/invoices.php` — list / detail / manual-create /
    `from-time-bundle` / PATCH-draft / approve / send / void
  - `modules/billing/api/payments.php` — list / record / `allocate`
    (manual or auto-FIFO)
  - `modules/billing/api/aging.php` — on-read aging buckets
- **Public viewer** (`/billing/invoice.php?t=<token>`): unauthenticated,
  `noindex,nofollow`, print-friendly CSS (Cmd/Ctrl+P → PDF), bumps
  view-counter on each load
- **React UI** (`modules/billing/ui/*`): BillingModule (router),
  InvoicesList (status filter chips, "New from time bundle"),
  InvoiceDetail (summary boxes, lines, allocations, approve/send/void),
  InvoiceFromTimeBundleModal (period picker → AR bundle preview →
  per-placement vs per-client aggregation → bulk-create), PaymentsList
  (record + allocate with FIFO), AgingTable
- **Manifest** (already existed; depends_on tightened to
  `['placements','time']`; `accounting` dropped until that module
  ships)
- **Smoke tests** — `tests/billing_spec_smoke.php` — 103 contract
  assertions ✓
- **All 13 platform smoke suites still green** (700+ total assertions)
- **Vite bundle rebuilt** — 1721 modules, 386kB JS

## Deploy steps

### 1. Push to GitHub
**Save to GitHub** in chat input.

### 2. Run the migration on Cloudways
```bash
mysql -u <user> -p <db> < /path/to/coreflux/modules/billing/migrations/001_init.sql
```
Idempotent — safe to re-run.

### 3. Configure tenant billing settings (one-time per tenant)

```sql
UPDATE tenants SET
  billing_invoice_prefix = 'INV',         -- e.g. 'ACME-INV'
  billing_next_invoice_seq = 1001,         -- if migrating from another system
  billing_tax_rate_pct = 0.00,             -- flat rate; jurisdiction matrix in A1
  billing_invoice_terms = 'NET30',         -- NET15, NET30, NET45, NET60 supported
  billing_payment_instructions = 'Wire to: ...\nACH routing: ...'
WHERE id = <tenant_id>;
```

### 4. Verify env vars (already set from earlier phases)
- `RESEND_API_KEY` — outbound invoice email
- `RESEND_FROM_EMAIL` / `RESEND_FROM_NAME` — platform sender
- `APP_URL` — used to build the public invoice link (defaults to corefluxapp.com)

If `RESEND_API_KEY` isn't set yet, the **Send** action still creates the
public-link token but the email send fails gracefully with a clear error
banner — you can copy the URL from the invoice detail page and paste it
into your own email until DNS is wired up.

### 5. End-to-end smoke walk (after deploy)

1. Log in as a tenant admin → **Billing** in the sidebar.
2. (Prerequisite) Have at least one **closed** time period with at least
   one approved entry on a placement that has bill rates set. The Time
   module's Period Close Wizard will have created `bundle_type='ar'`
   rows in `time_downstream_feed`.
3. Click **New from time bundle** → pick the closed period →
   the modal lists every placement with a ready AR bundle, pre-selected.
4. Choose **per_placement** (one invoice per placement) or
   **per_client** (one invoice per client, with multiple lines if a
   client has multiple placements). Click **Create N drafts**.
5. The list refreshes — you see new `draft` invoices with assigned
   numbers (e.g. `INV-2026-0001`).
6. Click into a draft → review lines, optionally edit
   `po_number` / `notes_external` via PATCH (UI is read-only in A0;
   editing API works). Click **Approve** — succeeds only if you're not
   the same user who created it (two-eye SPEC §9).
7. Click **Send** → enter customer email → submit. Email goes out
   immediately via Resend (with tenant's Reply-To from Phase 6b).
   Invoice flips to `sent`. Token row created — view count = 0.
8. Open the email you sent yourself → click the **View invoice**
   button → public page renders with print-friendly CSS. Hit
   **Print / Save as PDF** to confirm browser PDF rendering works.
   Refresh the invoice detail in CoreFlux — view count is now 1.
9. **Payments** tab → **Record payment** → enter the same client name +
   amount → check "Auto-allocate FIFO" → save. The matching invoice flips
   to `paid`. **Aging** tab → that client's row disappears (or shows
   reduced amounts).
10. **Void test (do this on a separate test invoice)**: void a draft →
    confirm the consumed `time_downstream_feed` rows flip back to
    `ready` (so you can reissue). Voiding an invoice that has payments
    allocated keeps the bundles `consumed` (audit trail intact).

## What's deferred to Phase A1 (next drop)

- Real server-side PDF rendering (via `dompdf/dompdf`) + S3 storage +
  email attachment (today: customer prints HTML to PDF in browser)
- Credit memos + debit memos (issue, link to invoice, allocate against)
- Tax jurisdictions/rates matrix (today: flat per-tenant rate)
- AR aging snapshot table + nightly cron (today: computed on-read)
- GL posting endpoint `POST /post` (waiting for Accounting v1.0 module)

## Phase B (per SPEC §13)

- Recurring service definitions + auto-generation
- Dunning schedules + automated reminders
- Statements of account
- AI anomaly flags ("invoice 3× normal")
- Stripe / ACH payment acceptance

## Rollback

- Schema is additive only. To roll back:
  ```sql
  DROP TABLE IF EXISTS billing_invoice_tokens;
  DROP TABLE IF EXISTS billing_payment_allocations;
  DROP TABLE IF EXISTS billing_payments;
  DROP TABLE IF EXISTS billing_invoice_lines;
  DROP TABLE IF EXISTS billing_invoices;
  ALTER TABLE tenants
    DROP COLUMN billing_payment_instructions,
    DROP COLUMN billing_invoice_terms,
    DROP COLUMN billing_next_invoice_seq,
    DROP COLUMN billing_invoice_prefix,
    DROP COLUMN billing_tax_rate_pct;
  ```
- No mutation to existing tables (`time_downstream_feed`,
  `placements`, etc.) — only reads + the consumed-marker flow which is
  already part of the Time module's contract.
