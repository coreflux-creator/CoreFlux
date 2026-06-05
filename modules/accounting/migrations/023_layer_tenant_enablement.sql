-- =============================================================================
-- Migration 023 — LayerFi per-tenant enablement (DB-backed admin toggle)
-- =============================================================================
-- Lets ops enable/disable LayerFi for a tenant from the admin UI without an
-- env change or redeploy. The env LAYER_TENANT_ALLOWLIST (when set) still
-- hard-overrides this table.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tenant_layer_enablement` (
    `tenant_id`         INT UNSIGNED NOT NULL,
    `layer_environment` VARCHAR(20)  NOT NULL DEFAULT 'sandbox',
    `enabled`           TINYINT(1)   NOT NULL DEFAULT 0,
    `updated_by`        INT UNSIGNED NULL,
    `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`tenant_id`, `layer_environment`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
