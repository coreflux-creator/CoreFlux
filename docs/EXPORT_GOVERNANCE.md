# CoreFlux Export Governance

Exports are a platform service over governed datasets. Templates define column
layout; datasets define ownership, permissions, fields, sensitive fields, audit
events, and optional custom-field participation.

## Dataset Contract

Datasets live in `core/export_datasets.php`:

```php
'people_directory' => [
    'label'                 => 'People Directory',
    'module_id'             => 'people',
    'permission'            => 'people.view',
    'formats'               => ['csv'],
    'audit_event'           => 'people.directory.exported',
    'sensitive_fields'      => [],
    'custom_field_entities' => ['people'],
    'fetcher'               => 'exportDatasetFetchPeopleDirectory',
    'fields'                => [
        'person_id' => ['label' => 'Person ID', 'sample' => '42'],
    ],
],
```

## Custom Fields

Datasets that include `custom_field_entities` can expose tenant-defined fields
through `exportDatasetFieldRegistry($dataset, $tenantId)`.

Custom field source keys use:

```text
custom_fields.{entity_type}.{field_key}
```

Example:

```text
custom_fields.people.vendor_id
```

PII custom fields are marked sensitive in the field registry. Fetchers should
avoid exporting PII custom fields unless an explicit sensitive-export flow has
checked the required permission and audited the action.

## API Exposure

`GET /api/export_templates.php?action=datasets` returns dataset governance
metadata and tenant-aware fields:

- `module_id`
- `permission`
- `formats`
- `audit_event`
- `sensitive_fields`
- `custom_field_entities`
- `fields`

Dataset registry and template APIs filter by the dataset's `permission`.
Authenticated users may only discover, list templates for, clone, create,
update, or delete templates for datasets they can access.

## Product Rule

Modules may ship export presets, but the dataset registry is the authoritative
platform contract. New exports should not bypass dataset registration,
permission declaration, sensitive-field declaration, or audit-event declaration.
