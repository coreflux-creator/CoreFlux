-- Phase 1a — Canonical Event Registry (Live Books Rails, 2026-02-14)
--
-- The authoritative catalog of every business event CoreFlux modules are
-- permitted to emit. accountingProcessEvent() validates `event_type` and
-- the presence of required_payload_keys against this table before the
-- event is persisted to `accounting_events`.
--
-- Seed source: /app/memory/EVENT_REGISTRY.md (v1 — 51 events).
--
-- Idempotent. Each row carries a (event_type, schema_version) primary key
-- so we can evolve payload contracts without breaking older events on disk.

DO 0;

CREATE TABLE IF NOT EXISTS event_registry (
    event_type            VARCHAR(120) NOT NULL,
    schema_version        INT UNSIGNED NOT NULL DEFAULT 1,
    domain                VARCHAR(40)  NOT NULL,
    description           TEXT         NOT NULL,
    required_payload_keys JSON         NOT NULL,
    optional_payload_keys JSON         NULL,
    counterparty_type     VARCHAR(40)  NULL,
    expected_consumers    JSON         NULL,
    parent_event_types    JSON         NULL,
    typical_accounting    TEXT         NULL,
    deprecated_at         DATETIME     NULL,
    deprecated_alias_for  VARCHAR(120) NULL,        -- non-null on rows that exist
                                                    -- ONLY to alias an old emit
                                                    -- name to its new canonical name
    created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (event_type, schema_version),
    INDEX idx_evr_domain (domain),
    INDEX idx_evr_deprecated (deprecated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
