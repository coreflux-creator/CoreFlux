# Billing — Module Specification

**Status**: DRAFT — pending user sign-off
**Stack**: PHP 8 (per HARD_RULES decisions log)
**Owner module of**: customer-facing money movement — billing rules, invoice generation, payment tracking, AR aging, overdue notices, recurring billing, credit/debit memos, tax calculation.
**NOT owner of**: chart of accounts / journal posting (lives in `accounting/`), vendor payments (lives in `ap/`), payroll (lives in `payroll/`).
**Sidebar grouping**: Finance.

This SPEC is the source of truth and must be matched by implementation.

---

## 1. Purpose

Billing converts approved operational data into invoices, dunning workflows, payments received, and AR aging. It is **enterprise workflow**, not consumer bookkeeping.

Inputs:
- Approved time bundles from `time/` (`time_downstream_feed.bundle_type='ar'`).
- Placement-level billing rules (per service type, per client, per MSA).
- Recurring service definitions (per-tenant catalogs).
- Tax rate matrix (per jurisdiction).
- Credit/debit adjustments (manual or workflow).

Outputs:
- Invoices (PDF + structured payload), sent via Core MailService.
- Payment receipts logged against invoices.
- Journal entries posted into `accounting/` per tenant's accounting policy.
- AR aging snapshots and overdue notices.
- Recurring invoice runs.

---

## 2. Core principles

1. **Operational data → money flow** is the spine. Every invoice line traces back to either a `time_entries` row (via the consumed bundle), a recurring service line, or an adjustment.
2. **Posting to GL is a separate step** from invoice issuance. Invoice creation produces a draft journal payload; posting requires accounting policy + period checks (cash vs accrual, period open).
3. **Rate snapshot semantics** carry through: an invoice line uses the `placement_rates.id` snapshot the time entry was approved against. Bill-rate changes after approval do NOT retroactively change historical invoices.
4. **Credit/debit memos are first-class** — never edit a posted invoice. Always issue a memo.
5. **Tax is configurable per jurisdiction**, applied at the line level, snapshotted on the invoice.
6. **Outbound emails (invoices, statements, dunning) sent FROM tenant's domain** via Core MailService.
7. **Multi-currency** support is in scope at the **invoice header level** (per HARD_RULES placements decision: single tenant operational currency, but Billing/Accounting may carry historical multi-currency for clients invoiced in other currencies before policy was set).

---

## 3. Data model

### 3.1 `billing_clients_index` (denormalized typeahead — clients are NOT a CoreFlux entity per HARD_RULES R-2026-04-27)

Same purpose as `tenant_end_clients` from Placements. Reused / cross-referenced. A given `client_name` string ties an invoice to an operational customer without a relational FK to a clients table.

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `client_name` | VARCHAR(255) | |
| `billing_address_json` | TEXT NULL | snapshot of bill-to address |
| `default_payment_terms` | VARCHAR(40) | e.g. `NET30`, `NET45` |
| `default_currency` | CHAR(3) | |
| `default_tax_jurisdiction_id` | BIGINT NULL FK | |
| `last_invoice_at` | DATETIME NULL | for ranking |

### 3.2 `billing_rules` (per-placement / per-client / per-service rules)

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `scope` | ENUM('placement','client','service','tenant_default') | precedence: placement > client > service > tenant_default |
| `placement_id` | BIGINT NULL FK | |
| `client_name` | VARCHAR(255) NULL | |
| `service_type_code` | VARCHAR(80) NULL | tenant-defined; e.g. "consulting", "managed_services", "subscription" |
| `frequency` | ENUM('per_period','weekly','biweekly','semimonthly','monthly','quarterly','annual','one_off') | |
| `aggregate` | ENUM('per_placement','per_client','consolidated') | one invoice per placement / per client / single consolidated |
| `po_required` | BOOLEAN | block invoice creation without a PO# |
| `po_format_regex` | VARCHAR(120) NULL | validate PO format if required |
| `payment_terms` | VARCHAR(40) | e.g. NET30 |
| `late_fee_pct` | DECIMAL(5,2) NULL | |
| `dunning_schedule_id` | BIGINT NULL FK | |
| `tax_jurisdiction_id` | BIGINT NULL FK | overrides client default |
| `template_id` | BIGINT NULL FK | invoice template |
| `effective_from` | DATE | |
| `effective_to` | DATE NULL | |
| `notes` | TEXT NULL | |

### 3.3 `billing_invoices` (the header)

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `invoice_number` | VARCHAR(40) | tenant-defined sequence; UNIQUE per tenant |
| `client_name` | VARCHAR(255) | snapshot |
| `bill_to_json` | TEXT | full snapshot of bill-to block |
| `currency` | CHAR(3) | |
| `issue_date` | DATE | |
| `due_date` | DATE | |
| `period_start` | DATE NULL | for time-based invoices |
| `period_end` | DATE NULL | |
| `subtotal` | DECIMAL(12,2) | |
| `tax_total` | DECIMAL(12,2) | |
| `total` | DECIMAL(12,2) | |
| `amount_paid` | DECIMAL(12,2) | running, updated on payment |
| `amount_due` | DECIMAL(12,2) | total − amount_paid − credits_applied |
| `status` | ENUM('draft','pending_approval','approved','sent','partially_paid','paid','void','overdue','disputed') | |
| `po_number` | VARCHAR(80) NULL | |
| `template_id` | BIGINT NULL FK | |
| `notes_internal` | TEXT NULL | |
| `notes_external` | TEXT NULL | shown on PDF |
| `pdf_storage_object_id` | BIGINT NULL FK | rendered PDF in S3 |
| `journal_entry_id` | BIGINT NULL FK→`accounting_journal_entries.id` | populated on post-to-GL |
| `created_by_user_id` | BIGINT FK | |
| `created_at` / `updated_at` | DATETIME | |
| `sent_at` | DATETIME NULL | |
| `voided_at` | DATETIME NULL | |
| `voided_by_user_id` | BIGINT NULL FK | |
| `void_reason` | VARCHAR(500) NULL | |

Indexes: `(tenant_id, invoice_number)` UNIQUE, `(tenant_id, client_name, status)`, `(tenant_id, due_date, status)`, `(tenant_id, status)`.

### 3.4 `billing_invoice_lines`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `invoice_id` | BIGINT FK | |
| `line_no` | INT | display order |
| `source_type` | ENUM('time','recurring','manual','adjustment') | |
| `source_ref_id` | BIGINT NULL | `time_downstream_feed.id` / `billing_recurring_services.id` |
| `placement_id` | BIGINT NULL FK | for traceability |
| `rate_snapshot_id` | BIGINT NULL FK→`placement_rates.id` | locked at consumption |
| `description` | VARCHAR(500) | |
| `quantity` | DECIMAL(12,4) | hours, units |
| `unit` | VARCHAR(40) | hour, day, month, project |
| `unit_price` | DECIMAL(12,4) | |
| `subtotal` | DECIMAL(12,2) | quantity * unit_price |
| `tax_rate_snapshot_pct` | DECIMAL(7,4) NULL | snapshotted at issuance |
| `tax_amount` | DECIMAL(12,2) | |
| `total` | DECIMAL(12,2) | subtotal + tax_amount |
| `gl_revenue_account_code` | VARCHAR(40) NULL | for posting |

### 3.5 `billing_recurring_services`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `client_name` | VARCHAR(255) | |
| `placement_id` | BIGINT NULL FK | optional link |
| `service_code` | VARCHAR(80) | |
| `description` | VARCHAR(500) | |
| `unit_price` | DECIMAL(12,4) | |
| `unit` | VARCHAR(40) | |
| `frequency` | ENUM('weekly','biweekly','semimonthly','monthly','quarterly','annual') | |
| `start_date` | DATE | |
| `end_date` | DATE NULL | |
| `next_run_date` | DATE | |
| `status` | ENUM('active','paused','ended') | |
| `auto_send` | BOOLEAN | when true, generated invoice auto-sends after issuance |
| `created_by_user_id` | BIGINT FK | |

### 3.6 `billing_credits` (credit/debit memos)

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `memo_number` | VARCHAR(40) | tenant sequence; UNIQUE per tenant |
| `memo_type` | ENUM('credit','debit') | |
| `against_invoice_id` | BIGINT NULL FK | |
| `client_name` | VARCHAR(255) | |
| `amount` | DECIMAL(12,2) | always positive; sign comes from memo_type |
| `tax_amount` | DECIMAL(12,2) | |
| `total` | DECIMAL(12,2) | |
| `currency` | CHAR(3) | |
| `reason` | VARCHAR(500) | |
| `status` | ENUM('draft','approved','applied','void') | |
| `applied_at` | DATETIME NULL | when reduced an invoice's amount_due |
| `journal_entry_id` | BIGINT NULL FK | |
| `pdf_storage_object_id` | BIGINT NULL FK | |
| `created_by_user_id` | BIGINT FK | |
| `created_at` | DATETIME | |

### 3.7 `billing_payments` (received payments / receipts)

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `client_name` | VARCHAR(255) | |
| `received_at` | DATE | |
| `method` | ENUM('ach','wire','check','card','cash','other') | |
| `reference` | VARCHAR(120) | check number, wire ref, processor txn id |
| `amount` | DECIMAL(12,2) | |
| `currency` | CHAR(3) | |
| `unallocated_amount` | DECIMAL(12,2) | for over-payments / on-account |
| `notes` | TEXT NULL | |
| `journal_entry_id` | BIGINT NULL FK | |
| `created_by_user_id` | BIGINT FK | |

### 3.8 `billing_payment_allocations` (payment ↔ invoice many-to-many)

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `payment_id` | BIGINT FK | |
| `invoice_id` | BIGINT FK | |
| `amount_applied` | DECIMAL(12,2) | |
| `applied_at` | DATETIME | |
| `applied_by_user_id` | BIGINT FK | |

### 3.9 `billing_tax_jurisdictions` + `billing_tax_rates`

```
billing_tax_jurisdictions: id, tenant_id, name (e.g. "NJ State"),
                            country, state, county, city, postal_pattern, active
billing_tax_rates:          id, jurisdiction_id, rate_pct, effective_from,
                            effective_to, type (sales/use/vat/gst), exemption_codes_json
```

### 3.10 `billing_dunning_schedules` (overdue notice cadence)

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `name` | VARCHAR(120) | e.g. "Standard NET30 Dunning" |
| `is_default` | BOOLEAN | one TRUE per tenant |
| `active` | BOOLEAN | |

#### `billing_dunning_steps`
| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `schedule_id` | BIGINT FK | |
| `days_after_due` | INT | 7, 14, 30, 60, 90 |
| `template_id` | BIGINT FK | which email template |
| `escalate_to_role` | VARCHAR(40) NULL | notify internal user |
| `late_fee_pct` | DECIMAL(5,2) NULL | apply on this step |
| `auto_send` | BOOLEAN | vs require manual review |

### 3.11 `billing_templates` (invoice + dunning email templates)

```
billing_templates: id, tenant_id, kind ('invoice_pdf' | 'invoice_email' | 'dunning_email' |
                   'statement_email' | 'credit_memo_pdf'), name, subject, body_html,
                   variables_json, is_default, active
```

Templates support tokens: `{client_name}`, `{invoice_number}`, `{amount_due}`, `{due_date}`, `{period_label}`, `{tenant_name}`, `{portal_link}`, `{ar_summary}`.

### 3.12 `billing_aging_snapshots` (computed daily)

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `as_of` | DATE | |
| `client_name` | VARCHAR(255) | |
| `current` | DECIMAL(12,2) | |
| `bucket_1_30` | DECIMAL(12,2) | |
| `bucket_31_60` | DECIMAL(12,2) | |
| `bucket_61_90` | DECIMAL(12,2) | |
| `bucket_91_plus` | DECIMAL(12,2) | |
| `total_due` | DECIMAL(12,2) | |

---

## 4. Permissions (RBAC)

| Slug | Description |
|---|---|
| `billing.view` | View billing data |
| `billing.invoice.draft` | Create / edit draft invoices |
| `billing.invoice.approve` | Move draft → approved (two-eye) |
| `billing.invoice.send` | Send invoice via MailService |
| `billing.invoice.void` | Void with reason |
| `billing.invoice.post` | Post to GL (calls Accounting module) |
| `billing.recurring.manage` | Manage recurring service catalog |
| `billing.payments.record` | Record received payment |
| `billing.payments.allocate` | Allocate payments to invoices |
| `billing.credits.manage` | Issue credit / debit memos |
| `billing.dunning.manage` | Manage dunning schedules + send overdue notices |
| `billing.tax.manage` | Manage tax jurisdictions + rates |
| `billing.templates.manage` | Manage invoice / email templates |
| `billing.reports.view` | AR aging, sales by client, etc. |

`default_roles`: `master_admin`, `tenant_admin`, `admin`. `billing.invoice.approve` and `billing.invoice.post` should be separate from `billing.invoice.draft` (two-eye / SoD).

---

## 5. API surface

### 5.1 Invoices
- `GET /api/billing/invoices` — filters: `client_name`, `status`, `from`, `to`, `due_before`, `placement_id`.
- `POST /api/billing/invoices` — create draft (manual or from time bundle).
- `POST /api/billing/invoices/from-time-bundle` — body `{period_id, placement_ids[]}`; pulls `time_downstream_feed` rows of `bundle_type='ar'`, builds invoice draft, marks bundles `consumed`.
- `PATCH /api/billing/invoices/{id}` — only in `draft` / `pending_approval` / `disputed`.
- `POST /api/billing/invoices/{id}/approve` — gated by `billing.invoice.approve`.
- `POST /api/billing/invoices/{id}/send` — renders PDF, stores via Core StorageService, emails via Core MailService from tenant domain.
- `POST /api/billing/invoices/{id}/void` — body `{reason}`; reverses GL posting if posted.
- `POST /api/billing/invoices/{id}/post` — calls `accounting/` to create journal entry; sets `journal_entry_id`.
- `GET /api/billing/invoices/{id}/pdf` — signed URL to rendered PDF.

### 5.2 Recurring
- `GET|POST|PATCH /api/billing/recurring`
- `POST /api/billing/recurring/{id}/run-now` — generates invoice early
- Cron: nightly run picks up `next_run_date <= today` records and generates invoices.

### 5.3 Credits / debits
- `GET|POST|PATCH /api/billing/credits`
- `POST /api/billing/credits/{id}/approve`
- `POST /api/billing/credits/{id}/apply` — reduce a target invoice's amount_due

### 5.4 Payments
- `GET|POST /api/billing/payments`
- `POST /api/billing/payments/{id}/allocate` — body `{allocations: [{invoice_id, amount}]}`
- `POST /api/billing/payments/{id}/auto-allocate` — FIFO oldest unpaid invoices

### 5.5 Dunning + AR
- `GET /api/billing/aging` — current snapshot or `?as_of=YYYY-MM-DD`
- `GET /api/billing/dunning/queue` — invoices due for next dunning step today
- `POST /api/billing/dunning/queue/{step_id}/send` — sends overdue notice (or auto-runs by cron when `auto_send=true`)

### 5.6 Tax / templates / reports
- `GET|POST|PATCH /api/billing/tax/jurisdictions` and `.../rates`
- `GET|POST|PATCH /api/billing/templates`
- `GET /api/billing/reports/sales-by-client`
- `GET /api/billing/reports/sales-by-service`
- `GET /api/billing/reports/collections`

---

## 6. UI / sidebar

Manifest actions:

| Route | Label | Permission |
|---|---|---|
| `dashboard` | AR Dashboard | `billing.view` |
| `invoices` | Invoices | `billing.view` |
| `recurring` | Recurring Services | `billing.recurring.manage` |
| `payments` | Payments | `billing.payments.record` |
| `credits` | Credits & Debits | `billing.credits.manage` |
| `aging` | Aging | `billing.reports.view` |
| `dunning` | Dunning Queue | `billing.dunning.manage` |
| `tax` | Tax Settings | `billing.tax.manage` |
| `templates` | Templates | `billing.templates.manage` |
| `reports` | Reports | `billing.reports.view` |
| `rules` | Billing Rules | `billing.invoice.draft` |

---

## 7. Audit events

`billing.invoice.created/updated/approved/sent/voided/posted/disputed/paid_in_full`,
`billing.recurring.created/run/paused/ended`,
`billing.credit.created/approved/applied/void`,
`billing.payment.recorded/allocated/unallocated`,
`billing.dunning.step_sent/escalated`,
`billing.tax.jurisdiction.created/rate_added`,
`billing.template.updated`,
`billing.aging.snapshot.built`.

---

## 8. AI usage

MVP: none direct. Phase B candidates (all describe / human-decide):
- **Anomaly detection**: invoice total > 30% above placement's typical period → flag for review.
- **Dunning tone tuning**: AI drafts the next dunning email body; human approves.
- **Cash application assist**: AI suggests payment ↔ invoice matches when reference is fuzzy; human confirms allocations.

Hard rule: AI never directly modifies invoices, payments, GL postings, or memos.

---

## 9. Validation rules

- Invoice cannot be `approved` while any line has `subtotal <= 0` (use credit memo instead).
- Approved invoices are immutable except via void or credit/debit memo.
- A void requires `reason`; reverses GL if posted; cannot void a partially-paid invoice without first issuing offsetting credits.
- Time-source invoice line must reference a `time_downstream_feed` row of `bundle_type='ar'` not yet `consumed`.
- Posting blocked if accounting period is `closed`.
- Tax rate snapshot mandatory at issuance (cannot change historical tax retroactively).

---

## 10. Multi-tenancy

- All tables filter by `tenant_id`.
- Invoice numbering sequences are per-tenant.
- Tax jurisdictions are per-tenant.
- Outbound email goes via Core MailService → tenant domain.

---

## 11. Decisions inherited / locked

1. ✅ Emails sent from tenant's own domain via Core MailService.
2. ✅ PDFs stored in S3 via Core StorageService (`billing/{tenant}/invoice/{id}/...`).
3. ✅ Single tenant currency at operational level (Placements); Billing supports historical multi-currency at invoice header for legacy/exception invoices.
4. ✅ Time → AR feed via `time_downstream_feed.bundle_type='ar'` (Time SPEC §3.7).
5. ✅ AI describes / humans decide.

---

## 12. Open questions

1. **Invoice numbering format** — fixed (`INV-2026-0001`) or tenant-customizable (`{prefix}-{YY}-{seq}` template)?
2. **Customer portal** — public read-only invoice view via signed link (no login)? Many SaaS billing tools offer this.
3. **Stripe / payment processor integration** — for accepting card / ACH payments, generating receipts? Recommend deferred to Phase B.
4. **Statement of account** — periodic statement showing all invoices + payments to a client? Recommend yes, monthly.
5. **PO matching strictness** — when `po_required=true`, hard block or soft warn?
6. **Tax engine vs static rates** — at MVP, manual tax rates per jurisdiction; do we need Avalara/TaxJar integration roadmap noted?

---

## 13. MVP cut list

**Phase A:**
- Invoices (manual + from time bundle), draft → approve → send
- Payments + allocations
- Credit/debit memos
- Tax jurisdictions/rates (manual)
- AR aging snapshot
- Render invoice PDF + email via tenant domain

**Phase B:**
- Recurring services
- Dunning schedules + automated overdue notices
- Customer portal (signed-link read-only view)
- Statements of account
- AI anomaly flags

**Phase C:**
- Stripe / ACH payment acceptance
- Avalara/TaxJar integration
- AI dunning tone + cash app assist

---

*Binding once signed off. Update before code.*
