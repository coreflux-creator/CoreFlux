-- Treasury — 004 — match/post wiring on liability statement lines
-- Idempotent. Adds matched_je_id (FK, nullable) so cards can post journal
-- entries the same way deposits do via accounting_bank_statement_lines.

SELECT COUNT(*) INTO @col_exists
  FROM information_schema.columns
 WHERE table_schema = DATABASE()
   AND table_name   = 'treasury_liability_statement_lines'
   AND column_name  = 'matched_je_id';
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE treasury_liability_statement_lines
     ADD COLUMN matched_je_id BIGINT UNSIGNED NULL AFTER match_status,
     ADD INDEX idx_tlsl_matched_je (tenant_id, matched_je_id)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- counterparty_company_id / counterparty_person_id allow tagging WHO the
-- charge was for (vendor reimbursement, employee swipe, etc.) without
-- forcing it. Optional metadata that intercompany rollup can use later.
SELECT COUNT(*) INTO @cc_exists
  FROM information_schema.columns
 WHERE table_schema = DATABASE()
   AND table_name   = 'treasury_liability_statement_lines'
   AND column_name  = 'counterparty_company_id';
SET @sql := IF(@cc_exists = 0,
  'ALTER TABLE treasury_liability_statement_lines
     ADD COLUMN counterparty_company_id BIGINT UNSIGNED NULL AFTER matched_je_id,
     ADD COLUMN counterparty_person_id  BIGINT UNSIGNED NULL AFTER counterparty_company_id',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
