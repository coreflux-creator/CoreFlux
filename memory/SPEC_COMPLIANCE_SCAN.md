# CoreFlux AI-Native Extension — Spec Compliance Scan

**Spec:** `CoreFlux_AI_Native_Extension_Specification_v1_artifacts_network_ai_workers.docx`
v1.2, dated 2026-05-31.

**Scanned:** 2026-02 (this session).

**Method:** Walked every deliverable in the spec's 7-phase Implementation
Roadmap against `git ls-files` + table inventory (`/tmp/all_tables.txt`,
256 unique tables across core + module migrations) + grep of the 4
target directories.

**Legend:**
- ✅ Built and matches the spec (or close enough that no rebuild needed)
- 🟡 Partially built — table exists but name/columns differ, or feature exists in code but is missing the spec-named entry point
- ❌ Missing

---

## Headline numbers

| Phase | % done | Critical gap |
|---|---|---|
| 1 — Foundation | ~75% | DB-backed `tool_registry` / `tool_permissions` tables, Artifact Layer foundation, AI audit admin UI |
| 2 — LangGraph MVP | ~75% | `vendor_aliases` table, `TransactionRecommendationCard`, `AccountingExceptionQueue` components |
| 3 — Accounting MVP | ~35% | `journal_entry_drafts` table, `draftJournalEntry`/`validateJournalEntry` tools, ExceptionQueue UI |
| 4 — Close MVP | ~35% | `accounting_close_runs` (we have packets+tasks but no run wrapper), `CloseDashboard`, `WorkflowTimeline` |
| 5 — AP/Treasury/Payroll | ~20% | Invoice extraction run table, 13-week cash forecast, timesheet anomaly detection, `PayrollReviewPacket` |
| 6 — Vertical Extensions | 0% | All 8 tables missing |
| 7 — Advanced Operations | 0% | Knowledge graph + agent registry untouched |
| **Artifact Layer (§ 2A)** | **0%** | **No `artifact_objects` / `artifact_events` / `artifact_relationships` tables. This is the spec's novel ask.** |
| **AI Worker Runtime** | **0%** | No worker job queue or runtime. Existing `ai_runs` table is synchronous-only. |

---

## Phase 1 — Foundation

Spec acceptance criterion: "Read and analysis AI runs are logged and
permission scoped."

| Spec deliverable | Status | Location / evidence |
|---|---|---|
| `ai_runs` table | ✅ | `core/migrations/090_ai_runs.sql` |
| `ai_tool_calls` table | 🟡 → renamed | `core/migrations/089_ai_tool_invocations.sql` — same shape, different name (`ai_tool_invocations`). **Decision: keep current name OR rename + add alias view.** |
| `ai_prompt_versions` table | ✅ | `core/migrations/091_ai_prompt_versions.sql` |
| `tool_registry` table | ❌ | Today the registry lives in a PHP array at `core/ai/tool_gateway.php:47` (`aiToolRegistry()`). Spec requires a persisted table so registrations can be admin-managed / versioned / dropped by tenant. |
| `tool_permissions` table | ❌ | Today permissions are an in-array `'permission' => 'integrations.qbo.manage'` string on each tool entry. Spec wants per-tenant per-tool permission rows for dynamic gating. |
| `artifact_events` table | ❌ | (See Artifact Layer section below.) |
| AI Gateway POST `/api/ai/runs` | ✅ | `api/ai/runs.php` (154 lines); `core/ai/gateway.php` (470 lines) |
| Tool registration loader | 🟡 | Static load via `aiToolRegistry()` PHP array. Manifest-style discovery NOT implemented. |
| Tool schema validation | ✅ | `core/ai/tool_gateway.php` — "light schema" with type checks + `required` flag (line 44-46). |
| **Seed tools:** | | |
| `getTenantContext` | ✅ | `coreflux.get_tenant_context` — `tool_gateway.php:100` |
| `getUserPermissions` | ✅ | `coreflux.get_user_permissions` — `tool_gateway.php:106` |
| `getChartOfAccounts` | ✅ | `coreflux.get_chart_of_accounts` — `tool_gateway.php:59` |
| `getBankTransactions` | ✅ | `coreflux.get_bank_transactions` — `tool_gateway.php:112` |
| Audit event writer | ✅ | Tool gateway writes to `ai_tool_invocations` on every call |
| Prompt version storage + active resolution | ✅ | `ai_prompt_versions` table + resolution logic |
| React Ask AI Panel shell | ✅ | `dashboard/src/pages/AskAiPanel.jsx` (175 lines) |
| AI audit admin page | ❌ | No `/admin/ai/runs` SPA page exists. Data is in `ai_runs` and `ai_tool_invocations`, but no UI to browse / filter / drill-in. |
| Trace drilldown | ❌ | No "click an AI run → see all tool calls → see prompt version" UI. |

---

## Phase 2 — LangGraph MVP

Spec acceptance criterion: "Classification workflow pauses/resumes and
creates drafts/exceptions."

| Spec deliverable | Status | Location / evidence |
|---|---|---|
| `workflow_runs` | ✅ | `core/migrations/092_workflow_runtime.sql` |
| `workflow_steps` | 🟡 → renamed | We have `workflow_step_actions` (more granular schema). Functional equivalence likely. |
| `workflow_approvals` | ✅ | migration 092 |
| Checkpoint mirroring (LangGraph → CoreFlux) | ✅ | `workflow_checkpoints` table + runtime wrapper |
| Approval interrupt | ✅ | `workflow_approvals` + interrupt logic |
| Transaction classification graph | ✅ | `core/ai/workflows/graphs/transaction_classification.php` |
| `vendor_aliases` table | ❌ | Spec wants vendor-name → canonical vendor mapping. Today we have `accounting_account_mappings` (account mapping, different concept). |
| `resolveVendorAlias` tool | ❌ | No such tool in `tool_gateway.php`. |
| Prior classification retrieval | 🟡 | Some retrieval exists (`ai_categorization_history`, `accounting_ai_interpretations`) but no spec-named tool. |
| pgvector retrieval stub | ❌ | We're on MySQL; no vector store, no embeddings table. (Spec assumes Postgres+pgvector — this is a stack-level decision pending.) |
| `draftTransactionClassification` tool | 🟡 | `coreflux.draft_journal_entry` exists (`tool_gateway.php:126`) but it's a JE draft, not specifically a classification-draft tool. |
| `createAccountingException` tool | ✅ | `coreflux.create_exception` — `tool_gateway.php:141` |
| `TransactionRecommendationCard` component | ❌ | No file matches. |
| `AccountingExceptionQueue` component | ❌ | No file matches. Data exists in `accounting_exceptions` (migration 093). |

---

## Phase 3 — Accounting MVP

| Spec deliverable | Status | Notes |
|---|---|---|
| Accounting Agent | 🟡 | `core/ai_agents.php` exists; spec's exact "Accounting Agent" persona not formalized. |
| JE draft graph | 🟡 | `draft_journal_entry` tool exists; no LangGraph graph specifically for JE drafting. |
| `journal_entry_drafts` table | ❌ | Drafts exist conceptually via `ai_suggestions`, but no dedicated table. |
| `draftJournalEntry` tool | ✅ | `coreflux.draft_journal_entry` |
| `validateJournalEntry` tool | ❌ | Backend has JE validation in `core/accounting/`, not exposed as a registered tool. |
| Approval request table | 🟡 | `tenant_approval_policies`, `ap_bill_approvals`, `payment_instruction_approvals` exist module-by-module. No generic `approval_requests`. |
| Policy engine baseline | 🟡 | Per-module (AP has the most mature one in `ap_approval_workflows`). No platform-level policy engine. |
| `postApprovedJournalEntry` blocked unless approval exists | 🟡 | Approval gate exists in `tool_gateway.php:208` ("approval_required" error code), but the explicit post tool isn't shipped yet. |
| Reconciliation packet generation | 🟡 | Reconciliation lives in `core/accounting/reconciliation*`, packets not bundled as artifacts. |
| Exception queue UI | ❌ | Data exists, no SPA page. |

---

## Phase 4 — Close MVP

| Spec deliverable | Status | Notes |
|---|---|---|
| Close Agent | ❌ | No agent persona registered. |
| `close_runs` table | ❌ | We have `accounting_close_packets` + `accounting_close_tasks` but no run-level wrapper. |
| `close_tasks` | 🟡 → prefixed | `accounting_close_tasks` |
| `close_packets` | 🟡 → prefixed | `accounting_close_packets` |
| Close workflow graph | ❌ | No close-specific LangGraph graph. |
| `CloseDashboard` component | ❌ | |
| `WorkflowTimeline` component | ✅ | `dashboard/src/pages/WorkflowTimeline.jsx` — exists, useful as a generic component for Phase 4. |

---

## Phase 5 — AP / Treasury / Payroll

| Spec deliverable | Status | Notes |
|---|---|---|
| AP Invoice Review Graph | ❌ | AP module has approval workflows but no LangGraph AI graph for invoice review. |
| Invoice extraction run table | ❌ | No `ap_invoice_extraction_runs` or similar. |
| AP extraction wrapper | ❌ | No OCR/extraction integration registered as a tool. |
| Duplicate invoice check tool | ❌ | Duplicate detection logic exists somewhere in `modules/ap/`, not exposed as a tool. |
| Bill draft tool | ❌ | No `draftBill` tool in registry. |
| Cash position read tool | 🟡 | `treasury/ui/TreasuryOverview.jsx` and a CashForecast component exist, but no `coreflux.get_cash_position` tool. |
| 13-week cash forecast run table | ❌ | |
| Forecast graph | ❌ | |
| Timesheet anomaly detection tool | ❌ | Timesheets module exists; no anomaly detection wired. |
| `PayrollReviewPacket` component | ❌ | |

---

## Phase 6 — Vertical Extensions

| Spec deliverable | Status |
|---|---|
| `placement_margin_runs` | ❌ |
| `staffing_client_profitability` | ❌ |
| `restaurant_prime_cost_runs` | ❌ |
| `menu_profitability_runs` | ❌ |
| `firm_client_access` | ❌ |
| `workpapers` | ❌ |
| `tax_returns` | ❌ |
| `tax_diagnostics` | ❌ |

Restaurant and CPA modules don't exist in this codebase yet. Staffing
has `placements` but no margin-run table.

---

## Phase 7 — Advanced Operations

| Spec deliverable | Status |
|---|---|
| `knowledge_documents` | ❌ |
| `knowledge_embeddings` | ❌ |
| `knowledge_entities` | ❌ |
| `knowledge_edges` | ❌ |
| `agent_registry` | ❌ |
| `agent_handoffs` | ❌ |

---

## Section 2A — First-Class Artifact Layer (the novel ask)

The spec elevates Reports, Workpapers, Close Packets, Reconciliations,
Tax XML, Approval Packets, and AI-generated deliverables to **first-class
platform objects** with identity, lifecycle, versions, provenance,
permissions, relationships, and audit history.

| Required table | Status |
|---|---|
| `artifact_objects` | ❌ |
| `artifact_events` | ❌ |
| `artifact_relationships` | ❌ |
| `artifact_versions` (implied) | ❌ |

**Net:** the entire Artifact Layer is unbuilt. Reports / packets today
live as ad-hoc rows in module-specific tables (`accounting_close_packets`,
`accounting_report_snapshots`, etc.) without a unified identity or
lineage graph.

---

## AI Worker Runtime (§ 2)

Spec component: "AI Worker Runtime" — durable job queue, AI workers pick
up permitted jobs, run agents and workflows, call registered tools,
update artifact state.

| Required piece | Status |
|---|---|
| Worker queue table | ❌ |
| Worker process loop | ❌ |
| Worker permission scoping | ❌ |
| Worker → artifact write path | ❌ |

Today `ai_runs` is synchronous — a request comes in, gateway runs, result
returns. No async/durable worker concept.

---

## Ordered backlog by phase (recommended sequencing)

### Slice A — Close out Phase 1 properly (~3 hr)
1. Migration 098 — `tool_registry` + `tool_permissions` tables.
2. Refactor `aiToolRegistry()` to read from DB with the static PHP array
   as a one-time seed source.
3. Migration 099 — `artifact_objects` + `artifact_events` +
   `artifact_relationships`.
4. New SPA page `/admin/ai/runs` with trace drilldown.
5. New SPA page `/admin/ai/tools` listing registered tools + last-N
   invocations per tool.

### Slice B — Vendor + Exception UX (Phase 2 finish, ~2 hr)
6. Migration 100 — `vendor_aliases`.
7. `resolveVendorAlias` tool.
8. `TransactionRecommendationCard` component (drops into bank reco UI).
9. `AccountingExceptionQueue` component (drops into Bookkeeping section).

### Slice C — JE Draft + Validation (Phase 3, ~3 hr)
10. Migration 101 — `journal_entry_drafts`.
11. `validateJournalEntry` tool.
12. Promote `coreflux.draft_journal_entry` to write into the new drafts
    table (currently it only renders).
13. Wire approval gate to refuse `post` unless approval row exists.

### Slice D — Close Run wrapper + Dashboard (Phase 4, ~3 hr)
14. Migration 102 — `accounting_close_runs` orchestrator.
15. `CloseDashboard` component (consumes `accounting_close_runs` +
    `accounting_close_tasks` + `accounting_close_packets`).
16. Wire the existing `WorkflowTimeline.jsx` as the run history view.

### Slice E — AP Invoice Review + Cash Forecast (Phase 5, ~5 hr)
17. Migration 103 — `ap_invoice_extraction_runs` + `cash_forecast_runs`.
18. Duplicate invoice check tool.
19. Bill draft tool.
20. Cash position read tool.
21. Timesheet anomaly detection tool.

### Slice F — Verticals + Knowledge Graph (Phases 6 + 7)
Large; should be its own sprint set after Slices A-E close.

---

## Recommendations

1. **Build the Artifact Layer first inside Slice A.** It's the spec's
   most novel and load-bearing concept — every later phase wants to
   write artifacts (close packets, recon packets, JE drafts, forecast
   runs, etc.) into the unified layer. Building it post-hoc is more
   expensive.
2. **Keep `ai_tool_invocations` as the table name** — the rename to
   `ai_tool_calls` would force 10+ file changes for zero behaviour
   improvement. Add a `CREATE VIEW ai_tool_calls AS SELECT * FROM
   ai_tool_invocations` if spec-name parity is needed externally.
3. **Defer pgvector / embeddings** until the stack-level decision on
   Postgres vs MySQL is made. Today CoreFlux is MySQL — pulling in a
   pgvector dependency would require either Postgres migration or a
   sidecar vector store. Either is a separate architectural decision.
4. **AI Worker Runtime can wait.** The spec needs it for autonomous
   long-running graphs (close, forecast). For the next 3-5 slices,
   synchronous `ai_runs` is sufficient.
