-- Core's durable reservation precedes any provider draft authorization.
-- No timers release these claims. A provider release receipt is required.
CREATE TABLE IF NOT EXISTS payroll_source_authorities (
    id CHAR(68) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    request_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    input_delivery_id BIGINT UNSIGNED NOT NULL,
    connection_id BIGINT UNSIGNED NOT NULL,
    organization_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    instance_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    time_tenant_id BIGINT UNSIGNED NOT NULL,
    input_id CHAR(68) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    content_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_ct MEDIUMBLOB NOT NULL,
    state ENUM('held','released') NOT NULL DEFAULT 'held',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    released_at DATETIME NULL,
    release_receipt_ct MEDIUMBLOB NULL,
    UNIQUE KEY uq_payroll_authority_request (tenant_id, request_id),
    KEY ix_payroll_authority_input (tenant_id, input_delivery_id),
    CONSTRAINT fk_payroll_authority_delivery FOREIGN KEY (input_delivery_id) REFERENCES integration_deliveries(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_source_claims (
    time_tenant_id BIGINT UNSIGNED NOT NULL,
    time_entry_id BIGINT UNSIGNED NOT NULL,
    authority_id CHAR(68) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    placement_tenant_id BIGINT UNSIGNED NOT NULL,
    rate_snapshot_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (time_tenant_id, time_entry_id),
    KEY ix_payroll_claim_rate (placement_tenant_id, rate_snapshot_id),
    KEY ix_payroll_claim_authority (authority_id),
    CONSTRAINT fk_payroll_claim_authority FOREIGN KEY (authority_id) REFERENCES payroll_source_authorities(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
