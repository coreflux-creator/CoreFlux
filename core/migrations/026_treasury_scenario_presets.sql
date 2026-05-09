-- Sprint 7g+ — Saved Treasury Scenarios.
--
-- Tenant-scoped library of named what-if scenarios (event lists). Lets
-- the operator persist a custom scenario they've built and re-run it
-- next quarter without retyping every event.
--
-- Idempotent. utf8mb4_unicode_ci. Cloudways MySQL 5.7+ compatible.

CREATE TABLE IF NOT EXISTS treasury_scenario_presets (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    name                VARCHAR(120) NOT NULL,
    description         VARCHAR(500) NULL,
    events_json         JSON NOT NULL,
    created_by_user_id  BIGINT UNSIGNED NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_tenant_name (tenant_id, name),
    INDEX idx_tsp_tenant (tenant_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
