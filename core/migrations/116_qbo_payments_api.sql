-- Migration 116 — QBO Payments API shadow tables
-- =======================================================================
-- QBO Payments is a DIFFERENT product from QBO Accounting. It exposes a
-- merchant-rail API (`/v4/payments/charges`, `/v4/payments/echecks`)
-- that lets a tenant accept card / ACH payments directly through Intuit.
--
-- Slice scope (Step 6 Phase 1):
--   - shadow table for every charge / e-check created against an
--     invoice, mirroring the QBO Payments response payload verbatim
--   - links to the originating CoreFlux billing_invoice + the
--     billing_payments row created upon successful capture
--   - idempotency: (tenant_id, qbo_charge_id) is unique so cron retries
--     and webhook replays don't double-insert
--
-- Idempotent. Cloudways MySQL 5.7+ compatible. utf8mb4_unicode_ci.
-- =======================================================================

CREATE TABLE IF NOT EXISTS qbo_payment_charges (
    id                  BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tenant_id           INT UNSIGNED     NOT NULL,
    qbo_charge_id       VARCHAR(64)      NOT NULL,
    charge_type         ENUM('card','echeck') NOT NULL DEFAULT 'card',

    -- monetary
    amount_cents        BIGINT           NOT NULL DEFAULT 0,
    currency            CHAR(3)          NOT NULL DEFAULT 'USD',

    -- QBO lifecycle: ISSUED (auth only) → CAPTURED / DECLINED / VOIDED
    --                → SETTLED (funds cleared, days later)  → REFUNDED
    status              VARCHAR(32)      NOT NULL DEFAULT 'ISSUED',

    -- card-specific (nullable for echeck)
    card_brand          VARCHAR(32)      NULL,
    card_last4          VARCHAR(8)       NULL,
    card_exp_month      TINYINT UNSIGNED NULL,
    card_exp_year       SMALLINT UNSIGNED NULL,

    -- echeck-specific (nullable for card)
    bank_name           VARCHAR(120)     NULL,
    account_last4       VARCHAR(8)       NULL,
    routing_last4       VARCHAR(8)       NULL,

    -- linkage
    coreflux_invoice_id BIGINT UNSIGNED  NULL,         -- billing_invoices.id (origin)
    coreflux_payment_id BIGINT UNSIGNED  NULL,         -- billing_payments.id (created on capture)
    context_token       VARCHAR(64)      NULL,         -- our outbound Request-Id for traceability

    -- error surface (Charter primitive #6)
    error_code          VARCHAR(64)      NULL,
    error_message       VARCHAR(500)     NULL,

    raw_payload         MEDIUMTEXT       NULL,
    created_at          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    captured_at         DATETIME         NULL,
    settled_at          DATETIME         NULL,
    updated_at          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uniq_tenant_qbo_charge (tenant_id, qbo_charge_id),
    KEY idx_tenant_status (tenant_id, status),
    KEY idx_tenant_invoice (tenant_id, coreflux_invoice_id),
    KEY idx_tenant_created (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
