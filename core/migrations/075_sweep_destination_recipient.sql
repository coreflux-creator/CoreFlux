-- 075_sweep_destination_recipient.sql
--
-- Treasury Sweep go-live: the worker needs to originate a Mercury
-- payment_instruction whose recipient is the destination account.
-- Mercury treats internal transfers as "send to a counterparty
-- representing the destination account", which fits the existing
-- mercury_recipients model cleanly — we just need a new `kind` value.
--
-- This migration:
--   1. Extends mercury_recipients.kind ENUM with 'sweep_destination'.
--   2. Adds tenant_sweep_rules.destination_recipient_id so each rule
--      points to the mercury_recipients row that represents its
--      destination account's counterparty.
--
-- Operators wire the recipient once per destination account (push to
-- Mercury as a counterparty via the existing mercury_recipients flow);
-- the worker then reuses the same payment_instructions / mpAdvance
-- pipeline as vendor payments, with full approval-policy support.

-- 1. Extend the kind ENUM. Adding values to an ENUM is a metadata-only
--    operation in MySQL 5.7+ as long as the new values are appended;
--    we keep the existing order so column values stay stable.
ALTER TABLE mercury_recipients
    MODIFY COLUMN kind ENUM('vendor','funding_source','sweep_destination') NOT NULL;

-- 2. Link each sweep rule to its destination recipient. NULL until the
--    operator wires it up — engine Layer 3c records 'failed_execute'
--    with an explanatory message when missing, surfacing exactly what
--    setup the operator still owes.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'tenant_sweep_rules'
                AND COLUMN_NAME  = 'destination_recipient_id');
SET @sql := IF(@col = 0,
  'ALTER TABLE tenant_sweep_rules
     ADD COLUMN destination_recipient_id INT UNSIGNED NULL
                AFTER destination_account_id,
     ADD KEY idx_sweep_dest_recipient (tenant_id, destination_recipient_id)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
