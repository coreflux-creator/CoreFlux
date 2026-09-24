-- Quanta time integration. Deployment creates no connection or import.

CREATE TABLE IF NOT EXISTS quanta_connections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    api_key_ct VARBINARY(2048) NULL,
    api_key_last4 VARCHAR(8) NULL,
    status ENUM('active','revoked','error') NOT NULL DEFAULT 'revoked',
    last_probe_at DATETIME NULL,
    last_probe_error VARCHAR(500) NULL,
    last_import_at DATETIME NULL,
    last_import_error VARCHAR(500) NULL,
    connected_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_quanta_connection_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A route is effective-dated because a worker may move between placements.
-- Empty worksite_id means an entry without a worksite, not a wildcard.
CREATE TABLE IF NOT EXISTS quanta_time_routes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    worker_id VARCHAR(128) NOT NULL,
    worksite_id VARCHAR(128) NOT NULL DEFAULT '',
    dimension_key CHAR(64) NOT NULL,
    dimension_values_json TEXT NULL,
    placement_id BIGINT UNSIGNED NOT NULL,
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_quanta_route_start (tenant_id, worker_id, worksite_id, dimension_key, effective_from),
    KEY ix_quanta_route_lookup (tenant_id, worker_id, worksite_id, dimension_key, effective_from, effective_to),
    KEY ix_quanta_route_placement (tenant_id, placement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quanta_time_imports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    quanta_entry_id VARCHAR(128) NOT NULL,
    component VARCHAR(32) NOT NULL,
    time_entry_id BIGINT UNSIGNED NOT NULL,
    timesheet_id BIGINT UNSIGNED NOT NULL,
    source_hash CHAR(64) NOT NULL,
    source_timesheet_status VARCHAR(32) NOT NULL,
    imported_by_user_id BIGINT UNSIGNED NULL,
    imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_quanta_import_source (tenant_id, quanta_entry_id, component),
    UNIQUE KEY uq_quanta_import_entry (tenant_id, time_entry_id),
    KEY ix_quanta_import_sheet (tenant_id, timesheet_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preserve a distinct source in the existing canonical time ledger.
SET @time_entries_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'time_entries');
SET @sql := IF(@time_entries_exists = 1,
    "ALTER TABLE time_entries MODIFY COLUMN source ENUM('ai_inbox','bulk_upload','manual_entry','client_portal_paste','quanta') NOT NULL DEFAULT 'manual_entry'",
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @source_system_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'source_system');
SET @sql := IF(@source_system_exists = 1,
    "ALTER TABLE time_entries MODIFY COLUMN source_system ENUM('manual','jobdiva','qbo','mercury','plaid','jaz','zoho','airtable','gusto','quanta','other') NOT NULL DEFAULT 'manual'",
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
