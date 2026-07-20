-- Migration 101 - Books-health column aliases + Chart of Accounts backfill
-- =========================================================================
--
-- Production-safe idempotency: live tenant databases may have come from
-- different accounting vintages. This migration discovers the actual column
-- names before altering/backfilling so update.php can converge older tenants
-- instead of failing on one missing alias.

-- Part 1 - reconciliation date aliases.
SET @has_recon := (
    SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'accounting_reconciliations'
);
SET @has_period_end := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'accounting_reconciliations'
       AND COLUMN_NAME = 'period_end'
);
SET @has_statement_end := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'accounting_reconciliations'
       AND COLUMN_NAME = 'statement_end_date'
);
SET @after_col := IF(@has_period_end > 0, ' AFTER period_end', '');
SET @sql := IF(@has_recon > 0 AND @has_statement_end = 0,
    CONCAT('ALTER TABLE accounting_reconciliations ADD COLUMN statement_end_date DATE NULL', @after_col),
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_statement_end := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'accounting_reconciliations'
       AND COLUMN_NAME = 'statement_end_date'
);
SET @has_reconciled_through := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'accounting_reconciliations'
       AND COLUMN_NAME = 'reconciled_through_date'
);
SET @after_col := IF(@has_statement_end > 0, ' AFTER statement_end_date', IF(@has_period_end > 0, ' AFTER period_end', ''));
SET @sql := IF(@has_recon > 0 AND @has_reconciled_through = 0,
    CONCAT('ALTER TABLE accounting_reconciliations ADD COLUMN reconciled_through_date DATE NULL', @after_col),
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_statement_end := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'accounting_reconciliations'
       AND COLUMN_NAME = 'statement_end_date'
);
SET @sql := IF(@has_recon > 0 AND @has_period_end > 0 AND @has_statement_end > 0,
    'UPDATE accounting_reconciliations SET statement_end_date = period_end WHERE statement_end_date IS NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_reconciled_through := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'accounting_reconciliations'
       AND COLUMN_NAME = 'reconciled_through_date'
);
SET @sql := IF(@has_recon > 0 AND @has_period_end > 0 AND @has_reconciled_through > 0,
    'UPDATE accounting_reconciliations SET reconciled_through_date = period_end WHERE reconciled_through_date IS NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_status := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'accounting_reconciliations'
       AND COLUMN_NAME = 'status'
);
SET @has_idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'accounting_reconciliations'
       AND INDEX_NAME = 'idx_arec_tenant_status_end'
);
SET @sql := IF(@has_recon > 0 AND @has_status > 0 AND @has_statement_end > 0 AND @has_idx = 0,
    'CREATE INDEX idx_arec_tenant_status_end ON accounting_reconciliations (tenant_id, status, statement_end_date)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Part 2 - Chart of Accounts backfill for bank accounts. The source account
-- code has appeared as gl_account_code, legal_account_code, or account_code
-- across older data loads; choose the first live column.
SET @has_accounting_accounts := (
    SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'accounting_accounts'
);
SET @has_account_code_target := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'accounting_accounts'
       AND COLUMN_NAME = 'code'
);
SET @has_bank_accounts := (
    SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'accounting_bank_accounts'
);
SET @aba_code_col := (
    SELECT COLUMN_NAME FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'accounting_bank_accounts'
       AND COLUMN_NAME IN ('gl_account_code', 'legal_account_code', 'account_code')
     ORDER BY FIELD(COLUMN_NAME, 'gl_account_code', 'legal_account_code', 'account_code')
     LIMIT 1
);
SET @aba_has_name := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'accounting_bank_accounts'
       AND COLUMN_NAME = 'name'
);
SET @aba_has_bank_name := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'accounting_bank_accounts'
       AND COLUMN_NAME = 'bank_name'
);
SET @aba_has_last4 := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'accounting_bank_accounts'
       AND COLUMN_NAME = 'last4'
);
SET @aba_code_expr := IF(@aba_code_col IS NULL, '', CONCAT('aba.`', @aba_code_col, '`'));
SET @aba_name_expr := IF(@aba_has_name > 0 AND @aba_has_bank_name > 0 AND @aba_has_last4 > 0,
    "TRIM(CONCAT(COALESCE(NULLIF(aba.`name`, ''), aba.`bank_name`, 'Bank account'), IF(aba.`last4` IS NULL OR aba.`last4` = '', '', CONCAT(' ...', aba.`last4`))))",
    IF(@aba_has_name > 0,
        "COALESCE(NULLIF(aba.`name`, ''), 'Bank account')",
        IF(@aba_has_bank_name > 0,
            "COALESCE(NULLIF(aba.`bank_name`, ''), 'Bank account')",
            "'Bank account'"
        )
    )
);
SET @sql := IF(@has_accounting_accounts > 0 AND @has_account_code_target > 0 AND @has_bank_accounts > 0 AND @aba_code_expr <> '',
    CONCAT(
        'INSERT INTO accounting_accounts ',
        '(tenant_id, code, name, account_type, normal_side, is_postable, parent_account_id, active, created_at) ',
        'SELECT aba.tenant_id, ', @aba_code_expr, ' AS code, ', @aba_name_expr, ' AS name, ',
        '''asset'' AS account_type, ''debit'' AS normal_side, 1 AS is_postable, NULL AS parent_account_id, 1 AS active, NOW() AS created_at ',
        'FROM accounting_bank_accounts aba ',
        'WHERE ', @aba_code_expr, ' IS NOT NULL AND ', @aba_code_expr, ' <> '''' ',
        'AND NOT EXISTS (SELECT 1 FROM accounting_accounts aa WHERE aa.tenant_id = aba.tenant_id AND aa.code = ', @aba_code_expr, ')'
    ),
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Part 3 - optional liability companion backfill. Current treasury stores
-- treasury_liability_accounts.account_id as the existing CoA id, so there may
-- be no source code to backfill. If an older table has a code column, use it.
SET @has_liability_accounts := (
    SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'treasury_liability_accounts'
);
SET @tla_code_col := (
    SELECT COLUMN_NAME FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'treasury_liability_accounts'
       AND COLUMN_NAME IN ('gl_account_code', 'legal_account_code', 'account_code')
     ORDER BY FIELD(COLUMN_NAME, 'gl_account_code', 'legal_account_code', 'account_code')
     LIMIT 1
);
SET @tla_has_name := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'treasury_liability_accounts'
       AND COLUMN_NAME = 'name'
);
SET @tla_has_institution := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'treasury_liability_accounts'
       AND COLUMN_NAME = 'institution_name'
);
SET @tla_has_last4 := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'treasury_liability_accounts'
       AND COLUMN_NAME = 'last4'
);
SET @tla_code_expr := IF(@tla_code_col IS NULL, '', CONCAT('tla.`', @tla_code_col, '`'));
SET @tla_name_expr := IF(@tla_has_name > 0 AND @tla_has_last4 > 0,
    "TRIM(CONCAT(COALESCE(NULLIF(tla.`name`, ''), 'Liability account'), IF(tla.`last4` IS NULL OR tla.`last4` = '', '', CONCAT(' ...', tla.`last4`))))",
    IF(@tla_has_institution > 0 AND @tla_has_last4 > 0,
        "TRIM(CONCAT(COALESCE(NULLIF(tla.`institution_name`, ''), 'Liability account'), IF(tla.`last4` IS NULL OR tla.`last4` = '', '', CONCAT(' ...', tla.`last4`))))",
        IF(@tla_has_name > 0,
            "COALESCE(NULLIF(tla.`name`, ''), 'Liability account')",
            IF(@tla_has_institution > 0,
                "COALESCE(NULLIF(tla.`institution_name`, ''), 'Liability account')",
                "'Liability account'"
            )
        )
    )
);
SET @sql := IF(@has_accounting_accounts > 0 AND @has_account_code_target > 0 AND @has_liability_accounts > 0 AND @tla_code_expr <> '',
    CONCAT(
        'INSERT INTO accounting_accounts ',
        '(tenant_id, code, name, account_type, normal_side, is_postable, parent_account_id, active, created_at) ',
        'SELECT tla.tenant_id, ', @tla_code_expr, ' AS code, ', @tla_name_expr, ' AS name, ',
        '''liability'' AS account_type, ''credit'' AS normal_side, 1 AS is_postable, NULL AS parent_account_id, 1 AS active, NOW() AS created_at ',
        'FROM treasury_liability_accounts tla ',
        'WHERE ', @tla_code_expr, ' IS NOT NULL AND ', @tla_code_expr, ' <> '''' ',
        'AND NOT EXISTS (SELECT 1 FROM accounting_accounts aa WHERE aa.tenant_id = tla.tenant_id AND aa.code = ', @tla_code_expr, ')'
    ),
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
