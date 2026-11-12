-- Keep removed components and their source approvals; current import readers skip them.
SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='quanta_time_imports' AND column_name='is_current');
SET @sql := IF(@col_exists=0, 'ALTER TABLE quanta_time_imports ADD COLUMN is_current BOOLEAN NOT NULL DEFAULT 1', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Immutable reviewed corrections. Snapshots retain old entry IDs, frozen approvals,
-- import provenance and weekly headers even when the current component pointer changes.
CREATE TABLE IF NOT EXISTS quanta_time_revisions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    source_entry_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    review_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(500) NOT NULL,
    before_ct MEDIUMBLOB NOT NULL,
    after_ct MEDIUMBLOB NOT NULL,
    source_ct MEDIUMBLOB NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_quanta_revision_review (tenant_id, review_hash),
    KEY ix_quanta_revision_history (tenant_id, source_entry_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
