# AP Module — Phase A0 Deploy Notes

**Date:** 2026-02 (this fork)
**Status:** Shipped. 192 new contract tests ✓. Total platform: 903 smoke assertions.
**Scope:** AP bills + vendors + payments + allocations + expenses + AP aging + 1099 ledger. Plaid Transfer env-gated (keys not wired yet). GL posting stubbed until Accounting v1.0 ships.

## What this ships
- `ap_*` tables (8 total) — `ap_vendors_index`, `ap_bills`, `ap_bill_lines`, `ap_payments`, `ap_payment_allocations`, `ap_expense_reports(+lines)`, `ap_1099_ledger`
- 4 tenant config columns: `ap_bill_prefix`, `ap_next_bill_seq`, `ap_default_terms`, `ap_1099_threshold`
- 6 API endpoints: `bills`, `payments`, `vendors`, `expenses`, `aging`, `1099`
- 10 React UI components under `/modules/ap/ui/`
- Full state machines for bills (8 states) and payments (6 states)
- Atomic bill numbering (FOR UPDATE on tenant row)
- Two-eye approve on bills; SoD guard on payment.send
- `apBuildDraftFromBundle` consumes `time_downstream_feed.bundle_type='ap'` (1099 / C2C pay)
- On-read AP aging (5 buckets)
- 1099-NEC ledger with idempotent rebuild from cleared payments

## Cloudways deploy steps
1. **Push code** to main. Pull on Cloudways.
2. **Run migration** via `/update.php`:
   ```
   SOURCE /home/master/applications/<app>/public_html/modules/ap/migrations/001_init.sql;
   ```
3. **Verify schema** — all 8 `ap_*` tables created; 4 new `tenants` columns present.
4. **Rebuild SPA** — Vite bundle is already committed under `/spa-assets/`. If you rebuild locally, run `cd dashboard && yarn build && cp dist/spa-assets/* ../spa-assets/`.
5. **Reload RBAC permissions** if using a permission cache. All 16 `ap.*` permissions need to be granted to `master_admin`, `tenant_admin`, `admin` (or per-role as needed).

## Optional: enable Plaid Transfer (deferred)
The AP module scaffolds but does NOT call Plaid at Phase A0. To light it up:
1. Get Plaid sandbox keys from https://dashboard.plaid.com/developers/keys
2. Set env vars on Cloudways → Application Settings → ENV:
   ```
   PLAID_CLIENT_ID=xxx
   PLAID_SECRET_SANDBOX=xxx
   PLAID_ENV=sandbox
   ```
3. `apPlaidConfigured()` flips true, the UI unlocks "Plaid Transfer" as a payment method.
4. Phase A1 will wire the actual transfer creation (`POST /transfer/create`).

## Smoke walk
1. **Create a vendor**: AP → Vendors → New vendor → save as `1099_individual`.
2. **Create a manual bill**: currently via API; UI for manual create is Phase A1. For now, use time-bundle flow.
3. **Close a time period** with `ap` bundles (Time module → Periods → Close).
4. **Create bill from time bundle**: AP → Bills → New from time bundle → pick period → confirm.
5. **Two-eye approve**: log in as a second user → Bills → select bill → Approve.
6. **Record payment** against vendor: AP → Payments → Record payment (auto-allocate FIFO recommended).
7. **Clear payment**: same page → Allocate → dev tool to call `?action=clear&id=N` (clear-from-bank-rec UI coming in A1).
8. **Rebuild 1099**: AP → 1099 → Rebuild from cleared payments. Vendors over threshold show `requires_1099_nec = Yes`.
9. **Verify audit trail**: `audit_log` should contain `ap.bill.created`, `ap.bill.approved`, `ap.payment.drafted`, `ap.payment.allocated`, `ap.payment.cleared`, `ap.1099.ledger_built`.

## What's deferred (explicitly out of Phase A0)
- Real GL posting to Accounting (stubbed — emits audit event, no journal entry created)
- Inbox / AI vendor invoice parsing (Phase A1+ alongside Time Phase B Slice 2b/c)
- Recurring bills
- NACHA file generation
- Card import (Plaid / Brex / Ramp)
- Three-way match (PO ↔ receipt ↔ invoice)
- 1099-NEC form PDF + IRS e-file
- Vendor portal (tokenized read-only)

## Rollback
1. SPA: redeploy previous `/spa-assets/index-*.js`.
2. DB: drop tables in reverse FK order:
   ```sql
   DROP TABLE IF EXISTS ap_expense_report_lines;
   DROP TABLE IF EXISTS ap_expense_reports;
   DROP TABLE IF EXISTS ap_payment_allocations;
   DROP TABLE IF EXISTS ap_payments;
   DROP TABLE IF EXISTS ap_bill_lines;
   DROP TABLE IF EXISTS ap_bills;
   DROP TABLE IF EXISTS ap_1099_ledger;
   DROP TABLE IF EXISTS ap_vendors_index;
   -- tenant columns are additive, leave them
   ```
3. Restore prior vite bundle.
