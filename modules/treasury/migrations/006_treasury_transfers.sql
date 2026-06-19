-- Sprint 7c — treasury_transfers (spec §15).
-- Internal transfers (same entity, two bank accounts) → 2-line JE
-- (Dr Cash-Dest, Cr Cash-Source).
-- Intercompany transfers (different entity_ids on src vs dest bank
-- accounts) → 2 mirror JEs:
--   Entity A: Dr Intercompany Receivable, Cr Cash
--   Entity B: Dr Cash, Cr Intercompany Payable
-- The mirror posting is handled by the engine's posting rule template.
--
-- Idempotent.

CREATE TABLE IF NOT EXISTS treasury_transfers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    transfer_number VARCHAR(40) NOT NULL,
    transfer_kind ENUM('internal','intercompany') NOT NULL DEFAULT 'internal',
    source_bank_account_id BIGINT UNSIGNED NOT NULL,
    destination_bank_account_id BIGINT UNSIGNED NOT NULL,
    source_entity_id BIGINT UNSIGNED NOT NULL,
    destination_entity_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(18,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    transfer_date DATE NOT NULL,
    memo VARCHAR(500) NULL,
    status ENUM('draft','pending_approval','approved','scheduled','executed','failed','voided','rejected')
        NOT NULL DEFAULT 'draft',
    workflow_instance_id BIGINT UNSIGNED NULL,
    source_journal_entry_id BIGINT UNSIGNED NULL,
    destination_journal_entry_id BIGINT UNSIGNED NULL,  -- intercompany only
    accounting_event_id BIGINT UNSIGNED NULL,
    external_ref VARCHAR(120) NULL,
    failure_reason TEXT NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    approved_by_user_id BIGINT UNSIGNED NULL,
    executed_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at DATETIME NULL,
    executed_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tt_tenant_number (tenant_id, transfer_number),
    INDEX idx_tt_tenant_status (tenant_id, status, transfer_date),
    INDEX idx_tt_tenant_kind (tenant_id, transfer_kind, status),
    INDEX idx_tt_tenant_src_bank (tenant_id, source_bank_account_id),
    INDEX idx_tt_tenant_dst_bank (tenant_id, destination_bank_account_id),
    INDEX idx_tt_workflow (tenant_id, workflow_instance_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
