-- Existing CoreOne credentials retain journal/report access only.
SET @col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'coreone_accounting_credentials'
      AND column_name = 'scopes_json');
SET @sql := IF(@col = 0,
    'ALTER TABLE coreone_accounting_credentials ADD COLUMN scopes_json VARCHAR(255) NOT NULL DEFAULT ''["journals:write","reports:read"]'' AFTER label',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Idempotency/provenance only; invoices and their balances remain owned by Billing.
CREATE TABLE IF NOT EXISTS coreone_document_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    source_type VARCHAR(40) NOT NULL,
    source_record_id VARCHAR(120) NOT NULL,
    intent_hash CHAR(64) NOT NULL,
    target_id BIGINT UNSIGNED NOT NULL,
    credential_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_coreone_source (tenant_id, source_type, source_record_id),
    INDEX idx_coreone_target (tenant_id, source_type, target_id),
    INDEX idx_coreone_entity (tenant_id, entity_id, source_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
