# CoreFlux Product Architecture Alignment

This document is the canonical alignment record for resolving drift between the
product plan, module specs, and current implementation.

## 1. Canonical Module Boundary

CoreFlux is a shared platform with domain modules connected through governed
APIs, events, workflows, and reports. Modules must not duplicate platform
services such as RBAC, audit, workflow, custom fields, exports, report building,
or approval tokens.

### Staffing Boundary

`staffing` is an operating-layer consumer and coordinator, not the owner of all
labor objects.

Staffing consumes:

- People and worker profile data from `people`
- Placements, assignment economics, rate snapshots, and client/worksite context
  from `placements`
- Time entries, weekly timesheets, approval state, and payroll/billing readiness
  from `time` or shared time services
- Payroll readiness and payroll run status from `payroll`
- Billing readiness, invoice candidates, and AR state from `billing`
- Accounting event lineage, posting status, margin, WIP, and financial outcomes
  from `accounting`
- Cross-module analytics and custom report outputs from `reports`

Staffing owns:

- Staffing-specific workbench views
- Staffing operating KPIs
- Staffing-specific workflow orchestration
- Staffing health checks, exception surfaces, and recommended actions
- Staffing-specific report presets and dashboard composition

Staffing may emit domain events, but it should not become the source of truth
for people, placements, time, payroll, billing, accounting, or reporting data.
Existing `staffing_clients` workbench records remain a compatibility surface
for client terms and UI workflows while client/company ownership is unified;
mutations require `staffing.clients.manage` and emit `staffing.client.*` or
`staffing.clients.imported` audit events.

## 2. Platform Priorities

The following are platform priorities and should be corrected before deepening
vertical-specific product work.

### API Conventions

CoreFlux APIs should converge on one convention:

- Versioned external contract: `/api/v1/{module}/{resource}`
- Resource-first paths for normal CRUD
- Explicit action subresources for state transitions:
  `/api/v1/time/entries/{id}/submit`,
  `/api/v1/time/entries/{id}/approve`,
  `/api/v1/payroll/runs/{id}/approve`
- Consistent JSON envelopes, error shapes, pagination, filtering, idempotency,
  and audit metadata
- Backward-compatible adapters may remain temporarily, but new product work
  should use the canonical contract

### Custom Fields And Layouts

Custom fields and layouts are shared platform capabilities.

Every entity type that opts in must get:

- Tenant-scoped field definitions
- Validation rules
- Form layout configuration
- List/table column configuration
- API read/write support
- Export support
- Report-builder availability
- Audit history for schema changes and value changes where material

Modules should declare supported entity types in their manifests instead of
creating one-off custom-field systems.

### Exports

Exports are a shared platform service.

Every exportable dataset should declare:

- Dataset id
- Owning module
- Available fields
- Required permissions
- Tenant/entity scoping rules
- Default templates
- Supported formats
- Audit event emitted when exported

Mass export, sensitive-field export, and cross-entity export should require
stronger permissions and produce explicit audit records.

### Report Builder

The report builder is a platform capability over governed datasets, not a
collection of module-specific report pages.

The canonical path is:

1. Modules register datasets, measures, dimensions, joins, filters, and row-level
   security.
2. Reports composes those definitions into dashboards, saved reports, scheduled
   exports, and ad hoc analysis.
3. Vertical modules such as Staffing may ship presets, but the report builder
   engine remains shared.

Current implementation status:

- `core/report_builder.php` projects governed export datasets into report
  dimensions, measures, filters, and sensitive-field metadata.
- Source module manifests declare owned export/report datasets, and
  `ModuleRegistry` exposes those declarations for validation and admin
  surfaces.
- `api/report_builder.php` exposes the builder catalog through the API layer and
  preserves source dataset RBAC checks.
- Saved report definitions are persisted in `report_builder_reports` after
  validation against governed dataset fields.
- Query execution uses registered export dataset fetchers and in-memory
  projection, grouped-measure, filter, and sort logic; saved definitions do not
  permit arbitrary SQL.
- Sensitive-field execution requires `reports.export` in addition to source
  dataset access.
- Custom report CSV export uses the shared platform CSV export primitive and
  emits explicit audit metadata.

### Audit And Enterprise Controls

The shared audit log is the platform evidence layer. Domain modules can keep
specialized audit tables, but the unified audit log owns the tenant-scoped
viewer/export surface, canonical actor/object/request metadata, auditor access,
and cross-module investigation path.

Current implementation status:

- `api/audit_log.php` normalizes legacy and canonical schemas at read time,
  including `actor_user_id`/`user_id`, `event`/`action`,
  `target_id`/`entity_id`, and canonical actor/object/request fields.
- `/api/v1/platform/audit-log` exposes the same governed audit surface through
  the canonical API router while `/api/audit_log.php` remains compatible.
- `/admin/audit-log` exposes filters for event, actor, object, request, source,
  IP, and date range, and CSV export uses the same role gate and query logic.
- Admin and auditor roles can read audit evidence; external auditor sessions
  remain read-only through the shared API bootstrap guard.
- Audit schema migrations include both fresh-create parity and guarded upgrade
  fields for actor type/email, object type, before/after snapshots, request id,
  source, and user agent.
- `core/audit.php` provides the shared schema-adaptive writer. Platform
  services now use `platformAuditLogWrite` so exports, report-builder actions,
  custom-field governance, and access-review lifecycle events emit canonical
  evidence fields while remaining compatible with legacy audit schemas.

### People Graph

People Graph is a shared platform primitive for authority, responsibility,
delegation, notification, and AI-supervision routing. It is not just HR,
user management, or RBAC.

People Graph answers:

- Who owns this object?
- Who approves this object?
- Who reviews AI-created output?
- Who supervises an AI worker?
- Who should be notified or escalated?
- Who held delegated authority at the time?

Implementation status:

- `core/migrations/112_people_graph.sql` adds graph organizations, actor links,
  teams, roles, relationships, responsibility assignments, delegations,
  permission grants, approval policies/rules, notification preferences, and
  graph audit records.
- `core/people_graph.php` exposes resolver functions such as
  `peopleGraphResolve()`, `peopleGraphAssignResponsibility()`,
  `peopleGraphCheckPermission()`, and `peopleGraphResolveApprovers()`.
- `api/people_graph.php` exposes the governed API through
  `/api/v1/people/graph/...`.
- The People module manifest declares `people.graph.view`,
  `people.graph.manage`, and `people.graph.delegate`.
- AI-worker guardrails deny human-only actions such as approve, post, release,
  file, override, and permission grants through the graph permission check.

Future P2 services should consume People Graph rather than creating local
owner/approver/reviewer/delegation/approval-policy tables.

### Workflow Graph Consumption

Workflow Graph is a consumer of People Graph. Workflow definitions may keep
static `approver_user_ids` for compatibility, but new steps should resolve
assignees and approvers through `approver_resolution` strategies:

- `approval_policy`
- `responsibility`
- `role`
- `relationship`
- `named_actor`
- `manager_chain`

The workflow inbox and push-notification paths resolve these strategies at
runtime, then fall back to explicit approver IDs only when the graph has no
answer.

The canonical API router exposes Workflow Graph through
`/api/v1/platform/workflow/inbox`,
`/api/v1/platform/workflow/instances/{id}`, and
`/api/v1/platform/workflow/instances/{id}/act`; legacy `/api/workflow.php`
callers continue to work during migration.
Workflow detail reads are participant-scoped: current approvers, originators,
payload participants, prior action actors/delegates, and admin/auditor roles can
view an instance. Comments require that same visibility, while delegate and
escalate actions require current-step authority just like approve/reject/skip.

AI workflow approval decisions are state-changing approval actions, not audit
reads. `/api/ai/workflows.php?action=decide_approval` requires
`ai.workflow.approve`, `accounting.approve`, or `platform.ai.admin`, and
`workflowDecideApproval()` writes an `ai.workflow.approval_*` audit event after
persisting the reviewer decision.

AI worker processes must declare queue and tool capabilities. `cron/ai_worker.php`
accepts `--tools=...`, stores that list as `tool_allowlist` in
`ai_workers.capabilities_json`, and `aiWorkerClaim()` filters queued work by
`tool_name` before a worker can claim and execute it.

### AI Provider And Gateway Boundary

CoreFlux has two governed AI surfaces:

- Agentic runs, tool execution, workflow workers, and async jobs go through the
  AI Gateway, Tool Gateway, Workflow Graph, and AI worker queue. These paths
  materialize run/job records, enforce RBAC and risk approval gates, and write
  canonical audit events.
- Simple module advisory/extraction features use the legacy module-facing AI
  service helpers: `aiAsk()` for prose, `aiExtract()` for document/image
  extraction, and `aiExtractJson()` for text-only structured JSON drafts.

Module, API, and UI code must not call provider credentials, provider hosts, or
the low-level `aiCallOpenAI()` tuple helper directly. Provider access is isolated
to `core/ai_service.php`, `core/ai/providers/*`, and install/deploy liveness
checks. CSV mapping, GL categorization, and time settlement grouping now consume
`aiExtractJson()` so structured drafts inherit tenant feature gates, JSON
contract checks, provider fallback, and `ai_interactions` audit records.
User-triggered advisory, extraction, mapping, narrative, and AI-agent run
endpoints require both their source-domain permission and `ai.use`. Deterministic
read endpoints may still return the underlying data without AI enrichment; they
must suppress or omit generated suggestions unless the caller has `ai.use`.
External self-service/webhook ingestion remains governed by tenant feature gates,
signature/session verification, and downstream human review rather than an
internal user RBAC grant.

### Artifact Graph Consumption

Artifact Graph is also a consumer of People Graph. Artifact objects own
artifact identity, lifecycle, provenance, event history, and lineage. People
Graph owns the people roles around those artifacts: owner, preparer, reviewer,
approver, requester, recipient, AI creator, AI supervisor, and escalation
contact.

Artifact helpers expose `artifactAssignPeopleGraph()`,
`artifactPeopleGraph()`, and `artifactResolvePeopleGraph()` so modules can link
artifact people roles without adding local artifact owner/reviewer/approver
columns.

### Domain Module Consumption

Domain modules consume People Graph through manifest declarations and
`core/domain_people_graph.php`. The source module still owns the business
record and state machine; People Graph owns shared authority and routing.

The first aligned source-module contracts are declared for:

- `placements`: placements, rate snapshots, commission plans, approval contacts
- `time`: entries, timesheets, approval tokens, settlement periods
- `ap`: bills, payments, vendors, expense reports
- `billing`: invoices, payments, credit memos, dunning cases
- `payroll`: runs, profiles, tax liabilities, Gusto submissions
- `accounting`: journal entries, period close, reconciliations, consolidation
  runs, integration writes
- `treasury`: accounts, payments, transfers, sweeps

`staffing` declares `mode = consumer_orchestrator`; it may own workbench
responsibilities and readiness exceptions, but source-object authority is
resolved from the owning modules.

## 3. Enterprise Controls

Enterprise controls are the product rules that keep financial, payroll, PII,
approval, and integration workflows trustworthy enough for real companies.

They are not just "security." They are the guardrails that prove:

- The right person performed the action
- The person had permission at the time
- The action was allowed by the workflow state
- The action did not violate separation of duties
- The record belonged to the active tenant/entity scope
- The before/after state is reconstructable
- Sensitive data access was minimized and audited
- Financially material actions cannot be silently altered later

### Required Controls

All material create, update, delete, submit, approve, reject, post, export,
payment, payroll, and integration-write actions must enforce:

- Authentication
- Tenant scope
- Entity scope when applicable
- Fine-grained RBAC permission
- Workflow/state-machine validity
- Separation of duties where required
- Audit event with actor, tenant, action, target, timestamp, source IP/user agent
  when available, and before/after material fields
- Idempotency for externally-triggered or retryable actions
- Consistent error responses

WorkflowEngine action decisions emit platform audit events named
`workflow.action.{action}` in addition to the internal `workflow_step_actions`
ledger. Started, advanced, completed, blocked, and People Graph resolution
events use the canonical audit fields for actor, object, request id, source,
user agent, and before/after state where available.

### Separation Of Duties

Separation of duties means the same person should not be able to both originate
and finalize certain high-risk actions.

Examples:

- A worker should not approve their own time.
- The person preparing a payroll run should not be the only approver of that
  payroll run.
- The person creating a payment should not be the sole approver/executor above
  policy thresholds.
- AI may draft or recommend, but material posting, payroll, or payment actions
  require governed human approval unless a tenant policy explicitly allows an
  automated low-risk path.

Workflow Graph now enforces this when a step or matching People Graph approval
policy requires SoD: approve/skip actions are blocked before action persistence
when the current actor is the starter, creator, preparer, requester, submitter,
or an explicit SoD-blocked user for the workflow subject.

### High-Risk Domains

These domains require enterprise controls first:

- Time approval feeding payroll or billing
- Payroll run compute, approval, submission, and paid status
- Placement activation and rate approval
- PII/SSN/bank-data access or export
- Journal entry post/reverse
- Payment approval/execution
- Integration writes to accounting, payroll, banking, or HR systems
- AI tool execution that creates financial, payroll, payment, or worker records

### Time Entry And Timesheet Controls

Time approval is the source recognition gate feeding Billing, AP, Payroll, and
Accounting downstream bundles. Time owns time-entry state, rate-snapshot locking,
and approval evidence; Staffing may host legacy weekly UI surfaces, but it
consumes the Time workflow subject.

- Time entry create/update paths cannot set approved status directly.
- Entry submit, approve, reject, correction, and CSV pre-approval paths record
  source-row evidence.
- Weekly timesheet submit starts the `time_timesheet` Workflow Graph with People
  Graph approver resolution and separation-of-duties checks.
- Workflow approval/rejection sync mutates the Time entries and legacy
  timesheet header only after the workflow decision is approved.
- Tokenized client email approval is an external approval channel with token
  response evidence, not an authenticated user shortcut.
- Settlement extraction and un-extraction are material downstream handoffs; the
  source Time rows must remain reconstructable before and after they are stamped
  into Billing, AP, or Payroll.

Current implementation status:

- `timeAudit` delegates to the shared `platformAuditLogWrite` writer with Time
  source/object metadata.
- Manual entry submit/approve/reject/correct, CSV pre-approval, tokenized client
  approval, external email approval, timesheet WorkflowGraph start/submit, and
  workflow approval/rejection sync capture before/after entry, timesheet, or
  token snapshots where approval state is created or materially changed.
- Manual settlement extract/un-extract and Billing/AP/Payroll auto-create
  settlement paths emit `time_settlement` audit evidence with before/after Time
  row snapshots and the created downstream target metadata.

### Payroll Run Controls

Payroll run transitions are governed as separate actions, not one broad
"build" capability.

- Draft run creation requires `payroll.run.create` and records
  `created_by_user_id` plus `payroll.run.created`.
- Gross-to-net computation/recomputation requires `payroll.run.compute`, stamps
  `computed_by_user_id`, cancels stale pending approvals on recompute, starts the
  `payroll_run` Workflow Graph, and fails closed if the workflow cannot start.
- Approval requires `payroll.run.approve`, a computed run, Workflow Graph
  approval through People Graph approver resolution, and SoD against the creator
  or computer of the run.
- Submit/originate, Gusto submission, Gusto paid marking, and local paid marking
  require `payroll.run.disburse`, an approved run where applicable, and SoD
  against the approving actor.
- `payroll.run.build` remains a workbench/navigation permission, not the
  authority for material create, compute, approve, submit, or paid transitions.

Current implementation status:

- `payrollAudit` delegates to the shared `platformAuditLogWrite` writer with
  Payroll source/object metadata.
- Payroll run WorkflowGraph start, approval sync, rejection sync, compute,
  paid, rail origination, Gusto submission, Gusto paid marking, Gusto unlink,
  and pay-period patch events capture before/after source-row snapshots where
  run or period state is created or materially changed.
- Pay-cycle create/update/deactivate/advance events write through
  `payrollAuditLight`, which delegates to `payrollAudit` and emits cycle,
  period, and draft-run snapshots where cycle generation creates downstream
  payroll artifacts.

### Placement Activation And Rates

Placement activation and rate approval are separate governed actions.

- Rate rows are drafted and approved through the placement rate WorkflowGraph,
  with People Graph approver resolution and separation-of-duties checks.
- Activation requires an already-approved current rate covering the activation
  date. Activation must not silently approve draft rates as a side effect.
- Successful activation readiness writes `placement.activation_rate_verified`.
  Blocked activation writes `placement.activation_blocked_missing_rate` with the
  activation date and caller path.
- Existing draft-rate catch-up remains WorkflowGraph/financial-approval gated
  for non-active draft promotion paths, but activation never invokes it.

Current implementation status:

- `placementsAudit`, `placementsWorkflowAudit`, and placement rate tenant-audit
  helpers delegate to the shared `platformAuditLogWrite` writer with placement
  source/object metadata.
- Rate drafting, WorkflowGraph start, approval/rejection sync, snapshot locking,
  supersede closure, activation readiness, and placement status transitions
  capture source-row snapshots where placement or rate state is created or
  materially changed.

### Accounts Payable Bill And Payment Controls

AP bill approval is a Workflow Graph decision with People Graph approver
resolution and separation-of-duties checks. AP owns bill, payment, allocation,
vendor, expense, and 1099 state. Workflow Graph owns approval routing, while
AP owns the source-row mutation after approval and the payment-release state
machine.

Bill approval and vendor payment release are separate enterprise controls:

- `ap.bill.create` governs bill intake, review submission, and draft creation.
- `ap.bill.approve` governs Workflow Graph approve/reject decisions for bills.
- `ap.bill.post` governs AP-to-Accounting posting after bill approval.
- `ap.payment.create` and `ap.payment.allocate` govern payment drafts and bill
  allocations.
- `ap.payment.send` governs payment release, rail origination, clear, and void
  actions. Release checks enforce maker/checker separation, disputed/void bill
  blocks, and pay-when-paid AR collection gates before any rail dispatch.

Current implementation status:

- `apAudit` delegates to the shared `platformAuditLogWrite` writer with AP
  source/object metadata.
- AP bill workflow submission and approval/rejection sync capture before/after
  bill snapshots while preserving Workflow Graph and People Graph routing
  evidence.
- AP payment draft, allocation, release, batch origination, rail origination,
  clear, void, and blocked-release events capture source-row snapshots where
  payment state is created or materially changed.

### Billing Invoice Approval And Posting

Billing invoice approval is a Workflow Graph decision with People Graph
approver resolution and separation-of-duties checks. Billing owns the invoice
state mutation after workflow approval and writes invoice audit metadata with
workflow source evidence.

Invoice approval and GL posting are separate enterprise controls:

- `billing.invoice.approve` governs draft-to-approved invoice workflow actions.
- `billing.invoice.post` governs invoice posting and intercompany invoice split
  posting, with Accounting GL post permissions required where journal entries
  are created directly.

Current implementation status:

- `billingAudit` and `billingWorkflowAudit` delegate to the shared
  `platformAuditLogWrite` writer with Billing source/object metadata. Invoice
  WorkflowGraph start/approval sync and invoice post/void paths capture
  before/after source-row snapshots for material state changes.

### Accounting Journal Entry Controls

Manual journal entries separate draft, submit, approve, post, reverse, and void
controls. Accounting remains the source of truth for JE rows and posting
state; Workflow Graph owns approval routing, People Graph approver resolution,
and separation-of-duties enforcement.

- `accounting.je.view` governs JE listing, detail, and trial-balance reads.
- `accounting.je.create` governs draft JE creation.
- `accounting.je.submit` starts the Workflow Graph approval for a draft JE.
- `accounting.je.approve` governs workflow approve/reject decisions.
- `accounting.je.post` posts an existing draft only after workflow approval, and
  blocks the same actor from approving and posting when approval was required.
- `accounting.je.reverse` and `accounting.je.void` remain separate controls.

Current implementation status:

- `accountingAudit` and `accountingWorkflowAudit` delegate to the shared
  `platformAuditLogWrite` writer with Accounting source/object metadata.
  Journal-entry WorkflowGraph submission and approval/rejection sync events
  capture before/after source-row snapshots for approval-state changes.

For compatibility with existing reports and subledger posting, `status` remains
the posting lifecycle (`draft`, `posted`, `reversed`, `void`) and
`approval_state` carries the approval lifecycle (`draft`, `pending_approval`,
`approved`, `rejected`). Subledgers continue to post via the Accounting
idempotent posting protocol; manual JEs use the approval overlay before
promotion to posted state.

### Treasury Money Movement

Treasury payment and transfer approval is a Workflow Graph decision with People
Graph approver resolution and separation-of-duties checks. Treasury owns the
payment/transfer state mutation after workflow approval and writes source-row
audit metadata with workflow evidence.

Money movement separates create, submit, approve, execute, and void/reject
controls:

- `treasury.create_payment` and `treasury.create_transfer` govern draft creation
  and submit-to-approval workflow start.
- `treasury.approve_payment` and `treasury.approve_transfer` govern workflow
  approve/reject decisions.
- `treasury.execute_payment` governs payment/transfer execution and accounting
  event emission after approved or scheduled state.
- `treasury.payment.view` governs payment and transfer list visibility, while
  `treasury.view_bank_balances` remains the bank-balance/cash-position read
  gate.

Current implementation status:

- Treasury payment and transfer create, submit, approve/reject, execute,
  failure, and void paths write through `treasuryWorkflowAudit`, which delegates
  to the shared `platformAuditLogWrite` writer with Treasury source/object
  metadata and before/after source-row snapshots for material state changes.
- Mercury payment instruction, co-approval, connection, recipient, and
  reconciliation rail events write through `mercuryAuditLogWrite`, which
  delegates to the shared platform audit writer with Treasury source metadata,
  Mercury object types, masked recipient/connection snapshots, and payment
  before/after source-row snapshots for material rail state changes.

### Access Review And Certification

Access reviews are now a platform enterprise control. Campaigns snapshot
high-risk access from tenant memberships, role-derived RBAC permissions,
direct module grants, and People Graph permission grants. Reviewers can certify,
revoke, exception, or mark items as needing a change; decisions and remediation
results are stored in `access_review_items` and `access_review_audit`.

The canonical API surface is `/api/v1/people/access-reviews`, governed by
`people.access_reviews.view` and `people.access_reviews.manage`.

### Legacy People/PII Controls

Legacy People endpoints must follow the same control model as the canonical
People API.

- DOB, SSN last4, gender, marital status, citizenship, home/mailing addresses,
  I-9 records, emergency contacts, and PII-tagged custom field values require
  `people.pii.view` for reads and `people.pii.manage` for writes.
- Bank-account endpoints require `people.banking.view` or
  `people.banking.manage`; masked reads and all writes emit banking audit
  events.
- Tax setup/history endpoints require `people.tax.view` or `people.tax.manage`;
  reads and writes emit tax audit events.
- Legacy compensation history is financially sensitive and requires
  `people.comp.view` or `people.comp.manage` while deeper comp ownership
  remains aligned to Placements.
- AI onboarding/readiness helpers must not receive sensitive values and must be
  gated before exposing SSN/bank/tax readiness categories or personal email
  addresses.
- Legacy bridge/sync helpers should avoid copying PII between People-era tables
  unless a governed write path explicitly requires it.

Current implementation status:

- `modules/people/lib/audit.php` delegates to the shared
  `platformAuditLogWrite` writer with `source=people` and People-specific object
  types. Legacy People-local PII/change ledgers are preserved, while unified
  person PII writes, legacy employee PII/change events, banking, tax,
  compensation, and custom-field PII events emit canonical platform audit rows.

## 4. Correction Plan

### Phase 1: Contract Lock

- Publish the API convention and add compatibility wrappers for legacy endpoints.
- Require manifests to declare resources, permissions, custom-field entity types,
  exportable datasets, report datasets, workflows, and audit events.
- Add endpoint review rules: no state-changing endpoint ships without RBAC,
  tenant scope, audit, and workflow validation.

### Phase 2: Control Hardening

- Fix time approval so clients cannot create or patch records into approved
  state without the approval workflow.
- Enforce payroll run permissions and separation of duties for create, compute,
  approve, submit, and paid transitions.
- Prevent placement activation without approved rate coverage.
- Lock down legacy People/PII endpoints behind explicit PII permissions.

### Phase 3: Platform Services

- Promote custom fields/layouts to a shared manifest-driven service.
- Promote exports to a shared dataset/template/audit service.
- Promote report builder to a shared governed-dataset engine.
- Route module presets through the shared services instead of module-specific
  one-offs.
  - Placement Expiring Soon now resolves `placements.expiring_soon` and runs
    through `reportBuilderRunDefinition` over `placements_directory`, with the
    module endpoint preserving `placements.view`.
  - Placement Active by Client now resolves `placements.active_by_client`, using
    grouped dimensions/measures in the shared report builder rather than module
    aggregate SQL.

### Phase 4: Product Alignment

- Keep `staffing` as the consumer/orchestrator workbench.
  - Staffing workbench routes now prefer source-domain permissions for
    Placements, Time, Payroll, Billing, and Reports, with legacy `staffing.*`
    permission strings retained as compatibility aliases.
  - Staffing UI links to placement records and placement creation now target
    the canonical `/modules/placements/...` routes rather than treating
    Placements as a Staffing-owned subspace.
- Keep People, Placements, Time, Payroll, Billing, Accounting, Treasury, and
  Reports as sources of truth for their respective domains.
- Update specs and code references to reflect this ownership model.
- Treat restaurant and other future verticals as consumers of the same platform
  contracts rather than exceptions.
  - Restaurant alignment is locked in `docs/RESTAURANT_ALIGNMENT.md`: future
    Restaurant work must be a native CoreFlux consumer-orchestrator, not a
    separate service and not the owner of AP, accounting, payroll, reporting,
    RBAC, audit, custom-field, export, report-builder, Workflow Graph, or
    People Graph primitives.
