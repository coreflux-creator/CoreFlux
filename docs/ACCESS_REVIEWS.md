# Access Reviews

Access Reviews are the enterprise certification control for CoreFlux access.
They snapshot high-risk entitlements from:

- tenant memberships and role-derived RBAC permissions
- direct `membership_module_access` grants
- People Graph permission grants

Campaign owners can certify, revoke, exception, or mark each item as needing a
change. Decisions are stored on `access_review_items`, and every campaign/item
event is appended to `access_review_audit`.

## API

Canonical route:

```text
GET  /api/v1/people/access-reviews
GET  /api/v1/people/access-reviews/{id}
GET  /api/v1/people/access-reviews/items?campaign_id=123
POST /api/v1/people/access-reviews?action=create
POST /api/v1/people/access-reviews?action=open
POST /api/v1/people/access-reviews?action=snapshot
POST /api/v1/people/access-reviews?action=decision
POST /api/v1/people/access-reviews?action=complete
```

Permissions:

- `people.access_reviews.view`
- `people.access_reviews.manage`

## Revocation Behavior

- `membership_module_access` items are revoked by setting `access_level = none`
  and writing `membership_audit`.
- `people_graph_permission_grant` items are revoked through
  `peopleGraphRevokePermissionGrant()`.
- `rbac_role_permission` items cannot be removed from a single user without
  changing the user's role/persona, so revoke decisions are marked with
  `remediation_status = pending`.

## Default Scope

By default, campaigns snapshot high-risk permissions only: PII, banking, tax,
payment execution, payroll disbursement, journal posting/reversal, report
exports, integration admin, People Graph admin/delegation, and AI approval/admin
actions. A campaign scope can set `include_low_risk = true` or restrict the
review to specific modules or exact permissions.
