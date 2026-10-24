-- Reviewed identity link and encrypted before/after evidence commit together.
CREATE TABLE IF NOT EXISTS jobdiva_person_link_receipts (
    tenant_id BIGINT UNSIGNED NOT NULL,
    review_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    binding_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    review_ct MEDIUMBLOB NOT NULL,
    result_ct MEDIUMBLOB NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id,review_id)
) ENGINE=InnoDB;
