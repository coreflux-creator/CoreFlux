-- Credits settle existing source balances without cash or a second GL posting.
CREATE TABLE IF NOT EXISTS accounting_credit_applications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    side ENUM('ar','ap') NOT NULL,
    credit_id BIGINT UNSIGNED NOT NULL,
    document_id BIGINT UNSIGNED NOT NULL,
    credit_je_id BIGINT UNSIGNED NOT NULL,
    document_je_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    application_date DATE NOT NULL,
    request_key CHAR(64) NOT NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reversal_date DATE NULL,
    reversal_reason VARCHAR(500) NULL,
    reversed_by_user_id BIGINT UNSIGNED NULL,
    reversed_at DATETIME NULL,
    UNIQUE KEY uq_credit_request (tenant_id, request_key),
    INDEX idx_credit_source (tenant_id, side, credit_id, application_date),
    INDEX idx_credit_target (tenant_id, side, document_id, application_date),
    INDEX idx_credit_entity (tenant_id, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
