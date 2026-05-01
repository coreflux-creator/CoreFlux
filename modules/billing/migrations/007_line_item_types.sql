-- Migration 007: AP/Billing line items support more than labor-hours.
--
-- Adds:
--   • ap_bill_lines.item_type             ENUM (semantic category)
--   • billing_invoice_lines.item_type     ENUM (same vocabulary)
--   • billing_invoice_lines.gl_revenue_account_code  VARCHAR(40)
-- Expands:
--   • billing_invoice_lines.source_type   ENUM gains expense/recurring/milestone
--
-- All idempotent. utf8mb4_unicode_ci.
--
-- The vocabulary models the line items a staffing/services agency actually
-- bills or pays for in the field:
--   labor          (default for time-tracked rows; what we already had)
--   expense        (out-of-pocket reimbursements — meals, parking, software)
--   materials      (hardware, supplies, licenses purchased for a project)
--   fixed_fee      (statement-of-work milestone or flat-fee engagement)
--   milestone      (deliverable-based payment)
--   discount       (negative line — promo / settlement / volume rebate)
--   subscription   (monthly tooling, retainer, MSP fee)
--   mileage        (qty=miles, unit_price=$/mi)
--   per_diem       (qty=days, unit_price=$/day)
--   reimbursement  (1:1 pass-through, no markup)
--   other          (catch-all; description carries the meaning)

-- ─── ap_bill_lines.item_type
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ap_bill_lines' AND COLUMN_NAME='item_type');
SET @sql := IF(@col=0,
  'ALTER TABLE ap_bill_lines ADD COLUMN item_type ENUM("labor","expense","materials","fixed_fee","milestone","discount","subscription","mileage","per_diem","reimbursement","other") NOT NULL DEFAULT "labor" AFTER source_ref_id',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill: AP rows that came from time_entries are labor; everything else
-- pre-existing was a manual generic line — keep them as labor (the default).
-- Future manual rows must specify their item_type explicitly.

-- ─── billing_invoice_lines.item_type
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='billing_invoice_lines' AND COLUMN_NAME='item_type');
SET @sql := IF(@col=0,
  'ALTER TABLE billing_invoice_lines ADD COLUMN item_type ENUM("labor","expense","materials","fixed_fee","milestone","discount","subscription","mileage","per_diem","reimbursement","other") NOT NULL DEFAULT "labor" AFTER source_ref_id',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── billing_invoice_lines.gl_revenue_account_code
-- Allows non-labor lines to land in the right revenue account
-- (e.g. 4100 Reimbursable expenses, 4200 Materials, 4300 SOW Fees).
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='billing_invoice_lines' AND COLUMN_NAME='gl_revenue_account_code');
SET @sql := IF(@col=0,
  'ALTER TABLE billing_invoice_lines ADD COLUMN gl_revenue_account_code VARCHAR(40) NULL AFTER total',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── Expand billing_invoice_lines.source_type to match AP capabilities.
-- MySQL doesn't allow conditional ALTER on ENUM definitions cleanly, but
-- changing an ENUM only adds new members — that operation is fast and
-- safe (in-place metadata change). It is idempotent because re-running
-- the ALTER simply re-asserts the same column definition.
ALTER TABLE billing_invoice_lines
  MODIFY COLUMN source_type ENUM('time','manual','expense','recurring','milestone') NOT NULL DEFAULT 'manual';
