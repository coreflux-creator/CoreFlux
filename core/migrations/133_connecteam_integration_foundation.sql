-- Connecteam integration foundation.
--
-- This slice is deliberately read-only with respect to workforce data. It
-- stores one encrypted credential per tenant plus compact capability and
-- inventory snapshots. No People, Placement, Time, Payroll, AP, AR, or GL
-- row is changed by this migration or its probe endpoints.

CREATE TABLE IF NOT EXISTS connecteam_connections (
    id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id                BIGINT UNSIGNED NOT NULL,
    api_key_ct               VARBINARY(2048) NOT NULL,
    api_key_last4            VARCHAR(8) NULL,
    region                   ENUM('us','au') NOT NULL DEFAULT 'us',
    account_id               VARCHAR(128) NULL,
    account_name             VARCHAR(255) NULL,
    status                   ENUM('active','revoked','error') NOT NULL DEFAULT 'active',
    capability_snapshot      JSON NULL,
    inventory_snapshot       JSON NULL,
    last_probe_at            DATETIME NULL,
    last_probe_error         VARCHAR(500) NULL,
    connected_by_user_id     BIGINT UNSIGNED NULL,
    created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_connecteam_tenant (tenant_id),
    KEY ix_connecteam_status (tenant_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS connecteam_sync_audit (
    id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id                BIGINT UNSIGNED NOT NULL,
    action                   VARCHAR(60) NOT NULL,
    ok                       TINYINT(1) NOT NULL DEFAULT 1,
    items_inspected          INT UNSIGNED NOT NULL DEFAULT 0,
    detail                   JSON NULL,
    actor_user_id            BIGINT UNSIGNED NULL,
    occurred_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_connecteam_audit_tenant_time (tenant_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
