-- Ensure fresh installations that create Billing after core migration 138
-- receive the canonical placement one-time item source type.

ALTER TABLE billing_invoice_lines
  MODIFY COLUMN source_type ENUM('time','time_entry','economic_item','manual','expense','recurring','milestone') NOT NULL DEFAULT 'manual';
