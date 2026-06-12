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

Source module manifests also declare the datasets they own under
`export_datasets` and, when report-builder-visible, `report_datasets`. The
code-side registry remains the execution contract; the manifest declarations are
the ownership contract used by module discovery, admin surfaces, and governance
tests.

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

The sensitive-field helper is tenant-aware so tenant-defined PII fields are
recognized with the same `custom_fields.{entity_type}.{field_key}` keys that
exports and reports expose. Dataset fetchers do not include sensitive custom
field values by default; callers must pass an explicit sensitive custom-field
opt-in after enforcing the stronger export permission.

Governed export datasets include archived custom-field definitions by default.
This is intentional: deleting a tenant-defined field removes it from active
forms and current definition lists, but historical values remain exportable for
audit packets and downstream reporting. Archived field metadata is surfaced as
`archived` and `archived_at` on the tenant-aware field registry.

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

## Execution

Template-backed exports should run through `core/export_service.php`:

```php
exportTemplateStreamDatasetCsv(
    $tenantId,
    'people_directory',
    $templateId,
    ['status' => 'active'],
    'people-directory',
    $actorUserId
);
```

The shared runner validates that the template belongs to the requested dataset,
fetches rows with the dataset fetcher, renders the template, normalizes the CSV
filename, and writes the dataset's declared `audit_event` to `audit_log`.
Audit metadata always includes `generated_at`, `filter_params`, `option_keys`,
dataset, format, row count, and template metadata when a template was used.
Raw governed CSV exports use the same metadata helper, so filter values are not
lost just because the tenant did not choose an export template.

People Directory, Placements, Time Entries, Staffing Clients, Payroll
Disbursements, AP Payments, AP Bills, AP Vendors, Expenses, Accounting ledger
datasets, Billing Invoices, and Billing Payments use this shared runner for
template exports. Raw legacy CSV endpoints may remain for backward
compatibility, but they should consume the same governed dataset fetchers and
audit the dataset event with `mode=raw`. Any new configurable export should be
dataset and template backed.

## Product Rule

Modules may ship export presets, but the dataset registry is the authoritative
platform contract. New exports should not bypass dataset registration,
permission declaration, sensitive-field declaration, or audit-event declaration.
