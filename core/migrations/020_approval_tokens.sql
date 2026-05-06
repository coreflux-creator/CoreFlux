-- Generic email-only approval tokens (Sprint 4 / A2)
-- Generalizes time module's tokenized approval into a CORE primitive.
-- Subject types match WorkflowEngine: ap_bill, billing_invoice,
-- accounting_period_close, time_period, accounting_journal_entry.
--
-- One token = one signed URL = one (or more) approve/reject actions
-- the recipient is allowed to take. Token is sha256-hashed at rest.

CREATE TABLE IF NOT EXISTS approval_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    subject_type VARCHAR(80) NOT NULL,
    subject_id BIGINT UNSIGNED NOT NULL,
    workflow_instance_id BIGINT UNSIGNED NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    actor_email VARCHAR(255) NULL,
    actions_json TEXT NOT NULL,
    issued_by_user_id BIGINT UNSIGNED NULL,
    issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    consumed_via_action VARCHAR(40) NULL,
    consumed_ip VARCHAR(64) NULL,
    UNIQUE KEY uq_aprtok_hash (token_hash),
    INDEX idx_aprtok_subject (tenant_id, subject_type, subject_id),
    INDEX idx_aprtok_email (tenant_id, actor_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
