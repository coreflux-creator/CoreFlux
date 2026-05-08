-- Sprint 7b — accounting_events (spec §12).
-- Modules emit events; the posting engine consumes them. No module writes
-- ledger lines directly anymore (rule §3.6).
--
-- Idempotent: information_schema guards.

CREATE TABLE IF NOT EXISTS accounting_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(120) NOT NULL,
    source_module VARCHAR(64) NOT NULL,
    source_record_id VARCHAR(120) NOT NULL,
    event_date DATE NOT NULL,
    payload JSON NOT NULL,
    status ENUM('received','mapped','posted','failed','ignored','reversed') NOT NULL DEFAULT 'received',
    journal_entry_id BIGINT UNSIGNED NULL,
    posting_rule_id BIGINT UNSIGNED NULL,
    error_message TEXT NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    posted_at DATETIME NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ae_tenant_source (tenant_id, source_module, source_record_id, event_type),
    INDEX idx_ae_tenant_status_type (tenant_id, status, event_type),
    INDEX idx_ae_tenant_je (tenant_id, journal_entry_id),
    INDEX idx_ae_event_date (tenant_id, event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
