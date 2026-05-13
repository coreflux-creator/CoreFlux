-- CSV Import History (2026-02-14)
--
-- Every bulk CSV import (per-entity OR bulk wizard) writes one row here
-- when it completes — successful, partial, or failed. Gives admins +
-- auditors a queryable trail of who imported what, when, with which
-- mapping, and how many rows landed vs were skipped.
--
-- Lookup pattern on the Import History page:
--   SELECT * FROM csv_import_history
--    WHERE tenant_id = ? [AND entity = ? AND status = ?]
--    ORDER BY created_at DESC
--
-- Idempotent.

CREATE TABLE IF NOT EXISTS csv_import_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,

    entity            VARCHAR(60)  NOT NULL,  -- 'people' | 'ap_vendors' | ... | 'billing_payments'
    file_name         VARCHAR(255) NULL,      -- as provided by the client UI
    bytes_processed   INT UNSIGNED NOT NULL DEFAULT 0,

    rows_total        INT UNSIGNED NOT NULL DEFAULT 0,
    rows_imported     INT UNSIGNED NOT NULL DEFAULT 0,
    rows_skipped      INT UNSIGNED NOT NULL DEFAULT 0,
    errors_count      INT UNSIGNED NOT NULL DEFAULT 0,

    -- Settings that produced this run, for reproducibility + audit.
    skip_invalid      TINYINT(1) NOT NULL DEFAULT 0,
    update_existing   TINYINT(1) NOT NULL DEFAULT 0,
    ai_used           TINYINT(1) NOT NULL DEFAULT 0,
    preset_id         BIGINT UNSIGNED NULL,   -- FK soft-link to csv_mapping_presets.id
    column_map        JSON NULL,              -- the actual map used at commit time
    error_summary     JSON NULL,              -- { rowNumber: ["..","..",..] }

    status            ENUM('success','partial','failed') NOT NULL DEFAULT 'success',
    duration_ms       INT UNSIGNED NULL,

    created_by_user_id BIGINT UNSIGNED NULL,
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    KEY ix_tenant_created          (tenant_id, created_at),
    KEY ix_tenant_entity_created   (tenant_id, entity, created_at),
    KEY ix_tenant_status_created   (tenant_id, status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
