-- Immutable Core publication evidence for reviewed worker contact proposals.
-- No provider calls, contact updates, invitations or access grants.
CREATE TABLE IF NOT EXISTS quanta_profile_baselines (
    tenant_id BIGINT UNSIGNED NOT NULL,
    binding_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    baseline_id CHAR(68) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    baseline_version BIGINT UNSIGNED NOT NULL,
    people_tenant_id BIGINT UNSIGNED NOT NULL,
    person_id BIGINT UNSIGNED NOT NULL,
    source_workspace_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    worker_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    baseline_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    baseline_ct MEDIUMBLOB NOT NULL,
    delivery_id BIGINT UNSIGNED NOT NULL,
    provider_receipt_id CHAR(68) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, binding_hash, baseline_id, baseline_version),
    KEY ix_quanta_profile_person (tenant_id, people_tenant_id, person_id, baseline_version),
    KEY ix_quanta_profile_delivery (tenant_id, delivery_id)
) ENGINE=InnoDB;
