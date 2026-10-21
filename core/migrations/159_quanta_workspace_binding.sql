-- No keys, provider records or execution permissions are created by this migration.
SET @quanta_revision_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='quanta_connections' AND column_name='binding_revision');
SET @quanta_revision_sql := IF(@quanta_revision_exists=0, 'ALTER TABLE quanta_connections ADD COLUMN binding_revision BIGINT UNSIGNED NOT NULL DEFAULT 1', 'DO 0');
PREPARE quanta_revision_stmt FROM @quanta_revision_sql;
EXECUTE quanta_revision_stmt;
DEALLOCATE PREPARE quanta_revision_stmt;

CREATE TABLE IF NOT EXISTS quanta_workspace_bindings (
    tenant_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    connection_id BIGINT UNSIGNED NOT NULL,
    core_instance_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    remote_workspace_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    remote_workspace_name VARCHAR(200) NOT NULL,
    contract_version VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    context_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    binding_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state ENUM('verified','revoked') NOT NULL DEFAULT 'verified',
    verified_by BIGINT UNSIGNED NOT NULL,
    verified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
