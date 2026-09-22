-- Referral-only engagements earn an hourly client fee and create a separate
-- referral-vendor payable. They are not worker payroll or contractor labor.
ALTER TABLE placements
    MODIFY COLUMN engagement_type
        ENUM('w2','1099','c2c','temp_to_perm','direct_hire','internal','referral')
        NOT NULL DEFAULT 'w2';
