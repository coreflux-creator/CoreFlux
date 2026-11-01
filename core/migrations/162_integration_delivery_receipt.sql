-- Persist the provider receipt in the same durable write as the delivery result.
SET @delivery_receipt_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='integration_deliveries' AND column_name='remote_receipt_id');
SET @delivery_receipt_sql := IF(@delivery_receipt_exists=0, 'ALTER TABLE integration_deliveries ADD COLUMN remote_receipt_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL', 'DO 0');
PREPARE delivery_receipt_stmt FROM @delivery_receipt_sql;
EXECUTE delivery_receipt_stmt;
DEALLOCATE PREPARE delivery_receipt_stmt;
