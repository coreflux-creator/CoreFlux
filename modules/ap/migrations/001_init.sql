-- AP Module — Phase A0 init (SPEC §3 subset, Plaid deferred)
-- Idempotent. utf8mb4_unicode_ci. Cloudways MySQL 5.7+ compatible.

CREATE TABLE IF NOT EXISTS ap_vendors_index (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    vendor_name VARCHAR(255) NOT NULL,
    vendor_type ENUM('1099_individual','c2c_corp','w9_business','utility','other') NOT NULL DEFAULT 'other',
    default_remit_to_json TEXT NULL,
    default_terms VARCHAR(40) NOT NULL DEFAULT 'NET30',
    tax_id_last4 CHAR(4) NULL,
    tax_id_full_ct VARBINARY(512) NULL,
    kms_key_version VARCHAR(64) NULL,
    requires_1099 TINYINT(1) NOT NULL DEFAULT 0,
    last_bill_at DATETIME NULL,
    placement_id_last BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_apv_tenant_name (tenant_id, vendor_name),
    INDEX idx_apv_tenant_type (tenant_id, vendor_type),
    INDEX idx_apv_tenant_1099 (tenant_id, requires_1099)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ap_bills (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    bill_number VARCHAR(80) NOT NULL,
    internal_ref VARCHAR(40) NOT NULL,
    vendor_name VARCHAR(255) NOT NULL,
    vendor_type ENUM('1099_individual','c2c_corp','w9_business','utility','other') NOT NULL DEFAULT 'other',
    received_at DATE NOT NULL,
    bill_date DATE NOT NULL,
    due_date DATE NOT NULL,
    period_start DATE NULL,
    period_end DATE NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
    tax_total DECIMAL(12,2) NOT NULL DEFAULT 0,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount_due DECIMAL(12,2) NOT NULL DEFAULT 0,
    status ENUM('inbox','pending_review','pending_approval','approved','partially_paid','paid','void','disputed') NOT NULL DEFAULT 'pending_approval',
    source ENUM('mail_inbox','manual','time_bundle','recurring','expense_report','referral') NOT NULL DEFAULT 'manual',
    source_ref_id BIGINT UNSIGNED NULL,
    po_number VARCHAR(80) NULL,
    placement_id BIGINT UNSIGNED NULL,
    attachment_storage_object_id BIGINT UNSIGNED NULL,
    journal_entry_id BIGINT UNSIGNED NULL,
    notes_internal TEXT NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    approved_by_user_id BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    voided_at DATETIME NULL,
    voided_by_user_id BIGINT UNSIGNED NULL,
    void_reason VARCHAR(500) NULL,
    disputed_at DATETIME NULL,
    dispute_reason VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_apb_tenant_internal (tenant_id, internal_ref),
    INDEX idx_apb_tenant_vendor_status (tenant_id, vendor_name, status),
    INDEX idx_apb_tenant_due_status (tenant_id, due_date, status),
    INDEX idx_apb_tenant_source (tenant_id, source, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ap_bill_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bill_id BIGINT UNSIGNED NOT NULL,
    line_no INT UNSIGNED NOT NULL DEFAULT 1,
    source_type ENUM('time','manual','recurring','expense','referral') NOT NULL DEFAULT 'manual',
    source_ref_id BIGINT UNSIGNED NULL,
    placement_id BIGINT UNSIGNED NULL,
    rate_snapshot_id BIGINT UNSIGNED NULL,
    description VARCHAR(500) NOT NULL,
    quantity DECIMAL(12,4) NOT NULL DEFAULT 0,
    unit VARCHAR(40) NOT NULL DEFAULT 'hour',
    unit_price DECIMAL(12,4) NOT NULL DEFAULT 0,
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
    tax_rate_pct DECIMAL(7,4) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    gl_expense_account_code VARCHAR(40) NULL,
    is_1099_eligible TINYINT(1) NOT NULL DEFAULT 0,
    INDEX idx_apbl_bill (bill_id),
    INDEX idx_apbl_source (source_type, source_ref_id),
    CONSTRAINT fk_apbl_bill FOREIGN KEY (bill_id) REFERENCES ap_bills(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ap_payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    vendor_name VARCHAR(255) NOT NULL,
    pay_date DATE NOT NULL,
    method ENUM('ach','wire','check','card','cash','plaid','other') NOT NULL DEFAULT 'ach',
    reference VARCHAR(120) NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    unallocated_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    bank_account_id BIGINT UNSIGNED NULL,
    status ENUM('draft','queued','sent','cleared','failed','void') NOT NULL DEFAULT 'draft',
    cleared_at DATETIME NULL,
    sent_at DATETIME NULL,
    voided_at DATETIME NULL,
    void_reason VARCHAR(500) NULL,
    journal_entry_id BIGINT UNSIGNED NULL,
    plaid_transfer_id VARCHAR(120) NULL,
    notes TEXT NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    sent_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_app_tenant_vendor (tenant_id, vendor_name),
    INDEX idx_app_tenant_status (tenant_id, status),
    INDEX idx_app_tenant_pay_date (tenant_id, pay_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ap_payment_allocations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_id BIGINT UNSIGNED NOT NULL,
    bill_id BIGINT UNSIGNED NOT NULL,
    amount_applied DECIMAL(12,2) NOT NULL DEFAULT 0,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    applied_by_user_id BIGINT UNSIGNED NULL,
    INDEX idx_appa_payment (payment_id),
    INDEX idx_appa_bill (bill_id),
    CONSTRAINT fk_appa_payment FOREIGN KEY (payment_id) REFERENCES ap_payments(id) ON DELETE CASCADE,
    CONSTRAINT fk_appa_bill FOREIGN KEY (bill_id) REFERENCES ap_bills(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ap_expense_reports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    submitter_person_id BIGINT UNSIGNED NULL,
    submitter_user_id BIGINT UNSIGNED NULL,
    period_label VARCHAR(40) NOT NULL,
    status ENUM('draft','submitted','approved','rejected','paid') NOT NULL DEFAULT 'draft',
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    submitted_at DATETIME NULL,
    approved_at DATETIME NULL,
    paid_at DATETIME NULL,
    approved_by_user_id BIGINT UNSIGNED NULL,
    rejected_reason VARCHAR(500) NULL,
    bill_id BIGINT UNSIGNED NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_aper_tenant_status (tenant_id, status),
    INDEX idx_aper_tenant_submitter (tenant_id, submitter_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ap_expense_report_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    expense_report_id BIGINT UNSIGNED NOT NULL,
    line_no INT UNSIGNED NOT NULL DEFAULT 1,
    expense_date DATE NOT NULL,
    category VARCHAR(80) NOT NULL,
    merchant VARCHAR(255) NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    gl_expense_account_code VARCHAR(40) NULL,
    receipt_storage_object_id BIGINT UNSIGNED NULL,
    description VARCHAR(500) NULL,
    billable_to_client_name VARCHAR(255) NULL,
    INDEX idx_aperl_report (expense_report_id),
    CONSTRAINT fk_aperl_report FOREIGN KEY (expense_report_id) REFERENCES ap_expense_reports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ap_1099_ledger (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    tax_year INT NOT NULL,
    vendor_name VARCHAR(255) NOT NULL,
    vendor_type ENUM('1099_individual','c2c_corp','w9_business','utility','other') NOT NULL DEFAULT '1099_individual',
    tax_id_last4 CHAR(4) NULL,
    tax_id_full_ct VARBINARY(512) NULL,
    total_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
    requires_1099_nec TINYINT(1) NOT NULL DEFAULT 0,
    form_storage_object_id BIGINT UNSIGNED NULL,
    submitted_to_irs_at DATETIME NULL,
    computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ap1099_tenant_year_vendor (tenant_id, tax_year, vendor_name),
    INDEX idx_ap1099_tenant_year (tenant_id, tax_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tenant config (additive, idempotent via information_schema guard)
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tenants' AND COLUMN_NAME='ap_bill_prefix');
SET @sql := IF(@col_exists=0,'ALTER TABLE tenants ADD COLUMN ap_bill_prefix VARCHAR(20) NULL DEFAULT "BILL"','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tenants' AND COLUMN_NAME='ap_next_bill_seq');
SET @sql := IF(@col_exists=0,'ALTER TABLE tenants ADD COLUMN ap_next_bill_seq INT UNSIGNED NOT NULL DEFAULT 1','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tenants' AND COLUMN_NAME='ap_default_terms');
SET @sql := IF(@col_exists=0,'ALTER TABLE tenants ADD COLUMN ap_default_terms VARCHAR(40) NULL DEFAULT "NET30"','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tenants' AND COLUMN_NAME='ap_1099_threshold');
SET @sql := IF(@col_exists=0,'ALTER TABLE tenants ADD COLUMN ap_1099_threshold DECIMAL(12,2) NOT NULL DEFAULT 600.00','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
