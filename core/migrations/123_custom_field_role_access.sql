-- Custom field field-level role access.
--
-- The product contract allows each tenant custom field to declare role sets
-- for visibility and value editing. NULL/empty JSON means unrestricted, which
-- preserves existing fields while enabling stricter enterprise controls.

SET @tbl := (
    SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'people_custom_field_defs'
);

SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'people_custom_field_defs' AND COLUMN_NAME = 'visible_to_roles_json'
);
SET @sql := IF(@tbl = 1 AND @col = 0,
    'ALTER TABLE people_custom_field_defs ADD COLUMN visible_to_roles_json TEXT NULL AFTER pii',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'people_custom_field_defs' AND COLUMN_NAME = 'editable_by_roles_json'
);
SET @sql := IF(@tbl = 1 AND @col = 0,
    'ALTER TABLE people_custom_field_defs ADD COLUMN editable_by_roles_json TEXT NULL AFTER visible_to_roles_json',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @tbl := (
    SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'custom_fields'
);

SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'custom_fields' AND COLUMN_NAME = 'visible_to_roles_json'
);
SET @sql := IF(@tbl = 1 AND @col = 0,
    'ALTER TABLE custom_fields ADD COLUMN visible_to_roles_json TEXT NULL AFTER pii',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'custom_fields' AND COLUMN_NAME = 'editable_by_roles_json'
);
SET @sql := IF(@tbl = 1 AND @col = 0,
    'ALTER TABLE custom_fields ADD COLUMN editable_by_roles_json TEXT NULL AFTER visible_to_roles_json',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
