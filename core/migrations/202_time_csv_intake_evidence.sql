-- CSV assertions remain source evidence; a different Core reviewer decides time.
CREATE TABLE IF NOT EXISTS time_csv_imports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    request_hash CHAR(64) NOT NULL,
    source_hash CHAR(64) NOT NULL,
    source_ct LONGTEXT NOT NULL,
    options_json LONGTEXT NOT NULL,
    result_json LONGTEXT NULL,
    imported_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_time_csv_request (tenant_id,request_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS time_csv_row_evidence (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    import_id BIGINT UNSIGNED NOT NULL,
    source_row INT UNSIGNED NOT NULL,
    time_entry_id BIGINT UNSIGNED NOT NULL,
    entry_hash CHAR(64) NOT NULL,
    hours_basis ENUM('daily','weekly_total') NOT NULL DEFAULT 'daily',
    source_approval_json LONGTEXT NULL,
    is_current TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_time_csv_row (tenant_id,import_id,source_row),
    KEY idx_time_csv_entry (tenant_id,time_entry_id,is_current)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
