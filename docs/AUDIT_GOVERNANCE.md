# CoreFlux Audit Governance

The platform audit log is the enterprise evidence layer for create, update,
delete, submit, approve, reject, export, and system actions. Domain modules may
keep richer local audit trails, but the shared `audit_log` table is the
tenant-scoped surface used by admins, auditors, exports, anomaly review, and
cross-module investigations.

## Canonical Event Shape

Audit rows should preserve enough context to reconstruct who acted, what they
acted on, and how the request moved through the platform:

- `tenant_id`
- `actor_type`, `actor_user_id`, `actor_email`
- `event`
- `object_type`, `target_id`
- `before_json`, `after_json`
- `meta_json`
- `ip_address`, `user_agent`, `request_id`, `source`
- `created_at`

The read API normalizes legacy installs that still use `user_id`, `action`,
`entity`, or `entity_id`, so older evidence remains visible while new modules
write the canonical fields.

## Access Model

Audit evidence is tenant-scoped and read-only through
`GET /api/audit_log.php`.

Allowed readers:

- `master_admin`
- `tenant_admin`
- `admin`
- `auditor`
- `external_auditor`

CSV export uses the same endpoint, filters, tenant scope, and role gate as JSON
read access. External auditor sessions remain read-only through the shared API
bootstrap guard.

## Viewer And Export

The admin audit viewer is mounted at `/admin/audit-log` and uses the same API
contract as CSV export. Supported filters include event, actor user id, actor
type, actor email, object type, target id, request id, source, IP, date range,
and limit. Expanded rows show normalized metadata plus before/after snapshots
and user-agent evidence when present.

The CSV output includes actor, object, request/source, metadata, before/after,
IP, user-agent, and timestamp columns so audit packets can be exported without
screen scraping.

## Workflow Evidence

WorkflowEngine writes platform audit events in addition to its
`workflow_step_actions` ledger. Every approve, reject, skip, delegate, comment,
and escalate decision emits `workflow.action.{action}` with actor, workflow
instance, step, via, request id, and source metadata. State transitions also
emit `workflow.started`, `workflow.advanced`, and `workflow.completed` with
before/after status or step snapshots when available.

People Graph-backed routing emits `workflow.people_graph_resolved` with the
resolution strategy and resolved object, so later reviews can explain why an
approver was selected before the action was taken.

## Drift Rules

- New mutating APIs must emit a platform audit event or call a shared service
  that does.
- New audit columns must be added to both the fresh-create migration and an
  idempotent upgrade migration.
- Viewer filters and CSV export must stay backed by the same API query logic.
- Modules may expose local audit reports, but they should not bypass platform
  tenant scoping or auditor read-only gates.
