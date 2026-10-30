SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_outbox'
               AND COLUMN_NAME = 'cc_addresses_json');
SET @sql := IF(@col = 0,
    'ALTER TABLE mail_outbox ADD COLUMN cc_addresses_json TEXT NULL AFTER to_addresses_json',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
