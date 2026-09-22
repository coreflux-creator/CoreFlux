-- Preserve line-level reporting dimensions on recurring journal templates.
-- The run engine refreshes assignment-owned dimensions on every run date, so
-- ownership changes do not leave future journals with stale recruiter, branch,
-- client, or legal-entity context.

SET @has_recurring_dim_json := (
    SELECT COUNT(*)
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'accounting_recurring_je_lines'
       AND COLUMN_NAME = 'dim_json'
);
SET @recurring_dim_sql := IF(
    @has_recurring_dim_json = 0,
    'ALTER TABLE accounting_recurring_je_lines ADD COLUMN dim_json JSON NULL AFTER description',
    'SELECT 1'
);
PREPARE recurring_dim_stmt FROM @recurring_dim_sql;
EXECUTE recurring_dim_stmt;
DEALLOCATE PREPARE recurring_dim_stmt;
