-- Custom fields governance columns for modules still using the generic tables.
--
-- People has spec-aligned tables. Placements and any other manifest-declared
-- legacy consumers use custom_fields/custom_values through core/custom_fields.php.
-- These guarded alters add the metadata needed for shared layouts, PII gating,
-- soft delete, and deterministic ordering while preserving existing installs.

CREATE TABLE IF NOT EXISTS custom_fields (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    module VARCHAR(50) NOT NULL,
    field_name VARCHAR(100) NOT NULL,
    field_label VARCHAR(100) NOT NULL,
    field_type VARCHAR(50) NOT NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    options TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cf_tenant_module (tenant_id, module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS custom_values (
    id INT AUTO_INCREMENT PRIMARY KEY,
    record_id INT NOT NULL,
    field_id INT NOT NULL,
    tenant_id INT NOT NULL,
    value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cv_tenant_record (tenant_id, record_id),
    INDEX idx_cv_field (field_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @tbl := (
    SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'custom_fields'
);

SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'custom_fields' AND COLUMN_NAME = 'pii'
);
SET @sql := IF(@tbl = 1 AND @col = 0,
    'ALTER TABLE custom_fields ADD COLUMN pii TINYINT(1) NOT NULL DEFAULT 0 AFTER is_required',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'custom_fields' AND COLUMN_NAME = 'order_index'
);
SET @sql := IF(@tbl = 1 AND @col = 0,
    'ALTER TABLE custom_fields ADD COLUMN order_index INT NOT NULL DEFAULT 0 AFTER pii',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'custom_fields' AND COLUMN_NAME = 'is_active'
);
SET @sql := IF(@tbl = 1 AND @col = 0,
    'ALTER TABLE custom_fields ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER order_index',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'custom_fields' AND COLUMN_NAME = 'deleted_at'
);
SET @sql := IF(@tbl = 1 AND @col = 0,
    'ALTER TABLE custom_fields ADD COLUMN deleted_at DATETIME NULL AFTER created_at',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'custom_fields' AND COLUMN_NAME = 'updated_at'
);
SET @sql := IF(@tbl = 1 AND @col = 0,
    'ALTER TABLE custom_fields ADD COLUMN updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
