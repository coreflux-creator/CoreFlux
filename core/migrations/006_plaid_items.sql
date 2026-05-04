-- =======================================================================
-- Core migration 006 — Plaid (Link / Auth / Transactions)
-- -----------------------------------------------------------------------
-- Persists Plaid Items per tenant + their Plaid accounts + a webhook event
-- log. Extends `accounting_bank_statement_lines` (mig 002) for transactions
-- via `fitid = transaction_id`, so the existing bank-rec engine consumes
-- Plaid feeds without modification.
--
-- Polymorphic ownership on plaid_items.purpose + (vendor_id|employee_id|
-- accounting_bank_account_id):
--   - 'bank_feed'        : feeds accounting_bank_accounts.id            → bank rec
--   - 'vendor_banking'   : verifies an ap_vendors_index.id              → AP ACH origination
--   - 'employee_banking' : verifies a people_employees.id               → payroll DD
--   - 'tenant_funding'   : the tenant's own funding source for Transfer → AP/Payroll outbound
--
-- All charset utf8mb4_unicode_ci, idempotent.
-- =======================================================================

CREATE TABLE IF NOT EXISTS plaid_items (
    id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id                INT UNSIGNED NOT NULL,
    item_id                  VARCHAR(80) NOT NULL,                  -- Plaid item_id
    access_token_ct          VARBINARY(512) NOT NULL,               -- AES-256-GCM encrypted
    institution_id           VARCHAR(80) NULL,                      -- e.g. 'ins_109508'
    institution_name         VARCHAR(160) NULL,
    products_json            VARCHAR(255) NULL,                     -- JSON array: ['auth','transactions']
    purpose                  ENUM('bank_feed','vendor_banking','employee_banking','tenant_funding') NOT NULL,
    vendor_id                INT UNSIGNED NULL,                     -- ap_vendors_index.id
    employee_id              INT UNSIGNED NULL,                     -- people_employees.id (or people.id)
    accounting_bank_account_id INT UNSIGNED NULL,                   -- accounting_bank_accounts.id
    transactions_cursor      MEDIUMTEXT NULL,                       -- /transactions/sync cursor
    last_transaction_sync_at DATETIME NULL,
    status                   ENUM('linked','requires_update','revoked','error') NOT NULL DEFAULT 'linked',
    last_webhook_at          DATETIME NULL,
    last_error_code          VARCHAR(80) NULL,
    last_error_message       VARCHAR(500) NULL,
    created_by_user_id       INT UNSIGNED NULL,
    created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pi_tenant_item (tenant_id, item_id),
    INDEX idx_pi_purpose         (tenant_id, purpose, status),
    INDEX idx_pi_vendor          (tenant_id, vendor_id),
    INDEX idx_pi_employee        (tenant_id, employee_id),
    INDEX idx_pi_bank_account    (tenant_id, accounting_bank_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per (item, account_id). Auth+Transactions products both surface
-- accounts; we hydrate this lazily on /accounts/get during exchange.
CREATE TABLE IF NOT EXISTS plaid_accounts (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id         INT UNSIGNED NOT NULL,
    plaid_item_pk     INT UNSIGNED NOT NULL,                       -- FK plaid_items.id
    account_id        VARCHAR(80) NOT NULL,                        -- Plaid account_id
    name              VARCHAR(160) NULL,
    official_name     VARCHAR(200) NULL,
    mask              CHAR(4) NULL,
    type              VARCHAR(40) NULL,                            -- depository | credit | loan | investment | other
    subtype           VARCHAR(40) NULL,                            -- checking | savings | credit card | etc.
    routing_last4     CHAR(4) NULL,                                -- only set after /auth/get
    account_last4     CHAR(4) NULL,
    is_primary        TINYINT(1) NOT NULL DEFAULT 0,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pa_tenant_account (tenant_id, account_id),
    INDEX idx_pa_item (tenant_id, plaid_item_pk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Webhook event log (audit trail + replay safety).
CREATE TABLE IF NOT EXISTS plaid_webhook_events (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           INT UNSIGNED NULL,                          -- null when item_id can't be matched
    plaid_item_pk       INT UNSIGNED NULL,                          -- FK plaid_items.id (when matched)
    item_id_external    VARCHAR(80) NULL,                           -- raw Plaid item_id from payload
    webhook_type        VARCHAR(40) NOT NULL,                       -- ITEM | TRANSACTIONS | AUTH | LINK | TRANSFER | ...
    webhook_code        VARCHAR(60) NOT NULL,                       -- SYNC_UPDATES_AVAILABLE | ITEM_LOGIN_REQUIRED | ...
    payload_json        TEXT NOT NULL,
    signature_verified  TINYINT(1) NOT NULL DEFAULT 0,
    processed_at        DATETIME NULL,
    error_message       VARCHAR(500) NULL,
    received_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pwe_received      (received_at),
    INDEX idx_pwe_type_code     (webhook_type, webhook_code),
    INDEX idx_pwe_tenant_item   (tenant_id, plaid_item_pk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
