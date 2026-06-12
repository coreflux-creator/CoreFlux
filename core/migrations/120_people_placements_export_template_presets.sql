-- Platform export template presets for People and Placements governed datasets.
--
-- These are intentionally dataset-backed templates, not module-local CSV
-- conventions. The NOT EXISTS guards keep the seed idempotent on hosts where
-- migrations are replayed.

INSERT INTO export_templates
    (scope, tenant_id, dataset, name, delimiter, quote_char, has_header_row,
     encoding, column_mappings_json, is_active, is_system, created_at)
SELECT
    'platform', NULL, 'people_directory', 'People Directory (default)',
    ',', '"', 1, 'utf-8',
    '[
      {"position":1,"output_header":"Person ID","kind":"field","source_field":"person_id"},
      {"position":2,"output_header":"First name","kind":"field","source_field":"first_name"},
      {"position":3,"output_header":"Last name","kind":"field","source_field":"last_name"},
      {"position":4,"output_header":"Primary email","kind":"field","source_field":"email_primary"},
      {"position":5,"output_header":"Primary phone","kind":"field","source_field":"phone_primary"},
      {"position":6,"output_header":"Classification","kind":"field","source_field":"classification"},
      {"position":7,"output_header":"Status","kind":"field","source_field":"status"},
      {"position":8,"output_header":"Work auth","kind":"field","source_field":"work_auth_status"},
      {"position":9,"output_header":"External ID","kind":"field","source_field":"external_id"}
    ]',
    1, 1, NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM export_templates
     WHERE scope = 'platform'
       AND dataset = 'people_directory'
       AND name = 'People Directory (default)'
);

INSERT INTO export_templates
    (scope, tenant_id, dataset, name, delimiter, quote_char, has_header_row,
     encoding, column_mappings_json, is_active, is_system, created_at)
SELECT
    'platform', NULL, 'placements_directory', 'Placements (default)',
    ',', '"', 1, 'utf-8',
    '[
      {"position":1,"output_header":"Placement ID","kind":"field","source_field":"placement_id"},
      {"position":2,"output_header":"Person name","kind":"field","source_field":"person_name"},
      {"position":3,"output_header":"Person email","kind":"field","source_field":"person_email"},
      {"position":4,"output_header":"Title","kind":"field","source_field":"title"},
      {"position":5,"output_header":"Status","kind":"field","source_field":"status"},
      {"position":6,"output_header":"Engagement type","kind":"field","source_field":"engagement_type"},
      {"position":7,"output_header":"Start date","kind":"field","source_field":"start_date"},
      {"position":8,"output_header":"End date","kind":"field","source_field":"end_date"},
      {"position":9,"output_header":"End client","kind":"field","source_field":"end_client_name"},
      {"position":10,"output_header":"Bill rate","kind":"field","source_field":"bill_rate"},
      {"position":11,"output_header":"Pay rate","kind":"field","source_field":"pay_rate"},
      {"position":12,"output_header":"External ID","kind":"field","source_field":"external_id"}
    ]',
    1, 1, NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM export_templates
     WHERE scope = 'platform'
       AND dataset = 'placements_directory'
       AND name = 'Placements (default)'
);
