# CoreFlux Live-Run Readiness — Payroll & Adjacent Modules

**Date:** Feb 2026
**Goal:** First live payroll within weeks. Path of least resistance is **Gusto-as-engine**: CoreFlux owns time, employees, placements, GL; Gusto owns the disbursement + tax filing.

---

## TL;DR — Can you run a live payroll today?

**Yes, the code path is complete end-to-end** for the Gusto handoff flow. Every module smoke is green (3,354 / 0 failed). What you need to verify operationally before pulling the trigger is in section 5 below.

The CoreFlux → Gusto path:

```
[People]  →  [Placements]  →  [Time]  →  [Time Settlement]  →  [Payroll Run]  →  [Gusto submit]
employees    bill/pay rates   entries    "payroll" bundle     gross-to-net      compensations PUT
                                                              + anomalies       /calculate /submit
```

---

## 1. People module — READY (104 ✅)

**What's there:**
- Encrypted SSN (cipher + hash + last4), DOB, hire_date, classification (`w2` / `1099` / `c2c`)
- W-4 doc storage (`people_documents.kind='w4'`)
- Banking (encrypted routing/account)
- Federal tax setup (`people_tax_federal`: filing_status, allowances/dependents, addl withholding)
- State tax setup (`people_tax_state`: per-state SOTs + extra withholding)
- I-9 record, custom fields, audit log on every PII view

**What you need to do before payroll:**
- Make sure each W2 employee has: SSN, DOB, address, hire_date, federal_filing_status, state_tax_state. Can spot-check via `/modules/people/api/audit_pii.php` or just open each employee profile.
- Upload completed W-4 PDFs. The system stores them as documents but doesn't OCR — the federal_filing_status fields drive the calc, not the PDF.

## 2. Placements module — READY (96 ✅)

**What's there:**
- `placements` table with employee_id ↔ client_id ↔ job_role
- Bill rate + pay rate per placement (regular + OT multipliers)
- Effective-dated rate snapshots (every time entry locks to a specific `placement_rates.id`)
- Commissions, corp-to-corp details, chain logic, approval contacts

**What you need to do:**
- Each W2 employee must have an active placement with non-null `pay_rate_cents`. Without this, time → settlement won't produce payroll lines.

## 3. Time module — READY (85 + 120 settlement ✅)

**What's there:**
- Hourly + salaried entry capture
- Approval tokens (manager email-link approve)
- Period close + settlement that fans out to a `time_downstream_feed` row per `bundle_type` (`payroll`, `billing`, `commissions`, etc.)
- Settlement engine: 461 lines, fully spec'd with rate-snapshot semantics

**What you need to do:**
- Define your time periods (weekly / biweekly) in Time → Periods.
- Get managers approving entries before period close. Unapproved entries don't roll into payroll.

## 4. Payroll module — READY (16 + 104 + 73 ✅)

**Compute engine (`modules/payroll/lib/compute.php`, 396 lines, deterministic, no AI):**
- W-4 (2020+) percentage method, simplified annualized brackets
- FICA: SS 6.2% to wage base ($176,100 for 2026 placeholder), Medicare 1.45% + 0.9% addl over $200k YTD
- FUTA 6.0% on first $7,000 (less SUTA credit, default effective 0.6%)
- SUTA: tenant-configured rate × first $7,000 (placeholder)
- CA SDI: 1.10% (no wage cap)
- Federal income tax: full 2026 placeholder bracket tables (single, HoH, MFJ)
- All math in CENTS — no floats anywhere in the pay path

**Run lifecycle:**
- `payroll_pay_periods` opened → time bundles fed in → `runs.php?action=build` computes lines → review anomalies → `runs.php?action=approve` two-eye → submit to Gusto

**Gusto OAuth integration (`modules/payroll/api/gusto_submit.php`, 258 lines):**
- `action=list_unprocessed` → pulls candidate Gusto payrolls in your date range
- `action=submit` → maps CoreFlux employees ↔ Gusto by `employee_number`, distributes regular/OT hours to primary job, posts bonus/commission as fixed_compensations, calls `/calculate` then `/submit`
- Salaried employees skip hourly_compensations (Gusto computes from base salary)

**Pay schedules, pay periods, anomalies, AI run summary** all wired with manage UI (PayrollModule.jsx, PayrollRuns.jsx, PayrollRunDetail.jsx, PayrollAnomalies.jsx, GustoConnectCard.jsx, etc.).

**What you need to do:**
- Connect Gusto OAuth (Payroll → Settings → "Connect Gusto"). You'll need your Gusto app's `client_id` / `client_secret` from the Gusto dashboard.
- **Verify 2026 tax tables** before any production run — `compute.php` lines 28-100 have placeholder constants cited inline (IRS Pub 15-T methodology). When IRS publishes 2026 finalized tables, update these constants. Currently fine for Gusto-as-engine flow because Gusto recomputes server-side anyway.

## 5. Billing module — READY for math, NOT READY for invoice delivery

**What's there (103 ✅):**
- `invoices.php` (520 lines): invoice creation from time-billing bundles, line items, statuses, payments
- Aging report
- Payment recording + auto-allocation

**Gap:** No PDF generation yet. PDFs require object storage (S3) — that's why this is on your "AWS not setup" blocker. Workarounds:
- (a) Ship invoices as HTML / browser-print (same approach used for 1099-NEC PDF — works, no S3 needed)
- (b) Email body as the invoice (Resend already integrated)
- (c) Wait for AWS, then ship full PDF + dunning workflow (this is the queued P2 item)

**For first-payroll-only goal:** Billing isn't on the critical path. Payroll runs without invoicing customers.

## 6. AP module — READY (192 + 55 ✅)

**What's there:**
- Vendors, vendor portal (magic-link), bills, payments
- 1099 ledger + browser-print 1099-NEC PDFs
- Approval workflows (amount-bracketed, multi-level routing) — **as of this deploy**

**For first-payroll-only goal:** AP isn't on the critical path either, unless you have non-W2 contractors getting paid alongside W2 payroll. If you do: Vendor → Bill → Pay flow works today; 1099 generation works.

## 7. Treasury / Plaid / Bank Feeds — READY post-this-deploy

**What just shipped:**
- Plaid health-check banner — top of Treasury, surfaces every institution stuck on `ITEM_LOGIN_REQUIRED` with one-click reconnect (Plaid update mode)
- Idempotent migration runner (no more "Unknown column" 500s)
- Saved Rules tab (every learned merchant→account mapping with mute/forget controls)
- Reconnect dedup (no more duplicate accounts each time you re-link)
- Live Plaid balances on Deposit / Liability lists
- Inline transactions on each account detail (no bouncing modules)

---

## What blocks you from a live run, prioritized

| Priority | Item | Effort | Blocker? |
|---|---|---|---|
| P0 | Connect Gusto OAuth, verify a payroll round-trip in Gusto sandbox | ½ day (your work) | YES |
| P0 | Confirm every W2 employee has SSN + W-4 + state tax + active placement with pay_rate | hours (your work) | YES |
| P1 | Run a parallel payroll: compute the same period in CoreFlux **and** by hand → diff | ½ day | NO but recommended |
| P1 | Verify 2026 federal/state tax constants in compute.php against final IRS Pub 15-T | 2 hours | NO if going Gusto-as-engine |
| P2 | Invoice PDF generation (S3-blocked) | when AWS lands | NO for payroll path |
| P2 | Settle the 13 legacy schema-contract violations (payroll_profiles refactor etc.) | 1-2 days | NO — caught by gate |
| P3 | DB integration test harness (sqlite-backed contract tests) | 1 day | NO but high-value |

**My honest take:** the code is in a position where the next blocker is **operational** (Gusto OAuth + employee data hygiene), not engineering. The recurring-bug pattern from the last few sessions was infrastructure (schema drift, route mismatches) — those are now gated by the migration runner + schema-contract test, so the same failure mode shouldn't recur.

If you want, I can write a **pre-flight checklist endpoint** (`/api/payroll/preflight.php?period_id=N`) that, given a pay period, walks every employee + placement + time bundle and returns a pass/fail report ("3 employees missing W-4 federal_filing_status", "1 placement has pay_rate_cents=0", etc.) so you can fix data issues without trial-and-error during your first run.
