-- Sprint 7b — subledger_links (spec §18).
-- Maps a source business record to one-or-more JEs (e.g. a single bill maps
-- to both an "approved" JE and later a "paid" JE + potentially a reversal).
--
-- Idempotent.

CREATE TABLE IF NOT EXISTS accounting_subledger_links (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    source_module VARCHAR(64) NOT NULL,
    source_record_id VARCHAR(120) NOT NULL,
    journal_entry_id BIGINT UNSIGNED NOT NULL,
    accounting_event_id BIGINT UNSIGNED NULL,
    link_kind VARCHAR(60) NOT NULL DEFAULT 'primary',  -- primary|reversal|payment|adjustment
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_asl_source_je (tenant_id, source_module, source_record_id, journal_entry_id, link_kind),
    INDEX idx_asl_tenant_source (tenant_id, source_module, source_record_id),
    INDEX idx_asl_tenant_je (tenant_id, journal_entry_id),
    INDEX idx_asl_tenant_event (tenant_id, accounting_event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
