-- Business references are persisted independently of source-system identifiers.
-- Retired rows remain reserved. No foreign-key cascade can recycle a number.
CREATE TABLE IF NOT EXISTS core_record_numbers (
    tenant_id BIGINT UNSIGNED NOT NULL,
    entity_type VARCHAR(24) NOT NULL,
    record_id BIGINT UNSIGNED NOT NULL,
    record_number VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    canonical_record_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    aliased_at DATETIME NULL,
    alias_actor_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (tenant_id, entity_type, record_id),
    UNIQUE KEY uq_record_number (tenant_id, entity_type, record_number),
    INDEX idx_number_canonical (tenant_id, entity_type, canonical_record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
