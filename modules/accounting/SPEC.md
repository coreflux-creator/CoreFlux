# Accounting — Module Specification (v1.0)

**Status**: DRAFT — pending user sign-off
**Stack**: PHP 8 (per HARD_RULES decisions log)
**Owner module of**: enterprise GL engine — multi-entity, multi-currency, dimensions, advanced allocations, intercompany, consolidation, period-close workflow, audit-ready immutable journal entries, financial reporting.
**NOT owner of**: subledger detail (Billing AR, AP, Payroll, future RevRec). They post INTO Accounting via standardized protocol.
**Sidebar grouping**: Finance.

This SPEC supersedes the earlier Accounting draft. v1.0 is enterprise-grade — not a starter bookkeeping tool. Per user direction, "ignore micro-frontend / separate-domain / SFTP-deploy" sections of the source brief; this module is embedded in the current core platform like any other CoreFlux module.

---

## 1. Purpose

CoreFlux Accounting is **the authoritative ledger and control layer** for the platform. It supports complex entities, multiple currencies, deep segment reporting, formal close controls, advanced allocations, intercompany accounting, consolidated reporting, and audit-ready immutable history.

It is the financial backbone of the broader CoreFlux ERP. Subledgers (Billing, AP, Payroll, future RevRec, Inventory, Lease) all flow into Accounting via a standardized posting protocol. Outside systems (QB, Xero, future SAP/Oracle) integrate via versioned APIs and HMAC-signed events.

---

## 2. Core principles (locked v1.0)

1. **Multi-entity is foundational, not optional.** A tenant can have 1..N legal entities, each with its own base currency, fiscal calendar, COA mappings, and period close. Consolidation rolls them up.
2. **Posted journals are immutable.** Corrections via reversing JEs only. No destructive edits, ever.
3. **Double-entry, balanced.** Debits = credits to the cent in functional currency.
4. **Posting always accrual.** Cash-basis is a reporting toggle, not a posting toggle.
5. **Period close is a workflow**, not a date lock. Tasks, owners, due dates, packets, flux/variance review.
6. **Dimensions are first-class.** Posted lines carry account + amount + dimensions; account-level rules can REQUIRE dimensions; dimension-level security applies.
7. **Idempotency on all system-generated postings.** Subledgers MUST send an idempotency key; duplicate posts return the prior result.
8. **HMAC-signed outbound webhooks** for every JE post / reverse / period close — consumers can subscribe.
9. **Audit everything.** Create, edit, post, approve, reopen, close, reverse, delete-like — every action with before/after diff, actor, timestamp, IP, request metadata, cross-app request ID.
10. **Subledgers own their detail; Accounting owns the GL.** No subledger writes directly to GL tables.

---

## 3. Data model

### 3.1 Multi-entity

#### `accounting_entities` (legal entities under a tenant)

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `code` | VARCHAR(40) | tenant-defined; UNIQUE per tenant |
| `legal_name` | VARCHAR(255) | |
| `country` | CHAR(2) | |
| `base_currency` | CHAR(3) | functional currency for this entity |
| `default_fiscal_calendar_id` | BIGINT FK | |
| `default_chart_id` | BIGINT NULL FK | when COA is entity-specific; NULL = uses tenant-shared COA |
| `parent_entity_id` | BIGINT NULL FK | for ownership hierarchy snapshots |
| `tax_id_ct` | VARBINARY(256) NULL | encrypted EIN |
| `kms_key_version` | VARCHAR(64) | |
| `address_json` | TEXT NULL | |
| `active` | BOOLEAN | |
| `created_at` / `updated_at` | DATETIME | |

#### `accounting_entity_groups` (consolidation groups)

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `code` | VARCHAR(40) | UNIQUE per tenant |
| `name` | VARCHAR(200) | |
| `group_currency` | CHAR(3) | reporting currency for the group |
| `consolidation_method_default` | ENUM('full','proportionate','equity_method') | |
| `active` | BOOLEAN | |

#### `accounting_entity_group_members` (effective-dated ownership table)

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `group_id` | BIGINT FK | |
| `entity_id` | BIGINT FK | |
| `consolidation_method` | ENUM('full','proportionate','equity_method') | overrides group default |
| `ownership_pct` | DECIMAL(7,4) | for proportionate / NCI |
| `effective_from` | DATE | |
| `effective_to` | DATE NULL | |
| `notes` | TEXT NULL | |

### 3.2 Fiscal calendars + periods

#### `accounting_fiscal_calendars`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `entity_id` | BIGINT NULL FK | NULL = tenant default; entity override allowed |
| `name` | VARCHAR(120) | "FY2026 Calendar" |
| `calendar_type` | ENUM('calendar_year','custom_fiscal','4_4_5','13_period') | |
| `start_date` / `end_date` | DATE | |
| `period_count` | INT | usually 12 or 13 |
| `period_definition_json` | TEXT | per-period start/end |
| `is_default` | BOOLEAN | one TRUE per entity |
| `active` | BOOLEAN | |

#### `accounting_periods`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `entity_id` | BIGINT FK | per-entity period |
| `calendar_id` | BIGINT FK | |
| `period_number` | INT | |
| `start_date` / `end_date` | DATE | |
| `status` | ENUM('future','open','soft_closed','closed','reopened') | |
| `close_workflow_id` | BIGINT NULL FK→`accounting_close_workflows.id` | |
| `closed_at` / `closed_by_user_id` | DATETIME / FK NULL | |
| `reopened_at` / `reopened_by_user_id` / `reopen_reason` | DATETIME / FK / VARCHAR(500) NULL | |

### 3.3 Period close workflow

#### `accounting_close_workflows`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `entity_id` | BIGINT FK | |
| `period_id` | BIGINT FK | |
| `status` | ENUM('not_started','in_progress','blocked','complete','approved') | |
| `template_id` | BIGINT NULL FK | which template was instantiated |
| `started_at` / `completed_at` / `approved_at` | DATETIME NULL | |
| `approved_by_user_id` | BIGINT NULL FK | |

#### `accounting_close_tasks`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `workflow_id` | BIGINT FK | |
| `task_code` | VARCHAR(80) | e.g. `bank_rec_complete`, `accruals_posted`, `flux_review` |
| `label` | VARCHAR(200) | |
| `assignee_user_id` | BIGINT NULL FK | |
| `due_at` | DATETIME NULL | |
| `status` | ENUM('open','in_progress','blocked','done','skipped') | |
| `completed_at` | DATETIME NULL | |
| `completed_by_user_id` | BIGINT NULL FK | |
| `evidence_storage_object_ids_json` | TEXT NULL | attached supporting docs |
| `notes` | TEXT NULL | |
| `order_index` | INT | |

#### `accounting_close_task_templates`

```
id, tenant_id, name, description, tasks_json (array of task definitions),
applies_to_entity_filter ('all' | entity_ids[]), is_default, active
```

#### `accounting_close_packets` (the deliverable bundle)

```
id, workflow_id, packet_pdf_storage_object_id, included_reports_json,
prepared_at, prepared_by_user_id, signed_off_at, signed_off_by_user_id
```

### 3.4 Chart of Accounts

#### `accounting_chart_of_accounts`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `entity_id` | BIGINT NULL FK | NULL = tenant-shared COA |
| `code` | VARCHAR(40) | tenant/entity scope; UNIQUE within scope |
| `name` | VARCHAR(200) | |
| `description` | VARCHAR(500) NULL | |
| `account_type` | ENUM('asset','liability','equity','revenue','expense','cogs','other_income','other_expense','contra_asset','contra_liability','contra_revenue','contra_expense','statistical') | |
| `subcategory` | VARCHAR(80) NULL | "Current Assets", "Cost of Services", etc. |
| `normal_balance` | ENUM('dr','cr') | derived from account_type but stored for fast checks |
| `parent_account_id` | BIGINT NULL FK→self | hierarchy |
| `currency` | CHAR(3) | usually entity base; can override |
| `cash_flow_tag` | ENUM('operating','investing','financing','none') | for indirect cash flow report |
| `external_qb_id` / `external_xero_id` | VARCHAR(80) NULL | round-trip mapping |
| `reporting_classification_json` | TEXT NULL | flexible mapping for custom reports |
| `is_bank_account` | BOOLEAN | drives bank-rec UX |
| `is_intercompany` | BOOLEAN | drives intercompany routing |
| `intercompany_partner_required` | BOOLEAN | when true, postings to this account must declare intercompany_partner_entity_id |
| `is_postable` | BOOLEAN | summary-only accounts have FALSE |
| `is_blocked` | BOOLEAN | temporarily blocked from posting |
| `block_reason` | VARCHAR(500) NULL | |
| `requires_approval_above` | DECIMAL(14,2) NULL | per-account threshold approval |
| `required_dimensions_json` | TEXT NULL | array of dimension type codes that MUST be present on a posting line, e.g. `["department","project"]` |
| `allowed_dimension_combos_json` | TEXT NULL | optional whitelist of value combinations |
| `active` | BOOLEAN | |

#### `accounting_coa_starter_templates` (platform-managed)

```
id, name (e.g. 'staffing_services_us', 'saas_us', 'manufacturing_us', 'professional_services_us'),
country, accounts_json (full COA blueprint), version, published_at
```

Tenant onboarding can clone a template into their COA.

### 3.5 Dimensions

#### `accounting_dimension_types`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `code` | VARCHAR(40) | `department`, `location`, `class`, `project`, `customer`, `vendor`, `product`, `entity_partner` |
| `label` | VARCHAR(120) | |
| `is_hierarchical` | BOOLEAN | |
| `is_required_globally` | BOOLEAN | when true, all postings must declare a value |
| `validation_regex` | VARCHAR(120) NULL | |
| `active` | BOOLEAN | |

#### `accounting_dimension_values`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `entity_id` | BIGINT NULL FK | NULL = tenant-wide; entity override allowed |
| `type_id` | BIGINT FK | |
| `code` | VARCHAR(80) | UNIQUE within (tenant, type, scope) |
| `name` | VARCHAR(200) | |
| `parent_value_id` | BIGINT NULL FK→self | hierarchy |
| `external_ref` | VARCHAR(120) NULL | for source-module linkage (e.g. `placement_id:318`) |
| `active` | BOOLEAN | |

#### `accounting_dimension_security`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `dimension_value_id` | BIGINT FK | |
| `subject_type` | ENUM('user','role') | |
| `subject_id` | BIGINT | user_id or role_id |
| `access` | ENUM('read','write','none') | |
| `effective_from` / `effective_to` | DATE | |

### 3.6 Currencies + FX

#### `accounting_currencies` (master list)

```
id, code (ISO 4217), name, decimal_places, symbol, active
```

#### `accounting_fx_rates`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `from_currency` | CHAR(3) | |
| `to_currency` | CHAR(3) | |
| `rate_type` | ENUM('spot','closing','average','historical','manual') | |
| `rate` | DECIMAL(18,8) | |
| `rate_date` | DATE | |
| `period_id` | BIGINT NULL FK | when rate_type='closing'/'average', tied to a period |
| `source` | ENUM('manual','oer','ecb','bank') | |
| `fetched_at` | DATETIME | |

#### `accounting_fx_revaluation_runs`

```
id, tenant_id, entity_id, period_id, run_type ('period_end_revaluation'|'manual'),
status ('draft'|'posted'|'reversed'),
revaluation_je_id (FK to journal), unrealized_gl_amount, cta_amount,
run_at, run_by_user_id
```

### 3.7 Journal entries

#### `accounting_journal_entries` (header)

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `entity_id` | BIGINT FK | |
| `je_number` | VARCHAR(40) | tenant+entity sequence; UNIQUE per (tenant, entity) |
| `transaction_date` | DATE | |
| `period_id` | BIGINT FK | resolved at posting |
| `je_type` | ENUM('standard','recurring','reversing','accrual','deferral','statistical','allocation','intercompany','consolidation','elimination','fx_revaluation','system_subledger','import_external') | |
| `source_module` | ENUM('billing','ap','payroll','manual','recurring','reversal','adjustment','allocation','consolidation','intercompany','fx_reval','import') | |
| `source_ref_type` | VARCHAR(40) NULL | |
| `source_ref_id` | BIGINT NULL | |
| `external_ref` | VARCHAR(255) NULL | external drill-through (QB/Xero id, etc.) |
| `idempotency_key` | VARCHAR(120) NULL | UNIQUE per (tenant, entity, source_module) when set; required for system posts |
| `description` | VARCHAR(500) | header memo |
| `transaction_currency` | CHAR(3) | |
| `functional_currency` | CHAR(3) | entity base |
| `fx_rate_used` | DECIMAL(18,8) NULL | |
| `total_debits` | DECIMAL(14,2) | functional |
| `total_credits` | DECIMAL(14,2) | functional |
| `status` | ENUM('draft','pending_approval','posted','reversed','voided') | |
| `requires_approval` | BOOLEAN | derived from rules |
| `approval_chain_id` | BIGINT NULL FK→`accounting_approval_chains.id` | snapshot of chain when JE created |
| `posted_by_user_id` / `posted_at` | BIGINT FK NULL / DATETIME NULL | |
| `reverses_je_id` | BIGINT NULL FK | for reversing entries |
| `reversed_by_je_id` | BIGINT NULL FK | back-reference |
| `attachment_storage_object_ids_json` | TEXT NULL | linked source docs in S3 |
| `cross_app_request_id` | VARCHAR(80) NULL | platform-wide correlation id |
| `created_by_user_id` | BIGINT FK | |
| `created_at` / `updated_at` | DATETIME | |

Indexes: `(tenant_id, entity_id, je_number)` UNIQUE, `(tenant_id, entity_id, period_id, status)`, `(tenant_id, source_module, source_ref_type, source_ref_id)`, `(tenant_id, idempotency_key)` UNIQUE WHERE NOT NULL, `(tenant_id, transaction_date, status)`.

#### `accounting_journal_entry_lines`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `je_id` | BIGINT FK | |
| `line_no` | INT | |
| `account_id` | BIGINT FK | |
| `dr_or_cr` | ENUM('dr','cr') | |
| `transaction_amount` | DECIMAL(14,2) | doc currency |
| `functional_amount` | DECIMAL(14,2) | translated |
| `description` | VARCHAR(500) NULL | line memo |
| `intercompany_partner_entity_id` | BIGINT NULL FK→`accounting_entities.id` | required when account is intercompany |
| `tax_code_id` | BIGINT NULL FK | |
| `reconciliation_id` | BIGINT NULL FK→`accounting_reconciliations.id` | for bank-rec |

#### `accounting_je_line_dimensions` (one row per dimension value per line)

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `je_line_id` | BIGINT FK | |
| `dimension_type_id` | BIGINT FK | |
| `dimension_value_id` | BIGINT FK | |

This many-to-many shape supports unlimited dimensions per line and avoids `dim1`/`dim2`/`dim3` columns.

### 3.8 Approvals

#### `accounting_approval_rules`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `scope` | ENUM('threshold','account','entity','dimension','je_type','combo') | |
| `applies_when_json` | TEXT | structured predicate, e.g. `{ "entity_id": 5, "amount_gte": 10000 }` |
| `required_role` | VARCHAR(80) NULL | who must approve |
| `required_user_id` | BIGINT NULL FK | specific user |
| `level` | INT | 1..N (multi-level chains) |
| `same_user_blocked` | BOOLEAN | maker/checker enforcement |
| `active` | BOOLEAN | |

#### `accounting_approval_chains` (snapshot of rules applied to a specific JE)

```
id, tenant_id, je_id, rules_snapshot_json, current_level, status
```

#### `accounting_approval_actions`

```
id, chain_id, level, actor_user_id, action ('approved'|'rejected'|'recalled'),
note, acted_at, ip
```

### 3.9 Allocations

#### `accounting_allocation_rule_sets`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `name` | VARCHAR(200) | |
| `entity_id` | BIGINT NULL FK | scope; NULL = cross-entity allocation |
| `effective_from` / `effective_to` | DATE | |
| `run_order` | INT | order of execution within a run |
| `auto_reverse` | BOOLEAN | |
| `tolerance` | DECIMAL(10,4) | for reciprocal convergence |
| `active` | BOOLEAN | |

#### `accounting_allocation_rules`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `rule_set_id` | BIGINT FK | |
| `method` | ENUM('fixed_pct','driver_based','financial_basis','statistical','step_down','reciprocal') | |
| `source_filter_json` | TEXT | accounts/dimensions/entities to pull amounts from |
| `target_distribution_json` | TEXT | where to send and at what proportions |
| `driver_id` | BIGINT NULL FK→`accounting_allocation_drivers.id` | for driver-based |
| `exclusions_json` | TEXT NULL | accounts/dimensions to skip |
| `notes` | TEXT NULL | |

#### `accounting_allocation_drivers`

```
id, tenant_id, code (e.g. 'headcount','sqft','revenue','units','prior_month_actuals','ytd_balance'),
label, source ('statistical_account'|'dimension_count'|'gl_balance'|'external_input'),
config_json, active
```

#### `accounting_allocation_runs`

```
id, tenant_id, period_id, rule_set_id, status ('preview'|'posted'|'reversed'),
preview_payload_json, je_id (when posted), trace_payload_json (full reconciliation),
run_at, run_by_user_id
```

### 3.10 Intercompany

#### `accounting_intercompany_mappings`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `entity_a_id` | BIGINT FK | |
| `entity_b_id` | BIGINT FK | |
| `due_to_account_id` | BIGINT FK→`accounting_chart_of_accounts.id` | A's payable to B |
| `due_from_account_id` | BIGINT FK | A's receivable from B |
| `auto_balance` | BOOLEAN | auto-create balancing entry in counterpart entity |
| `notes` | TEXT NULL | |
| `active` | BOOLEAN | |

#### `accounting_intercompany_transactions`

```
id, tenant_id, originating_entity_id, partner_entity_id, originating_je_id,
counterpart_je_id (nullable, populated when auto_balance), txn_type ('ar_ap'|'loan'|'revenue_cogs'),
amount, currency, status ('open'|'matched'|'eliminated'), created_at
```

### 3.11 Consolidation

#### `accounting_consolidation_runs`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `group_id` | BIGINT FK→`accounting_entity_groups.id` | |
| `period_id` | BIGINT FK | one per group per period |
| `status` | ENUM('draft','translated','eliminated','complete','reversed') | |
| `translation_completed_at` | DATETIME NULL | |
| `elimination_completed_at` | DATETIME NULL | |
| `cta_amount` | DECIMAL(14,2) NULL | cumulative translation adjustment |
| `nci_amount` | DECIMAL(14,2) NULL | non-controlling interest |
| `pre_elimination_payload_storage_object_id` | BIGINT NULL FK | |
| `post_elimination_payload_storage_object_id` | BIGINT NULL FK | |
| `run_at` / `run_by_user_id` | DATETIME / FK | |

#### `accounting_consolidation_eliminations`

```
id, run_id, elimination_type ('intercompany_arap'|'intercompany_loan'|'revenue_cogs'|'upi'),
related_intercompany_txn_ids_json, je_id (the elimination JE),
amount, currency, notes
```

#### `accounting_upi_rollforward` (unrealized profit in inventory)

```
id, run_id, prior_run_id, opening_balance, additions, eliminations, closing_balance
```

### 3.12 Bank reconciliation (in v1.0; matches source brief — bank rec moves to v1.0 not 1.2 since enterprise tenants need it)

#### `accounting_bank_accounts`

(See earlier v0 draft — same shape, plus `entity_id` FK now.)

#### `accounting_bank_statement_imports` / `_lines` / `accounting_reconciliations`

(Same as v0 draft, scoped to entity.)

### 3.13 Recurring journal entries

```
accounting_recurring_je: id, tenant_id, entity_id, name, frequency, next_run_date,
template_je_payload_json, auto_post (boolean), created_by_user_id, active
```

### 3.14 External integrations / webhooks

#### `accounting_external_links`

```
id, tenant_id, entity_id, system ('quickbooks_online'|'xero'|'csv_export'),
internal_table, internal_id, external_id,
last_synced_at, sync_direction ('out'|'in'|'both'), conflict_status, last_error
```

#### `accounting_outbound_webhook_subscriptions`

```
id, tenant_id, target_url, hmac_secret_ct (encrypted), event_filters_json
(e.g. ['je.posted','period.closed']), active, created_at
```

#### `accounting_outbound_webhook_deliveries`

```
id, subscription_id, event_type, payload_json, attempt_count, status ('pending'|'sent'|'failed'|'dlq'),
http_status, response_excerpt, sent_at, next_retry_at
```

### 3.15 Audit log (Accounting-specific extension of platform audit)

```
accounting_audit_log: id, tenant_id, entity_id, actor_user_id, action,
entity_type ('je'|'coa'|'period'|'dimension'|'allocation'|'consolidation'|'webhook'|'approval'),
entity_id (the row's id), before_json, after_json, ip, request_id, cross_app_request_id, occurred_at
```

This is in ADDITION to the platform audit log — Accounting needs richer diff/replay payloads than the platform default.

---

## 4. Permissions (RBAC)

### 4.1 Standard accounting roles (preset bundles)

- **Accountant** — view + create/edit drafts; submit for approval; cannot post.
- **Senior Accountant** — Accountant + post low-threshold JEs; reverse own posted JEs.
- **Controller** — full posting; approve threshold-based; close periods; manage COA; run allocations.
- **CFO / Admin** — full + reopen periods, run consolidations, manage approval rules.
- **Auditor (read-only)** — read everything including audit logs; cannot create or modify anything.
- **Read-only user** — read GL + reports; no audit log access.

### 4.2 Permission slugs (granular)

```
accounting.view, accounting.audit.view,
accounting.entities.view, accounting.entities.manage,
accounting.coa.view, accounting.coa.manage, accounting.coa.block,
accounting.dimensions.view, accounting.dimensions.manage, accounting.dimensions.security.manage,
accounting.je.create, accounting.je.edit_draft, accounting.je.submit,
accounting.je.approve, accounting.je.post, accounting.je.reverse, accounting.je.void,
accounting.period.view, accounting.period.close, accounting.period.reopen, accounting.period.adjust,
accounting.close_workflow.manage, accounting.close_task.assign, accounting.close_task.complete,
accounting.bank.manage, accounting.bank.reconcile,
accounting.fx.manage, accounting.fx.revalue,
accounting.recurring.manage,
accounting.allocations.manage, accounting.allocations.run,
accounting.intercompany.manage,
accounting.consolidation.run, accounting.consolidation.approve,
accounting.integrations.connect, accounting.integrations.sync,
accounting.webhooks.manage,
accounting.reports.view, accounting.reports.export
```

### 4.3 Segregation of Duties (enforced)

- `je.create` / `je.edit_draft` cannot be the same actor as `je.approve` for the same JE (configurable per tenant; default ON).
- `je.approve` cannot be the same as `je.post` if `requires_approval=true` AND `same_user_blocked=true` on the matched rule.
- `period.close` and `period.reopen` are separate slugs; reopen requires reason capture.
- Approval rules can constrain by `entity_id`, `account_id`, `amount`, `dimension_value`, `je_type`, or combos.

---

## 5. Posting protocol (the contract subledgers MUST follow)

This is the single most important interface in the platform. All subledgers (Billing, AP, Payroll, future RevRec) post via this contract.

### 5.1 Endpoint

`POST /api/v1/accounting/journal-entries`

### 5.2 Request body

```json
{
  "tenant_id": 42,
  "entity_id": 7,
  "transaction_date": "2026-02-15",
  "je_type": "system_subledger",
  "source_module": "billing",
  "source_ref_type": "billing_invoices",
  "source_ref_id": 1234,
  "external_ref": "INV-2026-0142",
  "idempotency_key": "billing.invoice.post.1234",
  "description": "Invoice INV-2026-0142 — Acme Corp",
  "transaction_currency": "USD",
  "fx_rate_date": "2026-02-15",
  "lines": [
    {
      "account_code": "1200-AR",
      "dr_or_cr": "dr",
      "transaction_amount": 12000.00,
      "description": "Acme Corp AR",
      "dimensions": {"customer": "ACME", "placement": "318"}
    },
    {
      "account_code": "4000-CONSULTING-REV",
      "dr_or_cr": "cr",
      "transaction_amount": 11428.57,
      "dimensions": {"customer": "ACME", "department": "STAFF", "placement": "318"}
    },
    {
      "account_code": "2200-SALES-TAX-PAYABLE",
      "dr_or_cr": "cr",
      "transaction_amount": 571.43,
      "dimensions": {"customer": "ACME"}
    }
  ],
  "attachments": [{"storage_object_id": 9911}],
  "cross_app_request_id": "req_abc123"
}
```

### 5.3 Response

- **200** with full posted JE on success (or matching prior result if idempotency_key already used).
- **422** with structured error envelope on validation failure (out of balance, period closed, account blocked, missing required dimension, missing intercompany partner, invalid FX, etc.).
- **409** on idempotency conflict (different payload, same key).

### 5.4 Validation order (in this order; first failure short-circuits)

1. Tenant + entity exist and active.
2. Idempotency key check — if found with same payload hash, return prior result.
3. Period status = `open` (or `soft_closed` with `period.adjust`).
4. All accounts exist, active, postable, not blocked.
5. All required dimensions present per account rules.
6. Allowed dimension combinations satisfied.
7. Intercompany accounts have `intercompany_partner_entity_id`.
8. Currency valid; FX rate available for date if multi-currency.
9. Debits = Credits in functional currency to the cent.
10. Approval rules — if any matched and not satisfied, JE created in `pending_approval` status (returns 202).
11. User has posting permission for entity + accounts + dimensions.
12. SoD check: maker ≠ approver where required.

### 5.5 Outbound webhooks (HMAC-signed)

On `je.posted`, `je.reversed`, `period.closed`, `period.reopened`, `consolidation.complete`:

```
POST <subscriber_url>
X-CoreFlux-Event: je.posted
X-CoreFlux-Signature: sha256=<hmac of body using subscription's secret>
X-CoreFlux-Delivery-Id: <uuid>
X-CoreFlux-Cross-App-Request-Id: <correlation id>
Content-Type: application/json

{...JE payload + metadata...}
```

Retry policy: exponential backoff up to 24h; DLQ after.

---

## 6. API surface (selected — full enumeration in implementation phase)

### 6.1 Entities + groups
- `GET|POST|PATCH /api/accounting/entities`
- `GET|POST|PATCH /api/accounting/entity-groups`
- `GET|POST|PATCH /api/accounting/entity-groups/{id}/members` (effective-dated)

### 6.2 COA + dimensions
- `GET|POST|PATCH /api/accounting/coa` (filter by entity)
- `POST /api/accounting/coa/clone-template?template=...`
- `POST /api/accounting/coa/{id}/block` (with reason)
- `GET|POST|PATCH /api/accounting/dimensions/types`
- `GET|POST|PATCH /api/accounting/dimensions/{type_code}/values`
- `GET|POST|PATCH /api/accounting/dimensions/{value_id}/security`

### 6.3 Periods + close
- `GET /api/accounting/periods?entity_id=...`
- `POST /api/accounting/periods/{id}/close-workflow/start?template_id=...`
- `GET|PATCH /api/accounting/close-workflows/{id}`
- `GET|PATCH /api/accounting/close-tasks/{id}`
- `POST /api/accounting/close-workflows/{id}/build-packet`
- `POST /api/accounting/periods/{id}/close`
- `POST /api/accounting/periods/{id}/reopen` (reason required)

### 6.4 Journal entries
- `GET /api/accounting/je` (rich filters: entity, period, account, dimension, status, source, je_type)
- `GET /api/accounting/je/{id}`
- `POST /api/v1/accounting/journal-entries` (subledger contract — see §5)
- `POST /api/accounting/je` (manual — UI)
- `POST /api/accounting/je/import` (CSV/XLSX with dry-run validation)
- `POST /api/accounting/je/{id}/copy` (duplicate to draft)
- `POST /api/accounting/je/{id}/submit`
- `POST /api/accounting/je/{id}/approve` / `.../reject`
- `POST /api/accounting/je/{id}/post`
- `POST /api/accounting/je/{id}/reverse`
- `POST /api/accounting/je/{id}/void`

### 6.5 FX
- `GET|POST /api/accounting/fx-rates`
- `POST /api/accounting/fx/revalue` (entity, period)

### 6.6 Allocations
- `GET|POST|PATCH /api/accounting/allocations/rule-sets`
- `POST /api/accounting/allocations/preview` (returns trace, no posting)
- `POST /api/accounting/allocations/post` (creates allocation JE)
- `GET /api/accounting/allocations/runs/{id}/trace`

### 6.7 Intercompany
- `GET|POST|PATCH /api/accounting/intercompany/mappings`
- `GET /api/accounting/intercompany/transactions?status=open` (out-of-balance candidates)
- `GET /api/accounting/intercompany/out-of-balance` (report)

### 6.8 Consolidation
- `POST /api/accounting/consolidation/run` (group, period)
- `GET /api/accounting/consolidation/runs/{id}` (with trace)
- `POST /api/accounting/consolidation/runs/{id}/approve`

### 6.9 Bank rec
- (Same as v0 draft, plus entity scope.)

### 6.10 Integrations
- `POST /api/accounting/integrations/quickbooks/connect` (OAuth)
- `POST /api/accounting/integrations/quickbooks/sync`
- (Same shape for `xero`.)
- `POST /api/accounting/export/csv?scope=tb&period_id=...`

### 6.11 Webhooks
- `GET|POST|PATCH|DELETE /api/accounting/webhooks/subscriptions`
- `GET /api/accounting/webhooks/deliveries?subscription_id=...`
- `POST /api/accounting/webhooks/deliveries/{id}/retry`

### 6.12 Reports
Standard: trial balance, GL detail, P&L, balance sheet, cash flow (indirect), JE listing, unposted JEs, approval queue, period activity, FX revaluation, allocation trace, intercompany OOB, close checklist, audit log.
Consolidated: consolidated TB, consolidated P&L, consolidated BS, pre-elim, post-elim.
Drill-through to JE → source module via `external_ref`.
Filters: entity / entity-group / period / account range / dimensions / currency view (local/base/group).
Exports: CSV, XLSX, PDF (close packet).

---

## 7. UI / sidebar

| Route | Label | Permission |
|---|---|---|
| `dashboard` | Accounting Dashboard | `accounting.view` |
| `entities` | Entities & Groups | `accounting.entities.view` |
| `coa` | Chart of Accounts | `accounting.coa.view` |
| `dimensions` | Dimensions | `accounting.dimensions.view` |
| `journal` | Journal Entries | `accounting.je.create` |
| `approval-queue` | Approval Queue | `accounting.je.approve` |
| `periods` | Periods | `accounting.period.view` |
| `close` | Period Close | `accounting.close_workflow.manage` |
| `bank` | Bank Accounts | `accounting.bank.manage` |
| `reconcile` | Bank Reconciliation | `accounting.bank.reconcile` |
| `recurring` | Recurring JEs | `accounting.recurring.manage` |
| `allocations` | Allocations | `accounting.allocations.manage` |
| `intercompany` | Intercompany | `accounting.intercompany.manage` |
| `consolidation` | Consolidation | `accounting.consolidation.run` |
| `fx` | FX Rates | `accounting.fx.manage` |
| `integrations` | QB / Xero / Webhooks | `accounting.integrations.connect` |
| `reports` | Financial Reports | `accounting.reports.view` |
| `audit` | Audit Log | `accounting.audit.view` |

---

## 8. Audit events (richer than platform default)

Every action emits a row to `accounting_audit_log` with full before/after diff:

`accounting.entity.*` (created/updated/activated/deactivated)
`accounting.coa.*` (created/updated/blocked/unblocked)
`accounting.dimension.*` (type_created/value_created/security_changed)
`accounting.je.*` (drafted/submitted/approved/rejected/posted/reversed/voided)
`accounting.period.*` (opened/soft_closed/closed/reopened/adjusted)
`accounting.close_workflow.*` (started/task_assigned/task_completed/packet_built/approved)
`accounting.bank.*` (account_added/statement_imported/reconciled)
`accounting.fx.*` (rate_added/revalued)
`accounting.recurring.*` (created/run/paused)
`accounting.allocation.*` (rule_created/previewed/posted/reversed)
`accounting.intercompany.*` (mapping_created/match_resolved/eliminated)
`accounting.consolidation.*` (started/translated/eliminated/complete/reversed)
`accounting.integration.*` (connected/synced/disconnected/error)
`accounting.webhook.*` (subscription_created/delivered/failed/retried)

---

## 9. AI usage

MVP: none. Phase 1.1+ (advisory only, never directly mutating GL):
- Bank rec auto-match suggestions
- JE coding suggestions for manual entries (account + dimensions)
- Period-close anomaly narrative ("revenue down 15% vs prior period; primarily Acme placement")
- Close packet draft narrative
- Variance / flux explanation drafts
- GL natural-language search

Hard rule (HARD_RULES): AI never posts, approves, reverses, closes, or modifies.

---

## 10. Multi-tenancy + isolation

- All tables filter by `tenant_id`.
- Most also filter by `entity_id` (per-entity isolation within a tenant).
- COA can be tenant-shared OR entity-specific.
- Dimension values can be tenant-wide OR entity-specific.
- Periods are always per-entity (entities close on their own calendars).
- Bank account / EIN / sensitive ids encrypted at app layer (KMS).
- QB/Xero OAuth tokens encrypted at app layer.

---

## 11. Decisions inherited / locked from this spec

1. ✅ Multi-entity is foundational (v1.0).
2. ✅ Dimensions are first-class (account-required, security, allowed combos).
3. ✅ Allocations advanced (driver-based, step-down, reciprocal) in v1.0.
4. ✅ Intercompany + Consolidation in v1.0.
5. ✅ Posting protocol is THE contract: idempotency key + source + external_ref required for system posts.
6. ✅ HMAC-signed outbound webhooks for subscribers.
7. ✅ Posted entries immutable; corrections via reversal.
8. ✅ Period close = workflow with tasks/owners/due-dates/packet, not just date lock.
9. ✅ Multi-currency: doc + functional + group; FX revaluation; CTA; closing/average/historical rate types.
10. ✅ Approval rules: threshold / account / entity / dimension / je_type / combos; SoD enforced.
11. ✅ Bank rec is in v1.0 (escalated from earlier draft's Phase 1.2).
12. ✅ COA starter templates platform-managed (`staffing_services_us` first).
13. ✅ AI never in mutation path.

---

## 12. Decisions locked (resolved in spec sign-off) + remaining open

### Locked
1. ✅ **Starter COA templates at v1.0**: `generic` + `staffing_services_us`. Additional verticals (SaaS, manufacturing, professional services) deferred to Phase 1.1.
2. ✅ **Approval rule evaluation = multi-level chain.** All matching rules fire in order by `level` ascending. Two-step / N-step approvals supported by stacking rules.
3. ✅ **External integrations**: CSV import/export covering ALL accounting ledgers (COA, JEs, TB, periods, dimensions, FX, allocations, intercompany, consolidation results) at v1.0. **QuickBooks + Wave** are the next priorities for native API sync (Phase 1.1). Xero deferred / removed from priority list.
4. ✅ **Cash flow statement = indirect method only** at v1.0. Direct method deferred to Phase 1.1+ if requested. `cash_flow_tag` on COA already supports indirect.
5. ✅ **Statistical accounts** kept in COA as `account_type='statistical'`. Excluded from TB / P&L / BS. Used as allocation drivers (e.g., headcount, sqft, units).
6. ✅ **Webhook delivery retention = 7 years** for `accounting_outbound_webhook_deliveries` (matches IRS retention floor; aligns with audit posture).
7. ✅ **Reopen-period guardrails**: only the most-recent closed period can be reopened by default. Tenant_admin override allowed for older periods with mandatory reason capture + extra approval (configurable in approval rules).
8. ✅ **Maker/checker = tenant setting, default OFF.** Per-tenant configuration; new tenants land with maker/checker disabled (so a one-person shop can post). Tenants with multiple finance staff toggle ON in settings.

---

## 13. MVP cut list (matches "v1.0" from source brief)

**Phase A — v1.0 (must ship together):**
- Multi-entity + entity groups + ownership table
- Fiscal calendars (calendar / custom / 4-4-5) + per-entity periods
- Period close workflow (templates, tasks, owners, due dates, packet)
- COA (hierarchical, account types incl COGS, normal balance, postable/blocked, required dimensions, approval thresholds, cash flow tags) + starter template `staffing_services_us`
- Dimensions (types, values, hierarchy, security, account-required, allowed combos)
- Journal entries (all 13 je_types) + lines + line dimensions
- Posting protocol (`POST /api/v1/accounting/journal-entries`) with idempotency, validation order, structured errors
- Approvals (threshold/account/entity/dimension/combo) with SoD enforcement
- Multi-currency (doc + functional + group, FX rates by type, FX revaluation, CTA)
- Recurring JEs
- Allocations (all 6 methods incl reciprocal, drivers, preview, trace, auto-reverse)
- Intercompany (mappings, due-to/due-from, auto-balance, OOB report)
- Consolidation (full / proportionate / equity-method, NCI, CTA, eliminations, pre/post views)
- Bank accounts + statement import + reconciliation
- HMAC outbound webhooks (delivery + retry + DLQ)
- Standard reports (TB, GL, BS, P&L, CF indirect, JE listing, unposted, approval queue, FX revaluation, allocation trace, intercompany OOB, close checklist, audit log)
- Consolidated reports (TB, BS, P&L, pre/post elim)
- CSV/XLSX/PDF exports + drill-through to source module
- Roles preset (Accountant, Senior Accountant, Controller, CFO, Auditor, Read-only)
- Accounting audit log with before/after diffs

**Phase 1.1 — usability/automation/governance:**
- Allocation UI polish, scenarios, versioning, scheduling
- Consolidation scenario comparisons, ownership restatement
- Saved report views, scheduled exports, close packet automation
- COA change request workflow, bulk COA/dimension changes with impact analysis
- Admin dashboards, AI assists (bank rec match, JE coding, anomaly narrative)
- QB Online + Xero bidirectional sync

**Phase 1.2 — broader controls:**
- Multi-book / multi-GAAP (US GAAP vs IFRS adjustment books)
- Fixed assets subledger (separate module)
- Cash management / treasury workflows
- Intercompany netting + settlement
- SAML SSO + SCIM provisioning at platform level
- Region-specific retention, localized formats

**Phase 2.0 — full ERP expansion:**
- (Note: Billing, AP, Payroll already exist as siblings under Finance — see their SPECs.)
- Revenue recognition (separate module)
- Inventory accounting
- Lease accounting
- Tax provision workflows
- Budgeting / forecasting / planning
- Procurement integration
- Contract accounting
- Data lake exports

---

## 14. Notes on excluded sections

The source brief included two sections that are explicitly out-of-scope for this SPEC per user direction:

- **Micro-frontend UX (separate domain, custom element `<cf-accounting>`, JWT handoff)** — NOT applicable. This module is built inside the current CoreFlux core platform like every other module. Standard React routes under `/modules/accounting/*`, standard CoreFlux layout, standard core API router. If we ever pursue a micro-frontend architecture later, it'll be a platform-wide decision, not an Accounting-specific one.
- **SFTP-only deploy / temporary folder rename / `deploy_once.php`** — NOT applicable. Deployment uses the existing CoreFlux Cloudways flow.

These are documented here so future agents don't accidentally interpret the source brief as binding on those points.

---

*Binding once signed off. Update before code.*
