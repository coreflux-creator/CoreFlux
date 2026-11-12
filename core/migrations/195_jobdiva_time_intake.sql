-- Required, retained source/entry receipts; no approval or payroll authority.
CREATE TABLE IF NOT EXISTS jobdiva_time_intake (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    connection_id BIGINT UNSIGNED NOT NULL,
    connection_identity_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    external_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mapping_id BIGINT UNSIGNED NOT NULL,
    time_entry_id BIGINT UNSIGNED NOT NULL,
    people_tenant_id BIGINT UNSIGNED NOT NULL,
    placements_tenant_id BIGINT UNSIGNED NOT NULL,
    previous_id BIGINT UNSIGNED NULL,
    source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_ct MEDIUMBLOB NOT NULL,
    entry_ct MEDIUMBLOB NOT NULL,
    source_approval_state VARCHAR(32) NOT NULL DEFAULT 'unverified',
    actor_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_jobdiva_time_source (tenant_id, external_id, id),
    KEY ix_jobdiva_time_entry (tenant_id, time_entry_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
