-- Time — 005 — Intake events (per-module, per Time SPEC §315 + AP SPEC §5.2).
-- Captures inbound emails (from poll OR webhook OR future SMS) before AI
-- extraction runs. One row per inbound message; its eventual draft lives in
-- time_uploaded_documents (FK below).
--
-- Status flow:
--   received    → captured by intake (poll or webhook) but no attachments yet
--   downloaded  → attachments saved to S3, ready for AI extract
--   extracted   → AI extract complete; time_uploaded_documents row(s) populated
--   dismissed   → user marked as not-a-timesheet
--   failed      → unrecoverable error (logged in error_text)

CREATE TABLE IF NOT EXISTS time_intake_events (
    id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id              BIGINT UNSIGNED NOT NULL,
    source                 ENUM('poll_m365','poll_gmail','poll_imap','webhook_sendgrid','webhook_postmark','webhook_generic','sms_twilio') NOT NULL,
    folder_id              BIGINT UNSIGNED NULL,
    connection_id          BIGINT UNSIGNED NULL,
    provider_message_id    VARCHAR(255) NULL,
    from_address           VARCHAR(255) NULL,
    from_name              VARCHAR(255) NULL,
    sender_user_id         BIGINT UNSIGNED NULL,
    subject                VARCHAR(500) NULL,
    body_preview           VARCHAR(1000) NULL,
    received_at            DATETIME NULL,
    has_attachments        TINYINT(1) NOT NULL DEFAULT 0,
    attachment_count       INT UNSIGNED NOT NULL DEFAULT 0,
    upload_document_ids_json TEXT NULL,
    status                 ENUM('received','downloaded','extracted','dismissed','failed') NOT NULL DEFAULT 'received',
    error_text             VARCHAR(500) NULL,
    raw_meta_json          MEDIUMTEXT NULL,
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at           DATETIME NULL,
    INDEX idx_tie_tenant_status (tenant_id, status, created_at),
    INDEX idx_tie_tenant_from   (tenant_id, from_address),
    UNIQUE KEY uq_tie_provider_msg (tenant_id, source, provider_message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tenant-level inbound-email config: when a webhook provider (SendGrid /
-- Postmark) is configured, every tenant gets a unique `intake_email_address`
-- (e.g. `time+t42-acme@inbound.coreflux.app`). The provider POSTs to our
-- /api/time/intake.php?action=webhook endpoint when an email arrives.
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME   = 'tenants'
                      AND COLUMN_NAME  = 'time_intake_email_address');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE tenants ADD COLUMN time_intake_email_address VARCHAR(255) NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME   = 'tenants'
                      AND COLUMN_NAME  = 'time_intake_webhook_secret');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE tenants ADD COLUMN time_intake_webhook_secret VARCHAR(120) NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
