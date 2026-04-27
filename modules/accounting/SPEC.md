# Accounting — Module Specification

**Status**: DRAFT — pending user sign-off
**Stack**: PHP 8 (per HARD_RULES decisions log)
**Owner module of**: General Ledger engine — chart of accounts, journal entries, trial balance, P&L, balance sheet, cash/accrual toggle, fiscal year, period close, bank reconciliation, multi-currency, QB/Xero import-export.
**NOT owner of**: subledgers (those live in `billing/`, `ap/`, `payroll/`). Accounting receives postings FROM them.
**Sidebar grouping**: Finance.

This is the **GL engine of record**, not a consumer bookkeeping tool. It is the bridge from operational data → real financial statements.

---

## 1. Purpose

Accounting is the deterministic ledger that all subledgers post into. It owns:
- The tenant's chart of accounts.
- Posted journal entries (immutable).
- Trial balance, P&L, balance sheet, cash flow.
- Period open/close mechanics.
- Bank reconciliation.
- Cash vs accrual reporting toggle.
- QB / Xero round-trip imports/exports.

Subledgers (Billing, AP, Payroll) build journal entry payloads → call `accounting/` to post → Accounting validates and writes immutable JE rows.

---

## 2. Core principles

1. **Posted entries are immutable.** Corrections via reversing JE; never edit a posted row.
2. **Double-entry, balanced.** Every JE must have debits = credits to the cent before posting.
3. **Period close locks the past.** Once a period is closed, no JE can post to it (except via re-open with audit trail).
4. **Cash vs accrual is a reporting toggle**, not a posting toggle. Postings are always accrual; cash-basis reports re-derive at query time.
5. **Multi-currency is supported at the JE level.** Each line carries `transaction_currency` + `transaction_amount` AND `functional_currency` + `functional_amount` (translated at posting using `accounting_fx_rates`).
6. **Tenant-owned chart of accounts.** Tenants can adopt a starter template (e.g. SaaS, services agency) but every account is per-tenant.
7. **Bridge to outside systems.** QB Online / Xero import-export round-trip is a first-class workflow.

---

## 3. Data model

### 3.1 `accounting_fiscal_calendars`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `name` | VARCHAR(120) | "FY2026" |
| `start_date` | DATE | |
| `end_date` | DATE | |
| `period_count` | INT | usually 12 (monthly) or 13 (4-4-5) |
| `period_definition_json` | TEXT | start/end of each period |
| `is_default` | BOOLEAN | one TRUE per tenant |
| `active` | BOOLEAN | |

### 3.2 `accounting_periods`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `calendar_id` | BIGINT FK | |
| `period_number` | INT | 1..N within calendar |
| `start_date` / `end_date` | DATE | |
| `status` | ENUM('future','open','soft_closed','closed','reopened') | |
| `closed_at` / `closed_by_user_id` | DATETIME / FK NULL | |

`soft_closed` = no new postings from subledgers, but corrections by `accounting.period.adjust` permission allowed.
`closed` = locked; reopening requires explicit action and is audit-logged.

### 3.3 `accounting_chart_of_accounts`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `code` | VARCHAR(40) | e.g. `4000-CONSULTING-REV`; tenant-defined; UNIQUE per tenant |
| `name` | VARCHAR(200) | |
| `category` | ENUM('asset','liability','equity','revenue','expense','contra_asset','contra_revenue','contra_expense') | |
| `subcategory` | VARCHAR(80) NULL | "Current Assets", "Cost of Services", etc. |
| `parent_account_id` | BIGINT NULL FK→`accounting_chart_of_accounts.id` | for sub-accounts |
| `currency` | CHAR(3) | functional currency unless tenant overrides |
| `is_bank_account` | BOOLEAN | drives bank-rec UX |
| `is_cash_basis_visible` | BOOLEAN | for cash-toggle reports |
| `external_qb_id` / `external_xero_id` | VARCHAR(80) NULL | for round-trip mapping |
| `active` | BOOLEAN | |

### 3.4 `accounting_journal_entries` (header)

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `je_number` | VARCHAR(40) | tenant sequence; UNIQUE per tenant |
| `transaction_date` | DATE | |
| `period_id` | BIGINT FK | resolved at posting |
| `source_module` | ENUM('billing','ap','payroll','manual','recurring','reversal','adjustment','import') | |
| `source_ref_type` | VARCHAR(40) NULL | e.g. `billing_invoices`, `ap_bills`, `payroll_runs` |
| `source_ref_id` | BIGINT NULL | |
| `description` | VARCHAR(500) | |
| `transaction_currency` | CHAR(3) | |
| `functional_currency` | CHAR(3) | tenant default |
| `fx_rate_used` | DECIMAL(18,8) | |
| `total_debits` | DECIMAL(14,2) | functional |
| `total_credits` | DECIMAL(14,2) | functional |
| `status` | ENUM('draft','pending_approval','posted','reversed','voided') | |
| `requires_approval` | BOOLEAN | based on tenant policy + JE amount thresholds |
| `posted_by_user_id` / `posted_at` | BIGINT FK NULL / DATETIME NULL | |
| `reverses_je_id` | BIGINT NULL FK | for reversing entries |
| `reversed_by_je_id` | BIGINT NULL FK | back-reference |
| `attachment_storage_object_ids_json` | TEXT NULL | linked source docs in S3 |
| `created_by_user_id` | BIGINT FK | |
| `created_at` / `updated_at` | DATETIME | |

Indexes: `(tenant_id, je_number)` UNIQUE, `(tenant_id, period_id, status)`, `(tenant_id, source_module, source_ref_id)`, `(tenant_id, transaction_date, status)`.

### 3.5 `accounting_journal_entry_lines`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `je_id` | BIGINT FK | |
| `line_no` | INT | |
| `account_id` | BIGINT FK→`accounting_chart_of_accounts.id` | |
| `dr_or_cr` | ENUM('dr','cr') | exactly one per line |
| `transaction_amount` | DECIMAL(14,2) | in `transaction_currency` |
| `functional_amount` | DECIMAL(14,2) | translated |
| `description` | VARCHAR(500) NULL | per-line memo |
| `class_id` / `department_id` / `location_id` / `project_id` / `placement_id` | BIGINT NULL FK | tenant-defined dimensions; placement_id ties back to operations |
| `tax_code_id` | BIGINT NULL FK | |
| `reconciliation_id` | BIGINT NULL FK→`accounting_reconciliations.id` | for bank-rec |

### 3.6 `accounting_dimensions` (class/dept/location/project — tenant-defined)

```
accounting_dimension_types: id, tenant_id, code ('class'|'department'|'location'|'project'), label, active
accounting_dimension_values: id, tenant_id, type_id, code, name, parent_id (nullable, for hierarchy), active
```

`placement_id` is a hardcoded dimension since it crosses module boundaries.

### 3.7 `accounting_bank_accounts`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `coa_account_id` | BIGINT FK→`accounting_chart_of_accounts.id` | corresponding GL account |
| `display_name` | VARCHAR(120) | |
| `bank_name` | VARCHAR(120) | |
| `account_number_last4` | CHAR(4) | display |
| `account_number_full_ct` | VARBINARY(512) | encrypted |
| `routing_number_ct` | VARBINARY(256) | encrypted |
| `kms_key_version` | VARCHAR(64) | |
| `currency` | CHAR(3) | |
| `nacha_config_json` | TEXT NULL | for outbound ACH origination |
| `import_format` | ENUM('ofx','qfx','csv','plaid','manual') | for statement imports |
| `active` | BOOLEAN | |

### 3.8 `accounting_bank_statement_imports`

```
id, tenant_id, bank_account_id, statement_date_start, statement_date_end,
opening_balance, closing_balance, file_storage_object_id, status ('uploaded'|'parsed'|'reconciled'),
imported_by_user_id, imported_at
```

### 3.9 `accounting_bank_statement_lines`

```
id, import_id, txn_date, amount, description, bank_reference,
matched_je_line_id (nullable), match_confidence, match_method ('exact'|'fuzzy'|'manual'|'unmatched')
```

### 3.10 `accounting_reconciliations` (bank rec sessions)

```
id, tenant_id, bank_account_id, period_id, statement_import_id,
beginning_balance, ending_balance_per_book, ending_balance_per_bank, difference,
status ('in_progress'|'reconciled'|'voided'), reconciled_by_user_id, reconciled_at
```

### 3.11 `accounting_fx_rates`

```
id, tenant_id, from_currency, to_currency, rate, rate_date, source ('manual'|'oer'|'ecb'),
fetched_at
```

### 3.12 `accounting_recurring_je`

```
id, tenant_id, name, frequency, next_run_date, template_je_payload_json,
auto_post (boolean), created_by_user_id, active
```

### 3.13 `accounting_external_links` (QB / Xero round-trip)

```
id, tenant_id, system ('quickbooks_online'|'xero'),
internal_table, internal_id, external_id,
last_synced_at, sync_direction ('out'|'in'|'both'), conflict_status
```

---

## 4. Permissions

| Slug | Description |
|---|---|
| `accounting.view` | View accounting data |
| `accounting.coa.view` / `accounting.coa.manage` | Chart of accounts |
| `accounting.je.create` | Draft a journal entry |
| `accounting.je.approve` | Approve a JE awaiting approval |
| `accounting.je.post` | Post a JE |
| `accounting.je.reverse` | Reverse a posted JE |
| `accounting.period.adjust` | Adjust soft-closed period |
| `accounting.period.close` | Close a period |
| `accounting.period.reopen` | Reopen a closed period (high-bar permission) |
| `accounting.bank.manage` | Manage bank accounts |
| `accounting.bank.reconcile` | Run bank reconciliations |
| `accounting.fx.manage` | Manage FX rates |
| `accounting.recurring.manage` | Manage recurring JEs |
| `accounting.import.run` | Run QB/Xero imports |
| `accounting.export.run` | Export to QB/Xero |
| `accounting.reports.view` | Trial balance, P&L, balance sheet, cash flow |
| `accounting.reports.export` | Export reports |
| `accounting.audit.view` | View accounting audit log |

`default_roles`: `master_admin`, `tenant_admin`, `admin`. Strict SoD between `je.create` / `je.approve` / `je.post` / `period.close` / `period.reopen`.

---

## 5. API surface

### 5.1 COA
- `GET|POST|PATCH /api/accounting/coa`
- `POST /api/accounting/coa/import-template` — body `{template: 'services_agency'|'saas'|'staffing'}` for new tenant onboarding

### 5.2 Journal entries
- `GET /api/accounting/je` — filters: `period_id`, `status`, `source_module`, `account_id`, `placement_id`, `from`, `to`
- `GET /api/accounting/je/{id}` — header + lines
- `POST /api/accounting/je` — manual JE draft
- `POST /api/accounting/je/from-payload` — internal endpoint subledgers call to post
- `POST /api/accounting/je/{id}/approve` / `.../post` / `.../reverse` / `.../void`

### 5.3 Periods
- `GET|POST /api/accounting/periods`
- `POST /api/accounting/periods/{id}/soft-close` / `.../close` / `.../reopen`

### 5.4 Bank rec
- `GET|POST|PATCH /api/accounting/bank-accounts`
- `POST /api/accounting/bank-statements/import` — multipart OFX/QFX/CSV
- `POST /api/accounting/reconciliations` — start a session
- `POST /api/accounting/reconciliations/{id}/auto-match` — exact + fuzzy matching
- `POST /api/accounting/reconciliations/{id}/manual-match` — body `{statement_line_id, je_line_id}`
- `POST /api/accounting/reconciliations/{id}/finalize`

### 5.5 FX / recurring / dimensions
- `GET|POST /api/accounting/fx-rates`
- `GET|POST|PATCH /api/accounting/recurring-je`
- `GET|POST|PATCH /api/accounting/dimensions/{type}/values`

### 5.6 QB / Xero
- `POST /api/accounting/integrations/quickbooks/connect` — OAuth start
- `POST /api/accounting/integrations/quickbooks/callback` — OAuth complete
- `POST /api/accounting/integrations/quickbooks/sync` — body `{direction, scope}`
- (Same shape for `xero`.)

### 5.7 Reports
- `GET /api/accounting/reports/trial-balance?period_id=...&basis=accrual|cash`
- `GET /api/accounting/reports/income-statement?period_range=...&basis=...&compare_to=...`
- `GET /api/accounting/reports/balance-sheet?as_of=...&basis=...`
- `GET /api/accounting/reports/cash-flow?period_range=...`
- `GET /api/accounting/reports/general-ledger?account_id=...&period_range=...`
- `GET /api/accounting/reports/dimension-pivot?dim=placement_id&from=...&to=...`

---

## 6. UI / sidebar

| Route | Label | Permission |
|---|---|---|
| `dashboard` | Accounting Dashboard | `accounting.view` |
| `coa` | Chart of Accounts | `accounting.coa.view` |
| `journal` | Journal Entries | `accounting.je.create` |
| `periods` | Period Close | `accounting.period.close` |
| `bank` | Bank Accounts | `accounting.bank.manage` |
| `reconcile` | Bank Reconciliation | `accounting.bank.reconcile` |
| `recurring` | Recurring JEs | `accounting.recurring.manage` |
| `fx` | FX Rates | `accounting.fx.manage` |
| `integrations` | QuickBooks / Xero | `accounting.import.run` |
| `reports` | Financial Reports | `accounting.reports.view` |

---

## 7. Audit events

`accounting.coa.*`, `accounting.je.*` (drafted/approved/posted/reversed/voided),
`accounting.period.*` (opened/soft_closed/closed/reopened/adjusted),
`accounting.bank.*` (account_created/statement_imported/reconciled),
`accounting.fx.*`, `accounting.recurring.*`,
`accounting.integrations.*` (connected/synced/disconnected),
`accounting.report.*` (exported — for sensitive exports).

`accounting.period.reopened` is a high-severity event (explicit alert to master_admin).

---

## 8. AI usage

MVP: none. Phase B candidates (advisory only):
- **Bank rec auto-match**: AI suggests matches for unmatched bank lines based on description / amount / payee history. Human confirms.
- **JE coding suggestion**: for manual JEs, AI proposes accounts based on description + history. Human accepts or overrides.
- **Period-close anomaly summary**: AI narrates unusual variances vs prior period for tenant_admin review.
- **GL search natural language**: "show me all consulting revenue from Acme last quarter" → translates to a query.

Hard rule: AI never directly creates, posts, approves, or reverses a JE.

---

## 9. Validation rules

- JE total debits = total credits (in functional currency) to the cent.
- Posting blocked if `accounting_periods.status IN ('soft_closed', 'closed')` for the JE's period.
- Reversing a JE must produce equal-and-opposite lines (auto-generated, but auditable).
- COA account `is_active=false` rejects new postings.
- Bank rec cannot finalize while difference != 0 (or with explicit override + reason).
- Currency translation requires a non-stale FX rate (rate_date within tenant tolerance, default 7 days).

---

## 10. Multi-tenancy

- All tables filter by `tenant_id`.
- COA fully tenant-owned.
- Bank account numbers app-encrypted (KMS).
- QB/Xero OAuth tokens stored via Core MailService pattern (encrypted at app layer).

---

## 11. Decisions inherited / locked

1. ✅ All-PHP backend, single tenant currency operationally (Placements) — Accounting supports historical multi-currency JEs.
2. ✅ Posted entries immutable; corrections by reversal.
3. ✅ Posting always accrual; cash-basis is a reporting toggle.
4. ✅ Subledgers (Billing/AP/Payroll) own their tables; Accounting owns the GL.
5. ✅ S3 attachments via Core StorageService; mail/integrations via Core MailService.

---

## 12. Open questions

1. **Starter COA templates** — which industries to ship at MVP? Recommend "services_agency" (matches user's staffing focus). Add SaaS, manufacturing later.
2. **Period-close order** — must subledgers (Billing, AP, Payroll) be closed before GL period closes, or do they all close simultaneously? Recommend subledger-first ordering enforced by close workflow.
3. **Multi-entity / consolidations** — does a tenant ever have multiple legal entities to consolidate? If yes, this needs an `accounting_entities` table and changes throughout. Recommend defer to Phase C unless you say otherwise.
4. **Fiscal year flexibility** — calendar year only at MVP, or arbitrary fiscal years (e.g. July–June)? Recommend arbitrary (it's small marginal effort).
5. **QB / Xero direction at MVP** — full bidirectional sync, one-way out (CoreFlux → QB), or one-way in (import legacy)? Recommend Phase B for any direction; MVP just exposes a CSV export of GL/TB to make manual handoff possible.
6. **AI in Phase B** — confirm OK.
7. **Plaid integration** for bank statement auto-import — Phase B?

---

## 13. MVP cut list

**Phase A:**
- Fiscal calendar + periods + COA + JE + JE lines
- Manual JE draft → approve → post → reverse
- Posting endpoint subledgers call (`/from-payload`)
- Trial balance, P&L, balance sheet, GL by account
- Period close / reopen (with audit)
- Cash vs accrual reporting toggle
- Multi-currency at JE header level
- CSV export for manual QB/Xero handoff

**Phase B:**
- Bank accounts + statement import (OFX/CSV) + reconciliation
- Recurring JEs
- Dimensions (class/dept/location/project pivots)
- Plaid bank import
- FX rate auto-fetch (OER / ECB)
- AI assists (bank rec match, JE coding, anomaly narrative)

**Phase C:**
- QuickBooks Online + Xero bidirectional sync
- Multi-entity consolidations
- 4-4-5 / 13-period calendars
- Advanced reporting (custom report builder)

---

*Binding once signed off.*
