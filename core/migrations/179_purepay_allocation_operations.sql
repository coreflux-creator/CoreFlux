-- Persist cancellation before contacting the provider. Never reset this flag.
SET @pp_has_cancel := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='purepay_allocated_payments' AND COLUMN_NAME='release_requested');
SET @pp_cancel_sql := IF(@pp_has_cancel=0,
    'ALTER TABLE purepay_allocated_payments ADD COLUMN release_requested TINYINT(1) NOT NULL DEFAULT 0', 'DO 0');
PREPARE pp_cancel_stmt FROM @pp_cancel_sql;
EXECUTE pp_cancel_stmt;
DEALLOCATE PREPARE pp_cancel_stmt;

-- Append requested and outcome events; an interrupted request remains visible.
CREATE TABLE IF NOT EXISTS purepay_allocation_operations (
    operation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    phase VARCHAR(24) NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    core_payment_id BIGINT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(24) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    review_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    result_json JSON NOT NULL,
    recorded_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (operation_id, phase),
    KEY ix_ppop_payment (tenant_id, core_payment_id, recorded_at),
    CONSTRAINT fk_ppop_parent FOREIGN KEY (tenant_id, core_payment_id)
        REFERENCES purepay_allocated_payments (tenant_id, core_payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
