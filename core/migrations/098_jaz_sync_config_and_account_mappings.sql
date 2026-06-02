-- =============================================================================
-- Migration 098 — Jaz integration parity (sync_config per entity + mappings)
-- =============================================================================
-- Per user spec, accounting integrations are *per legal entity*:
--   • Each (tenant_id, sub_tenant_id, provider) has its own sync_config
--     describing which entity types push / pull / two_way / off.
--   • Consolidation + elimination JEs stay PLATFORM-only and never sync.
--   • Intercompany transactions still sync from each entity's own books to
--     the connected accounting destination (Jaz today, Zoho Books next).
--
-- This migration:
--   1) Adds `sync_config` JSON to accounting_provider_connections.
--   2) Adds `accounting_account_mappings` for per-entity CoreFlux↔provider
--      account mapping (the "mappable like the others" affordance the user
--      asked for, mirroring qbo_account_map / zoho_account_map but
--      provider-neutral).
--   3) Adds an `is_consolidation_entry` flag on accounting_journal_entries
--      so the outbox enqueue hook can skip consolidation/elimination JEs
--      without re-parsing the JE memo every time.
-- =============================================================================

-- 1) sync_config on the connection row (idempotent).
SET @has_sync := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'accounting_provider_connections'
      AND COLUMN_NAME = 'sync_config'
);
SET @stmt := IF(@has_sync = 0,
    "ALTER TABLE accounting_provider_connections ADD COLUMN `sync_config` JSON NULL AFTER `base_currency`",
    'SELECT 1');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- 2) per-entity provider-neutral account mapping table.
CREATE TABLE IF NOT EXISTS accounting_account_mappings (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id                   INT UNSIGNED NOT NULL,
    sub_tenant_id               INT UNSIGNED NOT NULL,
    provider                    VARCHAR(32)  NOT NULL,
    coreflux_account_id         INT UNSIGNED NOT NULL,
    coreflux_account_code       VARCHAR(40)  NOT NULL,
    provider_account_id         VARCHAR(120) NULL,
    provider_account_code       VARCHAR(80)  NULL,
    provider_account_name       VARCHAR(255) NULL,
    provider_account_type       VARCHAR(60)  NULL,
    confidence                  TINYINT UNSIGNED NOT NULL DEFAULT 100, -- 100 = manual; auto-match drops to 80
    source                      ENUM('manual','auto_code','auto_name','imported') NOT NULL DEFAULT 'manual',
    notes                       VARCHAR(500) NULL,
    last_synced_at              DATETIME NULL,
    created_by_user_id          INT UNSIGNED NULL,
    created_at                  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_aam_cf_account (tenant_id, sub_tenant_id, provider, coreflux_account_id),
    KEY ix_aam_provider_obj (tenant_id, sub_tenant_id, provider, provider_account_id),
    KEY ix_aam_provider (provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) is_consolidation_entry on JEs.
-- Used by the outbox enqueue hook to skip consolidation/elimination
-- entries (which are CoreFlux-only by user spec).
SET @has_consol := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'accounting_journal_entries'
      AND COLUMN_NAME = 'is_consolidation_entry'
);
SET @stmt := IF(@has_consol = 0,
    "ALTER TABLE accounting_journal_entries
       ADD COLUMN `is_consolidation_entry` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`,
       ADD INDEX `ix_je_consol` (`tenant_id`,`is_consolidation_entry`,`status`)",
    'SELECT 1');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
