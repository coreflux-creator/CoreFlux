-- =======================================================================
-- Core migration 130 — Pure//Pay outbound AP rail
-- -----------------------------------------------------------------------
-- Tenant-owned Pure//Pay API keys, vendor identity mappings, durable
-- origination state, and signed webhook receipts. Secrets are encrypted
-- with CoreFlux AES-256-GCM helpers; plaintext keys never enter these rows.
-- =======================================================================

CREATE TABLE IF NOT EXISTS purepay_connections (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id             INT UNSIGNED NOT NULL,
    label                 VARCHAR(80) NULL,
    api_key_ct            VARBINARY(1024) NOT NULL,
    api_key_last4         VARCHAR(8) NOT NULL,
    webhook_secret_ct     VARBINARY(512) NULL,
    webhook_secret_last4  VARCHAR(8) NULL,
    status                ENUM('active','revoked','error') NOT NULL DEFAULT 'active',
    wallet_balance_cents  BIGINT NULL,
    last_probe_at         DATETIME NULL,
    last_probe_error      VARCHAR(255) NULL,
    created_by_user_id    INT UNSIGNED NULL,
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ppcon_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purepay_vendor_mappings (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id             INT UNSIGNED NOT NULL,
    core_vendor_ref       VARCHAR(120) NOT NULL,
    purepay_vendor_id     VARCHAR(120) NOT NULL,
    vendor_name           VARCHAR(200) NULL,
    vendor_email          VARCHAR(255) NULL,
    verification_status   VARCHAR(40) NOT NULL DEFAULT 'verified',
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ppvm_core (tenant_id, core_vendor_ref),
    UNIQUE KEY uq_ppvm_remote (tenant_id, purepay_vendor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purepay_payment_links (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id             INT UNSIGNED NOT NULL,
    source_ref            VARCHAR(160) NOT NULL,
    core_payment_id       BIGINT UNSIGNED NULL,
    purepay_vendor_id     VARCHAR(120) NULL,
    purepay_bill_id       VARCHAR(120) NULL,
    purepay_payment_id    VARCHAR(120) NULL,
    amount_cents          BIGINT NOT NULL,
    status                VARCHAR(40) NOT NULL DEFAULT 'creating',
    request_fingerprint   CHAR(64) NOT NULL,
    response_json         JSON NULL,
    last_error            VARCHAR(500) NULL,
    last_error_json       JSON NULL,
    last_synced_at        DATETIME NULL,
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pppl_source (tenant_id, source_ref),
    KEY ix_pppl_bill (tenant_id, purepay_bill_id),
    KEY ix_pppl_payment (tenant_id, purepay_payment_id),
    KEY ix_pppl_status (tenant_id, status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purepay_webhook_events (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id             INT UNSIGNED NOT NULL,
    event_id              VARCHAR(160) NOT NULL,
    event_type            VARCHAR(80) NULL,
    verified              TINYINT(1) NOT NULL DEFAULT 0,
    verify_error          VARCHAR(80) NULL,
    signature_timestamp   BIGINT NULL,
    payload_json          JSON NULL,
    raw_body              MEDIUMTEXT NULL,
    processed_at          DATETIME NULL,
    processing_error      VARCHAR(500) NULL,
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ppwe_event (tenant_id, event_id),
    KEY ix_ppwe_recent (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
