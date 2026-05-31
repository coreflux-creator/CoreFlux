-- 086_payment_instruction_internal_transfer.sql
--
-- Treasury Sweep go-live wiring.
--
-- Mercury treats every money movement as a payment to a "counterparty".
-- A sweep is Mercury account → Mercury account inside the same org;
-- there's no external funding leg to pull, so the dual-leg flow we use
-- for vendor payments collapses to a single Approved → Submitted hop.
--
-- The flag tells mpAdvance to skip mpOriginateFunding() and call
-- mpOriginateInternalTransfer() instead. The source Mercury account is
-- reused via the existing `operating_mercury_account_id` column ("the
-- Mercury account I'm debiting") — same semantic as a vendor payment.

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'payment_instructions'
                AND COLUMN_NAME  = 'is_internal_transfer');
SET @sql := IF(@col = 0,
  'ALTER TABLE payment_instructions
     ADD COLUMN is_internal_transfer TINYINT(1) NOT NULL DEFAULT 0
                AFTER source_ref',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
