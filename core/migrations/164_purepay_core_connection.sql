-- Separate from the AP payment credential. Creates no access or provider records.
CREATE TABLE IF NOT EXISTS purepay_core_connections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    api_key_ct VARBINARY(2048) NULL,
    api_key_last4 VARCHAR(8) NULL,
    status ENUM('active','revoked') NOT NULL DEFAULT 'revoked',
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    core_instance_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    remote_organization_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
    remote_organization_name VARCHAR(200) NULL,
    remote_api_base VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    binding_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    verified_by BIGINT UNSIGNED NULL,
    verified_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_purepay_core_tenant (tenant_id),
    UNIQUE KEY uq_purepay_core_organization (core_instance_id, remote_organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
