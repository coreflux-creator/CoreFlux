-- 087_mercury_webhooks.sql
--
-- Mercury webhooks — real-time payment state advancement.
--
-- Today the worker advances payment_instructions by polling Mercury
-- every cron tick. The poll cycle adds ~minute-latency between Mercury
-- settling a funding leg and CoreFlux originating the vendor payout.
-- This migration adds the storage for Mercury's push notifications so
-- mpAdvance can fire seconds after Mercury's state change.
--
-- Two tables:
--   1. mercury_webhook_endpoints — per-tenant signing secret + status.
--      Mercury allows up to 100 endpoints per org so we keep room for
--      multi-endpoint configs later; v1 stores one row per tenant.
--   2. mercury_webhook_events — every received event, even when
--      signature verification fails (audit/forensics). Event id is the
--      PK so duplicate deliveries naturally dedupe.

CREATE TABLE IF NOT EXISTS mercury_webhook_endpoints (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id            INT UNSIGNED NOT NULL,
    mercury_endpoint_id  VARCHAR(80) NULL,             -- id returned by Mercury when we created the endpoint
    url                  VARCHAR(255) NULL,            -- the URL Mercury delivers to (display only)
    signing_secret_ct    VARBINARY(512) NOT NULL,      -- AES-256-GCM via encryptField()
    signing_secret_last4 VARCHAR(8) NOT NULL,          -- masked display
    status               ENUM('active','paused','error') NOT NULL DEFAULT 'active',
    last_event_at        DATETIME NULL,
    last_error           VARCHAR(255) NULL,
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by_user_id   INT UNSIGNED NULL,
    updated_at           TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mwh_tenant (tenant_id)                -- one endpoint per tenant in v1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mercury_webhook_events (
    event_id          VARCHAR(80) NOT NULL PRIMARY KEY,
    tenant_id         INT UNSIGNED NOT NULL,
    resource_type     VARCHAR(40) NOT NULL,           -- 'transaction'|'checkingAccount'|...
    resource_id       VARCHAR(80) NULL,
    event_type        VARCHAR(80) NOT NULL,           -- derived: '{resource}.{operation}' or 'unknown'
    operation_type    VARCHAR(20) NULL,               -- 'create'|'update'
    occurred_at       DATETIME NULL,
    received_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    verified          TINYINT(1) NOT NULL DEFAULT 0,
    verify_error      VARCHAR(120) NULL,              -- 'signature_mismatch'|'replay_too_old'|...
    payload_json      MEDIUMTEXT NOT NULL,
    processed_at      DATETIME NULL,
    processing_outcome VARCHAR(40) NULL,              -- 'advanced'|'no_match'|'skipped_unverified'|'error'
    processing_error  VARCHAR(255) NULL,
    payment_instruction_id INT UNSIGNED NULL,         -- which PI this event advanced (when matched)
    KEY ix_mwh_tenant_received (tenant_id, received_at),
    KEY ix_mwh_resource        (tenant_id, resource_type, resource_id),
    KEY ix_mwh_pi              (tenant_id, payment_instruction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
