-- A read-only audit preset retaining the complete calculation/source identity.
-- Existing tenant templates and provider presets are not rewritten.
INSERT INTO export_templates
    (scope,tenant_id,dataset,name,delimiter,quote_char,has_header_row,encoding,column_mappings_json,is_active,is_system)
SELECT 'platform',NULL,'payroll_disbursements','Payroll calculation audit',',','"',1,'utf-8',
'[
 {"position":1,"output_header":"Run","kind":"field","source_field":"run_id"},
 {"position":2,"output_header":"Run status","kind":"field","source_field":"run_status"},
 {"position":3,"output_header":"Review","kind":"field","source_field":"calculation_review_status"},
 {"position":4,"output_header":"Calculation revision","kind":"field","source_field":"computation_revision"},
 {"position":5,"output_header":"Calculation fingerprint","kind":"field","source_field":"computation_sha256"},
 {"position":6,"output_header":"Pay date","kind":"field","source_field":"pay_date"},
 {"position":7,"output_header":"Period start","kind":"field","source_field":"period_start"},
 {"position":8,"output_header":"Period end","kind":"field","source_field":"period_end"},
 {"position":9,"output_header":"Line","kind":"field","source_field":"line_item_id"},
 {"position":10,"output_header":"People company","kind":"field","source_field":"people_owner_tenant_id"},
 {"position":11,"output_header":"Employee number","kind":"field","source_field":"employee_number"},
 {"position":12,"output_header":"Person number","kind":"field","source_field":"person_number"},
 {"position":13,"output_header":"First name","kind":"field","source_field":"employee_first_name"},
 {"position":14,"output_header":"Last name","kind":"field","source_field":"employee_last_name"},
 {"position":15,"output_header":"Earnings basis","kind":"field","source_field":"earnings_basis"},
 {"position":16,"output_header":"Regular hours","kind":"field","source_field":"regular_hours"},
 {"position":17,"output_header":"Overtime hours","kind":"field","source_field":"overtime_hours"},
 {"position":18,"output_header":"Double-time hours","kind":"field","source_field":"doubletime_hours"},
 {"position":19,"output_header":"PTO hours","kind":"field","source_field":"pto_hours"},
 {"position":20,"output_header":"Holiday hours","kind":"field","source_field":"holiday_hours"},
 {"position":21,"output_header":"Sick hours","kind":"field","source_field":"sick_hours"},
 {"position":22,"output_header":"Gross cents","kind":"field","source_field":"gross_pay_cents"},
 {"position":23,"output_header":"Net cents","kind":"field","source_field":"net_pay_cents"},
 {"position":24,"output_header":"Earnings details","kind":"field","source_field":"earnings_components_json"},
 {"position":25,"output_header":"Approved time receipt","kind":"field","source_field":"source_time_json"},
 {"position":26,"output_header":"Approved time fingerprint","kind":"field","source_field":"source_time_sha256"}
]',1,1
WHERE NOT EXISTS (SELECT 1 FROM export_templates
    WHERE scope='platform' AND dataset='payroll_disbursements' AND name='Payroll calculation audit' AND is_system=1);
