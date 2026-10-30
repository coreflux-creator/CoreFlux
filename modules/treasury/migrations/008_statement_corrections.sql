-- An audited correction belongs to the statement line and posting attempt,
-- not to a free-standing journal unmatch. Both statement tables share this log.
CREATE TABLE IF NOT EXISTS treasury_statement_corrections (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    line_type ENUM('deposit', 'liability') NOT NULL,
    line_id BIGINT UNSIGNED NOT NULL,
    attempt_no INT UNSIGNED NOT NULL,
    original_je_id BIGINT UNSIGNED NOT NULL,
    reversal_je_id BIGINT UNSIGNED NOT NULL,
    accounting_event_id BIGINT UNSIGNED NULL,
    source_record_id VARCHAR(120) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    corrected_by_user_id BIGINT UNSIGNED NULL,
    corrected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tsc_attempt (tenant_id, line_type, line_id, attempt_no),
    UNIQUE KEY uq_tsc_original_je (tenant_id, original_je_id),
    INDEX idx_tsc_line (tenant_id, line_type, line_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
