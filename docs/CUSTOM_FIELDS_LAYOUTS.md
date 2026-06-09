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

## Discovery API

Use:

```text
GET /api/custom_field_entities.php
GET /api/custom_field_entities.php?entity_type=people
GET /api/custom_field_definitions.php?entity_type=people
GET /api/custom_field_layouts.php
GET /api/custom_field_layouts.php?entity_type=people
GET /api/custom_field_layouts.php?entity_type=people&surface=forms
GET /api/custom_field_values.php?entity_type=people&record_id=123
POST /api/custom_field_values.php?entity_type=people&record_id=123
```

The response includes entity metadata plus `can_view` and `can_manage` flags
computed from the active user's RBAC permissions.

The definitions API returns tenant-scoped definitions through the shared
service, regardless of whether the owning module is on spec-aligned tables or
legacy `custom_fields`.

The layout API returns normalized surface layouts for `forms`, `detail`,
`lists`, `exports`, and `reports`. This lets modules consume shared layout
metadata without inventing separate form/list/export/report conventions.

The values API reads and upserts tenant custom-field values through the shared
service. Sensitive custom-field values are omitted from reads unless the actor
has the entity's `pii_permission`; writes to sensitive values require the same
PII permission plus the entity's manage permission.

## Product Rule

Custom fields must flow into forms, detail views, lists, exports, and reports
through this shared contract. Modules may ship entity-specific UI, but should
not invent separate permission, audit, export, or report-builder conventions.
