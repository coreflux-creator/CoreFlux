-- Source-reported approval metadata is distinct from Core's own approval.
-- Do not invent or backfill evidence for historical imports.
SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='quanta_time_imports' AND column_name='source_approval_json');
SET @sql := IF(@col_exists=0, 'ALTER TABLE quanta_time_imports ADD COLUMN source_approval_json LONGTEXT NULL', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='quanta_time_imports' AND column_name='source_approval_hash');
SET @sql := IF(@col_exists=0, 'ALTER TABLE quanta_time_imports ADD COLUMN source_approval_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS quanta_time_approval_evidence (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    quanta_time_import_id BIGINT UNSIGNED NOT NULL,
    source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_approval_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_approval_json LONGTEXT NOT NULL,
    source_workspace_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_workspace_binding_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    observed_by_user_id BIGINT UNSIGNED NOT NULL,
    observed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_quanta_approval_observation (tenant_id,quanta_time_import_id,source_hash,source_approval_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
