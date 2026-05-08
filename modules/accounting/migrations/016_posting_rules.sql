-- Sprint 7b — posting_rules (spec §13).
-- Each rule maps event_type + (optional) entity scope + (optional) JSON
-- conditions to a journal_template_id. Highest-priority match wins.
--
-- Idempotent.

CREATE TABLE IF NOT EXISTS accounting_posting_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    entity_id BIGINT UNSIGNED NULL,        -- NULL = applies to every entity
    name VARCHAR(160) NOT NULL,
    event_type VARCHAR(120) NOT NULL,
    conditions JSON NULL,                  -- e.g. {"payload.amount": {"gt": 0}}
    journal_template_id BIGINT UNSIGNED NOT NULL,
    priority INT NOT NULL DEFAULT 100,     -- higher wins; ties broken by id ASC
    status ENUM('active','draft','archived') NOT NULL DEFAULT 'active',
    description VARCHAR(500) NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_apr_tenant_event (tenant_id, event_type, status, priority),
    INDEX idx_apr_tenant_entity (tenant_id, entity_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
