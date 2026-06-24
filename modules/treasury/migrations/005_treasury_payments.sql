-- Sprint 7c — treasury_payments (spec §15).
-- 8-state lifecycle: draft → pending_approval → approved → scheduled →
-- executed → failed → voided  (rejected handled separately).
--
-- Payments are first-class Treasury objects. Approval routes through the
-- existing WorkflowEngine; execute emits a `treasury.payment.executed`
-- event that the posting engine maps to a JE (Dr AP/Liability, Cr Cash).
--
-- Idempotent.

CREATE TABLE IF NOT EXISTS treasury_payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    payment_number VARCHAR(40) NOT NULL,
    payee_type ENUM('vendor','employee','customer','tax_authority','other') NOT NULL DEFAULT 'vendor',
    payee_id BIGINT UNSIGNED NULL,
    payee_name VARCHAR(255) NOT NULL,
    amount DECIMAL(18,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    payment_date DATE NOT NULL,
    payment_method ENUM('ach','check','wire','card','other') NOT NULL DEFAULT 'ach',
    bank_account_id BIGINT UNSIGNED NOT NULL,           -- accounting_bank_accounts.id
    counterparty_account_id BIGINT UNSIGNED NULL,       -- AP, payroll liability, etc.
    memo VARCHAR(500) NULL,
    status ENUM('draft','pending_approval','approved','scheduled','executed','failed','voided','rejected')
        NOT NULL DEFAULT 'draft',
    workflow_instance_id BIGINT UNSIGNED NULL,
    journal_entry_id BIGINT UNSIGNED NULL,              -- set once executed
    accounting_event_id BIGINT UNSIGNED NULL,
    external_ref VARCHAR(120) NULL,                     -- bank rail tx id (NACHA / Plaid Transfer)
    failure_reason TEXT NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    approved_by_user_id BIGINT UNSIGNED NULL,
    executed_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at DATETIME NULL,
    executed_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tp_tenant_number (tenant_id, payment_number),
    INDEX idx_tp_tenant_status (tenant_id, status, payment_date),
    INDEX idx_tp_tenant_entity (tenant_id, entity_id, status),
    INDEX idx_tp_tenant_bank (tenant_id, bank_account_id),
    INDEX idx_tp_payee (tenant_id, payee_type, payee_id),
    INDEX idx_tp_workflow (tenant_id, workflow_instance_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
