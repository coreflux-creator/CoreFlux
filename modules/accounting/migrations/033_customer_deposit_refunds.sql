-- A refund is a separate bank outflow, never a reversal of an invoice allocation.
CREATE TABLE IF NOT EXISTS billing_deposit_refunds (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    payment_id BIGINT UNSIGNED NOT NULL,
    bank_account_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(18,2) NOT NULL,
    currency VARCHAR(3) NOT NULL,
    refunded_at DATE NOT NULL,
    reference VARCHAR(255) NULL,
    request_key VARCHAR(80) NOT NULL,
    journal_entry_id BIGINT UNSIGNED NOT NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bdr_payment_request (tenant_id, payment_id, request_key),
    INDEX idx_bdr_payment (tenant_id, payment_id),
    INDEX idx_bdr_journal (tenant_id, journal_entry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
