# CoreFlux NORTH_STAR — Live Books Rails Architecture

**Source doc:** `CoreFlux_Full_Live_Books_Rails_Architecture_INTEGRATED.docx` (uploaded 2026-02-13).
**Status:** Phase 0 = approved. Phase 1 = scheduled to begin 2026-02-14.

## Core philosophy
> "The business operates, and the books maintain themselves."

CoreFlux is NOT bookkeeping software with AI features. CoreFlux is **an AI-native operational financial intelligence platform where financial intelligence emerges automatically from business operations.**

Traditional systems answer "What happened?".
CoreFlux must answer: Why did it happen? What caused it? What changes next? What action should be taken? What risk exists? What financial consequence emerges?

## North Star processing flow

```
User/System Action
  → Source Object
    → Canonical Business Event           (validated against registry)
      → Financial Reasoning Layer
        → AI Interpretation              (proposed JE + confidence + evidence)
          → Proposed Accounting / Treasury / Tax Consequences
            → Review / Approval Workflow (exception queue if low-confidence)
              → Ledger / Subledger Posting
                → Live Financial State Update
                  → Reporting / Treasury / Tax / Forecasting / AI Actions
```

## Hard rules (MUST)
- **All journal entries must balance.**
- **All postings must trace to events.** No JE without a parent event.
- **All reversals must link to originals.**
- **All AI decisions must remain attached** to the event they interpreted (and the JE they produced).
- **Locked periods require explicit override.**
- **AI must always remain explainable and auditable.**

## Phased rollout (approved 2026-02-13)

### Phase 0 — Backup (done 2026-02-13)
`/app/memory/backups/pre_live_books_rails_20260513_130735.tar.gz` (468 MB, 2096 files, git d272ba6).

### Phase 1 — Kernel rails (begin 2026-02-14)
**Step 1 (tomorrow's start):** document the canonical event list — 50+ events × full payload schemas, BEFORE any code. User wants schema sign-off first.

After sign-off, build:
- a. `event_registry` — declarative, versioned, validated at emit time.
- b. `accounting_ai_interpretations` — 1:1 with each event; proposed JE, confidence, evidence ptrs, reasoning text, reviewer disposition.
- c. `event_lineage` — parent/child causal chain (`contract_signed → invoice_issued → payment_received → revenue_recognized → commission_paid → tax_calculated`).
- d. `unified_exception_queue` — single inbox over low-confidence AI, missing docs, unusual amounts, new vendors, period-locked attempts, duplicate risk, related-party flags.
- e. `evidence_attachments` — canonical pivot replacing per-module attachment tables.

**Backwards-compat directive (user confirmed):** migrate each legacy emit to validate against the registry AS PART of Phase 1. No parallel emit paths. Bigger risk, faster overall.

### Phase 2 — AI Interpretation Layer (~4-6 weeks)
- AI proposes JE for every untyped event (currently rules-only).
- Confidence score + evidence per proposed JE.
- Reviewer correction → memory store → improved future confidence.
- "Explain this entry" surface on every JE.

### Phase 3 — Live Financial State Cache (~3-4 weeks)
- Materialized `live_financial_state(tenant, entity)` — cash, AR, AP, payroll liab, sales tax liab, inventory, debt, equity, runway, est. tax exposure.
- Incremental update on each event.
- All dashboards drilling to the same number.

### Phase 4 — Module emission migration (ongoing)
- Refactor Staffing, AP, AR, Treasury, Payroll, Dunning, Placements to emit events ONLY (no direct GL writes).
- Deprecate legacy posting paths.

---

## Canonical objects (Section 4-5 of doc)

### Platform foundation
- Tenant, Entity, User (humans + AI agents), Roles/Permissions, Modules, Audit Layer.

### Financial objects
Chart of Accounts, Ledger Accounts, Bank Accounts, Credit Cards, Loans, Equity Accounts, Fixed Assets, Inventory Items, Tax Accounts.

### Operational objects
Customers, Vendors, Employees, Contractors, Projects, Departments, Products, Services, Contracts, Subscriptions, Purchase Orders, Bills, Invoices, Receipts, Payroll Runs, Timesheets.

### Evidence objects
Uploaded documents, OCR results, Emails, Receipts, Statements, Contracts, AI reasoning records, Approval notes.

## Canonical event (every event MUST include)
- `entity_id`
- `source_module`
- `source_object_type` + `source_object_id`
- `counterparty_type` + `counterparty_id` (nullable)
- `amount` + `currency`
- `dimensions` (entity, department, location, project, customer, vendor, employee, product, service, contract, tax_category, cost_center, class)
- `timestamps` (occurred_at, recorded_at, effective_at)
- `documents[]`
- `status`
- `creator_user_id` (or `creator_agent_id` for AI)
- `workflow_state`
- `parent_event_id` (for lineage)

## Required subledgers (Section 8)
AR, AP, Payroll, Inventory, Fixed Assets, Loans, Revenue, Projects, Sales Tax, Intercompany, Equity.

## Workflow lifecycle states (Section 11)
`created → classified → matched → interpreted → review_required → approved → posted → reconciled → locked`

## Exception queue triggers
low confidence, missing documents, unusual amounts, new vendors, policy violations, related-party transactions, duplicate risk, locked periods.

## Already-exists infrastructure (Sprint 7+ work — reuse, don't rebuild)
| Capability | Existing artifact |
|---|---|
| Event table | `accounting_events` (migration 015) |
| Posting rules | `accounting_posting_rules` (016) + `core/posting_engine/formula.php` |
| Journal templates | `accounting_journal_templates` (017) |
| Subledger links | `accounting_subledger_links` (018) |
| Posting engine | `core/posting_engine/process.php` |
| Workflow engine | `core.workflow_engine.v1` feature flag |
| Approval tokens | `core/approval_tokens.php` |
| AI agents (5) | bookkeeper / recon / treasury_analyst / cfo / tax |
| Dimensions | `accounting.dimensions.engine` |
| Multi-entity scope | `core.active_entity.session`, entity_scoped JEs/periods/close |
| Audit log | `api/audit_log.php` + CSV export |
| Period states / close | `core.workflow_engine.v1` + close workflow UI |

**Implication:** Phase 1 layers a stricter contract on top of existing infrastructure rather than replacing it. The `accounting_events` table gets a non-null FK to `event_registry`. The `core/posting_engine/process.php` becomes one of several consumers of the new validated event stream.

## Long-term differentiation
- Shared financial rails
- Explainable AI accounting
- Operational event intelligence
- Live books
- Integrated treasury
- Embedded workflows
- Unified evidence
- Continuous learning
- Operational financial graph intelligence
