-- Mutable delivery evidence is separate from the immutable reviewed decision.
CREATE TABLE IF NOT EXISTS quanta_profile_returns (
    tenant_id BIGINT UNSIGNED NOT NULL,
    source_workspace_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    change_id CHAR(68) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    body_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state ENUM('sending','uncertain','failed','delivered') NOT NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_actor_id BIGINT UNSIGNED NOT NULL,
    claim_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    receipt_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    receipt_ct MEDIUMBLOB NULL,
    error_code VARCHAR(80) NULL,
    attempted_at DATETIME NULL,
    finished_at DATETIME NULL,
    PRIMARY KEY (tenant_id, source_workspace_id, change_id),
    CONSTRAINT fk_quanta_profile_return_decision FOREIGN KEY (tenant_id, source_workspace_id, change_id)
        REFERENCES quanta_profile_decisions (tenant_id, source_workspace_id, change_id)
) ENGINE=InnoDB;
