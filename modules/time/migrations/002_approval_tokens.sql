-- Time Module — Phase B Slice 1: tokenized client-approval tokens (SPEC §3.6, §5.5)
-- Idempotent, utf8mb4_unicode_ci (Cloudways-compat).

CREATE TABLE IF NOT EXISTS time_approval_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    placement_id BIGINT UNSIGNED NOT NULL,
    period_id BIGINT UNSIGNED NOT NULL,
    client_approver_email VARCHAR(255) NOT NULL,
    token VARCHAR(96) NOT NULL,
    token_hash VARBINARY(64) NOT NULL,
    entries_json LONGTEXT NOT NULL,
    entries_total_hours DECIMAL(8,2) NOT NULL DEFAULT 0,
    issued_by_user_id BIGINT UNSIGNED NULL,
    issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    responded_at DATETIME NULL,
    response ENUM('pending','approved','rejected','expired','revoked') NOT NULL DEFAULT 'pending',
    responder_ip VARCHAR(45) NULL,
    responder_user_agent VARCHAR(255) NULL,
    responder_note VARCHAR(500) NULL,
    revoked_by_user_id BIGINT UNSIGNED NULL,
    revoked_at DATETIME NULL,
    provider_message_id VARCHAR(255) NULL,
    email_status ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
    email_error VARCHAR(500) NULL,
    UNIQUE KEY uq_tat_token (token),
    INDEX idx_tat_tenant_placement_period (tenant_id, placement_id, period_id),
    INDEX idx_tat_tenant_response (tenant_id, response),
    INDEX idx_tat_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
