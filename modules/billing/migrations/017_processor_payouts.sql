-- Processor captures live in clearing until a reviewed net bank payout arrives.
CREATE TABLE IF NOT EXISTS billing_processor_payouts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    processor VARCHAR(32) NOT NULL,
    bank_line_id BIGINT UNSIGNED NOT NULL,
    bank_account_id BIGINT UNSIGNED NOT NULL,
    attempt_no INT UNSIGNED NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    posted_date DATE NOT NULL,
    gross_amount DECIMAL(18,2) NOT NULL,
    fee_amount DECIMAL(18,2) NOT NULL,
    net_amount DECIMAL(18,2) NOT NULL,
    fee_account_id BIGINT UNSIGNED NULL,
    journal_entry_id BIGINT UNSIGNED NULL,
    reversal_je_id BIGINT UNSIGNED NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'posting',
    correction_reason VARCHAR(500) NULL,
    corrected_at DATETIME NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    corrected_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_processor_payout_attempt (tenant_id, bank_line_id, attempt_no),
    KEY idx_processor_payout_line (tenant_id, bank_line_id, status),
    KEY idx_processor_payout_journal (tenant_id, journal_entry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_processor_payout_payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    payout_id BIGINT UNSIGNED NOT NULL,
    payment_id BIGINT UNSIGNED NOT NULL,
    gross_amount DECIMAL(18,2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_processor_payout_payment (tenant_id, payout_id, payment_id),
    KEY idx_processor_payment_history (tenant_id, payment_id, payout_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
