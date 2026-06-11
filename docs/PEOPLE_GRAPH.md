# People Graph

People Graph is the shared CoreFlux authority and responsibility layer. It
models who owns, approves, reviews, supervises, delegates, receives
notifications, and is accountable for platform objects.

It does not replace source systems:

- `people` remains the person/talent system of record.
- `users` remains the authentication account table.
- `companies` remains the company directory.
- `tenant_memberships` and `membership_module_access` remain RBAC grants.
- `ai_workers` remains the worker runtime registry.

People Graph links those actors through graph tables and resolver APIs so
modules do not reinvent approver, owner, reviewer, escalation, delegation, or
AI-supervision logic.

## Core Concepts

Actors:

```text
person, user, organization, company, team, role, ai_worker, external
```

Relationships:

```text
reports_to, manages, member_of, owns, accountable_to, approves_for,
reviews_for, supervises_ai, notifies, escalates_to, delegates_to,
primary_contact_for, works_for, custom
```

Responsibilities:

```text
owner, accountable, preparer, approver, reviewer, requester,
recipient, ai_creator, ai_supervisor, notifier, operator, viewer,
escalation_contact
```

Delegations:

```text
approval, review, notification, ownership, supervision, all
```

Permission actions:

```text
view, create, edit, delete, approve, submit, post, release, invite,
assign, export, override, review, notify, resolve, supervise, file,
grant_permission
```

Approval strategies:

```text
role, relationship, responsibility, named_actor, manager_chain
```

## Resolver Questions

The service intentionally exposes product questions instead of raw table names:

```php
peopleGraphResolve($tenantId, 'who_owns', [
    'object_module' => 'payroll',
    'object_type' => 'run',
    'object_id' => '123',
]);
```

Supported questions:

- `who_owns`
- `who_prepares`
- `who_approves`
- `who_reviews`
- `who_reviews_ai`
- `who_created_ai`
- `who_receives`
- `who_notifies`
- `who_escalates`
- `who_operates`
- `who_can_view`

Delegations are applied during resolution. For example, if the approver of a
payroll run delegated approval authority for that run, `who_approves` returns
the delegate while preserving `delegated_from` metadata.

## API

Canonical v1 routes:

```text
GET  /api/v1/people/graph/vocabulary
GET  /api/v1/people/graph/resolve
GET  /api/v1/people/graph/responsibilities
POST /api/v1/people/graph/responsibilities
GET  /api/v1/people/graph/relationships
POST /api/v1/people/graph/relationships
GET  /api/v1/people/graph/delegations
POST /api/v1/people/graph/delegations
POST /api/v1/people/graph/revoke-delegation?id=123
GET  /api/v1/people/graph/actor-links
POST /api/v1/people/graph/actor-links
GET  /api/v1/people/graph/organizations
POST /api/v1/people/graph/organizations
GET  /api/v1/people/graph/teams
POST /api/v1/people/graph/teams
GET  /api/v1/people/graph/roles
POST /api/v1/people/graph/roles
GET  /api/v1/people/graph/permission-grants
POST /api/v1/people/graph/permission-grants
POST /api/v1/people/graph/revoke-permission-grant?id=123
POST /api/v1/people/graph/check-permission
GET  /api/v1/people/graph/approval-policies
POST /api/v1/people/graph/approval-policies
GET  /api/v1/people/graph/approval-rules
POST /api/v1/people/graph/approval-rules
POST /api/v1/people/graph/resolve-approvers
```

Resolver example:

```text
GET /api/v1/people/graph/resolve?question=who_approves&object_module=payroll&object_type=run&object_id=123
```

Responsibility assignment example:

```json
{
  "object_module": "payroll",
  "object_type": "run",
  "object_id": "123",
  "responsibility_type": "approver",
  "actor_type": "user",
  "actor_id": 42,
  "priority": 10
}
```

Permission check example:

```json
{
  "actor_type": "user",
  "actor_id": 42,
  "action": "approve",
  "resource_module": "treasury",
  "resource_type": "payment",
  "resource_id": "pay_123",
  "scope_type": "legal_entity",
  "scope_id": "entity_1",
  "context": {
    "amount": 3000,
    "currency": "USD"
  }
}
```

Approval policy rule example:

```json
{
  "policy_key": "treasury_payment_approval",
  "sequence": 1,
  "conditions": { "amount_greater_than": 2500 },
  "approver_strategy": "role",
  "approver_role_key": "payment_approver",
  "minimum_approvals": 1,
  "separation_of_duties_required": true
}
```

## Permissions

- `people.graph.view`: read graph actors, relationships, assignments,
  delegations, and resolver answers.
- `people.graph.manage`: manage actors, teams, roles, relationships, and
  responsibility assignments.
- `people.graph.delegate`: create or revoke delegations.

`people.graph.delegate` maps to admin-level access in the RBAC bridge until a
self-delegation-only flow is added.

## P2 Usage Rule

New P2 platform services should use People Graph for authority and routing:

- Artifact ownership, reviewers, approvers, and AI supervisors
- Workflow approver routing and escalation
- AI worker supervision and human review paths
- Scheduled report/export recipients
- Enterprise audit explanations of who had authority and why

Modules may cache or denormalize resolved actors for performance, but People
Graph remains the resolver of record.

### Domain Module Consumption

Domain modules declare a `people_graph` contract in `manifest.php` and use
`core/domain_people_graph.php` as their bridge into the shared authority layer.
The domain module still owns the business record, state machine, and persistence;
People Graph owns cross-cutting owner, preparer, reviewer, approver, operator,
recipient, notifier, escalation, delegation, approval-policy, and permission
resolution.

```php
domainPeopleGraphAssignResponsibility(
    $tenantId,
    'payroll',
    'run',
    $runId,
    'approver',
    'user',
    $approverUserId
);

domainPeopleGraphResolve($tenantId, 'payroll', 'run', $runId, 'who_approves');
domainPeopleGraphResolveApprovers($tenantId, 'treasury', 'payment', $paymentId, ['amount' => 7500]);
```

The bridge validates that the module manifest declares the object type and
responsibility before writing People Graph assignments. Workflow definitions
can use `domainPeopleGraphWorkflowApproverResolution()` to produce an
`approver_resolution` payload for the Workflow Graph.

### Artifact People Roles

Artifact Graph stores artifact identity, lifecycle, provenance, event history,
and artifact-to-artifact/row lineage. People Graph stores the people roles
around an artifact.

Modules should use the artifact helper functions instead of adding local owner,
reviewer, or approver columns:

```php
artifactAssignPeopleGraph($tenantId, $artifactId, 'reviewer', 'user', 42);
artifactResolvePeopleGraph($tenantId, $artifactId, 'who_reviews');
artifactPeopleGraph($tenantId, $artifactId);
```

Supported artifact responsibilities are `owner`, `preparer`, `reviewer`,
`approver`, `requester`, `recipient`, `ai_creator`, `ai_supervisor`, and
`escalation_contact`. Artifact lineage responses include a `people_graph`
projection so artifact detail UIs can show owners, reviewers, approvers, AI
creators, and supervisors without duplicating the authority model.

### Workflow Routing

Workflow steps can resolve approvers dynamically by adding an
`approver_resolution` block. Static `approver_user_ids` remain supported as a
fallback for legacy workflows. Steps that require two-eye control can set
`separation_of_duties_required` or inherit it from a matching People Graph
approval-policy rule.

```json
{
  "step": 1,
  "label": "Payment approval",
  "approver_resolution": {
    "strategy": "approval_policy",
    "resource_module": "treasury",
    "resource_type": "payment",
    "resource_id": "payment_123",
    "context": { "amount": 7500 }
  },
  "separation_of_duties_required": true,
  "sod_blocked_user_ids": [18],
  "fallback_approver_user_ids": [3],
  "quorum": 1
}
```

Supported workflow strategies are `approval_policy`, `responsibility`, `role`,
`relationship`, `named_actor`, and `manager_chain`. The workflow inbox and push
notifications resolve these strategies through People Graph at runtime.

For approve/skip actions, Workflow Graph confirms the actor is a current-step
approver and blocks the actor when SoD is required and they match
`started_by_user_id`, creator/preparer/requester/submitter fields, explicit
`sod_blocked_user_ids`, or user-typed source/preparer/requester actor refs in
the step, payload, or payload context.

## AI Safety

AI workers can be graph actors, but the permission check denies human-only
verbs such as `approve`, `post`, `release`, `file`, `grant_permission`, and
`override`. AI workers can still be assigned preparer, recommender, routing,
explanation, or exception-detection responsibilities, with human supervisors
resolved through `ai_supervisor` responsibilities or `supervises_ai`
relationships.
