-- Platform export template preset for the Staffing Clients governed dataset.
--
-- Idempotent seed. This keeps the Clients screen export-template picker useful
-- immediately while preserving tenant-specific templates on top.

INSERT INTO export_templates
    (scope, tenant_id, dataset, name, delimiter, quote_char, has_header_row,
     encoding, column_mappings_json, is_active, is_system, created_at)
SELECT
    'platform', NULL, 'staffing_clients', 'Staffing Clients (default)',
    ',', '"', 1, 'utf-8',
    '[
      {"position":1,"output_header":"Client ID","kind":"field","source_field":"client_id"},
      {"position":2,"output_header":"Client name","kind":"field","source_field":"name"},
      {"position":3,"output_header":"Legal name","kind":"field","source_field":"legal_name"},
      {"position":4,"output_header":"External ID","kind":"field","source_field":"external_id"},
      {"position":5,"output_header":"Source system","kind":"field","source_field":"source_system"},
      {"position":6,"output_header":"Industry","kind":"field","source_field":"industry"},
      {"position":7,"output_header":"Primary contact","kind":"field","source_field":"primary_contact_name"},
      {"position":8,"output_header":"Primary email","kind":"field","source_field":"primary_contact_email"},
      {"position":9,"output_header":"Payment terms days","kind":"field","source_field":"payment_terms_days"},
      {"position":10,"output_header":"Status","kind":"field","source_field":"status"},
      {"position":11,"output_header":"MSA status","kind":"field","source_field":"msa_status"},
      {"position":12,"output_header":"Active placements","kind":"field","source_field":"active_placements"}
    ]',
    1, 1, NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM export_templates
     WHERE scope = 'platform'
       AND dataset = 'staffing_clients'
       AND name = 'Staffing Clients (default)'
);
