-- =============================================================================
-- Migration 022 — LayerFi Sandbox embed (per-tenant embedded accounting)
-- =============================================================================
-- Implements the CoreFlux ⇄ LayerFi sandbox evaluation spec:
--   CoreFlux tenant  ->  one LayerFi Business per tenant  ->  business token
--   ->  embedded LayerFi accounting UI.
--
-- `tenant_layer_accounts` maps a CoreFlux tenant to exactly one LayerFi
-- Business per environment (unique on tenant+env, business+env, external+env).
--
-- `integration_audit_log` is the provider-neutral integration audit trail.
-- It is intentionally separate from the unified `audit_log` so integration
-- traffic (token issuance, smoke tests, embedded errors) can be queried on
-- its own without sensitive tokens ever being written here.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tenant_layer_accounts` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`         INT UNSIGNED    NOT NULL,
    `layer_environment` VARCHAR(20)     NOT NULL DEFAULT 'sandbox',
    `layer_business_id` VARCHAR(191)    NOT NULL,
    `layer_external_id` VARCHAR(191)    NOT NULL,
    `legal_name`        VARCHAR(255)    NULL,
    `status`            VARCHAR(32)     NOT NULL DEFAULT 'active',
    `created_by`        INT UNSIGNED    NULL,
    `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tla_tenant_env`   (`tenant_id`, `layer_environment`),
    UNIQUE KEY `uq_tla_business_env` (`layer_business_id`, `layer_environment`),
    UNIQUE KEY `uq_tla_external_env` (`layer_external_id`, `layer_environment`),
    KEY `idx_tla_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `integration_audit_log` (
    `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`            INT UNSIGNED    NULL,
    `user_id`              INT UNSIGNED    NULL,
    `provider`             VARCHAR(40)     NOT NULL,
    `environment`          VARCHAR(20)     NOT NULL,
    `action`               VARCHAR(64)     NOT NULL,
    `external_object_type` VARCHAR(64)     NULL,
    `external_object_id`   VARCHAR(191)    NULL,
    `status`               VARCHAR(32)     NOT NULL,
    `request_id`           VARCHAR(64)     NULL,
    `error_code`           VARCHAR(64)     NULL,
    `error_message`        VARCHAR(500)    NULL,
    `metadata`             MEDIUMTEXT      NULL,
    `created_at`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ial_tenant_created`  (`tenant_id`, `created_at`),
    KEY `idx_ial_provider_action` (`provider`, `action`),
    KEY `idx_ial_status`          (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
