SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE billing_invoices ADD COLUMN amount_credited DECIMAL(14,2) NOT NULL DEFAULT 0', 'DO 0')
    FROM information_schema.columns WHERE table_schema = DATABASE()
      AND table_name = 'billing_invoices' AND column_name = 'amount_credited');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
