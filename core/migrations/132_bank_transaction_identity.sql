-- 132_bank_transaction_identity.sql
--
-- Preserve bank transaction identity across Plaid reconnects and CSV backfills.
-- Provider transaction ids are aliases, not the business event itself: Plaid
-- may issue a new id after an Item is relinked. Duplicate statement rows remain
-- in the audit trail and point at the canonical row instead of being deleted.

SET @has_lines := (
    SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'accounting_bank_statement_lines'
);

SET @has_duplicate_of := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'accounting_bank_statement_lines'
       AND COLUMN_NAME = 'duplicate_of_line_id'
);
SET @sql := IF(@has_lines > 0 AND @has_duplicate_of = 0,
    'ALTER TABLE accounting_bank_statement_lines ADD COLUMN duplicate_of_line_id BIGINT UNSIGNED NULL AFTER matched_by_user_id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_dedupe_reason := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'accounting_bank_statement_lines'
       AND COLUMN_NAME = 'dedupe_reason'
);
SET @sql := IF(@has_lines > 0 AND @has_dedupe_reason = 0,
    'ALTER TABLE accounting_bank_statement_lines ADD COLUMN dedupe_reason VARCHAR(80) NULL AFTER duplicate_of_line_id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_deduplicated_at := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'accounting_bank_statement_lines'
       AND COLUMN_NAME = 'deduplicated_at'
);
SET @sql := IF(@has_lines > 0 AND @has_deduplicated_at = 0,
    'ALTER TABLE accounting_bank_statement_lines ADD COLUMN deduplicated_at DATETIME NULL AFTER dedupe_reason',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_duplicate_index := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'accounting_bank_statement_lines'
       AND INDEX_NAME = 'idx_absl_duplicate_of'
);
SET @sql := IF(@has_lines > 0 AND @has_duplicate_index = 0,
    'ALTER TABLE accounting_bank_statement_lines ADD INDEX idx_absl_duplicate_of (tenant_id, bank_account_id, duplicate_of_line_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS accounting_bank_transaction_aliases (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id         BIGINT UNSIGNED NOT NULL,
    bank_account_id   BIGINT UNSIGNED NOT NULL,
    statement_line_id BIGINT UNSIGNED NOT NULL,
    provider          VARCHAR(32) NOT NULL,
    external_id       VARCHAR(160) NOT NULL,
    provider_item_id  VARCHAR(160) NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_abta_provider_id (tenant_id, bank_account_id, provider, external_id),
    INDEX idx_abta_line (tenant_id, statement_line_id),
    INDEX idx_abta_item (tenant_id, provider, provider_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
