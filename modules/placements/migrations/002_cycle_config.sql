-- Cycle assignment per placement.
-- Allows a single placement to bill on one cadence (billing_cycle_id),
-- pay vendors on another (ap_cycle_id), and pay employees/contractors on
-- a third (payroll_cycle_id) — all decoupled. Time settlement walks these
-- pointers to know which downstream bundles to emit.
--
-- Idempotent. utf8mb4_unicode_ci. Cloudways MySQL 5.7+ compatible.

SELECT COUNT(*) INTO @col_exists
  FROM information_schema.columns
 WHERE table_schema = DATABASE()
   AND table_name   = 'placements'
   AND column_name  = 'billing_cycle_id';
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE placements ADD COLUMN billing_cycle_id BIGINT UNSIGNED NULL AFTER bulk_uploads_can_be_pre_approved',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists
  FROM information_schema.columns
 WHERE table_schema = DATABASE()
   AND table_name   = 'placements'
   AND column_name  = 'ap_cycle_id';
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE placements ADD COLUMN ap_cycle_id BIGINT UNSIGNED NULL AFTER billing_cycle_id',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists
  FROM information_schema.columns
 WHERE table_schema = DATABASE()
   AND table_name   = 'placements'
   AND column_name  = 'payroll_cycle_id';
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE placements ADD COLUMN payroll_cycle_id BIGINT UNSIGNED NULL AFTER ap_cycle_id',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Index for downstream "what placements feed this cycle?" queries.
SELECT COUNT(*) INTO @idx_exists
  FROM information_schema.statistics
 WHERE table_schema = DATABASE()
   AND table_name   = 'placements'
   AND index_name   = 'idx_pl_billing_cycle';
SET @sql := IF(@idx_exists = 0,
  'CREATE INDEX idx_pl_billing_cycle ON placements (tenant_id, billing_cycle_id)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @idx_exists
  FROM information_schema.statistics
 WHERE table_schema = DATABASE()
   AND table_name   = 'placements'
   AND index_name   = 'idx_pl_payroll_cycle';
SET @sql := IF(@idx_exists = 0,
  'CREATE INDEX idx_pl_payroll_cycle ON placements (tenant_id, payroll_cycle_id)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
