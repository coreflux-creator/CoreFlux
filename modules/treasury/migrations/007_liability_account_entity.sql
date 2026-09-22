-- Liability accounts, like deposit accounts, belong to one legal entity.
-- Existing rows are backfilled only when the tenant has exactly one active
-- entity. Multi-entity tenants must assign the owner explicitly.

SET @col := (SELECT COUNT(*)
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'treasury_liability_accounts'
                AND COLUMN_NAME = 'entity_id');
SET @sql := IF(@col = 0,
    'ALTER TABLE treasury_liability_accounts ADD COLUMN entity_id BIGINT UNSIGNED NULL AFTER account_id',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*)
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'treasury_liability_accounts'
                AND INDEX_NAME = 'idx_tla_tenant_entity');
SET @sql := IF(@idx = 0,
    'ALTER TABLE treasury_liability_accounts ADD INDEX idx_tla_tenant_entity (tenant_id, entity_id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE treasury_liability_accounts tla
JOIN (
    SELECT tenant_id, MIN(id) AS entity_id
      FROM accounting_entities
     WHERE active = 1
     GROUP BY tenant_id
    HAVING COUNT(*) = 1
) single_entity ON single_entity.tenant_id = tla.tenant_id
SET tla.entity_id = single_entity.entity_id
WHERE tla.entity_id IS NULL;
