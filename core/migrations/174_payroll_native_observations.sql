-- Read-only provider ledger observations, independent of Core payroll authority.
-- Capturing an observation does not allocate time, create payroll, or post a journal.
CREATE TABLE IF NOT EXISTS payroll_native_observations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    connection_id BIGINT UNSIGNED NOT NULL,
    organization_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    run_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    observation_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    core_source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_ct MEDIUMBLOB NOT NULL,
    observed_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payroll_native_observation(tenant_id,connection_id,run_id,observation_hash,core_source_hash),
    KEY ix_payroll_native_history(tenant_id,connection_id,run_id,id),
    CONSTRAINT fk_payroll_native_connection FOREIGN KEY(connection_id) REFERENCES purepay_core_connections(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
