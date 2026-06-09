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
  remains intentionally separate follow-on work.

## API

`GET /api/report_builder.php?action=datasets`

Returns accessible datasets with `fields`, `dimensions`, `measures`, and
`filters`.

`GET /api/report_builder.php?dataset=people_directory`

Returns one governed report dataset.

`GET /api/report_builder.php?action=reports`

Returns private reports owned by the actor and shared reports in the tenant.

`POST /api/report_builder.php`

Creates a saved report definition. Requires `reports.custom.build`; shared
visibility additionally requires `reports.custom.share`.

`PATCH /api/report_builder.php?id=123`

Updates a saved report definition.

`DELETE /api/report_builder.php?id=123`

Soft-deletes a saved report definition.

## Priority Alignment

This addresses the product-plan drift where custom reports were described as a
future Reports-module feature but not tied to API conventions, custom fields,
layouts, exports, or enterprise controls. The builder now sits on top of those
platform contracts instead of duplicating them.
