-- Treasury recommendation workflow handoff ledger.
-- Records attempts to move from an accepted recommendation into the canonical
-- Treasury payment workflow without becoming the workflow engine itself.

CREATE TABLE IF NOT EXISTS treasury_recommendation_handoffs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    recommendation_id VARCHAR(160) NOT NULL,
    payment_id BIGINT UNSIGNED NULL,
    handoff_action ENUM('submit','approve','reject','execute') NOT NULL,
    result ENUM('success','failure') NOT NULL,
    payment_status_before VARCHAR(40) NULL,
    payment_status_after VARCHAR(40) NULL,
    workflow_response_json MEDIUMTEXT NULL,
    error_text VARCHAR(1000) NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_trh_tenant_recommendation (tenant_id, recommendation_id, attempted_at),
    INDEX idx_trh_tenant_payment (tenant_id, payment_id, attempted_at),
    INDEX idx_trh_tenant_result (tenant_id, result, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
