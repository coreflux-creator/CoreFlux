-- Reviewed bill identity only. No payment, reservation, balance or journal changes.
CREATE TABLE IF NOT EXISTS purepay_bill_mappings (
    tenant_id BIGINT UNSIGNED NOT NULL,
    core_bill_id BIGINT UNSIGNED NOT NULL,
    core_vendor_id BIGINT UNSIGNED NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    origin_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    organization_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    purepay_bill_id VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    purepay_vendor_id VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    review_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    review_json JSON NOT NULL,
    reviewed_by BIGINT UNSIGNED NOT NULL,
    reviewed_at DATETIME NOT NULL,
    PRIMARY KEY (tenant_id, core_bill_id),
    UNIQUE KEY uq_ppbill_remote (origin_hash, organization_id, purepay_bill_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purepay_bill_mapping_reviews (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    core_bill_id BIGINT UNSIGNED NOT NULL,
    review_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    review_json JSON NOT NULL,
    reviewed_by BIGINT UNSIGNED NOT NULL,
    reviewed_at DATETIME NOT NULL,
    UNIQUE KEY uq_ppbill_review (tenant_id, core_bill_id, review_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
