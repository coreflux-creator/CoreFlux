-- An idempotent manual receipt may only be replayed with the same posting intent.
SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE billing_payments ADD COLUMN receipt_request_hash CHAR(64) NULL', 'DO 0')
    FROM information_schema.columns WHERE table_schema = DATABASE()
      AND table_name = 'billing_payments' AND column_name = 'receipt_request_hash');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
