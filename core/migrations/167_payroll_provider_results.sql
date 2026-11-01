-- Append-only encrypted provider observations. No payroll/GL posting or claim release.
CREATE TABLE IF NOT EXISTS payroll_provider_results (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    authority_id CHAR(68) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    input_delivery_id BIGINT UNSIGNED NOT NULL,
    connection_id BIGINT UNSIGNED NOT NULL,
    result_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_ct MEDIUMBLOB NOT NULL,
    observed_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payroll_result_observation(tenant_id,authority_id,result_hash),
    KEY ix_payroll_result_history(tenant_id,input_delivery_id,id),
    CONSTRAINT fk_payroll_result_authority FOREIGN KEY(authority_id) REFERENCES payroll_source_authorities(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
