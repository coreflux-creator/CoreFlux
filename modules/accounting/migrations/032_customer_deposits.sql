-- Customer money received before invoice application is a liability, not revenue or AR.
INSERT IGNORE INTO accounting_accounts
    (tenant_id, code, name, account_type, subtype, normal_side,
     is_postable, is_system_account, statement_section, sort_order, active)
SELECT t.id, '2300', 'Customer Deposits', 'liability', 'current_liability',
       'credit', 1, 1, 'current_liabilities', 400, 1
  FROM tenants t
 WHERE COALESCE(t.is_active, 1) = 1;

SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE billing_payment_allocations ADD COLUMN application_je_id BIGINT UNSIGNED NULL', 'DO 0')
    FROM information_schema.columns WHERE table_schema = DATABASE()
      AND table_name = 'billing_payment_allocations' AND column_name = 'application_je_id');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS billing_deposit_applications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    payment_id BIGINT UNSIGNED NOT NULL,
    invoice_id BIGINT UNSIGNED NOT NULL,
    allocation_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(18,2) NOT NULL,
    request_key VARCHAR(80) NOT NULL,
    journal_entry_id BIGINT UNSIGNED NOT NULL,
    applied_at DATE NOT NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bda_payment_request (tenant_id, payment_id, request_key),
    UNIQUE KEY uq_bda_allocation (tenant_id, allocation_id),
    INDEX idx_bda_payment (tenant_id, payment_id),
    INDEX idx_bda_invoice (tenant_id, invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE billing_payment_allocations ADD INDEX idx_bpa_application_je (application_je_id)', 'DO 0')
    FROM information_schema.statistics WHERE table_schema = DATABASE()
      AND table_name = 'billing_payment_allocations' AND index_name = 'idx_bpa_application_je');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
