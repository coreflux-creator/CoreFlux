CREATE TABLE IF NOT EXISTS accounting_opening_document_cutovers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    posting_date DATE NOT NULL,
    preview_hash CHAR(64) NOT NULL,
    balance_je_id BIGINT UNSIGNED NOT NULL,
    ar_count INT UNSIGNED NOT NULL DEFAULT 0,
    ap_count INT UNSIGNED NOT NULL DEFAULT 0,
    ar_total DECIMAL(14,2) NOT NULL DEFAULT 0,
    ap_total DECIMAL(14,2) NOT NULL DEFAULT 0,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_opening_documents_entity (tenant_id, entity_id),
    INDEX idx_opening_documents_je (balance_je_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
