# CoreFlux Canonical Event Registry — Draft v1

**Status:** DRAFT — awaiting user sign-off before any code is written.
**Authority:** This document IS the contract for Phase 1 of the Live Books Rails architecture (NORTH_STAR.md). Every module emits ONLY events listed here. The `event_registry` table will be seeded from this doc. Mismatches between code and this doc fail at emit time.

---

## Conventions

### Event naming
`<domain>.<entity>.<action>` — all lowercase, dots, snake_case where needed.
Examples: `ap.bill.approved`, `treasury.bank_transaction.matched`, `staffing.worker_hours.approved`.

### Versioning
Every event has a `schema_version` integer (default 1). Breaking payload changes increment the version. The registry stores both versions side-by-side during transition.

### Standardized top-level fields (apply to EVERY event)
| Field                    | Type      | Required | Notes                                                                       |
|--------------------------|-----------|----------|-----------------------------------------------------------------------------|
| `tenant_id`              | bigint    | Y        | injected by api_bootstrap, NOT in payload                                   |
| `entity_id`              | bigint    | Y        | legal entity scope; 0 = tenant root                                         |
| `event_type`             | string    | Y        | from this registry                                                          |
| `schema_version`         | int       | Y        | defaults to 1                                                               |
| `source_module`          | string    | Y        | `ap`, `billing`, `treasury`, `payroll`, `staffing`, `accounting`, etc.      |
| `source_record_id`       | string    | Y        | `<module>:<id>` (e.g. `ap_bill:1234`)                                       |
| `event_date`             | date      | Y        | financial date — drives the GL period                                       |
| `occurred_at`            | datetime  | N        | when the real-world action happened (defaults to NOW())                     |
| `effective_at`           | datetime  | N        | when financial effect begins (deferred recognition use case)                |
| `amount`                 | decimal   | event-specific | top-level money amount (currency normalized to `currency`)             |
| `currency`               | char(3)   | event-specific | ISO 4217                                                              |
| `counterparty_type`      | string    | N        | `customer` / `vendor` / `employee` / `contractor` / `internal_entity` / etc. |
| `counterparty_id`        | bigint    | N        | FK into the corresponding subject table                                     |
| `dimensions`             | json      | N        | `{ department, location, project, product, service, contract, cost_center, class, tax_category }` |
| `documents[]`            | json      | N        | `[{ type, id, hash, label }]` — pointers into `evidence_attachments`        |
| `parent_event_id`        | bigint    | N        | causal lineage — see Section "Lineage" below                                |
| `creator_user_id`        | bigint    | N        | the human who triggered it                                                  |
| `creator_agent_id`       | string    | N        | the AI agent ID if AI-triggered                                             |
| `workflow_state`         | string    | N        | `pending_review` / `approved` / `posted` / `reconciled` / `locked`          |
| `payload`                | json      | Y        | event-specific structured fields below                                      |

### Counterparty type vocabulary
`customer`, `vendor`, `employee`, `contractor`, `internal_entity`, `bank`, `tax_authority`, `lender`, `investor`, `regulator`, `system`, `unknown`.

### Status lifecycle (Section 11 of architecture doc)
`created → classified → matched → interpreted → review_required → approved → posted → reconciled → locked`

### Lineage (parent_event_id) — examples
- `contract.signed` → spawns `ar.invoice.issued` events as work delivers
- `ar.invoice.issued` → spawns `ar.payment.received`
- `ar.payment.received` → spawns `ar.cash.applied`
- `ap.bill.approved` → spawns `ap.payment.executed`
- `payroll.run.approved` → spawns `payroll.tax_liability.accrued` + `payroll.cash.disbursed`

---

## 1. Capital / Equity

| # | Event | Required payload | Counterparty | Triggers | Already emit? | Accounting hint |
|---|-------|-----------------|--------------|----------|---------------|-----------------|
| 1.1 | `capital.contribution.received` | `{ amount, currency, contribution_type: cash\|in_kind\|note, equity_account_id }` | investor | Owner/investor injects capital | N | Dr cash / Cr equity (members' capital) |
| 1.2 | `capital.distribution.paid` | `{ amount, currency, distribution_type, equity_account_id }` | investor | Owner takes a distribution | N | Dr equity / Cr cash |
| 1.3 | `capital.equity_grant.issued` | `{ amount, vesting_start, vesting_end, instrument_type }` | employee | Employee equity grant issued | N | memo (no GL until exercise) |
| 1.4 | `capital.equity.repurchased` | `{ amount, currency, shares_or_units }` | investor | Treasury stock / buyback | N | Dr treasury stock / Cr cash |
| 1.5 | `capital.note.issued` | `{ principal, rate, term_months, lender_party_id }` | lender | Company issues a note payable to a lender | N | Dr cash / Cr notes payable |

## 2. Sales / AR Cycle

| # | Event | Required payload | Counterparty | Triggers | Already emit? | Accounting hint |
|---|-------|-----------------|--------------|----------|---------------|-----------------|
| 2.1 | `sales.contract.signed` | `{ contract_id, total_value, currency, billing_schedule, term_start, term_end }` | customer | Master service agreement / SOW countersigned | N | memo (kicks off lineage) |
| 2.2 | `sales.subscription.activated` | `{ subscription_id, mrr, currency, term, customer_id }` | customer | Recurring subscription goes live | N | memo + spawns recurring `ar.invoice.issued` |
| 2.3 | `ar.invoice.drafted` | `{ invoice_id, total, currency, lines[] }` | customer | Invoice created but not sent | N | none yet |
| 2.4 | `ar.invoice.issued` ⟵ (renamed from `billing.invoice.sent`) | `{ invoice_id, invoice_number, total, currency, lines[], due_date }` | customer | Invoice sent to customer | **Y** (as `billing.invoice.sent`) | Dr AR / Cr revenue (per-line) |
| 2.5 | `ar.payment.received` ⟵ (was `billing.payment.received`) | `{ payment_id, amount, currency, method, invoice_ids[] }` | customer | Customer pays | **Y** | Dr cash / Cr AR |
| 2.6 | `ar.cash.applied` | `{ payment_id, application_id, amount, invoice_id }` | customer | Payment applied to specific invoice | N (handled inline) | application-level (no GL) |
| 2.7 | `ar.credit_memo.issued` | `{ memo_id, amount, currency, lines[], reason }` | customer | Credit memo issued | N | Dr revenue / Cr AR |
| 2.8 | `ar.writeoff.recorded` | `{ invoice_id, amount, currency, reason }` | customer | Invoice written off as bad debt | N | Dr bad debt / Cr AR |

## 3. Procurement / AP Cycle

| # | Event | Required payload | Counterparty | Triggers | Already emit? | Accounting hint |
|---|-------|-----------------|--------------|----------|---------------|-----------------|
| 3.1 | `ap.po.issued` | `{ po_id, total, currency, lines[], vendor_id }` | vendor | PO issued | N | memo / commitment tracking |
| 3.2 | `ap.bill.received` | `{ bill_id, total, currency, lines[] }` | vendor | Bill arrived but not approved | N | none yet |
| 3.3 | `ap.bill.approved` | `{ bill_id, internal_ref, amount, currency, lines[] }` | vendor | Bill approved for payment | **Y** | Dr expense / Cr AP |
| 3.4 | `ap.bill.rejected` | `{ bill_id, reason }` | vendor | Bill rejected | N | none |
| 3.5 | `ap.payment.scheduled` | `{ bill_id, payment_date, amount, currency, method }` | vendor | Payment queued in treasury | N | memo |
| 3.6 | `ap.payment.executed` ⟵ (was `treasury.payment.executed`) | `{ payment_id, bill_ids[], amount, currency, method, bank_ref }` | vendor | Payment actually sent | **Y** (split between treasury.payment.executed + ap.payment.cleared) | Dr AP / Cr cash |
| 3.7 | `ap.payment.cleared` | `{ payment_id, cleared_date, bank_transaction_id }` | vendor | Bank confirms payment cleared | **Y** | reclass (clearing → cash) |
| 3.8 | `ap.vendor_credit.received` | `{ credit_id, amount, currency, reason }` | vendor | Vendor issues credit memo | N | Dr AP / Cr expense |

## 4. Treasury / Cash Movement

| # | Event | Required payload | Counterparty | Triggers | Already emit? | Accounting hint |
|---|-------|-----------------|--------------|----------|---------------|-----------------|
| 4.1 | `treasury.transfer.completed` | `{ from_bank_account_id, to_bank_account_id, amount, currency }` | bank | Internal bank transfer | **Y** | Dr cash dest / Cr cash src |
| 4.2 | `treasury.intercompany.transfer.completed` | `{ from_entity_id, to_entity_id, amount, currency }` | internal_entity | Inter-entity cash move | **Y** | Dr cash + IC receivable / Cr cash + IC payable |
| 4.3 | `treasury.bank_transaction.matched` | `{ bank_txn_id, internal_ref, amount, currency, match_type }` | bank | Reconciliation match | **Y** | reclass or memo |
| 4.4 | `treasury.bank_transaction.unmatched` | `{ bank_txn_id, amount, currency, description }` | bank | Unmatched bank line → exception | N | exception queue |
| 4.5 | `treasury.bank_fee.detected` | `{ bank_txn_id, fee_amount, currency, fee_type }` | bank | Bank fee booked | **Y** | Dr bank fees / Cr cash |
| 4.6 | `treasury.interest.received` | `{ bank_txn_id, amount, currency }` | bank | Interest credited to deposit account | **Y** | Dr cash / Cr interest income |
| 4.7 | `treasury.interest.paid` | `{ loan_id, amount, currency, period }` | lender | Interest paid on loan | N | Dr interest expense / Cr cash |
| 4.8 | `treasury.loan.payment.made` | `{ loan_id, principal, interest, total, currency }` | lender | Loan repayment | N | Dr notes payable + interest exp / Cr cash |
| 4.9 | `treasury.fx.revaluation.recorded` | `{ pair, rate, exposure_amount, gain_or_loss }` | bank | Month-end FX reval | N | Dr/Cr unrealized FX |
| 4.10 | `treasury.cash.received_uncategorized` | `{ bank_txn_id, amount, currency, description }` | unknown | Cash hit the account, source unknown | N | suspense — Dr cash / Cr suspense |

## 5. Payroll / Workforce

| # | Event | Required payload | Counterparty | Triggers | Already emit? | Accounting hint |
|---|-------|-----------------|--------------|----------|---------------|-----------------|
| 5.1 | `payroll.run.calculated` | `{ run_id, period_start, period_end, gross, taxes, net, employee_count }` | employee (many) | Payroll run computed | N | memo |
| 5.2 | `payroll.run.approved` | `{ run_id, gross, taxes, net, currency }` | employee (many) | Run approved | N | Dr wages + ER taxes / Cr accrued payroll + tax liab |
| 5.3 | `payroll.cash.disbursed` | `{ run_id, amount, currency, bank_account_id }` | employee (many) | Net pay actually sent | N | Dr accrued payroll / Cr cash |
| 5.4 | `payroll.tax_liability.accrued` | `{ run_id, jurisdiction, amount, currency }` | tax_authority | Employer + withheld taxes | N | (already in 5.2) |
| 5.5 | `payroll.tax_liability.paid` | `{ jurisdiction, period, amount, currency, bank_ref }` | tax_authority | Payroll taxes remitted | N | Dr payroll tax liab / Cr cash |
| 5.6 | `payroll.fringe.accrued` | `{ run_id, fringe_type, amount, currency }` | employee | Health/401k/PTO accrual | N | Dr fringe exp / Cr accrued fringe |
| 5.7 | `payroll.401k.contribution.remitted` | `{ run_id, employee_portion, employer_match, custodian }` | employee | 401k remittance | N | Dr accrued 401k / Cr cash |
| 5.8 | `payroll.ptd.adjustment` | `{ employee_id, adjustment_type, hours_or_amount }` | employee | Retro / true-up / PTO take | N | Dr or Cr accrued PTO |

## 6. Staffing-specific (Operational layer on Payroll/AR)

| # | Event | Required payload | Counterparty | Triggers | Already emit? | Accounting hint |
|---|-------|-----------------|--------------|----------|---------------|-----------------|
| 6.1 | `staffing.worker.placed` | `{ placement_id, person_id, client_id, engagement_type, start_date, bill_rate, pay_rate }` | customer + employee | New placement | N | memo / starts revenue lineage |
| 6.2 | `staffing.worker_hours.submitted` | `{ timesheet_id, person_id, period_start, period_end, total_hours }` | customer | Weekly timesheet submitted | N | none |
| 6.3 | `staffing.worker_hours.approved` | `{ timesheet_id, person_id, placement_id, hours, revenue, cost, is_w2, is_1099, is_c2c, is_internal }` | customer + worker | Timesheet approved | **Y** | Dr unbilled-AR or labor cost / Cr accrued payroll or accrued AP |
| 6.4 | `staffing.placement.ended` | `{ placement_id, person_id, end_date, reason }` | customer + worker | Placement closes | N | memo |
| 6.5 | `staffing.worker.classification_changed` | `{ person_id, from_type, to_type, effective_date }` | worker | W2 ↔ 1099 / C2C reclass | N | memo — flag year-end re-issue |

## 7. Inventory / Fixed Assets

| # | Event | Required payload | Counterparty | Triggers | Already emit? | Accounting hint |
|---|-------|-----------------|--------------|----------|---------------|-----------------|
| 7.1 | `inventory.received` | `{ po_id, sku, qty, unit_cost, currency }` | vendor | Goods received | N | Dr inventory / Cr GR/IR |
| 7.2 | `inventory.consumed` | `{ sku, qty, project_id?, cost_center? }` | system | Sale or production draw | N | Dr COGS / Cr inventory |
| 7.3 | `fixed_asset.acquired` | `{ asset_id, cost, currency, useful_life_months, in_service_date }` | vendor | Asset placed in service | N | Dr fixed asset / Cr cash or AP |
| 7.4 | `fixed_asset.depreciation.recorded` | `{ asset_id, period, amount, method }` | system | Monthly depreciation | N | Dr deprec exp / Cr accum deprec |

## 8. Tax

| # | Event | Required payload | Counterparty | Triggers | Already emit? | Accounting hint |
|---|-------|-----------------|--------------|----------|---------------|-----------------|
| 8.1 | `tax.sales_tax.collected` | `{ invoice_id, jurisdiction, amount, currency, rate }` | tax_authority | Tax collected on AR invoice | N | Dr AR / Cr sales tax liab |
| 8.2 | `tax.sales_tax.remitted` | `{ jurisdiction, period, amount, currency, return_id }` | tax_authority | Sales tax filed/paid | N | Dr sales tax liab / Cr cash |
| 8.3 | `tax.income_tax.estimated_payment` | `{ jurisdiction, period, amount, currency }` | tax_authority | Quarterly estimated | N | Dr prepaid tax / Cr cash |

## 9. Period / Close

| # | Event | Required payload | Counterparty | Triggers | Already emit? | Accounting hint |
|---|-------|-----------------|--------------|----------|---------------|-----------------|
| 9.1 | `period.close.initiated` | `{ period_start, period_end, initiated_by_user_id }` | system | Month-end kickoff | N | memo |
| 9.2 | `period.close.locked` | `{ period_start, period_end, locked_by_user_id }` | system | Period locked | N | memo |
| 9.3 | `period.close.reopened` | `{ period_start, period_end, reopened_by_user_id, reason }` | system | Period reopened | N | memo / requires override |
| 9.4 | `period.close.adjustment.recorded` | `{ je_id, period_end, adjustment_type, amount }` | system | Top-side close adjustment | N | varies |

## 10. Reversals / Adjustments

| # | Event | Required payload | Counterparty | Triggers | Already emit? | Accounting hint |
|---|-------|-----------------|--------------|----------|---------------|-----------------|
| 10.1 | `accounting.je.reversed` | `{ original_je_id, reversal_je_id, reason }` | system | Reversal posted | N | mirrors original w/ flipped signs |
| 10.2 | `accounting.je.corrected` | `{ original_je_id, correction_je_id, reason, fields_changed[] }` | system | Correction posted | N | net adjustment |
| 10.3 | `accounting.ai.interpretation_overridden` | `{ event_id, ai_proposed_je_id, human_corrected_je_id, reviewer_user_id }` | system | Reviewer overrides AI | N | learning input — feeds AI memory store |

---

## Event count summary
- Capital/Equity: 5
- Sales/AR: 8
- Procurement/AP: 8
- Treasury: 10
- Payroll: 8
- Staffing: 5
- Inventory/Assets: 4
- Tax: 3
- Period/Close: 4
- Reversals/Adjustments: 3

**Total: 58 canonical events.**
**Today CoreFlux emits 7** (2.4, 2.5, 3.3, 3.6, 4.1, 4.2, 4.3, 4.5, 4.6, 6.3 — though 4.1 and 4.2 are partially overlapping).

---

## What gets built once this is signed off (Phase 1a-e per NORTH_STAR)

### Phase 1a — `event_registry` table
Seeded from this doc. Schema:
```sql
event_type            VARCHAR(120) PK
schema_version        INT          PK
domain                VARCHAR(40)
description           TEXT
required_payload_keys JSON         -- list of dotted keys that must be present
counterparty_type     VARCHAR(40)  -- nullable
expected_consumers    JSON         -- modules subscribing (e.g. ['accounting','treasury'])
parent_event_types    JSON         -- valid parent types for lineage
typical_accounting    TEXT         -- the Dr/Cr hint above (for AI grounding)
deprecated_at         DATETIME NULL
```

### Phase 1b — `accounting_ai_interpretations`
1:1 with each `accounting_events.id`. Stores AI's proposed JE + confidence + evidence ptrs + reasoning + reviewer disposition.

### Phase 1c — `event_lineage`
`(parent_event_id, child_event_id, relationship_type)` — populated automatically when `parent_event_id` is set on emit. Enables "drill to source" + AI's "why did this happen?" answers.

### Phase 1d — `unified_exception_queue` view
One inbox over: low-confidence AI, missing documents, unusual amounts, new vendors, related-party flags, period-locked attempts, duplicate risk.

### Phase 1e — `evidence_attachments` canonical pivot
Replaces ad-hoc bill_documents, ap_attachments, etc. `(event_id, document_type, document_id, hash, label, attached_at)`.

### Phase 1f — Backwards-compat migration (per user direction)
Each legacy emit site rewritten to validate against this registry as part of Phase 1. **No parallel emit paths.** Specifically:
- `billing.invoice.sent` → renamed to **`ar.invoice.issued`** (legacy name remains as `deprecated_at` alias for 1 release cycle).
- `billing.payment.received` → renamed to **`ar.payment.received`**.
- `treasury.payment.executed` → renamed to **`ap.payment.executed`** with payload restructure.
- `staffing.worker_hours.approved` → no rename, payload aligns to registry.
- All other current emits keep current names (already canonical).

### Test plan
A new smoke test, `event_registry_contract_smoke.php`, asserts:
1. Every event in this doc has a row in `event_registry`.
2. Every `accountingProcessEvent` call site in the codebase emits an `event_type` that exists in the registry.
3. No code emits an event type not in the doc.
4. Every required_payload_key actually appears in the corresponding emit site.

---

## OPEN QUESTIONS FOR USER

1. **Subscription / contract events (2.1, 2.2)** — Do you do MSAs / SOWs / subscriptions today, or is everything single-PO/single-bill? If no, we can defer these.

2. **Equity events (1.x)** — Are owner contributions/distributions a real flow for you, or a backlog item? Today CoreFlux has no equity module.

3. **Inventory (7.1-2)** — Staffing firms typically don't carry inventory. Skip entirely?

4. **Fixed assets (7.3-4)** — Do you book depreciation, or is everything expensed? If expensed, defer.

5. **FX (4.9)** — Do you transact in any non-USD currencies?

6. **PO-driven AP (3.1)** — Do you issue formal POs, or just receive bills?

7. **Naming check** — I renamed `billing.invoice.sent` → `ar.invoice.issued` to bring it in line with the doc's domain convention. Comfortable with that, or keep current legacy names? (Doesn't affect prod data — only the event_type string going forward.)

8. **Should I add anything else** that's specific to your business that isn't in the canonical 58?

---

**Next step after sign-off:** Build Phase 1a (event_registry table + seed from this doc) and the `event_registry_contract_smoke.php` enforcement test.
