-- Local intake receipts commit with the person/worker links; no provider write or access grant.
CREATE TABLE IF NOT EXISTS quanta_worker_intake_receipts (
    tenant_id BIGINT UNSIGNED NOT NULL,
    review_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    people_tenant_id BIGINT UNSIGNED NOT NULL,
    binding_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_workspace_id VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    operation VARCHAR(20) CHARACTER SET ascii NOT NULL,
    review_ct MEDIUMBLOB NOT NULL,
    result_ct MEDIUMBLOB NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, review_id)
) ENGINE=InnoDB;
