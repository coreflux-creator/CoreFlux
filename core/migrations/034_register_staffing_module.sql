-- CoreStaffing — register the umbrella module in the platform `modules` table
-- so it appears in tenant sidebars/module switchers alongside People, Time, etc.
--
-- The `modules` table is created by the legacy install SQL dump; if it
-- doesn't exist on a fresh dev DB we skip silently.
--
-- Idempotent (INSERT IGNORE on unique name).

SET @modules_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'modules')
;
SET @sql := IF(@modules_exists = 1, "INSERT IGNORE INTO modules (name, link, is_active) VALUES ('Staffing', '/modules/staffing', 1)", 'DO 0')
;
PREPARE stmt FROM @sql
;
EXECUTE stmt
;
DEALLOCATE PREPARE stmt
;

-- Auto-enable Staffing for every tenant that already has Time or Placements
-- enabled — they're the operating predecessors and the cutover should be
-- transparent.
SET @tm_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'tenant_modules')
;
SET @sql := IF(@modules_exists = 1 AND @tm_exists = 1, "INSERT IGNORE INTO tenant_modules (tenant_id, module_key, is_enabled, enabled_at) SELECT DISTINCT tenant_id, 'staffing', 1, NOW() FROM tenant_modules WHERE module_key IN ('time','placements') AND is_enabled = 1", 'DO 0')
;
PREPARE stmt FROM @sql
;
EXECUTE stmt
;
DEALLOCATE PREPARE stmt
;
