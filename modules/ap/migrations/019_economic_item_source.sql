-- Ensure fresh installations that create AP after core migration 138
-- receive the canonical placement one-time item source type.

ALTER TABLE ap_bill_lines
  MODIFY COLUMN source_type ENUM('time','time_entry','economic_party','economic_item','manual','recurring','expense','referral') NOT NULL DEFAULT 'manual';
