-- Plaid Transfer event-sync cursor (2026-02).
--
-- Plaid Transfer webhooks (`TRANSFER_EVENTS_UPDATE`) tell us "there are new
-- events — call /transfer/event/sync". Each call needs an `after_id` so we
-- only fetch deltas. Cursor is per-tenant.
--
-- Idempotent. Cloudways MySQL 5.7+ compatible.

CREATE TABLE IF NOT EXISTS plaid_transfer_cursor (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    last_event_id   BIGINT UNSIGNED NULL,    -- highest Plaid event_id we've ingested
    last_synced_at  DATETIME NULL,
    last_error      VARCHAR(255) NULL,
    UNIQUE KEY uq_ptc_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-event audit log so operators can replay history without re-hitting
-- Plaid. Includes the raw payload for forensics.
CREATE TABLE IF NOT EXISTS plaid_transfer_events (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id         INT UNSIGNED NOT NULL,
    plaid_event_id    BIGINT UNSIGNED NOT NULL,
    transfer_id       VARCHAR(64)  NOT NULL,
    event_type        VARCHAR(40)  NOT NULL,    -- 'pending' | 'posted' | 'settled' | 'returned' | 'failed' | 'cancelled' | ...
    failure_reason    VARCHAR(120) NULL,
    sweep_id          VARCHAR(64)  NULL,
    payload_json      JSON         NULL,
    received_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pte_event       (tenant_id, plaid_event_id),
    KEY        ix_pte_transfer    (tenant_id, transfer_id, event_type),
    KEY        ix_pte_received    (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
