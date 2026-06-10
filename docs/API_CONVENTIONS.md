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
GET  /api/v1/reports/report-builder/datasets
POST /api/v1/reports/report-builder/run
POST /api/v1/reports/report-builder/export
GET  /api/v1/reports/export-templates
POST /api/v1/reports/export-templates/123/clone
POST /api/v1/reports/export-templates/parse-headers
GET  /api/v1/people/custom-field-definitions
GET  /api/v1/people/custom-field-values/123
GET  /api/v1/people/custom-field-layouts/detail
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

Some platform services still live at legacy direct-file endpoints during the
migration window. The router exposes narrow v1 aliases for those services; for
example, `/api/v1/reports/report-builder/run` dispatches to
`/api/report_builder.php?action=run`.

Custom-field platform services are exposed through entity-scoped v1 aliases:

```text
/api/v1/{entity}/custom-field-definitions
/api/v1/{entity}/custom-field-values/{record_id}
/api/v1/{entity}/custom-field-layouts/{surface}
```

These dispatch to the shared custom-field platform handlers with compatibility
query keys such as `entity_type`, `record_id`, and `surface`.

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
