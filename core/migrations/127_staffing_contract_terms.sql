-- 127_staffing_contract_terms.sql
--
-- AR and AP relationships each require two independent contract rules:
-- cadence (weekly/biweekly/semimonthly/monthly) and payment terms.
-- The economic-party graph stores the effective values; this placement field
-- is the source-level default for the end-client receivable relationship.

SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placements');
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placements' AND COLUMN_NAME='client_payment_terms_override');
SET @sql := IF(@tbl>0 AND @col=0,
    'ALTER TABLE placements ADD COLUMN client_payment_terms_override VARCHAR(40) NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @billing_tbl := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='billing_invoices');
SET @billing_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='billing_invoices' AND COLUMN_NAME='payment_terms');
SET @sql := IF(@billing_tbl>0 AND @billing_col=0,
    'ALTER TABLE billing_invoices ADD COLUMN payment_terms VARCHAR(40) NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO integration_writable_targets
    (target_module, target_table, target_column, value_type, description, default_linked_entity)
VALUES
    ('placements', 'placements', 'client_payment_terms_override', 'string', 'End-client invoice payment terms such as NET30 or NET60', 'self');
