# CoreFlux Sprint 7 — "Up-to-Spec" PRD

**Status:** APPROVED PLAN — implementation starts with 7a
**Target:** Bring in-house Accounting + Treasury to v1.0 of `CoreFlux_CoreAccounting_CoreTreasury_Master_Spec` (this fork, 2026-02)
**Total estimated effort:** 18–22 dev-days, 7 independently-shippable sub-sprints (7a–7g). 7h (Inventory + Restaurant) deferred until vertical demand.
**Source of truth:** the master spec text quoted inline below; gaps measured against `/app` codebase as of Sprint 6k.

---

## 0. Guiding rules (re-stated from spec §3)

1. CoreFlux owns the native GL.
2. **Posted ledger lines are immutable.**
3. Every posted journal entry must balance.
4. Every JE belongs to one tenant + one entity.
5. Dimensions live at the journal-line level.
6. **Modules generate accounting events, not direct ledger writes.** ← biggest rule we currently violate.
7. Treasury is a first-class companion to Accounting.
8. Reports derive from posted ledger lines (balance snapshots are perf only).
9. AI cannot bypass accounting controls.
10. Every material action is audited.
11. External systems are data sources or sync targets, never the accounting brain.

The Sprint-7 deliverables are designed so every existing direct `accountingPostJe()` call eventually flows through the event layer (Sprint 7b) without breaking the posted history we already have.

---

## 1. Sprint 7a — Foundations (2–3 days)

### 1.1 Goals
Cleanup-first. Without these, every later sprint either fails idempotency tests or violates the spec on its first commit.

### 1.2 Deliverables

| # | Item | Tables / files touched | Acceptance |
|---|---|---|---|
| 1 | **Period state machine** | `accounting_periods.status` ENUM extended to `('open','soft_closed','closed','locked')`. New columns `closed_at`, `closed_by`, `locked_at`, `locked_by`. | New tx matrix: open→soft_closed→closed→locked→reopen(open). State transitions enforced in `modules/accounting/api/periods.php`. Smoke test asserts the matrix. |
| 2 | **Required system accounts seed** | New migration `011_system_accounts_seed.sql` that idempotently inserts the 17 spec accounts per tenant (Cash, Clearing, Receivable, AP, Payroll Liability, Sales Tax Payable, Retained Earnings, Opening Balance Equity, Suspense, Uncategorized Income, Uncategorized Expense, Rounding Gain/Loss, Intercompany Receivable, Intercompany Payable, Bank Fees Expense, Interest Income, Interest Expense). Each carries `is_system_account=1`. | `tests/sprint7a_system_accounts_smoke.php` asserts all 17 names exist after migration on a fresh tenant. |
| 3 | **Entity `accounting_basis` enum** | `accounting_entities.accounting_basis ENUM('cash','accrual','modified_cash')` default `accrual`. | Smoke checks column shape. UI dropdown in entity admin (PR3 of 7a). |
| 4 | **Permission inventory** | `core/RBAC.php` defaults extended with the spec §36 list:<br>`accounting.reverse_entry`, `accounting.reopen_period`, `accounting.manage_dimensions`, `accounting.manage_posting_rules`, `treasury.execute_payment`, `treasury.approve_transfer`, `treasury.create_transfer`, `treasury.manage_forecast`, `ai.view_recommendations`, `ai.approve_actions`, `ai.configure_agents`, `ai.enable_auto_execute`. | `tests/sprint7a_permissions_smoke.php` asserts `RBAC::hasPermission()` recognises each perm string. |
| 5 | **Soft-closed override flow** | `accounting_journal_entries.soft_close_override_reason` (TEXT NULL). When posting into a `soft_closed` period, require the user to have `accounting.post_entry_override` and supply a reason — emit audit event `accounting.je.soft_close_override`. | Test: posting to soft_closed without override perm → 403; with override + reason → 201 + audit row. |

### 1.3 Out of scope for 7a
- Restating closed-period reversal mechanics (lives in 7f).
- AI permission modes (lives in 7g).

### 1.4 Migrations
- `modules/accounting/migrations/011_period_states.sql`
- `modules/accounting/migrations/012_system_accounts_seed.sql`
- `modules/accounting/migrations/013_entity_accounting_basis.sql`
- `modules/accounting/migrations/014_je_soft_close_override.sql`

### 1.5 Smoke tests
- `tests/sprint7a_period_states_smoke.php`
- `tests/sprint7a_system_accounts_smoke.php`
- `tests/sprint7a_permissions_smoke.php`

### 1.6 Definition of done
All 4 smokes green, all prior 89 smokes still green, Vite rebuilt, `.deploy-version` synced, PRD §1 ticked off in CHANGELOG.

---

## 2. Sprint 7b — Accounting Events + Posting Rules Engine (4–5 days)

### 2.1 Goals
Land the architectural keystone the rest of the spec hinges on. Convert at least one existing direct-post path (Treasury bank-feed) to the event layer as a vertical-slice proof.

### 2.2 Deliverables

| # | Item | Tables / files | Acceptance |
|---|---|---|---|
| 1 | **`accounting_events` table** | New migration. Columns per spec §12: `id, tenant_id, entity_id, event_type, source_module, source_record_id, event_date, payload (JSON), status ENUM('received','mapped','posted','failed','ignored'), journal_entry_id, error_message, created_at, updated_at`. UNIQUE (`tenant_id`,`source_module`,`source_record_id`,`event_type`) for idempotency per spec §12. | Insert same source twice → 2nd insert hits unique. Smoke. |
| 2 | **`posting_rules` + `journal_templates` tables** | Migrations per spec §13. `posting_rules` keyed on `event_type` + optional `entity_id`, with `priority`, JSON `conditions`, `journal_template_id`, `status`. `journal_templates` + `journal_template_lines` (account_selector, debit_formula, credit_formula, description_template, dimensions JSON). | A rule + template inserted via API can be selected via `findMatchingPostingRule(tenantId, event)`. Smoke. |
| 3 | **Sandboxed formula evaluator** | New `core/posting_engine/formula.php`. Supports `+`, `-`, `*`, `/`, `%`, parentheses, numeric literals, single-key payload references like `payload.amount`. **No PHP eval, no callable, no string concat injection.** | Smokes parse 30+ valid expressions and reject 20+ malicious ones (PHP code, file paths, etc.). |
| 4 | **`accountingProcessEvent()` chokepoint** | New `core/posting_engine/process.php`. Steps: (a) load event, (b) find matching rule (highest priority), (c) render template into `JournalEntry` + `JournalLine[]` via the formula evaluator, (d) post via existing `accountingPostJe()`, (e) update `accounting_events.status='posted'` + `journal_entry_id`, on throwable update `status='failed'` + `error_message`. Idempotent. | E2E smoke: insert event → call process → JE posted, balanced, traceable back via `accounting_events.journal_entry_id`. |
| 5 | **`subledger_links` table** | Per spec §18. Insert one link per posted JE: `(source_module, source_record_id) → journal_entry_id`. | Constraint: one source record can map to multiple JEs (bill + payment + reversal); enforce composite unique on `(source_module, source_record_id, journal_entry_id)` only. |
| 6 | **API endpoints** | Per spec §38: `POST /api/accounting/events` (create + immediately attempt to map+post), `GET /api/accounting/events`, `POST /api/accounting/events/:id/post` (re-attempt for `failed` events). Routes via `api/accounting_events.php` — full RBAC. | curl smokes via `tests/sprint7b_event_layer_smoke.php`. |
| 7 | **Vertical-slice migration: Treasury bank-feed** | Replace direct `accountingPostJe()` call in `modules/treasury/api/account_transactions.php?action=categorize_and_post` with: emit event `treasury.bank_transaction.matched` → engine posts via rule. Keep current behaviour as a fallback for 1 release. | All existing treasury tests still green. New test asserts bank-feed posts now flow via events. |

### 2.3 Edge cases handled
- Event arrives with no matching rule → status=`ignored`, error_message=`no rule matched`. UI banner offers "draft a rule" action.
- Rule template references a missing account → status=`failed`, error_message names the missing account.
- Idempotent re-process: calling `process` on already-`posted` event is a no-op.
- Period closed at posting time → status=`failed` + clear message; event is **kept**, not lost.

### 2.4 Migrations
- `modules/accounting/migrations/015_accounting_events.sql`
- `modules/accounting/migrations/016_posting_rules.sql`
- `modules/accounting/migrations/017_journal_templates.sql`
- `modules/accounting/migrations/018_subledger_links.sql`

### 2.5 Smoke tests
- `tests/sprint7b_event_layer_smoke.php` (idempotency, lifecycle, error paths, treasury vertical slice)
- `tests/sprint7b_formula_engine_smoke.php` (50+ expression cases)
- `tests/sprint7b_posting_rules_smoke.php` (priority, condition matching)

### 2.6 Out of scope for 7b
- Migrating AP / AR / Payroll / Time → event layer (Sprint 7e).
- Restaurant events (Sprint 7h).

---

## 3. Sprint 7c — Treasury Core (3–4 days)

### 3.1 Goals
Add the `treasury_payments` + `treasury_transfers` models the spec requires. Stand up Cash Position + Liquidity Forecast reports. All Treasury writes flow through the Sprint 7b event layer.

### 3.2 Deliverables

| # | Item | Tables / files | Acceptance |
|---|---|---|---|
| 1 | **`treasury_payments` table** | Per spec §15. 8-state machine: `draft→pending_approval→approved→scheduled→executed→failed→voided` (+ rejected). Columns: `payee_type` (vendor/employee/customer/tax_authority/other), `payee_id`, `amount`, `currency`, `payment_date`, `bank_account_id`, `journal_entry_id`. | Approval flow via existing `WorkflowEngine`. Execute → emits `treasury.payment.executed` event → engine posts JE (Dr AP/Liability, Cr Cash). |
| 2 | **`treasury_transfers` table** | Per spec §15. Fields: `source_bank_account_id`, `destination_bank_account_id`, `amount`, `currency`, `transfer_date`, `status`, `journal_entry_id`. Internal vs intercompany detected via entity match. | Internal transfer → 2-line JE (Dr Cash-Dest, Cr Cash-Source). Intercompany → 2 mirror JEs (Entity A: Dr Intercompany Recv / Cr Cash; Entity B: Dr Cash / Cr Intercompany Pay). |
| 3 | **Treasury event types** | Hook the 12 spec events: `treasury.bank_transaction.imported`, `bank_fee.detected`, `interest.received`, `payment.approved`, `payment.executed`, `receipt.matched`, `transfer.initiated`, `transfer.completed`, `debt_draw.recorded`, `debt_payment.made`, `cash_sweep.completed`, `reconciliation.completed`. Each ships with a default posting-rule template. | Smoke asserts: every event_type has a default rule + template seeded per tenant. |
| 4 | **`/api/treasury/payments` endpoints** | List, create (draft), approve (+RBAC), execute (+RBAC). | Smoke. |
| 5 | **`/api/treasury/transfers` endpoints** | List, create, approve, execute. | Smoke. |
| 6 | **Cash Position report** | `GET /api/treasury/reports/cash-position?as_of=YYYY-MM-DD`. Returns per-bank-account: GL cash balance, last reconciled date, outstanding payments, expected receipts (next 7 days). | UI: new `TreasuryCashPosition.jsx` tab with sortable table. |
| 7 | **Liquidity Forecast report (basic)** | `GET /api/treasury/reports/liquidity-forecast?days=30`. Walks posted-AR aging + posted-AP aging, applies historical avg-days-to-pay. **No AI yet** — that's 7g's AI Treasury Analyst. | Returns daily projected balance per bank account. |

### 3.3 Migrations
- `modules/treasury/migrations/005_treasury_payments.sql`
- `modules/treasury/migrations/006_treasury_transfers.sql`
- `modules/treasury/migrations/007_treasury_event_rules_seed.sql`

### 3.4 Smoke tests
- `tests/sprint7c_treasury_payments_smoke.php`
- `tests/sprint7c_treasury_transfers_smoke.php`
- `tests/sprint7c_cash_position_smoke.php`
- `tests/sprint7c_liquidity_forecast_smoke.php`

### 3.5 Out of scope for 7c
- Real bank-rail execution (Plaid Transfer / NACHA) — already env-gated; Sprint 7c only writes the model + emits the event. Driver wiring is a separate creds-blocked task.

---

## 4. Sprint 7d — Spec-Compliant API Surface (2 days)

### 4.1 Goals
Adopt the spec §38 URL paths verbatim so external partners (the eventual AI agents, mobile, Apideck-style integrators) hit the contract the spec promises.

### 4.2 Deliverables

| # | Item | Files | Acceptance |
|---|---|---|---|
| 1 | **`/api/accounting/*` aliases** | New `api/accounting/journal-entries.php` (router that maps to existing module endpoints). Same for `accounts`, `events`, `periods`, `dimensions`, `reports/*`. | Path-style URL works; returns identical payload to the legacy `/modules/accounting/api/...` URL. Both kept for 1 release. |
| 2 | **JE lifecycle endpoints split** | Today `journal_entries.php` mixes draft/post into a single POST with `?action=`. New REST shape per spec §38:<br>`POST /api/accounting/journal-entries/draft`<br>`POST /api/accounting/journal-entries/:id/approve`<br>`POST /api/accounting/journal-entries/:id/post`<br>`POST /api/accounting/journal-entries/:id/reverse`<br>Old query-string variant kept as a thin redirect wrapper. | Smoke asserts each canonical URL works; legacy URLs still 200 (back-compat). |
| 3 | **`/api/treasury/*` aliases** | Same pattern. `bank-accounts`, `bank-transactions`, `payments`, `transfers`, `reconciliations`, `reports/cash-position`, `reports/liquidity-forecast`. | Smoke. |
| 4 | **`/api/ai/financial/*` aliases** | Add stub endpoints: `recommendations` (list pending AI suggestions across modules), `:id/approve`, `:id/reject`. Backed by `ai_suggestions` table we already have. | Smoke. |

### 4.3 Out of scope for 7d
- Renaming any DB tables. Only HTTP surface.
- Removing legacy URLs (give a 1-release deprecation window).

---

## 5. Re-plan moment after 7d

After 7a→7d ship and pass tests, the team re-plans 7e–7g together with the new architecture in hand. The PRD already drafts them (sections below) but the order may shift based on what we learn from the event-layer cutover.

---

## 6. Sprint 7e — Module Event-Layer Migration (3 days, future)

Stop direct `accountingPostJe()` calls in:
- AP module (bills posted, payments cleared)
- Billing module (invoices sent, payments received)
- Payroll module (run posted)
- Time module (settlement → AR/AP/Payroll bundle posting)

Each module gets a posting-rule set. Backwards compat: `accountingPostJe()` becomes a thin `accountingProcessEvent('manual.je', payload)` wrapper.

---

## 7. Sprint 7f — Reports + Tax (2–3 days, future)

- GL Detail report
- Dimensional P&L (`group_by` query param: department, location, project, etc.)
- `tax_mappings` table + UI: map account → tax form line (1120, 1065, 1040 Sch C)
- Auto-reversing accruals helper (`accountingPostAccrual(JE, reverse_on_date)` → schedules its own reversal JE)
- Deferred revenue helper (`accountingDeferRevenue(amount, recognize_over_months)` → schedules N monthly recognition entries)

---

## 8. Sprint 7g — AI Agent Suite (4–5 days, future, can ship in slices)

Each agent ships independently. Shape per spec §31:
```
AIAuditRecord {
  agent_name, model_name, model_version, action_type,
  confidence_score, reasoning_summary, source_records[],
  proposed_action, approved_by, executed_at
}
```

| Agent | Status today | What ships in 7g |
|---|---|---|
| AI Bookkeeper | Partial (bank-rule AI) | Unified categorize across bank feed + AP bills + uncategorized JEs |
| AI Reconciliation | Partial (suggest_match in bank_ai.php) | Full match queue + duplicate detection + stale-item suggestions |
| AI Close Manager | ✓ shipped (6e) | Extend with anomaly detection + missing-task recommendation |
| AI AP Manager | Partial (risk + extract) | Add duplicate-invoice detection + payment-timing suggestion |
| AI AR Manager | ❌ none | Overdue prioritisation + payment-timing prediction + revenue-leakage flagging |
| AI Treasury Analyst | ❌ none | Cash forecasting (better than 7c's basic) + liquidity risk + transfer recommendations |
| AI CFO Agent | ❌ none | Variance analysis + KPI summary + monthly board narrative |
| AI Tax Agent | ❌ none | Tax mapping suggestions + book-to-tax adjustment detection |
| AI Audit & Compliance | ✓ shipped (6i) | Extend window options + per-user trend |
| AI Forecasting | ❌ none | Revenue / cash / labor / expense forecast envelopes |

**AI Permission Modes** (spec §35) land here too: `view_only / recommendation_only / draft_only / approval_required / auto_execute`. New table `ai_agent_configs (tenant_id, agent_name, mode, enabled_by_user_id)`.

---

## 9. Sprint 7h (deferred) — Inventory + Restaurant

Wait for vertical demand. Specs in §22 + §24 are already detailed enough to build straight from when needed.

---

## 10. Risk register

| Risk | Mitigation |
|---|---|
| Migrating modules to event layer breaks existing posted history | Keep `accountingPostJe()` as a private API; route through events at a higher level. Direct posts from migrations / seed / system events still work. |
| Posting-rule mistakes in production | All rule edits emit audit events; `dry_run` mode on `POST /api/accounting/events` returns the rendered JE without inserting. |
| Sandboxed formula engine has edge cases | 50+ smoke tests, restricted grammar, malicious-input library. |
| Spec-compliant URL aliases double the surface area | 1-release deprecation window, both URLs covered by smokes. |
| Treasury Payment without a real bank rail is "useless" | Phase 1 of 7c just stands up the model; rail driver (Plaid Transfer / NACHA) is a separate creds-gated PR. |

---

## 11. CHANGELOG entries (will be appended on each sprint completion)

- `[Sprint 7a]` …
- `[Sprint 7b]` …
- `[Sprint 7c]` …
- `[Sprint 7d]` …

(see `/app/memory/PRD.md` "Recently completed" log for canonical format)

---

**Owner:** main agent, with user-side approval gates after 7a, 7b, 7c, 7d.
**Smoke-test discipline:** custom PHP CLI suite remains the source of truth. No external testing subagent.
**First commit:** Sprint 7a foundations.
