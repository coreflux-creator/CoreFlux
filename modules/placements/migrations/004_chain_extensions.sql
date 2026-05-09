-- Adds the columns the placements lib (placementChain + portal-credentials
-- helpers) selects from `placement_client_chain` but were missing from the
-- original 001_init.sql. Production tenants are hitting:
--   "Database column 'updated_at' is missing — a migration probably needs to run."
-- when loading a placement detail page (which calls placementChain()).
--
-- Six columns missing in the original schema:
--   company_id              — soft FK to companies (party-name disambiguation)
--   submittal_id            — VMS submittal reference
--   vms_job_id              — VMS job reference
--   portal_credentials_ct   — encrypted vendor portal credentials blob
--   kms_key_version         — encryption key version stamp
--   updated_at              — row mutation timestamp
--
-- Idempotent. utf8mb4_unicode_ci. Cloudways MySQL 5.7+ compatible.

SELECT COUNT(*) INTO @col_exists
  FROM information_schema.columns
 WHERE table_schema = DATABASE()
   AND table_name   = 'placement_client_chain'
   AND column_name  = 'company_id';
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE placement_client_chain ADD COLUMN company_id BIGINT UNSIGNED NULL AFTER party_role',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists
  FROM information_schema.columns
 WHERE table_schema = DATABASE()
   AND table_name   = 'placement_client_chain'
   AND column_name  = 'submittal_id';
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE placement_client_chain ADD COLUMN submittal_id VARCHAR(64) NULL AFTER contract_storage_object_id',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists
  FROM information_schema.columns
 WHERE table_schema = DATABASE()
   AND table_name   = 'placement_client_chain'
   AND column_name  = 'vms_job_id';
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE placement_client_chain ADD COLUMN vms_job_id VARCHAR(64) NULL AFTER submittal_id',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists
  FROM information_schema.columns
 WHERE table_schema = DATABASE()
   AND table_name   = 'placement_client_chain'
   AND column_name  = 'portal_credentials_ct';
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE placement_client_chain ADD COLUMN portal_credentials_ct MEDIUMBLOB NULL AFTER vms_job_id',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists
  FROM information_schema.columns
 WHERE table_schema = DATABASE()
   AND table_name   = 'placement_client_chain'
   AND column_name  = 'kms_key_version';
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE placement_client_chain ADD COLUMN kms_key_version VARCHAR(16) NULL AFTER portal_credentials_ct',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists
  FROM information_schema.columns
 WHERE table_schema = DATABASE()
   AND table_name   = 'placement_client_chain'
   AND column_name  = 'updated_at';
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE placement_client_chain ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Index for "all chain rows for a placement" lookups also used by
-- placementChain(). Existing uq_pcc_placement_position covers writes; this
-- gives reads a non-unique secondary path keyed by tenant_id first.
SELECT COUNT(*) INTO @idx_exists
  FROM information_schema.statistics
 WHERE table_schema = DATABASE()
   AND table_name   = 'placement_client_chain'
   AND index_name   = 'idx_pcc_company';
SET @sql := IF(@idx_exists = 0,
  'CREATE INDEX idx_pcc_company ON placement_client_chain (tenant_id, company_id)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
