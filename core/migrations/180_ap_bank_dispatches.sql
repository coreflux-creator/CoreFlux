-- A payment keeps its original bank dispatch even when the browser loses the result.
CREATE TABLE IF NOT EXISTS ap_bank_dispatches (
    tenant_id BIGINT UNSIGNED NOT NULL,
    dispatch_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    rail VARCHAR(32) NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    packet_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_ct MEDIUMBLOB NOT NULL,
    result_ct MEDIUMBLOB NULL,
    state VARCHAR(24) NOT NULL DEFAULT 'attempted',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    recorded_at DATETIME NULL,
    PRIMARY KEY (tenant_id, dispatch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ap_bank_payment_claims (
    tenant_id BIGINT UNSIGNED NOT NULL,
    payment_id BIGINT UNSIGNED NOT NULL,
    dispatch_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    rail VARCHAR(32) NOT NULL,
    amount_cents BIGINT UNSIGNED NOT NULL,
    provider_ref VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NULL,
    receipt_owner VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NULL,
    result_status VARCHAR(32) NOT NULL DEFAULT 'unknown',
    PRIMARY KEY (tenant_id, payment_id),
    UNIQUE KEY uq_ap_bank_receipt (tenant_id, rail, receipt_owner),
    KEY ix_ap_bank_dispatch (tenant_id, dispatch_id),
    CONSTRAINT fk_ap_bank_dispatch FOREIGN KEY (tenant_id, dispatch_id)
        REFERENCES ap_bank_dispatches(tenant_id, dispatch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
