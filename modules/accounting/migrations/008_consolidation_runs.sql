-- =======================================================================
-- Accounting Phase 2 Sprint A.8 — Consolidation lock + run snapshots
-- + intercompany_group_id on billing_invoices
-- =======================================================================

CREATE TABLE IF NOT EXISTS accounting_consolidation_runs (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id             BIGINT UNSIGNED NOT NULL,
    report_type           ENUM('income_statement','balance_sheet','trial_balance') NOT NULL,
    period_from           DATE NULL,
    period_to             DATE NOT NULL,
    entity_ids_json       TEXT NOT NULL,           -- JSON array of entity ids in scope
    root_entity_id        BIGINT UNSIGNED NULL,
    payload_json          LONGTEXT NOT NULL,       -- full consolidated result at lock time
    ai_narrative          TEXT NULL,
    ai_narrative_generated_at DATETIME NULL,
    status                ENUM('locked','reversed','draft') NOT NULL DEFAULT 'locked',
    locked_at             DATETIME NULL,
    locked_by_user_id     BIGINT UNSIGNED NULL,
    reversed_at           DATETIME NULL,
    reversed_by_user_id   BIGINT UNSIGNED NULL,
    reverse_reason        VARCHAR(500) NULL,
    notes                 VARCHAR(500) NULL,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_acr_tenant_type_period (tenant_id, report_type, period_to),
    INDEX idx_acr_status             (tenant_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Add journal_entry_id to billing_invoices first if it's missing — the AR
-- posting flow (invoices.php "post" + "post_with_ic_split") writes to it,
-- but no earlier migration created the column on production schemas.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'billing_invoices'
               AND COLUMN_NAME  = 'journal_entry_id');
SET @sql := IF(@col = 0,
    'ALTER TABLE billing_invoices ADD COLUMN journal_entry_id BIGINT UNSIGNED NULL, ADD INDEX idx_binv_je (tenant_id, journal_entry_id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add intercompany_group_id to billing_invoices so invoices posted via
-- IC split are linked to their group alongside journal_entry_id.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'billing_invoices'
               AND COLUMN_NAME  = 'intercompany_group_id');
SET @sql := IF(@col = 0,
    'ALTER TABLE billing_invoices ADD COLUMN intercompany_group_id VARCHAR(64) NULL, ADD INDEX idx_binv_ic_group (tenant_id, intercompany_group_id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
