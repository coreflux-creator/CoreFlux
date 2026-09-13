-- Tenant product and service catalog for invoices that do not originate from
-- placements or approved time. Existing invoice lines remain valid snapshots.

CREATE TABLE IF NOT EXISTS billing_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(80) NOT NULL,
    name VARCHAR(255) NOT NULL,
    item_type ENUM('labor','expense','materials','fixed_fee','milestone','discount','subscription','mileage','per_diem','reimbursement','other') NOT NULL DEFAULT 'other',
    description VARCHAR(500) NULL,
    default_unit VARCHAR(40) NOT NULL DEFAULT 'each',
    default_unit_price DECIMAL(12,4) NULL,
    gl_revenue_account_code VARCHAR(40) NULL,
    taxable TINYINT(1) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    source_system VARCHAR(40) NULL,
    source_external_id VARCHAR(160) NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_billing_items_tenant_code (tenant_id, code),
    UNIQUE KEY uq_billing_items_tenant_source (tenant_id, source_system, source_external_id),
    INDEX idx_billing_items_tenant_active_name (tenant_id, active, name),
    INDEX idx_billing_items_tenant_type (tenant_id, item_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @catalog_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'billing_invoice_lines'
     AND COLUMN_NAME = 'catalog_item_id'
);
SET @catalog_sql := IF(
  @catalog_col = 0,
  'ALTER TABLE billing_invoice_lines ADD COLUMN catalog_item_id BIGINT UNSIGNED NULL AFTER source_ref_id',
  'DO 0'
);
PREPARE catalog_stmt FROM @catalog_sql;
EXECUTE catalog_stmt;
DEALLOCATE PREPARE catalog_stmt;

SET @catalog_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'billing_invoice_lines'
     AND INDEX_NAME = 'idx_bil_catalog_item'
);
SET @catalog_sql := IF(
  @catalog_idx = 0,
  'ALTER TABLE billing_invoice_lines ADD INDEX idx_bil_catalog_item (catalog_item_id)',
  'DO 0'
);
PREPARE catalog_stmt FROM @catalog_sql;
EXECUTE catalog_stmt;
DEALLOCATE PREPARE catalog_stmt;
