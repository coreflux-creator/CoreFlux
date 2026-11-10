-- An immutable decision is also the required audit and return-message outbox.
-- The source identity is independent of API-key rotations or review tokens.
CREATE TABLE IF NOT EXISTS quanta_profile_decisions (
    tenant_id BIGINT UNSIGNED NOT NULL,
    source_workspace_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    change_id CHAR(68) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    binding_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    people_tenant_id BIGINT UNSIGNED NOT NULL,
    person_id BIGINT UNSIGNED NOT NULL,
    worker_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    review_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    proposal_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    decision ENUM('accepted','rejected') NOT NULL,
    decision_ct MEDIUMBLOB NOT NULL,
    proposal_ct MEDIUMBLOB NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, source_workspace_id, change_id),
    KEY ix_quanta_profile_decision_person (tenant_id, people_tenant_id, person_id, created_at)
) ENGINE=InnoDB;
