-- Treasury recommendation exception ownership.
-- Operationalizes reserve-breach/split/hold recommendations with assignment
-- and human resolution while leaving money movement in the payment workflow.

CREATE TABLE IF NOT EXISTS treasury_recommendation_exceptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    recommendation_id VARCHAR(160) NOT NULL,
    payment_id BIGINT UNSIGNED NULL,
    recommendation_action VARCHAR(80) NOT NULL,
    severity ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    status ENUM('open','assigned','resolved','dismissed') NOT NULL DEFAULT 'open',
    reason VARCHAR(1000) NULL,
    policy_version INT UNSIGNED NULL,
    owner_user_id BIGINT UNSIGNED NULL,
    opened_by_user_id BIGINT UNSIGNED NULL,
    resolved_by_user_id BIGINT UNSIGNED NULL,
    resolution_note VARCHAR(1000) NULL,
    opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    assigned_at DATETIME NULL,
    resolved_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tre_tenant_recommendation (tenant_id, recommendation_id, status),
    INDEX idx_tre_tenant_payment (tenant_id, payment_id, status),
    INDEX idx_tre_tenant_owner (tenant_id, owner_user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
