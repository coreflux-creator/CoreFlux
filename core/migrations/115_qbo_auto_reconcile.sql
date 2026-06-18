-- Migration 115 — QBO auto-reconciliation flag
-- =======================================================================
-- Adds a per-tenant opt-in flag to `qbo_connections`. When enabled, the
-- two-way-sync cron will automatically close out `paid_out_of_band`
-- drift rows by creating a matching `billing_payments` / `ap_payments`
-- entry against the inbound QBO Payment / BillPayment and allocating
-- it to the open CoreFlux invoice / bill.
--
-- Drift detection still surfaces every paid_out_of_band row; the resolver
-- skips reconciliation when the tenant has the flag off, preserving the
-- explicit-operator-review default behaviour.
--
-- Idempotent. Cloudways MySQL 5.7+ compatible.
-- =======================================================================

SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'qbo_connections'
       AND COLUMN_NAME  = 'auto_reconcile_paid_out_of_band'
);
SET @sql := IF(@col = 0,
    "ALTER TABLE qbo_connections
         ADD COLUMN auto_reconcile_paid_out_of_band TINYINT(1) NOT NULL DEFAULT 0
         AFTER sync_config",
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
