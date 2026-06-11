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
- `api/report_builder.php` exposes the builder catalog through the API layer and
  preserves source dataset RBAC checks.
- Saved report definitions are persisted in `report_builder_reports` after
  validation against governed dataset fields.
- Query execution uses registered export dataset fetchers and in-memory
  projection/filter/sort logic; saved definitions do not permit arbitrary SQL.
- Sensitive-field execution requires `reports.export` in addition to source
  dataset access.
- Custom report CSV export uses the shared platform CSV export primitive and
  emits explicit audit metadata.

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
- Enforce payroll run permissions and separation of duties for compute, approve,
  submit, and paid transitions.
- Prevent placement activation without approved rate coverage.
- Lock down legacy People/PII endpoints behind explicit PII permissions.

### Phase 3: Platform Services

- Promote custom fields/layouts to a shared manifest-driven service.
- Promote exports to a shared dataset/template/audit service.
- Promote report builder to a shared governed-dataset engine.
- Route module presets through the shared services instead of module-specific
  one-offs.

### Phase 4: Product Alignment

- Keep `staffing` as the consumer/orchestrator workbench.
- Keep People, Placements, Time, Payroll, Billing, Accounting, Treasury, and
  Reports as sources of truth for their respective domains.
- Update specs and code references to reflect this ownership model.
- Treat restaurant and other future verticals as consumers of the same platform
  contracts rather than exceptions.
