-- Customer-facing billing delivery belongs to a legal entity, not merely a workspace.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'billing_client_contacts'
               AND COLUMN_NAME = 'entity_id');
SET @sql := IF(@col = 0,
    'ALTER TABLE billing_client_contacts ADD COLUMN entity_id BIGINT UNSIGNED NULL AFTER tenant_id',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Only a sole active entity is unambiguous. Multi-entity legacy rows need review.
UPDATE billing_client_contacts c
JOIN (
    SELECT tenant_id, MIN(id) AS entity_id
      FROM accounting_entities WHERE active = 1
     GROUP BY tenant_id HAVING COUNT(*) = 1
) sole ON sole.tenant_id = c.tenant_id
   SET c.entity_id = sole.entity_id
 WHERE c.entity_id IS NULL;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'billing_client_contacts'
               AND INDEX_NAME = 'uq_bcc_tenant_entity_client');
SET @sql := IF(@idx = 0,
    'ALTER TABLE billing_client_contacts ADD UNIQUE KEY uq_bcc_tenant_entity_client (tenant_id, entity_id, client_name)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'billing_client_contacts'
               AND INDEX_NAME = 'uq_bcc_tenant_client');
SET @sql := IF(@idx > 0,
    'ALTER TABLE billing_client_contacts DROP INDEX uq_bcc_tenant_client',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS billing_entity_mail_settings (
    tenant_id BIGINT UNSIGNED NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    from_name VARCHAR(120) NULL,
    reply_to VARCHAR(255) NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
