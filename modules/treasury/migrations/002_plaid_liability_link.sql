-- Plaid → Treasury liability account linkage.
-- Adds `plaid_account_id` to treasury_liability_accounts so the bank-link
-- exchange flow can mirror credit cards / loans / lines-of-credit alongside
-- depository accounts. Idempotent.

SELECT COUNT(*) INTO @col_exists
  FROM information_schema.columns
 WHERE table_schema = DATABASE()
   AND table_name   = 'treasury_liability_accounts'
   AND column_name  = 'plaid_account_id';
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE treasury_liability_accounts ADD COLUMN plaid_account_id VARCHAR(80) NULL AFTER autopay_from_bank_account_id',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @idx_exists
  FROM information_schema.statistics
 WHERE table_schema = DATABASE()
   AND table_name   = 'treasury_liability_accounts'
   AND index_name   = 'uq_tla_tenant_plaid';
SET @sql := IF(@idx_exists = 0,
  'CREATE UNIQUE INDEX uq_tla_tenant_plaid ON treasury_liability_accounts (tenant_id, plaid_account_id)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
