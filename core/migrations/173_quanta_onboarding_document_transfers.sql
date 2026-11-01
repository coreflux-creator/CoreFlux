-- Reserve the physical object identity before IO, so a failed or uncertain
-- transfer remains attributable and can be verified/reused by the same receipt.
CREATE TABLE IF NOT EXISTS quanta_onboarding_document_transfers (
    tenant_id BIGINT UNSIGNED NOT NULL,
    import_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    case_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    people_tenant_id BIGINT UNSIGNED NOT NULL,
    person_id BIGINT UNSIGNED NOT NULL,
    task_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    workspace_id VARCHAR(128) NOT NULL,
    provider_receipt_id CHAR(68) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    receipt_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    descriptor_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    binding_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    storage_identity CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    storage_key VARCHAR(512) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state ENUM('reserved','stored','registered') NOT NULL DEFAULT 'reserved',
    stamp_json TEXT NULL,
    document_id BIGINT UNSIGNED NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id,import_id),
    UNIQUE KEY uq_quanta_document_object (people_tenant_id,storage_key),
    KEY ix_quanta_document_case (tenant_id,case_id,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
