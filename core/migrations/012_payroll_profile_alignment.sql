-- 012_payroll_profile_alignment.sql
--
-- Aligns payroll_profiles with the columns the time-settlement engine and
-- payroll compute path actually reference. Pre-this migration, those
-- modules referenced `pp.pay_type`, `pp.pay_rate_cents`, `pp.flsa_class`,
-- `pp.cycle_id` — none of which existed on payroll_profiles. The
-- workaround was hand-joining through people / placements / payroll_cycles
-- per call, which silently dropped employees whose people-row classification
-- didn't match. Now that we're going to live, the per-employee payroll
-- override has to live somewhere stable.
--
-- All five new columns are nullable. NULL semantics:
--   pay_type        : NULL → derive from primary placement (hourly vs salaried)
--   pay_rate_cents  : NULL → use placement_rates.pay_rate (live rate snapshot)
--   flsa_class      : NULL → mirror people.flsa_class
--   cycle_id        : NULL → use schedule_id directly
--
-- The compute path can therefore choose: explicit override on payroll_profiles,
-- or live derivation. No data migration needed — leaving everything NULL keeps
-- existing behaviour identical to the pre-migration state.
--
-- Atomic ALTERs so the migration runner's "Duplicate column" safe-pattern
-- handles partial application.

ALTER TABLE payroll_profiles ADD COLUMN pay_type        ENUM('hourly','salary','contract') NULL;
ALTER TABLE payroll_profiles ADD COLUMN pay_rate_cents  BIGINT NULL;
ALTER TABLE payroll_profiles ADD COLUMN flsa_class      ENUM('exempt','non_exempt','seasonal','outside_sales') NULL;
ALTER TABLE payroll_profiles ADD COLUMN cycle_id        INT UNSIGNED NULL;

-- ---------------------------------------------------------------------
-- ap_1099_ledger column rename: code uses `l.vendor_id` but spec called
-- it `ap_vendor_id`. Add `vendor_id` as a generated alias so existing
-- queries keep working without touching the API code.
-- ---------------------------------------------------------------------
ALTER TABLE ap_1099_ledger ADD COLUMN vendor_id INT UNSIGNED NULL;

-- Backfill the alias from the existing `ap_vendor_id` column. Guarded
-- with INFORMATION_SCHEMA so tenants whose schema never had the legacy
-- `ap_vendor_id` column (e.g. fresh installs that started post-rename)
-- skip the UPDATE cleanly instead of throwing "Unknown column" — the
-- migration runner's safe-pattern list does NOT cover Unknown column
-- (those usually signal real schema bugs we'd want to surface).
SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'ap_1099_ledger'
       AND COLUMN_NAME  = 'ap_vendor_id'
);
SET @sql := IF(@has_col = 1,
    'UPDATE ap_1099_ledger SET vendor_id = ap_vendor_id WHERE vendor_id IS NULL AND ap_vendor_id IS NOT NULL',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- people_tax_federal needs an `is_active` column the legacy advisor
-- queries reference. We synthesize it from `effective_through IS NULL OR
-- effective_through >= CURRENT_DATE`. As a real column it can be set by
-- triggers later; for now default to 1 and let the app keep it accurate.
-- ---------------------------------------------------------------------
ALTER TABLE people_tax_federal ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1;

-- ---------------------------------------------------------------------
-- placement_corp_details — code references `pcd.corp_name` directly but
-- the column lives on placement_corps via FK. Add a denorm column for
-- query simplicity; backfill from the parent.
-- ---------------------------------------------------------------------
ALTER TABLE placement_corp_details ADD COLUMN corp_name VARCHAR(255) NULL;

-- Backfill guarded on placement_corps existence — fresh tenants without
-- the staffing corp module enabled don't have this parent table, and the
-- migration runner does NOT silently swallow "Table doesn't exist" since
-- that's usually a real bug.
SET @has_tbl := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'placement_corps'
);
SET @sql := IF(@has_tbl = 1,
    'UPDATE placement_corp_details pcd JOIN placement_corps pc ON pc.id = pcd.placement_corp_id AND pc.tenant_id = pcd.tenant_id SET pcd.corp_name = pc.name WHERE pcd.corp_name IS NULL',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- accounting_journal_entry_lines: code uses `l.tenant_id` and
-- `l.description`. Both need to exist for tenant-scoped scopedQuery() to
-- work. Schema only has them on the parent JE.
-- ---------------------------------------------------------------------
ALTER TABLE accounting_journal_entry_lines ADD COLUMN tenant_id   INT UNSIGNED NULL;
ALTER TABLE accounting_journal_entry_lines ADD COLUMN description VARCHAR(500) NULL;

-- Backfill tenant_id from the parent JE.
UPDATE accounting_journal_entry_lines jel
   JOIN accounting_journal_entries je ON je.id = jel.je_id
    SET jel.tenant_id = je.tenant_id
  WHERE jel.tenant_id IS NULL;

-- ---------------------------------------------------------------------
-- people.user_id: schema has `user_account_id`. The settlement engine
-- aliases it as `user_id` — add a synonym column kept in sync via app
-- code (not a generated col so older MySQL is happy).
-- ---------------------------------------------------------------------
ALTER TABLE people ADD COLUMN user_id INT UNSIGNED NULL;

-- Guard the backfill: legacy schemas pre-people-encryption used
-- `user_account_id`; greenfield schemas may only have `user_id` from
-- the start. INFORMATION_SCHEMA preflight skips the UPDATE rather than
-- throwing "Unknown column".
SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'people'
       AND COLUMN_NAME  = 'user_account_id'
);
SET @sql := IF(@has_col = 1,
    'UPDATE people SET user_id = user_account_id WHERE user_id IS NULL AND user_account_id IS NOT NULL',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- placements: cycle anchors used by billing but stored on placement_corps.
-- Add denormalised columns so the rate-engine queries don't need a
-- 4-table join during settlement.
-- ---------------------------------------------------------------------
ALTER TABLE placements ADD COLUMN client_bill_cycle_anchor DATE NULL;
ALTER TABLE placements ADD COLUMN vendor_pay_cycle_anchor  DATE NULL;
