-- Billing Module — Phase A0 init (SPEC §3 subset)
-- Idempotent. utf8mb4_unicode_ci. Cloudways MySQL 5.7+ compatible.

CREATE TABLE IF NOT EXISTS billing_invoices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    invoice_number VARCHAR(40) NOT NULL,
    client_name VARCHAR(255) NOT NULL,
    bill_to_json TEXT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    issue_date DATE NOT NULL,
    due_date DATE NOT NULL,
    period_start DATE NULL,
    period_end DATE NULL,
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
    tax_total DECIMAL(12,2) NOT NULL DEFAULT 0,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount_due DECIMAL(12,2) NOT NULL DEFAULT 0,
    status ENUM('draft','approved','sent','partially_paid','paid','void') NOT NULL DEFAULT 'draft',
    po_number VARCHAR(80) NULL,
    notes_internal TEXT NULL,
    notes_external TEXT NULL,
    aggregation ENUM('per_placement','per_client') NOT NULL DEFAULT 'per_placement',
    created_by_user_id BIGINT UNSIGNED NULL,
    approved_by_user_id BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    sent_at DATETIME NULL,
    voided_at DATETIME NULL,
    voided_by_user_id BIGINT UNSIGNED NULL,
    void_reason VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bi_tenant_number (tenant_id, invoice_number),
    INDEX idx_bi_tenant_status_due (tenant_id, status, due_date),
    INDEX idx_bi_tenant_client (tenant_id, client_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_invoice_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id BIGINT UNSIGNED NOT NULL,
    line_no INT UNSIGNED NOT NULL DEFAULT 1,
    source_type ENUM('time','manual') NOT NULL DEFAULT 'manual',
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
    INDEX idx_bil_invoice (invoice_id),
    INDEX idx_bil_source (source_type, source_ref_id),
    CONSTRAINT fk_bil_invoice FOREIGN KEY (invoice_id) REFERENCES billing_invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    client_name VARCHAR(255) NOT NULL,
    received_at DATE NOT NULL,
    method ENUM('ach','wire','check','card','cash','other') NOT NULL DEFAULT 'ach',
    reference VARCHAR(120) NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    unallocated_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bp_tenant_client (tenant_id, client_name),
    INDEX idx_bp_tenant_received (tenant_id, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_payment_allocations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_id BIGINT UNSIGNED NOT NULL,
    invoice_id BIGINT UNSIGNED NOT NULL,
    amount_applied DECIMAL(12,2) NOT NULL DEFAULT 0,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    applied_by_user_id BIGINT UNSIGNED NULL,
    INDEX idx_bpa_payment (payment_id),
    INDEX idx_bpa_invoice (invoice_id),
    CONSTRAINT fk_bpa_payment FOREIGN KEY (payment_id) REFERENCES billing_payments(id) ON DELETE CASCADE,
    CONSTRAINT fk_bpa_invoice FOREIGN KEY (invoice_id) REFERENCES billing_invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_invoice_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    invoice_id BIGINT UNSIGNED NOT NULL,
    token VARCHAR(96) NOT NULL,
    token_hash VARBINARY(32) NOT NULL,
    issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NULL,
    last_viewed_at DATETIME NULL,
    view_count INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_bit_token (token),
    INDEX idx_bit_tenant_invoice (tenant_id, invoice_id),
    CONSTRAINT fk_bit_invoice FOREIGN KEY (invoice_id) REFERENCES billing_invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tenant config (additive, idempotent via information_schema guard)
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tenants' AND COLUMN_NAME='billing_tax_rate_pct');
SET @sql := IF(@col_exists=0,'ALTER TABLE tenants ADD COLUMN billing_tax_rate_pct DECIMAL(5,2) NULL DEFAULT NULL','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tenants' AND COLUMN_NAME='billing_invoice_prefix');
SET @sql := IF(@col_exists=0,'ALTER TABLE tenants ADD COLUMN billing_invoice_prefix VARCHAR(20) NULL DEFAULT "INV"','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tenants' AND COLUMN_NAME='billing_next_invoice_seq');
SET @sql := IF(@col_exists=0,'ALTER TABLE tenants ADD COLUMN billing_next_invoice_seq INT UNSIGNED NOT NULL DEFAULT 1','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tenants' AND COLUMN_NAME='billing_invoice_terms');
SET @sql := IF(@col_exists=0,'ALTER TABLE tenants ADD COLUMN billing_invoice_terms VARCHAR(40) NULL DEFAULT "NET30"','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tenants' AND COLUMN_NAME='billing_payment_instructions');
SET @sql := IF(@col_exists=0,'ALTER TABLE tenants ADD COLUMN billing_payment_instructions TEXT NULL','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
