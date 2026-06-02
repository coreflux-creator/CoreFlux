-- =======================================================================
-- Core migration 096 — `external_id` + `source_system` for CSV-imported
-- primary entities (Wave 2).
-- -----------------------------------------------------------------------
-- See migration 095 for the design rationale (audit trail, idempotent
-- re-imports, per-row correlation to the system of record). Same pattern,
-- different tables:
--
--   ap_payments                       (bill payments)
--   billing_payments                  (customer receipts)
--   staffing_clients                  (recruiting client firms)
--   accounting_bank_statement_lines   (treasury bank lines — also gets
--                                      the columns so the bank's own
--                                      transaction id is first-class
--                                      next to fitid)
-- =======================================================================

-- -----------------------------------------------------------------------
-- ap_payments
-- -----------------------------------------------------------------------
SET @sql := (
    SELECT IF(COUNT(*) = 0,
        "ALTER TABLE ap_payments ADD COLUMN external_id VARCHAR(128) NULL AFTER reference",
        'DO 0')
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'ap_payments' AND column_name = 'external_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(COUNT(*) = 0,
        "ALTER TABLE ap_payments ADD COLUMN source_system ENUM('manual','jobdiva','qbo','mercury','plaid','jaz','zoho','airtable','gusto','other') NOT NULL DEFAULT 'manual' AFTER external_id",
        'DO 0')
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'ap_payments' AND column_name = 'source_system'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(COUNT(*) = 0,
        "ALTER TABLE ap_payments ADD UNIQUE KEY uq_app_tenant_source_ext (tenant_id, source_system, external_id)",
        'DO 0')
    FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'ap_payments' AND index_name = 'uq_app_tenant_source_ext'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------
-- billing_payments
-- -----------------------------------------------------------------------
SET @sql := (
    SELECT IF(COUNT(*) = 0,
        "ALTER TABLE billing_payments ADD COLUMN external_id VARCHAR(128) NULL AFTER reference",
        'DO 0')
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'billing_payments' AND column_name = 'external_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(COUNT(*) = 0,
        "ALTER TABLE billing_payments ADD COLUMN source_system ENUM('manual','jobdiva','qbo','mercury','plaid','jaz','zoho','airtable','gusto','other') NOT NULL DEFAULT 'manual' AFTER external_id",
        'DO 0')
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'billing_payments' AND column_name = 'source_system'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(COUNT(*) = 0,
        "ALTER TABLE billing_payments ADD UNIQUE KEY uq_bp_tenant_source_ext (tenant_id, source_system, external_id)",
        'DO 0')
    FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'billing_payments' AND index_name = 'uq_bp_tenant_source_ext'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------
-- staffing_clients
-- -----------------------------------------------------------------------
SET @sql := (
    SELECT IF(COUNT(*) = 0,
        "ALTER TABLE staffing_clients ADD COLUMN external_id VARCHAR(128) NULL AFTER name",
        'DO 0')
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'staffing_clients' AND column_name = 'external_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(COUNT(*) = 0,
        "ALTER TABLE staffing_clients ADD COLUMN source_system ENUM('manual','jobdiva','qbo','mercury','plaid','jaz','zoho','airtable','gusto','other') NOT NULL DEFAULT 'manual' AFTER external_id",
        'DO 0')
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'staffing_clients' AND column_name = 'source_system'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(COUNT(*) = 0,
        "ALTER TABLE staffing_clients ADD UNIQUE KEY uq_sc_tenant_source_ext (tenant_id, source_system, external_id)",
        'DO 0')
    FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'staffing_clients' AND index_name = 'uq_sc_tenant_source_ext'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------
-- accounting_bank_statement_lines
-- (kept alongside the pre-existing `fitid` dedup key — when present,
-- the bank's own transaction id is now first-class and reportable.)
-- -----------------------------------------------------------------------
SET @sql := (
    SELECT IF(COUNT(*) = 0,
        "ALTER TABLE accounting_bank_statement_lines ADD COLUMN external_id VARCHAR(128) NULL AFTER bank_reference",
        'DO 0')
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'accounting_bank_statement_lines' AND column_name = 'external_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(COUNT(*) = 0,
        "ALTER TABLE accounting_bank_statement_lines ADD COLUMN source_system ENUM('manual','jobdiva','qbo','mercury','plaid','jaz','zoho','airtable','gusto','other') NOT NULL DEFAULT 'manual' AFTER external_id",
        'DO 0')
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'accounting_bank_statement_lines' AND column_name = 'source_system'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Treasury bank statement lines already dedup on (tenant, account, fitid).
-- Re-using that uniqueness — no new uq_ index — but a non-unique
-- (tenant, source_system, external_id) helps the audit trail joins.
SET @sql := (
    SELECT IF(COUNT(*) = 0,
        "ALTER TABLE accounting_bank_statement_lines ADD INDEX idx_bsl_tenant_source_ext (tenant_id, source_system, external_id)",
        'DO 0')
    FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'accounting_bank_statement_lines' AND index_name = 'idx_bsl_tenant_source_ext'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
