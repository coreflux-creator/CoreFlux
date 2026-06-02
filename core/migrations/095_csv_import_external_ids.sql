-- =======================================================================
-- Core migration 095 — `external_id` + `source_system` for CSV-imported
-- primary entities (Wave 1).
-- -----------------------------------------------------------------------
-- WHY:
--   Every CSV-imported row needs a stable correlation key back to the
--   external system of record (JobDiva, QBO, Mercury, Jaz, Zoho, etc.)
--   so:
--     • re-uploading the same CSV upserts cleanly instead of
--       duplicating rows;
--     • the audit trail can answer "which JobDiva placement does
--       CoreFlux row 1234 correspond to?";
--     • the integration layer can match outbound writes back to the
--       inbound row.
--
-- WAVE 1 TABLES:
--   ap_vendors_index, ap_bills, billing_invoices, time_entries
--
-- DESIGN:
--   external_id   VARCHAR(128) NULL          — opaque key from the source system
--   source_system ENUM(...)    NOT NULL DEFAULT 'manual'
--   UNIQUE KEY (tenant_id, source_system, external_id)
--     — NULL external_id values are allowed and do NOT collide
--       (MySQL treats NULL as "distinct" in unique indexes), so
--       manual one-off imports without an external_id keep working.
--
-- Fully idempotent — every ALTER is gated by information_schema lookups
-- following the pattern from migration 054.
-- =======================================================================

-- -----------------------------------------------------------------------
-- ap_vendors_index
-- -----------------------------------------------------------------------
SET @sql := (
    SELECT IF(COUNT(*) = 0,
        "ALTER TABLE ap_vendors_index ADD COLUMN external_id VARCHAR(128) NULL AFTER vendor_name",
        'DO 0')
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'ap_vendors_index' AND column_name = 'external_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(COUNT(*) = 0,
        "ALTER TABLE ap_vendors_index ADD COLUMN source_system ENUM('manual','jobdiva','qbo','mercury','plaid','jaz','zoho','airtable','gusto','other') NOT NULL DEFAULT 'manual' AFTER external_id",
        'DO 0')
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'ap_vendors_index' AND column_name = 'source_system'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(COUNT(*) = 0,
        "ALTER TABLE ap_vendors_index ADD UNIQUE KEY uq_apv_tenant_source_ext (tenant_id, source_system, external_id)",
        'DO 0')
    FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'ap_vendors_index' AND index_name = 'uq_apv_tenant_source_ext'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------
-- ap_bills
-- -----------------------------------------------------------------------
SET @sql := (
    SELECT IF(COUNT(*) = 0,
        "ALTER TABLE ap_bills ADD COLUMN external_id VARCHAR(128) NULL AFTER bill_number",
        'DO 0')
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'ap_bills' AND column_name = 'external_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(COUNT(*) = 0,
        "ALTER TABLE ap_bills ADD COLUMN source_system ENUM('manual','jobdiva','qbo','mercury','plaid','jaz','zoho','airtable','gusto','other') NOT NULL DEFAULT 'manual' AFTER external_id",
        'DO 0')
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'ap_bills' AND column_name = 'source_system'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(COUNT(*) = 0,
        "ALTER TABLE ap_bills ADD UNIQUE KEY uq_apb_tenant_source_ext (tenant_id, source_system, external_id)",
        'DO 0')
    FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'ap_bills' AND index_name = 'uq_apb_tenant_source_ext'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------
-- billing_invoices
-- -----------------------------------------------------------------------
SET @sql := (
    SELECT IF(COUNT(*) = 0,
        "ALTER TABLE billing_invoices ADD COLUMN external_id VARCHAR(128) NULL AFTER invoice_number",
        'DO 0')
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'billing_invoices' AND column_name = 'external_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(COUNT(*) = 0,
        "ALTER TABLE billing_invoices ADD COLUMN source_system ENUM('manual','jobdiva','qbo','mercury','plaid','jaz','zoho','airtable','gusto','other') NOT NULL DEFAULT 'manual' AFTER external_id",
        'DO 0')
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'billing_invoices' AND column_name = 'source_system'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(COUNT(*) = 0,
        "ALTER TABLE billing_invoices ADD UNIQUE KEY uq_inv_tenant_source_ext (tenant_id, source_system, external_id)",
        'DO 0')
    FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'billing_invoices' AND index_name = 'uq_inv_tenant_source_ext'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------
-- time_entries
-- -----------------------------------------------------------------------
SET @sql := (
    SELECT IF(COUNT(*) = 0,
        "ALTER TABLE time_entries ADD COLUMN external_id VARCHAR(128) NULL AFTER work_date",
        'DO 0')
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'external_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(COUNT(*) = 0,
        "ALTER TABLE time_entries ADD COLUMN source_system ENUM('manual','jobdiva','qbo','mercury','plaid','jaz','zoho','airtable','gusto','other') NOT NULL DEFAULT 'manual' AFTER external_id",
        'DO 0')
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'source_system'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(COUNT(*) = 0,
        "ALTER TABLE time_entries ADD UNIQUE KEY uq_te_tenant_source_ext (tenant_id, source_system, external_id)",
        'DO 0')
    FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND index_name = 'uq_te_tenant_source_ext'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
