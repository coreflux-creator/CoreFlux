-- C1 — worker_class on people (Sprint 3 / Industry Layer 1: Staffing)
--
-- Drives Time → AR / AP / Payroll routing for a staffing tenant.
-- Vertical-specific in INTENT (this is the staffing-edition column),
-- but the column itself is just a scoped enum and other verticals can
-- ignore it (default = 'employee' covers the universal employer case).
--
-- Idempotent. utf8mb4_unicode_ci. Cloudways MySQL 5.7+ compatible.

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'people' AND COLUMN_NAME = 'worker_class');
SET @sql := IF(@col = 0,
  "ALTER TABLE people ADD COLUMN worker_class ENUM('employee','w2_temp','contractor_1099','c2c','eor','referral','vendor_backed') NOT NULL DEFAULT 'employee'",
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'people' AND COLUMN_NAME = 'worker_class_meta_json');
SET @sql := IF(@col = 0,
  "ALTER TABLE people ADD COLUMN worker_class_meta_json TEXT NULL COMMENT 'extra routing data (corp legal name for c2c, agency id for eor, etc.)'",
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'people' AND INDEX_NAME = 'idx_people_tenant_worker_class');
SET @sql := IF(@idx = 0,
  'CREATE INDEX idx_people_tenant_worker_class ON people (tenant_id, worker_class)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
