# Report Builder

The custom report builder is a governed consumer of platform datasets. It does
not own source domain data, staffing economics, custom fields, layouts, or export
definitions. Those remain with the owning module or platform service.

## Contract

- Source datasets come from `core/export_datasets.php`.
- Custom fields are included through `exportDatasetFieldRegistry($dataset, $tenantId)`.
- Dataset permissions are preserved on the builder surface.
- Sensitive fields are marked in field metadata and remain governed by the
  source dataset contract.
- Saved report definitions are persisted as governed metadata. Query execution
  uses only registered export dataset fetchers; arbitrary SQL is not accepted.
- Report presets are named metadata over the same governed definitions.
  Vertical modules may expose or deep-link presets, but the report builder
  registry owns validation, execution, export, and save-from-preset behavior.
- Running a definition that includes sensitive fields requires `reports.export`
  in addition to the source dataset permission.
- CSV export uses the platform `CsvExportService` and always requires
  `reports.export`.

## API

`GET /api/v1/reports/report-builder/datasets`

Returns accessible datasets with `fields`, `dimensions`, `measures`, and
`filters`.

`GET /api/v1/reports/report-builder?dataset=people_directory`

Returns one governed report dataset.

`GET /api/v1/reports/report-builder/reports`

Returns private reports owned by the actor and shared reports in the tenant.

`GET /api/v1/reports/report-builder/presets`

Returns accessible named presets. Presets preserve the source dataset,
permission, filters, sorts, and selected fields; inaccessible dataset presets
are filtered out.

`POST /api/v1/reports/report-builder/run`

Runs a validated ad hoc, saved-report, or `preset_key` definition through the
governed dataset fetcher and returns projected rows. Requires
`reports.custom.build`, preserves dataset RBAC, and writes
`reports.custom.executed` audit metadata.

`POST /api/v1/reports/report-builder/export`

Runs the same governed definition, saved report, or `preset_key` and streams
CSV. Requires `reports.custom.build` and `reports.export`, preserves dataset
RBAC, and writes `reports.custom.exported` audit metadata.

`POST /api/v1/reports/report-builder`

Creates a saved report definition. Passing `preset_key` without `definition`
saves the preset as a normal governed report definition. Requires
`reports.custom.build`; shared visibility additionally requires
`reports.custom.share`. Writes `reports.custom.saved` audit metadata.

`PATCH /api/v1/reports/report-builder/123`

Updates a saved report definition and writes `reports.custom.updated` audit
metadata.

`DELETE /api/v1/reports/report-builder/123`

Soft-deletes a saved report definition and writes `reports.custom.deleted`
audit metadata.

The legacy direct-file endpoint `/api/report_builder.php` remains as a
compatibility adapter during migration. New product UI and API callers should
use the v1 routes above.

## Priority Alignment

This addresses the product-plan drift where custom reports were described as a
future Reports-module feature but not tied to API conventions, custom fields,
layouts, exports, or enterprise controls. The builder now sits on top of those
platform contracts instead of duplicating them.
