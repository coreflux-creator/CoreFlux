-- Historical mappings/attempts stay unbound until reconciled; never infer their organization.
SET @pp_binding_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='purepay_payment_links' AND column_name='connection_fingerprint');
SET @pp_binding_sql := IF(@pp_binding_exists=0, 'ALTER TABLE purepay_payment_links ADD COLUMN connection_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL', 'DO 0');
PREPARE pp_binding_stmt FROM @pp_binding_sql;
EXECUTE pp_binding_stmt;
DEALLOCATE PREPARE pp_binding_stmt;
SET @pp_binding_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='purepay_vendor_mappings' AND column_name='connection_fingerprint');
SET @pp_binding_sql := IF(@pp_binding_exists=0, 'ALTER TABLE purepay_vendor_mappings ADD COLUMN connection_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL', 'DO 0');
PREPARE pp_binding_stmt FROM @pp_binding_sql;
EXECUTE pp_binding_stmt;
DEALLOCATE PREPARE pp_binding_stmt;

CREATE TABLE IF NOT EXISTS purepay_vendor_attempts (
    tenant_id BIGINT UNSIGNED NOT NULL,
    core_vendor_ref VARCHAR(120) NOT NULL,
    connection_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state ENUM('creating','verified','needs_review') NOT NULL DEFAULT 'creating',
    remote_vendor_id VARCHAR(120) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    PRIMARY KEY (tenant_id,core_vendor_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
