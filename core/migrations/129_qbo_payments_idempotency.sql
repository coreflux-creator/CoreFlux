-- Migration 129 — QBO Payments durable request idempotency
--
-- The UI reuses one Request-Id for retries of the same payment intent.
-- Enforce that invariant locally too, while allowing older rows that did
-- not carry a context token (multiple NULL values are valid in MySQL).

UPDATE qbo_payment_charges
   SET context_token = NULL
 WHERE context_token = '';

SET @idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'qbo_payment_charges'
       AND INDEX_NAME = 'uq_qbo_payment_context'
);
SET @sql := IF(@idx = 0,
    'ALTER TABLE qbo_payment_charges ADD UNIQUE KEY uq_qbo_payment_context (tenant_id, context_token)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
