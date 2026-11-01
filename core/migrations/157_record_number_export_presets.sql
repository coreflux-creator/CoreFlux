-- Extend platform defaults only. Tenant templates retain their selected columns.

UPDATE export_templates
SET column_mappings_json = JSON_ARRAY_APPEND(column_mappings_json, '$', JSON_OBJECT(
    'position', JSON_LENGTH(column_mappings_json) + 1,
    'output_header', 'Person number', 'kind', 'field', 'source_field', 'person_number'
))
WHERE scope = 'platform' AND tenant_id IS NULL AND is_system = 1 AND dataset = 'people_directory'
  AND JSON_VALID(column_mappings_json)
  AND JSON_CONTAINS(column_mappings_json, JSON_OBJECT('source_field', 'person_number')) = 0;

UPDATE export_templates
SET column_mappings_json = JSON_ARRAY_APPEND(column_mappings_json, '$', JSON_OBJECT(
    'position', JSON_LENGTH(column_mappings_json) + 1,
    'output_header', 'Placement number', 'kind', 'field', 'source_field', 'placement_number'
))
WHERE scope = 'platform' AND tenant_id IS NULL AND is_system = 1 AND dataset = 'placements_directory'
  AND JSON_VALID(column_mappings_json)
  AND JSON_CONTAINS(column_mappings_json, JSON_OBJECT('source_field', 'placement_number')) = 0;

UPDATE export_templates
SET column_mappings_json = JSON_ARRAY_APPEND(column_mappings_json, '$', JSON_OBJECT(
    'position', JSON_LENGTH(column_mappings_json) + 1,
    'output_header', 'Person number', 'kind', 'field', 'source_field', 'person_number'
))
WHERE scope = 'platform' AND tenant_id IS NULL AND is_system = 1 AND dataset = 'placements_directory'
  AND JSON_VALID(column_mappings_json)
  AND JSON_CONTAINS(column_mappings_json, JSON_OBJECT('source_field', 'person_number')) = 0;

UPDATE export_templates
SET column_mappings_json = JSON_ARRAY_APPEND(column_mappings_json, '$', JSON_OBJECT(
    'position', JSON_LENGTH(column_mappings_json) + 1,
    'output_header', 'Client number', 'kind', 'field', 'source_field', 'client_number'
))
WHERE scope = 'platform' AND tenant_id IS NULL AND is_system = 1 AND dataset = 'staffing_clients'
  AND JSON_VALID(column_mappings_json)
  AND JSON_CONTAINS(column_mappings_json, JSON_OBJECT('source_field', 'client_number')) = 0;

UPDATE export_templates
SET column_mappings_json = JSON_ARRAY_APPEND(column_mappings_json, '$', JSON_OBJECT(
    'position', JSON_LENGTH(column_mappings_json) + 1,
    'output_header', 'Company number', 'kind', 'field', 'source_field', 'company_number'
))
WHERE scope = 'platform' AND tenant_id IS NULL AND is_system = 1 AND dataset = 'staffing_clients'
  AND JSON_VALID(column_mappings_json)
  AND JSON_CONTAINS(column_mappings_json, JSON_OBJECT('source_field', 'company_number')) = 0;

UPDATE export_templates
SET column_mappings_json = JSON_ARRAY_APPEND(column_mappings_json, '$', JSON_OBJECT(
    'position', JSON_LENGTH(column_mappings_json) + 1,
    'output_header', 'Vendor number', 'kind', 'field', 'source_field', 'vendor_number'
))
WHERE scope = 'platform' AND tenant_id IS NULL AND is_system = 1 AND dataset = 'ap_vendors'
  AND JSON_VALID(column_mappings_json)
  AND JSON_CONTAINS(column_mappings_json, JSON_OBJECT('source_field', 'vendor_number')) = 0;

UPDATE export_templates
SET column_mappings_json = JSON_ARRAY_APPEND(column_mappings_json, '$', JSON_OBJECT(
    'position', JSON_LENGTH(column_mappings_json) + 1,
    'output_header', 'Company number', 'kind', 'field', 'source_field', 'company_number'
))
WHERE scope = 'platform' AND tenant_id IS NULL AND is_system = 1 AND dataset = 'ap_vendors'
  AND JSON_VALID(column_mappings_json)
  AND JSON_CONTAINS(column_mappings_json, JSON_OBJECT('source_field', 'company_number')) = 0;
