# CoreFlux API Conventions

CoreFlux API work should use the versioned contract below for new product work.
Legacy direct-file and `/api/{module}/{endpoint}` paths may remain during the
migration window, but should be treated as compatibility surfaces.

## Canonical Path Shape

Use:

```text
/api/v1/{module}/{resource}
/api/v1/{module}/{resource}/{id}
/api/v1/{module}/{resource}/{id}/{action}
```

Examples:

```text
GET  /api/v1/time/entries
GET  /api/v1/time/entries/123
POST /api/v1/time/entries/123/submit
POST /api/v1/time/entries/123/approve
POST /api/v1/payroll/runs/456/approve
POST /api/v1/placements/placements/789/activate
```

## Compatibility Behavior

The central router currently dispatches v1 paths to the existing module
endpoint files:

```text
/api/v1/time/entries/123/approve
```

dispatches to:

```text
modules/time/api/entries.php
```

with compatibility query keys:

```text
id=123
action=approve
```

Explicit query-string values win. This lets old clients keep working while new
clients move to resource/action paths.

## Endpoint Rules

All state-changing endpoints must enforce:

- Authentication
- Tenant scope
- Fine-grained RBAC
- Workflow/state validity
- Separation of duties where required
- Audit event emission
- Consistent JSON error responses

Normal CRUD should use resource paths. State transitions should use action
subresources, not caller-supplied status fields.
