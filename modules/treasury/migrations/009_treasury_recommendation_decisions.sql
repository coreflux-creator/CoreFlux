-- Treasury recommendation decision ledger.
-- Queryable human decision history that complements the canonical audit log.

CREATE TABLE IF NOT EXISTS treasury_recommendation_decisions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    recommendation_id VARCHAR(160) NOT NULL,
    payment_id BIGINT UNSIGNED NULL,
    decision ENUM('accept','dismiss') NOT NULL,
    recommendation_action VARCHAR(80) NULL,
    policy_version INT UNSIGNED NULL,
    evidence_hash CHAR(64) NOT NULL,
    evidence_json MEDIUMTEXT NULL,
    decision_note VARCHAR(1000) NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    decided_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_trd_tenant_recommendation (tenant_id, recommendation_id, decided_at),
    INDEX idx_trd_tenant_payment (tenant_id, payment_id, decided_at),
    INDEX idx_trd_tenant_decision (tenant_id, decision, decided_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
