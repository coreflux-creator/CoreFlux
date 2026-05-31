-- 088_jaz_integration_foundation.sql
--
-- CoreFlux × Jaz.ai Slice 1 — provider-neutral accounting backend.
--
-- Per the spec (§7 "Core Design Rule"): CoreFlux remains the operating
-- layer; Jaz becomes ONE accounting destination behind a generic
-- AccountingProviderAdapter. Schema is deliberately provider-neutral —
-- the same tables will host QBO / Xero / CoreFlux-Native rows when
-- those adapters land. ENUMs mirror spec §10 exactly.
--
-- Entity model (per user confirmation):
--   tenant_id     = master tenant
--   sub_tenant_id = the legal entity (= tenants.id where tenant_type='sub')
--
-- Adaptations from spec for MySQL + CoreFlux conventions:
--   • uuid → INT UNSIGNED AUTO_INCREMENT (matches the rest of the schema)
--   • jsonb → JSON
--   • timestamptz → DATETIME (UTC enforced by app code, same as the
--     rest of the system)

CREATE TABLE IF NOT EXISTS accounting_provider_connections (
    id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id                INT UNSIGNED NOT NULL,
    sub_tenant_id            INT UNSIGNED NOT NULL,
    provider                 ENUM('jaz','coreflux_native','qbo','xero') NOT NULL,
    provider_org_id          VARCHAR(120) NULL,                           -- Jaz organization id
    credential_secret_ct     VARBINARY(2048) NULL,                        -- AES-256-GCM via encryptField()
    credential_last4         VARCHAR(8) NULL,                             -- masked display only
    connection_status        ENUM('pending','active','expired','revoked','failed') NOT NULL DEFAULT 'pending',
    base_currency            VARCHAR(8) NOT NULL DEFAULT 'USD',
    api_scope_summary        JSON NULL,                                   -- {permissions:[...], shadow_user:'…'}
    last_validated_at        DATETIME NULL,
    last_validation_error    VARCHAR(255) NULL,
    created_by_user_id       INT UNSIGNED NULL,
    created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_apc_provider_entity (tenant_id, sub_tenant_id, provider),
    KEY ix_apc_status (tenant_id, connection_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounting_destination_links (
    id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id                INT UNSIGNED NOT NULL,
    sub_tenant_id            INT UNSIGNED NOT NULL,
    provider                 VARCHAR(32) NOT NULL,
    provider_org_id          VARCHAR(120) NOT NULL,
    coreflux_object_type     VARCHAR(40) NOT NULL,                        -- 'bill','invoice','journal','customer','vendor','account','item'
    coreflux_object_id       INT UNSIGNED NOT NULL,
    provider_object_type     VARCHAR(40) NOT NULL,
    provider_object_id       VARCHAR(120) NOT NULL,
    source_system            VARCHAR(40) NULL,                            -- 'jobdiva','airtable','manual','migration'
    source_object_id         VARCHAR(120) NULL,
    sync_status              ENUM('pending','posted','failed','voided','reversed','superseded') NOT NULL DEFAULT 'pending',
    idempotency_key          VARCHAR(120) NOT NULL,
    created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_adl_idem (idempotency_key),
    UNIQUE KEY uq_adl_provider_obj (tenant_id, sub_tenant_id, provider, provider_object_type, provider_object_id),
    KEY ix_adl_cf_obj (tenant_id, sub_tenant_id, coreflux_object_type, coreflux_object_id),
    KEY ix_adl_sync_status (tenant_id, sync_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounting_outbox_events (
    id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id                INT UNSIGNED NOT NULL,
    sub_tenant_id            INT UNSIGNED NOT NULL,
    provider                 VARCHAR(32) NOT NULL,
    command_type             VARCHAR(60) NOT NULL,                        -- 'create_draft_bill','post_journal',…
    command_payload          JSON NOT NULL,
    source_event_id          VARCHAR(80) NULL,                            -- linking back to a CoreFlux module event
    status                   ENUM('queued','processing','posted','failed','retrying','dead_letter') NOT NULL DEFAULT 'queued',
    attempts                 INT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts             INT UNSIGNED NOT NULL DEFAULT 5,
    idempotency_key          VARCHAR(120) NOT NULL,
    provider_result          JSON NULL,
    error_code               VARCHAR(80) NULL,
    error_message            VARCHAR(255) NULL,
    next_retry_at            DATETIME NULL,
    created_by_user_id       INT UNSIGNED NULL,
    posted_at                DATETIME NULL,
    created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_aoe_idem (idempotency_key),
    KEY ix_aoe_status_retry (status, next_retry_at),                       -- worker pull
    KEY ix_aoe_tenant (tenant_id, sub_tenant_id, status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounting_report_snapshots (
    id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id                INT UNSIGNED NOT NULL,
    sub_tenant_id            INT UNSIGNED NOT NULL,
    provider                 VARCHAR(32) NOT NULL,
    report_type              VARCHAR(40) NOT NULL,                        -- 'pnl','balance_sheet','gl','trial_balance','ar_aging','ap_aging'
    period_start             DATE NULL,
    period_end               DATE NULL,
    as_of_date               DATE NULL,
    filters                  JSON NULL,
    normalized_report_json   JSON NOT NULL,                               -- canonical CoreFlux shape
    provider_raw_response_ref VARCHAR(255) NULL,                          -- object-storage pointer for the raw provider blob
    generated_by_user_id     INT UNSIGNED NULL,
    generated_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_ars_tenant_report (tenant_id, sub_tenant_id, report_type, period_start),
    KEY ix_ars_as_of (tenant_id, sub_tenant_id, report_type, as_of_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
