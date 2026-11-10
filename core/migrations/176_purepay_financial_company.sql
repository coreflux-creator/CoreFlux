-- Ordinary financial keys have a separate reviewed company binding from the
-- staffing integration. A key rotation or disconnect never frees an org claim.
-- Existing connections are deliberately left unconfirmed; no identity guesses.
CREATE TABLE IF NOT EXISTS purepay_financial_bindings (
    tenant_id INT UNSIGNED NOT NULL PRIMARY KEY,
    connection_id INT UNSIGNED NOT NULL,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    remote_api_base VARCHAR(500) COLLATE utf8mb4_bin NOT NULL,
    remote_organization_id VARCHAR(128) COLLATE utf8mb4_bin NOT NULL,
    remote_organization_name VARCHAR(200) NOT NULL,
    provider_key_scope ENUM('read','write') NOT NULL,
    binding_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    verified_by INT UNSIGNED NULL,
    verified_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purepay_financial_organizations (
    origin_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    organization_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tenant_id INT UNSIGNED NOT NULL,
    connection_id INT UNSIGNED NOT NULL,
    reviewed_by INT UNSIGNED NOT NULL,
    reviewed_at DATETIME NOT NULL,
    PRIMARY KEY (origin_hash, organization_id),
    UNIQUE KEY uq_pp_financial_company (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
