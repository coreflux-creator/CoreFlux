-- Migration 003 — Core MailService
-- See /app/core/MailService.SPEC.md §3 for the data model spec.
--
-- Tables created:
--   tenant_mail_connections — per-tenant provider connection (OAuth or Resend)
--   tenant_mail_folders     — folder mapping for inbound connectors
--   mail_messages_seen      — dedupe ledger (prevents re-ingest on cursor reset)
--   mail_outbox             — every outbound email logged (90d body retention)
--
-- All tables tenant-scoped. tenant_id FK enforced at app layer (matches
-- existing CoreFlux pattern where tenants live in `tenants`).

CREATE TABLE IF NOT EXISTS tenant_mail_connections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    provider ENUM('m365','google','imap','resend','log') NOT NULL,
    purpose  ENUM('inbound','outbound','both') NOT NULL,
    display_name VARCHAR(200) NOT NULL,
    account_address VARCHAR(255) NOT NULL,

    -- OAuth (encrypted at app layer via Core\Encryption / KMS)
    oauth_access_token_ct  VARBINARY(2048) NULL,
    oauth_refresh_token_ct VARBINARY(2048) NULL,
    oauth_expires_at       DATETIME NULL,
    oauth_scope            VARCHAR(500) NULL,

    -- IMAP fallback
    imap_host        VARCHAR(255) NULL,
    imap_port        INT          NULL,
    imap_username    VARCHAR(255) NULL,
    imap_password_ct VARBINARY(1024) NULL,

    -- Resend (outbound only)
    resend_api_key_id       VARCHAR(120) NULL,
    resend_verified_domain  VARCHAR(200) NULL,

    kms_key_version VARCHAR(64) NULL,
    status ENUM('active','reauth_required','revoked','error') NOT NULL DEFAULT 'active',

    last_polled_at DATETIME NULL,
    last_sent_at   DATETIME NULL,
    last_error     TEXT NULL,

    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_mc_tenant_provider_purpose (tenant_id, provider, purpose),
    INDEX idx_mc_status_polled (status, last_polled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tenant_mail_folders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    connection_id BIGINT UNSIGNED NOT NULL,
    tenant_id     BIGINT UNSIGNED NOT NULL,
    module        VARCHAR(40) NOT NULL,
    folder_path   VARCHAR(500) NOT NULL,
    folder_id_at_provider VARCHAR(255) NULL,
    polling_enabled BOOLEAN NOT NULL DEFAULT 1,
    polling_interval_seconds INT NOT NULL DEFAULT 3600,
    last_polled_at DATETIME NULL,
    last_message_cursor VARCHAR(255) NULL,
    dedupe_message_ids_window INT NOT NULL DEFAULT 5000,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_mf_conn_module (connection_id, module),
    INDEX idx_mf_tenant_module (tenant_id, module),
    INDEX idx_mf_polling_due (polling_enabled, last_polled_at),
    CONSTRAINT fk_mf_connection FOREIGN KEY (connection_id)
        REFERENCES tenant_mail_connections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_messages_seen (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    folder_id BIGINT UNSIGNED NOT NULL,
    provider_message_id VARCHAR(255) NOT NULL,
    seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    intake_event_ref VARCHAR(120) NULL,

    UNIQUE KEY uq_ms_folder_provider_msg (folder_id, provider_message_id),
    INDEX idx_ms_tenant_seen (tenant_id, seen_at),
    CONSTRAINT fk_ms_folder FOREIGN KEY (folder_id)
        REFERENCES tenant_mail_folders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_outbox (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    module VARCHAR(40) NOT NULL,
    purpose VARCHAR(80) NOT NULL,
    connection_id BIGINT UNSIGNED NULL,
    driver VARCHAR(20) NOT NULL,

    to_addresses_json TEXT NOT NULL,
    from_address VARCHAR(255) NULL,
    reply_to     VARCHAR(255) NULL,
    subject VARCHAR(500) NOT NULL,

    body_text_ref VARCHAR(40) NULL,
    body_text     MEDIUMTEXT NULL,
    body_html     MEDIUMTEXT NULL,
    attachments_json TEXT NULL,

    status ENUM('queued','sent','failed','bounced','complaint') NOT NULL DEFAULT 'queued',
    provider_message_id VARCHAR(255) NULL,
    sent_at DATETIME NULL,
    error TEXT NULL,

    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    body_truncated_at DATETIME NULL,

    INDEX idx_mo_tenant_module (tenant_id, module),
    INDEX idx_mo_tenant_purpose (tenant_id, purpose),
    INDEX idx_mo_status_created (status, created_at),
    INDEX idx_mo_retention (created_at, body_truncated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
