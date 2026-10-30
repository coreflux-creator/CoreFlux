-- Reviewed associations of individual historical source lines; never a posting or future-item rule.
CREATE TABLE IF NOT EXISTS purepay_financial_line_links (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    connection_id INT UNSIGNED NOT NULL,
    capture_id BIGINT UNSIGNED NOT NULL,
    subject_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    link_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    placement_tenant_id INT UNSIGNED NOT NULL,
    placement_id INT UNSIGNED NOT NULL,
    payload_ct MEDIUMBLOB NOT NULL,
    reviewed_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pp_line_link (tenant_id,connection_id,link_hash),
    KEY ix_pp_line_subject (tenant_id,connection_id,subject_hash,id),
    KEY ix_pp_line_placement (tenant_id,placement_tenant_id,placement_id,id),
    CONSTRAINT fk_pp_line_capture FOREIGN KEY(capture_id) REFERENCES purepay_financial_observations(id),
    CONSTRAINT fk_pp_line_connection FOREIGN KEY(connection_id) REFERENCES purepay_connections(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
