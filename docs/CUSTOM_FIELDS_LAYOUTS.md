# CoreFlux Custom Fields And Layouts

Custom fields and layouts are platform capabilities. Modules declare which
entity types support tenant-defined fields; platform services discover those
declarations through `ModuleRegistry`.

## Manifest Contract

Modules opt in with `custom_field_entities`:

```php
'custom_field_entities' => [
    [
        'entity_type'       => 'people',
        'label'             => 'People',
        'definition_table'  => 'people_custom_field_defs',
        'value_table'       => 'people_custom_field_values',
        'record_id_key'     => 'person_id',
        'view_permission'   => 'people.view',
        'manage_permission' => 'people.custom_fields.manage',
        'pii_permission'    => 'people.pii.view',
        'pii_manage_permission' => 'people.pii.manage',
        'surfaces'          => ['forms', 'detail', 'lists', 'exports', 'reports'],
    ],
],
```

Optional `custom_field_layouts` describe how fields appear in product surfaces:

```php
'custom_field_layouts' => [
    'people' => [
        'form_sections' => ['profile', 'work', 'compliance'],
        'list_columns'  => ['field_label', 'field_type', 'required', 'pii'],
    ],
],
```

## Platform Service

Use `core/custom_fields.php` for shared behavior:

- `customFieldEntityRegistry()`
- `customFieldEntity($entityType)`
- `customFieldLayouts($entityType)`
- `customFieldSurfaceLayout($entityType, $surface)`
- `customFieldAllSurfaceLayouts($entityType)`
- `customFieldDefinitions($tenantId, $entityType)`
- `customFieldValueUpsert($tenantId, $entityType, $recordId, $fieldKey, $value)`

The service routes People to its spec-aligned tables and supports the legacy
generic `custom_fields` / `custom_values` tables during migration.
Placements currently consumes that generic path through the same service.

Each definition may declare field-level role gates:

- `visible_to` / `visible_to_roles`: role keys allowed to see the definition
  and its values.
- `editable_by` / `editable_by_roles`: role keys allowed to edit values.

The service stores those sets as `visible_to_roles_json` and
`editable_by_roles_json` on both the People definition table and the generic
`custom_fields` table. Empty or null role sets are unrestricted for backwards
compatibility. Runtime helpers (`customFieldUserCanViewDefinition` and
`customFieldUserCanEditDefinition`) are the shared interpretation; modules
should not implement their own role parsing.

Normal product forms and definition lists read active fields only. Export,
reporting, and audit flows can explicitly opt into archived definitions through
the shared service so a removed custom field remains available for historical
evidence packets and tenant exports without reappearing as an editable form
field. Archived definitions and values carry `archived`, `is_archived`, and
`deleted_at` metadata.

Manifest layouts are defaults. Tenant-specific layout overrides live in the
core `custom_field_layout_overrides` table and are resolved by
`customFieldSurfaceLayout($entityType, $surface, $tenantId)`. Overrides are
saved through the platform layout API using the entity's declared
`manage_permission`; modules consume resolved layouts and do not own separate
layout storage.

## Discovery API

Use:

```text
GET    /api/v1/people/custom-field-definitions
POST   /api/v1/people/custom-field-definitions
PATCH  /api/v1/people/custom-field-definitions?id=123
DELETE /api/v1/people/custom-field-definitions?id=123
GET    /api/v1/people/custom-field-layouts
GET    /api/v1/people/custom-field-layouts/forms
PUT    /api/v1/people/custom-field-layouts/forms
PATCH  /api/v1/people/custom-field-layouts/forms
DELETE /api/v1/people/custom-field-layouts/forms
GET    /api/v1/people/custom-field-values/123
POST   /api/v1/people/custom-field-values/123
GET    /api/v1/placements/custom-field-definitions
GET    /api/v1/placements/custom-field-layouts/detail
POST   /api/v1/placements/custom-field-values/456
```

The shared discovery endpoint remains available at
`/api/custom_field_entities.php` during migration. The underlying direct-file
handlers also remain as compatibility surfaces, but product UI should prefer
the entity-scoped v1 aliases.

The response includes entity metadata plus `can_view` and `can_manage` flags
computed from the active user's RBAC permissions.

The definitions API returns tenant-scoped definitions through the shared
service, regardless of whether the owning module is on spec-aligned tables or
legacy `custom_fields`. Definition writes require the entity's
`manage_permission`; creating or marking a field as PII also requires the
entity's `pii_manage_permission` when one is declared, falling back to
`pii_permission`. Manage users can see all definitions for administration, but
responses include `field_access` so value surfaces can tell whether the active
actor may see or edit a specific field.

The layout API returns normalized surface layouts for `forms`, `detail`,
`lists`, `exports`, and `reports`. `PUT`/`PATCH` writes a tenant override for
one surface, and `DELETE` resets that surface back to its manifest default.
This lets modules consume shared layout metadata without inventing separate
form/list/export/report conventions.

The values API reads and upserts tenant custom-field values through the shared
service. Sensitive custom-field values are omitted from reads unless the actor
has the entity's `pii_permission`; writes to sensitive values require the
entity's `pii_manage_permission` when declared, otherwise `pii_permission`,
plus the entity's manage permission. Field-level gates are enforced in addition:
reads omit fields whose `visible_to` set excludes the actor, and writes reject
fields whose `editable_by` set excludes the actor.

Definition mutations emit `custom_field.definition.*` audit events. Value
mutations emit `custom_field.value.updated` with entity type, record id, and the
field keys touched. Definition create/update audit metadata includes
`visible_to` and `editable_by` when supplied so access changes are reviewable.
Layout mutations emit `custom_field.layout.updated`; resets emit
`custom_field.layout.reset`.

## Product Rule

Custom fields must flow into forms, detail views, lists, exports, and reports
through this shared contract. Modules may ship entity-specific UI, but should
not invent separate permission, audit, export, or report-builder conventions.
If a module declares `custom_field_entities`, its product surfaces should use
the entity-scoped `/api/v1/<module>/custom-field-*` aliases and should expose
definition management through the declared `manage_permission`.
Field-level role gates are platform controls: consuming modules may render the
metadata, but the shared APIs and export/report consumers own enforcement.
Deleting a definition must be audit-preserving: values stay stored, active UI
surfaces hide the field, and export/report surfaces can still include the
archived field with metadata when the actor can see it.
