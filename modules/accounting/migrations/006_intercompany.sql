-- =======================================================================
-- Accounting Phase 2 Sprint A.5 — Intercompany split engine
-- -----------------------------------------------------------------------
-- Lets a single logical transaction (bank feed match, AP bill, manual JE,
-- etc.) post as N balanced JEs — one per entity — linked by a shared
-- intercompany_group_id. Each entity's JE is fully balanced by
-- construction using a stored "due-from / due-to" account mapping per
-- (from_entity, to_entity) pair.
--
-- All ALTERs idempotent via information_schema. utf8mb4_unicode_ci.
-- =======================================================================

-- ---- 1. Mappings table (one row per entity pair direction) ----
CREATE TABLE IF NOT EXISTS accounting_intercompany_mappings (
    id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id              BIGINT UNSIGNED NOT NULL,
    from_entity_id         BIGINT UNSIGNED NOT NULL,
    to_entity_id           BIGINT UNSIGNED NOT NULL,
    due_from_account_code  VARCHAR(40) NOT NULL,   -- asset on from_entity's books (receivable from the other co)
    due_to_account_code    VARCHAR(40) NOT NULL,   -- liability on to_entity's books (payable to the other co)
    notes                  VARCHAR(255) NULL,
    active                 TINYINT(1) NOT NULL DEFAULT 1,
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_aim_pair (tenant_id, from_entity_id, to_entity_id),
    INDEX idx_aim_tenant (tenant_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- 2. Link JEs posted together under the same logical transaction ----
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'accounting_journal_entries'
               AND COLUMN_NAME  = 'intercompany_group_id');
SET @sql := IF(@col = 0,
    'ALTER TABLE accounting_journal_entries ADD COLUMN intercompany_group_id VARCHAR(64) NULL AFTER reverses_je_id, ADD INDEX idx_aje_ic_group (tenant_id, intercompany_group_id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---- 3. Tag each IC line with the counterparty entity ----
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'accounting_journal_entry_lines'
               AND COLUMN_NAME  = 'counterparty_entity_id');
SET @sql := IF(@col = 0,
    'ALTER TABLE accounting_journal_entry_lines ADD COLUMN counterparty_entity_id BIGINT UNSIGNED NULL AFTER counterparty_person_id, ADD INDEX idx_ajel_ic_entity (counterparty_entity_id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
