# Accounts Payable (AP) — Module Specification

**Status**: DRAFT — pending user sign-off
**Stack**: PHP 8 (per HARD_RULES decisions log)
**Owner module of**: vendor invoice intake, expense tracking, vendor payments, 1099 / C2C contractor pay, expense approvals, AP aging.
**NOT owner of**: customer invoicing (lives in `billing/`), W2 employee payroll (lives in `payroll/`), GL postings (lives in `accounting/`).
**Sidebar grouping**: Finance.

---

## 1. Purpose

AP converts inbound vendor/contractor obligations into approved, paid liabilities, and posts them to GL. It is the mirror of Billing on the cost side.

Inputs:
- Inbound vendor invoices via Core MailService (tenant's `invoices` mail folder).
- Approved time bundles from `time/` for **non-W2** workers — `bundle_type='ap'` covers 1099 contractors and C2C corp pay.
- Manual expense entry (employee reimbursements, tenant credit-card uploads, recurring vendor charges).
- Placement.referrer entries → referral fee payables.

Outputs:
- Approved bills (vendor invoices accepted into the system).
- Outbound payments (ACH file, check stub, wire instruction, card payment).
- Journal entries posted to `accounting/`.
- 1099-NEC totals at year end.
- AP aging snapshots.

---

## 2. Core principles

1. **Bills require approval before payment.** Always two-eye control: enter ≠ approve ≠ pay.
2. **Vendors are NOT a CoreFlux entity** — same string-label rule as clients (per HARD_RULES R-2026-04-27). A `vendors_index` typeahead table exists for UX only.
3. **Three-way match available** when applicable: PO ↔ receipt ↔ invoice. Soft warnings, not hard blocks (configurable per tenant).
4. **Time-source bills** lock to `placement_rates.id` snapshot, just like Billing.
5. **AI inbox parsing** for inbound vendor invoices via Core MailService — describes, queues for review, never auto-pays.
6. **1099 tracking is a first-class output** — every payment to a non-corporate non-employee accumulates to a 1099-NEC ledger.

---

## 3. Data model

### 3.1 `ap_vendors_index` (typeahead — vendors are string labels per HARD_RULES)

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `vendor_name` | VARCHAR(255) | |
| `vendor_type` | ENUM('1099_individual','c2c_corp','w9_business','utility','other') | drives 1099 rollup |
| `default_remit_to_json` | TEXT NULL | snapshot of payment-to address / ACH details (encrypted) |
| `default_terms` | VARCHAR(40) | NET30 etc. |
| `tax_id_last4` | CHAR(4) NULL | cleartext display |
| `tax_id_full_ct` | VARBINARY(256) NULL | EIN / SSN encrypted at app layer (KMS) |
| `kms_key_version` | VARCHAR(64) | |
| `requires_1099` | BOOLEAN | |
| `last_bill_at` | DATETIME NULL | |
| `placement_id_last` | BIGINT NULL | for cross-link |

### 3.2 `ap_bills` (a vendor invoice received)

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `bill_number` | VARCHAR(80) | vendor's invoice number |
| `vendor_name` | VARCHAR(255) | snapshot |
| `vendor_type` | ENUM(...same as vendors_index) | |
| `received_at` | DATE | |
| `bill_date` | DATE | from the vendor invoice |
| `due_date` | DATE | |
| `period_start` | DATE NULL | for time-based bills |
| `period_end` | DATE NULL | |
| `currency` | CHAR(3) | |
| `subtotal` | DECIMAL(12,2) | |
| `tax_total` | DECIMAL(12,2) | |
| `total` | DECIMAL(12,2) | |
| `amount_paid` | DECIMAL(12,2) | |
| `amount_due` | DECIMAL(12,2) | |
| `status` | ENUM('inbox','pending_review','pending_approval','approved','partially_paid','paid','void','disputed') | |
| `source` | ENUM('mail_inbox','manual','time_bundle','recurring','expense_report','referral') | |
| `source_ref_id` | BIGINT NULL | mail intake id, time_downstream_feed id, etc. |
| `po_number` | VARCHAR(80) NULL | |
| `placement_id` | BIGINT NULL FK | when bill ties to a placement |
| `attachment_storage_object_id` | BIGINT NULL FK | original PDF/EML in S3 |
| `journal_entry_id` | BIGINT NULL FK | populated on post-to-GL |
| `created_by_user_id` | BIGINT NULL FK | NULL when AI-ingested |
| `approved_by_user_id` | BIGINT NULL FK | |
| `approved_at` | DATETIME NULL | |
| `created_at` / `updated_at` | DATETIME | |

Indexes: `(tenant_id, vendor_name, status)`, `(tenant_id, due_date, status)`, `(tenant_id, status, source)`.

### 3.3 `ap_bill_lines`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `bill_id` | BIGINT FK | |
| `line_no` | INT | |
| `source_type` | ENUM('time','manual','recurring','expense','referral') | |
| `source_ref_id` | BIGINT NULL | |
| `placement_id` | BIGINT NULL FK | |
| `rate_snapshot_id` | BIGINT NULL FK→`placement_rates.id` | |
| `description` | VARCHAR(500) | |
| `quantity` | DECIMAL(12,4) | |
| `unit` | VARCHAR(40) | |
| `unit_price` | DECIMAL(12,4) | |
| `subtotal` | DECIMAL(12,2) | |
| `tax_amount` | DECIMAL(12,2) | |
| `total` | DECIMAL(12,2) | |
| `gl_expense_account_code` | VARCHAR(40) NULL | for posting |
| `is_1099_eligible` | BOOLEAN | drives 1099-NEC rollup |

### 3.4 `ap_payments` (outbound payments)

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `vendor_name` | VARCHAR(255) | |
| `pay_date` | DATE | |
| `method` | ENUM('ach','wire','check','card','cash','other') | |
| `reference` | VARCHAR(120) | check number, wire ref |
| `amount` | DECIMAL(12,2) | |
| `currency` | CHAR(3) | |
| `bank_account_id` | BIGINT NULL FK | from `accounting_bank_accounts` |
| `status` | ENUM('draft','queued','sent','cleared','failed','void') | |
| `cleared_at` | DATETIME NULL | from bank rec |
| `journal_entry_id` | BIGINT NULL FK | |
| `created_by_user_id` | BIGINT FK | |

### 3.5 `ap_payment_allocations` (payment ↔ bill many-to-many)

Same shape as Billing's `billing_payment_allocations`.

### 3.6 `ap_expense_reports` (employee reimbursements)

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `submitter_person_id` | BIGINT FK→`people.id` | |
| `period_label` | VARCHAR(40) | "Mar 2026" or week label |
| `status` | ENUM('draft','submitted','approved','rejected','paid') | |
| `total` | DECIMAL(12,2) | |
| `currency` | CHAR(3) | |
| `submitted_at` / `approved_at` / `paid_at` | DATETIME NULL | |
| `approved_by_user_id` | BIGINT NULL FK | |
| `bill_id` | BIGINT NULL FK→`ap_bills.id` | when approved, becomes a bill |

### 3.7 `ap_expense_report_lines`

```
id, expense_report_id, expense_date, category, merchant, amount, currency,
gl_expense_account_code, receipt_storage_object_id, description, billable_to_client_name
```

### 3.8 `ap_recurring_bills` (utilities, subscriptions)

Mirror of `billing_recurring_services`.

### 3.9 `ap_1099_ledger` (year-end rollup)

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `tax_year` | INT | |
| `vendor_name` | VARCHAR(255) | snapshot |
| `tax_id_full_ct` | VARBINARY(256) | encrypted snapshot at year-end |
| `total_paid` | DECIMAL(12,2) | sum of cleared payments where lines `is_1099_eligible=true` |
| `requires_1099_nec` | BOOLEAN | computed (≥ $600 threshold + vendor_type) |
| `1099_pdf_storage_object_id` | BIGINT NULL FK | generated form |
| `submitted_to_irs_at` | DATETIME NULL | |

### 3.10 `ap_aging_snapshots`

Same shape as `billing_aging_snapshots`.

---

## 4. Permissions

| Slug | Description |
|---|---|
| `ap.view` | View AP data |
| `ap.bill.create` | Enter / draft bills |
| `ap.bill.review` | Work the AI/inbox review queue |
| `ap.bill.approve` | Approve bills (two-eye) |
| `ap.bill.void` | Void with reason |
| `ap.bill.post` | Post to GL |
| `ap.payment.create` | Create payments / payment runs |
| `ap.payment.send` | Release payments (transmit ACH/wire/check) |
| `ap.payment.allocate` | Allocate payments to bills |
| `ap.expense.submit` | Submit own expense report |
| `ap.expense.approve` | Approve someone else's expense report |
| `ap.recurring.manage` | Manage recurring bills |
| `ap.vendor.view_pii` | View vendor tax IDs (full) |
| `ap.1099.view` / `ap.1099.generate` | 1099 ledger + form generation |
| `ap.reports.view` | AP reports |

`default_roles`: `master_admin`, `tenant_admin`, `admin`. SoD between `bill.approve` and `payment.send` strongly recommended.

---

## 5. API surface

### 5.1 Bills
- `GET /api/ap/bills` — filters: `vendor_name`, `status`, `source`, `due_before`, `placement_id`.
- `POST /api/ap/bills` — manual create.
- `POST /api/ap/bills/from-time-bundle` — `{period_id, placement_ids[]}` for 1099/C2C bundles.
- `POST /api/ap/bills/from-mail-intake/{intake_id}` — convert AI-parsed mail event to bill draft.
- `PATCH /api/ap/bills/{id}` — only in `inbox`/`pending_review`/`pending_approval`.
- `POST /api/ap/bills/{id}/approve` / `.../void` / `.../dispute` / `.../post`.

### 5.2 Inbox / mail intake
- `POST /api/ap/inbox/poll` — system endpoint; calls Core MailService against the tenant's `invoices` folder.
- `GET /api/ap/intake` — paginated list of `mail_intake_events` (or shared with Time's intake table — see open Q1).
- `POST /api/ap/intake/{id}/convert` — turn AI proposal into bill draft.
- `POST /api/ap/intake/{id}/dismiss`.

### 5.3 Payments
- `GET|POST /api/ap/payments`
- `POST /api/ap/payment-runs/build` — body `{as_of, vendor_filter[], method}`; selects approved bills with due_date ≤ as_of and builds a run.
- `POST /api/ap/payment-runs/{id}/send` — gated by `ap.payment.send`; produces ACH file / check stubs / wire instructions; updates bills.
- `POST /api/ap/payments/{id}/allocate`.

### 5.4 Expense reports
- `GET|POST|PATCH /api/ap/expenses`
- `POST /api/ap/expenses/{id}/submit` / `.../approve` / `.../reject`.
- Approving an expense report records the expense approval, then creates a
  source=`expense_report` AP bill in `pending_approval` and routes that bill
  through the common AP approval workflow. Expense approval does not mark the
  payable approved.

### 5.5 1099
- `GET /api/ap/1099/ledger?tax_year=YYYY`
- `POST /api/ap/1099/generate?tax_year=YYYY` — builds PDFs into S3, marks `requires_1099_nec`.

### 5.6 Reports
- `GET /api/ap/aging`
- `GET /api/ap/reports/spend-by-vendor`
- `GET /api/ap/reports/spend-by-category`

---

## 6. UI / sidebar

| Route | Label | Permission |
|---|---|---|
| `dashboard` | AP Dashboard | `ap.view` |
| `inbox` | Vendor Inbox | `ap.bill.review` |
| `bills` | Bills | `ap.view` |
| `payments` | Payments | `ap.payment.create` |
| `expenses` | Expense Reports | `ap.expense.submit` |
| `recurring` | Recurring Bills | `ap.recurring.manage` |
| `aging` | AP Aging | `ap.reports.view` |
| `1099` | 1099 Ledger | `ap.1099.view` |
| `reports` | Reports | `ap.reports.view` |

---

## 7. AI usage

- **Inbox parsing**: Core MailService pulls mail from tenant's `invoices` folder. AI extracts: `{vendor_name, bill_number, bill_date, due_date, total, line items}`. Confidence score per field. Lands in `ap_bills` as `status='pending_review'`.
- **Vendor matching**: AI suggests which existing `ap_vendors_index` row matches the parsed vendor.
- **GL coding suggestion**: AI suggests `gl_expense_account_code` per line based on description + history. Reviewer accepts or overrides.
- **Three-way match assist**: when a bill has a PO# and there are open POs/receipts in the system (Phase C), AI flags discrepancies.

Hard rule: AI never approves bills, never posts to GL, never sends payment.

---

## 8. Audit events

`ap.bill.*` (created/updated/approved/posted/void/disputed/paid),
`ap.intake.*` (received/parsed/converted/dismissed),
`ap.payment.*` (drafted/run_built/sent/cleared/void),
`ap.expense.*` (submitted/approved/rejected/paid),
`ap.1099.*` (ledger_built/form_generated/submitted),
`ap.vendor.*` (created/tax_id_viewed/tax_id_updated).

---

## 9. Validation rules

- A bill cannot be `approved` while any line has `total <= 0`.
- A payment cannot be `sent` for a bill that is `disputed` or `void`.
- 1099 ledger only includes payments with `cleared_at IS NOT NULL`.
- Time-source bill line must reference an unconsumed `time_downstream_feed` row of `bundle_type='ap'`.
- Posting blocked if accounting period is `closed`.
- Vendor tax ID required before any payment ≥ tenant 1099 threshold (default $600).

---

## 10. Multi-tenancy

- All tables filter by `tenant_id`.
- Vendor tax IDs encrypted application-level (KMS), per HARD_RULES.
- Mail inbox connector uses Core MailService, tenant's own mail account.

---

## 11. Decisions inherited / locked

1. ✅ Vendor inbox via Core MailService (tenant's own mail folder, e.g. `invoices`).
2. ✅ Bill PDFs / EMLs in S3 via Core StorageService.
3. ✅ All outbound communication (remittance advice, check stubs by email) via Core MailService from tenant domain.
4. ✅ Time → AP feed via `time_downstream_feed.bundle_type='ap'` for non-W2 workers.
5. ✅ AI describes / humans decide. Two-eye on approve and on payment send.
6. ✅ **Posts to Accounting via standardized protocol** (`POST /api/v1/accounting/journal-entries`). Required: `entity_id`, `idempotency_key` (e.g. `ap.bill.post.{bill_id}`, `ap.payment.post.{payment_id}`), `source_module='ap'`, `source_ref_type/id`, `external_ref` (vendor's bill number), and `dimensions` per line (vendor, placement, department, project where applicable).
7. ✅ Multi-entity: every bill / payment / expense report carries an `entity_id`.

---

## 12. Decisions locked

1. ✅ **Mail intake table = one per module.** AP gets `ap_intake_events`; Time has `time_intake_events`. Each module owns its intake schema (allows module-specific fields without coupling). Both call Core MailService for the actual mail fetch.
2. ✅ **Outbound payments**: full bank integration in Phase B (not just NACHA file generation). **Vendor selection deferred** — decide between Plaid Transfer / Stripe ACH / Modern Treasury / Dwolla / direct bank API at Phase B kickoff. Code abstracts payment rails behind a single `PaymentRailsDriver` interface so the choice is swappable.
3. ✅ **1099 = generate forms only at MVP.** E-file via Track1099/Tax1099 integration deferred to Phase B.
4. ✅ **Three-way match** (PO ↔ receipt ↔ invoice) — **in MVP**. Soft warnings on mismatch (per Billing PO config), not hard blocks unless tenant explicitly configures hard.
5. ✅ **Card / corporate-card import**: Phase B. Vendor deferred (Plaid / Brex / Ramp / direct issuer API — same `PaymentRailsDriver` abstraction).
6. ✅ **Vendor portal** = Phase B (mirror of Billing customer portal: tokenized signed-link read-only view of bills + payment status).

---

## 13. MVP cut list

**Phase A:**
- `ap_vendors_index`, `ap_bills`, `ap_bill_lines`, `ap_payments`, `ap_payment_allocations`
- Manual bill entry, time-bundle bill creation, draft → approve → pay tracking
- Expense reports (submit → approve → convert to bill)
- AP aging snapshot
- GL post via Accounting

**Phase B:**
- Mail inbox intake + AI parser for vendor invoices
- Recurring bills
- 1099 ledger + form PDF generation
- NACHA ACH file origination
- Card import (Plaid/Brex/Ramp)
- Three-way match (basic)

**Phase C:**
- 1099 e-file via Track1099/Tax1099
- Vendor portal
- AI GL coding + variance flags

---

*Binding once signed off.*
