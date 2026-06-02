-- Migration 103 — AP payments method ENUM extension for Mercury
-- =======================================================================
-- The `ap_payments.method` ENUM originally listed
-- ('ach','wire','check','card','cash','plaid','other').  Mercury existed
-- only as a SECONDARY action — operators picked one of the original
-- methods then clicked "Send via Mercury" on the row.  Per Kunal's
-- direction (2026-02), Mercury becomes a first-class option from the
-- start of the AP pay flow.
--
-- Same change is mirrored on the legacy `ap_vendors_index.payment_method`
-- column (migration 008 introduced it for vendor-default routing) so
-- vendor records can also default to Mercury.
-- =======================================================================

-- ── ap_payments.method ──────────────────────────────────────────────────
SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'ap_payments'
       AND COLUMN_NAME  = 'method'
);
SET @sql := IF(@col = 1,
    "ALTER TABLE ap_payments MODIFY COLUMN method ENUM('ach','wire','check','card','cash','plaid','mercury','other') NOT NULL DEFAULT 'ach'",
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── ap_vendors_index.payment_method (vendor default rail) ───────────────
SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'ap_vendors_index'
       AND COLUMN_NAME  = 'payment_method'
);
SET @sql := IF(@col = 1,
    "ALTER TABLE ap_vendors_index MODIFY COLUMN payment_method ENUM('ach','wire','check','card','cash','plaid','mercury','other') NULL",
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
