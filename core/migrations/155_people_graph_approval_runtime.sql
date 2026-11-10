-- Shared People Graph approval policy runtime used by Billing invoice workflows.
-- This is not a separate CoreOne approval store. Other People Graph domains
-- (teams, roles, relationships and responsibilities) need their own schema.

CREATE TABLE IF NOT EXISTS people_graph_actor_links (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    actor_type VARCHAR(40) NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    person_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    organization_id BIGINT UNSIGNED NULL,
    company_id BIGINT UNSIGNED NULL,
    ai_worker_id BIGINT UNSIGNED NULL,
    label VARCHAR(200) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    source VARCHAR(80) NULL,
    metadata_json TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pg_actor_link (tenant_id, actor_type, actor_id),
    INDEX idx_pg_actor_user (tenant_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS people_graph_approval_policies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    policy_key VARCHAR(80) NOT NULL,
    name VARCHAR(200) NOT NULL,
    resource_module VARCHAR(80) NULL,
    resource_type VARCHAR(80) NOT NULL,
    scope_type VARCHAR(80) NULL,
    scope_id VARCHAR(120) NULL,
    priority INT UNSIGNED NOT NULL DEFAULT 100,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    requires_human_for_ai TINYINT(1) NOT NULL DEFAULT 1,
    metadata_json TEXT NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pg_approval_policy (tenant_id, policy_key),
    INDEX idx_pg_approval_route (tenant_id, resource_type, resource_module, status, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS people_graph_approval_policy_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    policy_id BIGINT UNSIGNED NOT NULL,
    sequence_num INT UNSIGNED NOT NULL DEFAULT 1,
    condition_json TEXT NULL,
    approver_strategy VARCHAR(40) NOT NULL,
    approver_role_id BIGINT UNSIGNED NULL,
    approver_role_key VARCHAR(80) NULL,
    relationship_type VARCHAR(80) NULL,
    responsibility_type VARCHAR(80) NULL,
    approver_actor_type VARCHAR(40) NULL,
    approver_actor_id BIGINT UNSIGNED NULL,
    scope_type VARCHAR(80) NULL,
    scope_id VARCHAR(120) NULL,
    minimum_approvals INT UNSIGNED NOT NULL DEFAULT 1,
    separation_of_duties_required TINYINT(1) NOT NULL DEFAULT 1,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    metadata_json TEXT NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pg_approval_rules (tenant_id, policy_id, status, sequence_num)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS people_graph_audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    event VARCHAR(120) NOT NULL,
    target_table VARCHAR(120) NULL,
    target_id BIGINT UNSIGNED NULL,
    meta_json TEXT NULL,
    ip_address VARCHAR(45) NULL,
    request_id VARCHAR(120) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pg_audit_tenant (tenant_id, created_at),
    INDEX idx_pg_audit_target (tenant_id, target_table, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
