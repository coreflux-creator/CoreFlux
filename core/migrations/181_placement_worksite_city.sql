-- Preserve assignment working city independently of worker residence and company address.
SET @placements_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'placements');
SET @city_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'placements' AND column_name = 'worksite_city');
SET @sql := IF(@placements_exists = 1 AND @city_exists = 0,
    'ALTER TABLE placements ADD COLUMN worksite_city VARCHAR(255) NULL AFTER engagement_type', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- NULL tenant IDs do not deduplicate through a MySQL unique key; check explicitly.
SET @targets_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'integration_writable_targets');
SET @sql := IF(@targets_exists = 1,
    'INSERT INTO integration_writable_targets (target_module,target_table,target_column,value_type,description,default_linked_entity)
     SELECT ''placements'',''placements'',''worksite_city'',''string'',''Assignment worksite city; not worker residence'',''self''
     WHERE NOT EXISTS (SELECT 1 FROM integration_writable_targets WHERE tenant_id IS NULL AND target_module=''placements'' AND target_table=''placements'' AND target_column=''worksite_city'')', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
