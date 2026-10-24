-- Entity-scoped, revocable machine credentials for the versioned CoreOne journal API.
-- Only a SHA-256 digest is stored. The clear token is returned once at issuance.
CREATE TABLE IF NOT EXISTS coreone_accounting_credentials (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    label VARCHAR(120) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    token_last4 CHAR(4) NOT NULL,
    expires_at DATETIME NOT NULL,
    last_used_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_coreone_accounting_token_hash (token_hash),
    INDEX idx_coreone_accounting_tenant (tenant_id, entity_id, revoked_at),
    INDEX idx_coreone_accounting_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
