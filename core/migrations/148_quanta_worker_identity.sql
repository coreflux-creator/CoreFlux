-- Keep Quanta worker identity separate from effective-dated work routing.
-- A connection alone never creates or reassigns a CoreFlux person.
CREATE TABLE IF NOT EXISTS quanta_worker_links (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    worker_id VARCHAR(128) NOT NULL,
    person_id BIGINT UNSIGNED NOT NULL,
    linked_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_quanta_worker_identity (tenant_id, worker_id),
    UNIQUE KEY uq_quanta_person_identity (tenant_id, person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Source worker on each import lets an incorrect link be removed only when no
-- imported time depends on it. NULL legacy rows conservatively block unlink.
SET @quanta_imports_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'quanta_time_imports');
SET @quanta_worker_column_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'quanta_time_imports' AND column_name = 'worker_id');
SET @sql := IF(@quanta_imports_exists = 1 AND @quanta_worker_column_exists = 0,
    'ALTER TABLE quanta_time_imports ADD COLUMN worker_id VARCHAR(128) NULL AFTER quanta_entry_id, ADD KEY ix_quanta_import_worker (tenant_id, worker_id)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
