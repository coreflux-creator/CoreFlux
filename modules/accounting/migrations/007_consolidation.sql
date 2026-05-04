-- =======================================================================
-- Accounting Phase 2 Sprint A.7 — Consolidation foundations
-- -----------------------------------------------------------------------
-- (1) accounting_entity_relationships  — directional ownership edges
--     with % and relationship type (subsidiary / affiliate / branch /
--     jv / other). Powers consolidated reporting.
-- (2) billing_invoices.entity_id       — invoices belong to an entity.
-- (3) people.entity_id                 — employees belong to an entity.
--
-- All idempotent via information_schema. utf8mb4_unicode_ci.
-- =======================================================================

CREATE TABLE IF NOT EXISTS accounting_entity_relationships (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id          BIGINT UNSIGNED NOT NULL,
    parent_entity_id   BIGINT UNSIGNED NOT NULL,
    child_entity_id    BIGINT UNSIGNED NOT NULL,
    ownership_pct      DECIMAL(7,4) NOT NULL DEFAULT 100.0000,
    relationship_type  ENUM('subsidiary','affiliate','branch','jv','other') NOT NULL DEFAULT 'subsidiary',
    consolidation_method ENUM('full','equity','cost','none') NOT NULL DEFAULT 'full',
    effective_from     DATE NOT NULL,
    effective_to       DATE NULL,
    notes              VARCHAR(255) NULL,
    active             TINYINT(1) NOT NULL DEFAULT 1,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_aer (tenant_id, parent_entity_id, child_entity_id, effective_from),
    INDEX idx_aer_tenant (tenant_id, active),
    INDEX idx_aer_child  (child_entity_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- billing_invoices.entity_id
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'billing_invoices'
               AND COLUMN_NAME  = 'entity_id');
SET @sql := IF(@col = 0,
    'ALTER TABLE billing_invoices ADD COLUMN entity_id BIGINT UNSIGNED NULL AFTER tenant_id, ADD INDEX idx_binv_tenant_entity (tenant_id, entity_id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- people.entity_id (main employee record)
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'people'
               AND COLUMN_NAME  = 'entity_id');
SET @sql := IF(@col = 0,
    'ALTER TABLE people ADD COLUMN entity_id BIGINT UNSIGNED NULL AFTER tenant_id, ADD INDEX idx_people_tenant_entity (tenant_id, entity_id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ap_bills.entity_id (bills often belong to a specific entity's AP — still
-- splittable via IC, but the bill ITSELF has a home entity)
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'ap_bills'
               AND COLUMN_NAME  = 'entity_id');
SET @sql := IF(@col = 0,
    'ALTER TABLE ap_bills ADD COLUMN entity_id BIGINT UNSIGNED NULL AFTER tenant_id, ADD INDEX idx_apbills_tenant_entity (tenant_id, entity_id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
