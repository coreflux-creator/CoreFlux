-- AP/Billing — P2 admin settings.
-- 1. AP digest day-of-week (tenant override; replaces hard-coded Sunday-22:00 cron).
-- 2. (billing_client_contacts already migrated in 009_dunning.sql — no schema change here.)
-- Idempotent.

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ap_settings' AND COLUMN_NAME='weekly_queue_email_dow');
SET @sql := IF(@col=0,
  -- ISO 8601 day-of-week. 0 = disabled. 7 = Sunday (matches the original schedule).
  "ALTER TABLE ap_settings
     ADD COLUMN weekly_queue_email_dow TINYINT NOT NULL DEFAULT 7
       COMMENT '0=disabled, 1=Mon … 7=Sun (ISO-8601). Day the Weekly Queue digest is sent.'",
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ap_settings' AND COLUMN_NAME='weekly_queue_email_hour');
SET @sql := IF(@col=0,
  "ALTER TABLE ap_settings
     ADD COLUMN weekly_queue_email_hour TINYINT NOT NULL DEFAULT 22
       COMMENT 'Hour-of-day in UTC, 0..23. The cron itself must run hourly for this to take effect.'",
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
