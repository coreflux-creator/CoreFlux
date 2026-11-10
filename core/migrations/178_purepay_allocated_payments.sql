-- Original supplier obligations; legacy purepay_payment_links remain immutable history.
CREATE TABLE IF NOT EXISTS purepay_allocated_payments (
    tenant_id BIGINT UNSIGNED NOT NULL,
    core_payment_id BIGINT UNSIGNED NOT NULL,
    amount_cents BIGINT UNSIGNED NOT NULL,
    instance_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    origin_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    organization_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    connection_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_json JSON NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'prepared',
    last_error VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, core_payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purepay_allocation_receipts (
    tenant_id BIGINT UNSIGNED NOT NULL,
    core_payment_id BIGINT UNSIGNED NOT NULL,
    core_bill_id BIGINT UNSIGNED NOT NULL,
    receipt_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reservation_json JSON NOT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, core_payment_id, core_bill_id, receipt_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purepay_payment_allocations (
    tenant_id BIGINT UNSIGNED NOT NULL,
    core_payment_id BIGINT UNSIGNED NOT NULL,
    core_bill_id BIGINT UNSIGNED NOT NULL,
    origin_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    organization_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    provider_bill_id VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    provider_vendor_id VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reservation_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    amount_cents BIGINT UNSIGNED NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_json JSON NOT NULL,
    provider_state VARCHAR(32) NOT NULL DEFAULT 'unreserved',
    provider_payment_id VARCHAR(200) CHARACTER SET ascii COLLATE ascii_bin NULL,
    payment_status VARCHAR(32) NOT NULL DEFAULT 'unknown',
    terminal_review TINYINT(1) NOT NULL DEFAULT 0,
    reservation_json JSON NULL,
    receipt_json JSON NULL,
    last_error VARCHAR(255) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, core_payment_id, core_bill_id),
    UNIQUE KEY uq_ppalloc_reservation (origin_hash, organization_id, reservation_id),
    UNIQUE KEY uq_ppalloc_receipt (origin_hash, organization_id, provider_payment_id),
    CONSTRAINT fk_ppalloc_parent FOREIGN KEY (tenant_id, core_payment_id)
        REFERENCES purepay_allocated_payments (tenant_id, core_payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
