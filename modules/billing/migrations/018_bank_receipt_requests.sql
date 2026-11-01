-- Preserve the exact intent and result of a bank-line invoice receipt attempt.
CREATE TABLE IF NOT EXISTS billing_bank_receipt_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    bank_line_id BIGINT UNSIGNED NOT NULL,
    attempt_no INT UNSIGNED NOT NULL,
    action VARCHAR(32) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    journal_entry_id BIGINT UNSIGNED NOT NULL,
    response_json LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bank_receipt_request_attempt (tenant_id, bank_line_id, attempt_no),
    KEY idx_bank_receipt_request_journal (tenant_id, journal_entry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
