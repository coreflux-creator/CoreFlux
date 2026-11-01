-- Reviewed outbound envelopes. Migration never enables delivery or changes a provider.
CREATE TABLE IF NOT EXISTS integration_delivery_subjects (
    tenant_id BIGINT UNSIGNED NOT NULL,
    subject_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (tenant_id, subject_hash)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS integration_deliveries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    connection_ref VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    binding_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    entity_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    entity_tenant_id BIGINT UNSIGNED NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    operation VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    remote_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    review_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    dedupe_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    subject_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_ct MEDIUMBLOB NOT NULL,
    state ENUM('preview','approved','sending','delivered','uncertain','conflict','failed','cancelled') NOT NULL DEFAULT 'preview',
    idempotency_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    claim_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    remote_id VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    error_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    approved_at DATETIME NULL,
    attempted_at DATETIME NULL,
    finished_at DATETIME NULL,
    UNIQUE KEY uq_delivery_dedupe (tenant_id, dedupe_hash),
    UNIQUE KEY uq_delivery_idempotency (idempotency_key),
    KEY ix_delivery_state (tenant_id, provider, state, id),
    KEY ix_delivery_subject (tenant_id, subject_hash, state),
    KEY ix_delivery_entity (tenant_id, provider, entity_type, entity_tenant_id, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
