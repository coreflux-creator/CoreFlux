-- =======================================================================
-- Core migration 048 — Mercury Bank integration (Slice 1: Foundation)
-- -----------------------------------------------------------------------
-- Tenant-owned Mercury accounts. Each tenant pastes their own Mercury API
-- token (Mercury Dashboard → Settings → API Tokens). CoreFlux is NOT a
-- Mercury "partner"; we're orchestrating against the tenant's own
-- workspace through their personal token.
--
-- Slice 1 scope: connection probe + account listing + transaction sync.
-- Recipients (Slice 2), payments (Slice 3), funding + reconciliation
-- (Slice 4) build on top of these tables.
--
-- Idempotent. Cloudways MySQL 5.7+ compatible. utf8mb4_unicode_ci.
-- =======================================================================

CREATE TABLE IF NOT EXISTS mercury_connections (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id         INT UNSIGNED NOT NULL,
    label             VARCHAR(80) NULL,                     -- operator-facing name
    api_token_ct      VARBINARY(512) NOT NULL,              -- AES-256-GCM via encryptField()
    api_token_last4   VARCHAR(8) NOT NULL,                  -- masked display
    status            ENUM('active','revoked','error') NOT NULL DEFAULT 'active',
    last_probe_at     DATETIME NULL,
    last_probe_error  VARCHAR(255) NULL,
    workspace_name    VARCHAR(120) NULL,                    -- echo'd back from Mercury /accounts
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by_user_id INT UNSIGNED NULL,
    updated_at        TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mcon_tenant (tenant_id)                  -- one connection per tenant in MVP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mercury_accounts (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id         INT UNSIGNED NOT NULL,
    connection_id     INT UNSIGNED NOT NULL,
    mercury_account_id VARCHAR(80) NOT NULL,                 -- Mercury's `id`
    nickname          VARCHAR(120) NULL,
    account_number_last4 VARCHAR(8) NULL,                    -- masked
    routing_number    VARCHAR(20) NULL,                      -- ABA — already public, ok to store
    kind              VARCHAR(40) NULL,                      -- 'checking' | 'savings' | 'credit' | ...
    status            VARCHAR(40) NULL,                      -- 'active' etc per Mercury
    available_balance_cents BIGINT NULL,
    current_balance_cents   BIGINT NULL,
    currency          VARCHAR(8) NOT NULL DEFAULT 'USD',
    last_synced_at    DATETIME NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mac_account (tenant_id, mercury_account_id),
    KEY        ix_mac_conn   (connection_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mercury_transactions (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id         INT UNSIGNED NOT NULL,
    account_pk        INT UNSIGNED NOT NULL,                 -- FK to mercury_accounts.id
    mercury_txn_id    VARCHAR(80) NOT NULL,
    mercury_account_id VARCHAR(80) NOT NULL,
    amount_cents      BIGINT NOT NULL,                       -- negative = outflow
    currency          VARCHAR(8) NOT NULL DEFAULT 'USD',
    posted_at         DATETIME NULL,
    estimated_delivery_date DATE NULL,
    status            VARCHAR(40) NULL,                      -- 'pending' | 'sent' | 'failed' | ...
    kind              VARCHAR(40) NULL,                      -- 'externalTransfer' | 'internalTransfer' | 'fee' | ...
    counterparty_name VARCHAR(200) NULL,
    note              VARCHAR(255) NULL,
    bank_description  VARCHAR(255) NULL,
    payload_json      JSON NULL,                             -- raw Mercury body for forensics
    received_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mtx_id       (tenant_id, mercury_txn_id),
    KEY        ix_mtx_account  (tenant_id, account_pk, posted_at),
    KEY        ix_mtx_received (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
