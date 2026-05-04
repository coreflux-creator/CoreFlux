-- Export Templates — tenant-defined CSV column mappings for ALL data exports.
--
-- Two scopes:
--   • `platform`  — seeded or master_admin-authored, visible to every tenant.
--   • `tenant`    — tenant-owned (created / cloned from platform).
--
-- Column mappings live in JSON: [{ position, output_header, source_field, kind, fixed_value }]
--   kind='field'  → take value at row[source_field]
--   kind='fixed'  → emit the static fixed_value string for every row
--
-- Datasets (payroll_disbursements, ap_payments, expenses, …) are code-side
-- (see /app/core/export_datasets.php); templates only declare the mapping.
--
-- Idempotent. utf8mb4_unicode_ci. Cloudways MySQL 5.7+ compatible.

CREATE TABLE IF NOT EXISTS export_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scope ENUM('platform','tenant') NOT NULL DEFAULT 'tenant',
    tenant_id INT UNSIGNED NULL,
    dataset VARCHAR(64) NOT NULL,
    name VARCHAR(160) NOT NULL,
    delimiter VARCHAR(4) NOT NULL DEFAULT ',',
    quote_char VARCHAR(2) NOT NULL DEFAULT '"',
    has_header_row TINYINT(1) NOT NULL DEFAULT 1,
    encoding VARCHAR(16) NOT NULL DEFAULT 'utf-8',
    column_mappings_json MEDIUMTEXT NOT NULL,
    based_on_template_id INT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_xtpl_tenant_dataset (tenant_id, dataset, is_active),
    INDEX idx_xtpl_scope_dataset (scope, dataset, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the two platform presets requested (Gusto Payroll Import + AP Payments
-- standard CSV). They are idempotent — re-running the migration leaves them
-- untouched unless someone has tweaked them on this host.
INSERT IGNORE INTO export_templates
    (scope, tenant_id, dataset, name, delimiter, quote_char, has_header_row,
     encoding, column_mappings_json, is_active, is_system, created_at)
VALUES
('platform', NULL, 'payroll_disbursements', 'Gusto Payroll Import (default)',
 ',', '"', 1, 'utf-8',
 '[
   {"position":1,"output_header":"First name","kind":"field","source_field":"employee_first_name"},
   {"position":2,"output_header":"Last name","kind":"field","source_field":"employee_last_name"},
   {"position":3,"output_header":"Regular hours","kind":"field","source_field":"regular_hours"},
   {"position":4,"output_header":"Overtime hours","kind":"field","source_field":"overtime_hours"},
   {"position":5,"output_header":"PTO hours","kind":"field","source_field":"pto_hours"},
   {"position":6,"output_header":"Reimbursement","kind":"field","source_field":"reimbursement_dollars"},
   {"position":7,"output_header":"Bonus","kind":"field","source_field":"bonus_dollars"}
 ]',
 1, 1, NOW()),

('platform', NULL, 'ap_payments', 'AP Payments — Standard CSV',
 ',', '"', 1, 'utf-8',
 '[
   {"position":1,"output_header":"Payment Date","kind":"field","source_field":"payment_date"},
   {"position":2,"output_header":"Vendor Name","kind":"field","source_field":"vendor_name"},
   {"position":3,"output_header":"Vendor ID","kind":"field","source_field":"vendor_external_id"},
   {"position":4,"output_header":"Amount","kind":"field","source_field":"amount_dollars"},
   {"position":5,"output_header":"Currency","kind":"fixed","fixed_value":"USD"},
   {"position":6,"output_header":"Memo","kind":"field","source_field":"memo"},
   {"position":7,"output_header":"Account Number","kind":"field","source_field":"bank_account_number"},
   {"position":8,"output_header":"Routing Number","kind":"field","source_field":"bank_routing_number"},
   {"position":9,"output_header":"Payment Method","kind":"fixed","fixed_value":"ACH"}
 ]',
 1, 1, NOW());
