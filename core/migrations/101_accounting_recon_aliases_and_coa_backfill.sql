-- Migration 101 — Books-health column aliases + Chart of Accounts backfill
-- =======================================================================
-- Two unrelated bugs caught in one migration because both manifest on the
-- Bookkeeping page and both require zero schema churn beyond ALTER + DML:
--
--   1. `books_health.php` and `treasury_cash_position.php` query
--      `accounting_reconciliations.statement_end_date` but the table has
--      a `period_end` column instead.  `core/ai_agents.php` queries a
--      third name, `reconciled_through_date`.  Three call sites, three
--      different aliases, one real column.
--
--      Fix: add both aliases as DATE columns, backfill from period_end,
--      and keep them eventually-consistent via a trigger so future inserts
--      that only touch period_end keep the aliases populated.  (We do NOT
--      drop period_end — too many tests + admin scripts read it.)
--
--   2. Older Plaid links wrote a row into `accounting_bank_accounts` but
--      did NOT write the companion `accounting_accounts` (Chart of
--      Accounts) row, so connected accounts never showed up under
--      Accounting → Chart of Accounts.  Newer plaid_bank_link.php (3a
--      block) writes them inline; this migration backfills the rows that
--      predate that fix.
-- =======================================================================

-- ── Part 1 — recon column aliases ───────────────────────────────────────
SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'accounting_reconciliations'
       AND COLUMN_NAME  = 'statement_end_date'
);
SET @sql := IF(@col = 0,
    'ALTER TABLE accounting_reconciliations ADD COLUMN statement_end_date DATE NULL AFTER period_end',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'accounting_reconciliations'
       AND COLUMN_NAME  = 'reconciled_through_date'
);
SET @sql := IF(@col = 0,
    'ALTER TABLE accounting_reconciliations ADD COLUMN reconciled_through_date DATE NULL AFTER statement_end_date',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Backfill aliases from the canonical column.  We only stamp rows where
-- the alias is NULL so an operator who's been manually populating the
-- aliases (unlikely but possible) doesn't get clobbered.
UPDATE accounting_reconciliations
   SET statement_end_date = period_end
 WHERE statement_end_date IS NULL;
UPDATE accounting_reconciliations
   SET reconciled_through_date = period_end
 WHERE reconciled_through_date IS NULL;

-- Helpful index for the books_health.php query
-- (tenant_id + status + statement_end_date).  Idempotent.
SET @idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'accounting_reconciliations'
       AND INDEX_NAME   = 'idx_arec_tenant_status_end'
);
SET @sql := IF(@idx = 0,
    'CREATE INDEX idx_arec_tenant_status_end ON accounting_reconciliations (tenant_id, status, statement_end_date)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── Part 2 — Chart of Accounts backfill for Plaid bank accounts ─────────
-- For every accounting_bank_accounts row whose gl_account_code does NOT
-- yet have a matching accounting_accounts row, insert one with sensible
-- defaults so the Chart of Accounts page surfaces it.
--
-- We use INSERT ... SELECT ... WHERE NOT EXISTS to keep this idempotent
-- — running the migration twice yields zero new rows the second time.
INSERT INTO accounting_accounts
    (tenant_id, code, name, account_type, normal_side,
     is_postable, parent_account_id, active, created_at)
SELECT
    aba.tenant_id,
    aba.gl_account_code AS code,
    -- Surface the institution + nickname + last4 so the operator can
    -- recognise the row at a glance on the CoA page.
    TRIM(CONCAT(
        COALESCE(NULLIF(aba.name, ''), aba.bank_name, 'Bank account'),
        IF(aba.last4 IS NULL OR aba.last4 = '', '', CONCAT(' …', aba.last4))
    )) AS name,
    'asset'  AS account_type,
    'debit'  AS normal_side,
    1        AS is_postable,
    NULL     AS parent_account_id,
    1        AS active,
    NOW()    AS created_at
  FROM accounting_bank_accounts aba
 WHERE aba.gl_account_code IS NOT NULL
   AND aba.gl_account_code <> ''
   AND NOT EXISTS (
       SELECT 1 FROM accounting_accounts aa
        WHERE aa.tenant_id = aba.tenant_id
          AND aa.code      = aba.gl_account_code
   );

-- Same pass for treasury_liability_accounts (Mercury credit cards / loans).
-- Liabilities sit on the credit side.
INSERT INTO accounting_accounts
    (tenant_id, code, name, account_type, normal_side,
     is_postable, parent_account_id, active, created_at)
SELECT
    tla.tenant_id,
    tla.gl_account_code AS code,
    TRIM(CONCAT(
        COALESCE(NULLIF(tla.name, ''), 'Liability account'),
        IF(tla.last4 IS NULL OR tla.last4 = '', '', CONCAT(' …', tla.last4))
    )) AS name,
    'liability' AS account_type,
    'credit'    AS normal_side,
    1           AS is_postable,
    NULL        AS parent_account_id,
    1           AS active,
    NOW()       AS created_at
  FROM treasury_liability_accounts tla
 WHERE tla.gl_account_code IS NOT NULL
   AND tla.gl_account_code <> ''
   AND NOT EXISTS (
       SELECT 1 FROM accounting_accounts aa
        WHERE aa.tenant_id = tla.tenant_id
          AND aa.code      = tla.gl_account_code
   );
