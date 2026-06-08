-- Migration 114 — QBO two-way sync shadow tables + drift detection
--
-- Implements Phases 1-3 of two-way QBO sync. Architecture:
--
--   pull  → shadow tables (qbo_inbound_*) hold the latest snapshot of
--           every QBO-side entity verbatim
--   drift → comparator computes diffs between CoreFlux state and shadow
--   surface → drift rows land in qbo_sync_drift for the admin UI to
--             triage and reconcile
--
-- Shadow tables are intentionally narrow: they hold just the fields we
-- need for matching + drift detection + AR/AP visibility, NOT a full
-- mirror. The full payload lives in qbo_inbound_*.raw_payload (TEXT)
-- for engineers who need to peek.

-- ===== AR shadow: Invoices pulled from QBO =====
CREATE TABLE qbo_inbound_invoices (
    id                  BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tenant_id           INT UNSIGNED     NOT NULL,
    qbo_invoice_id      VARCHAR(64)      NOT NULL,
    doc_number          VARCHAR(64)      NULL,
    customer_qbo_id     VARCHAR(64)      NULL,
    customer_name       VARCHAR(255)     NULL,
    issue_date          DATE             NULL,
    due_date            DATE             NULL,
    total_amount_cents  BIGINT           NOT NULL DEFAULT 0,
    balance_cents       BIGINT           NOT NULL DEFAULT 0, -- 0 = paid in QBO
    currency            VARCHAR(8)       NOT NULL DEFAULT 'USD',
    qbo_status          VARCHAR(32)      NULL,               -- 'Sent' | 'Paid' | 'PartiallyPaid' | etc.
    qbo_last_updated    DATETIME         NULL,               -- QBO MetaData.LastUpdatedTime
    coreflux_invoice_id BIGINT UNSIGNED  NULL,               -- resolved via external_entity_mappings
    raw_payload         MEDIUMTEXT       NULL,               -- full QBO response for engineering peek
    first_seen_at       DATETIME         NOT NULL,
    last_seen_at        DATETIME         NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_tenant_qbo_invoice (tenant_id, qbo_invoice_id),
    KEY idx_doc_number (tenant_id, doc_number),
    KEY idx_coreflux_link (coreflux_invoice_id),
    KEY idx_balance (tenant_id, balance_cents)
);

-- ===== AR shadow: Payments (PaymentReceived) pulled from QBO =====
CREATE TABLE qbo_inbound_payments (
    id                  BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tenant_id           INT UNSIGNED     NOT NULL,
    qbo_payment_id      VARCHAR(64)      NOT NULL,
    customer_qbo_id     VARCHAR(64)      NULL,
    customer_name       VARCHAR(255)     NULL,
    payment_date        DATE             NULL,
    total_amount_cents  BIGINT           NOT NULL DEFAULT 0,
    unapplied_cents     BIGINT           NOT NULL DEFAULT 0,  -- not yet linked to any invoice
    payment_method      VARCHAR(64)      NULL,                -- 'Check', 'Credit Card', 'ACH', etc.
    deposit_qbo_id      VARCHAR(64)      NULL,                -- DepositToAccountRef
    linked_invoice_ids  TEXT             NULL,                -- JSON array of QBO Invoice ids
    qbo_last_updated    DATETIME         NULL,
    raw_payload         MEDIUMTEXT       NULL,
    first_seen_at       DATETIME         NOT NULL,
    last_seen_at        DATETIME         NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_tenant_qbo_payment (tenant_id, qbo_payment_id),
    KEY idx_customer (tenant_id, customer_qbo_id),
    KEY idx_date (tenant_id, payment_date)
);

-- ===== AR shadow: Deposits (for processor-fee netting) =====
-- When a payment processor (Stripe, QBO Payments, Square, etc.) batches
-- a day of customer payments and deposits the net into the tenant's
-- bank, QBO records a Deposit with positive Line[] entries per Payment
-- and negative Line[] entries for the processor's cut. We extract the
-- sum of those negatives into fee_cents so AR can match gross vs net.
--
-- True wire-in / monthly-maintenance "bank fees" do NOT land here —
-- they show up as separate Expense / JournalEntry rows in QBO and are
-- pulled via the existing accounting sync.
CREATE TABLE qbo_inbound_deposits (
    id                  BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tenant_id           INT UNSIGNED     NOT NULL,
    qbo_deposit_id      VARCHAR(64)      NOT NULL,
    deposit_date        DATE             NULL,
    total_amount_cents  BIGINT           NOT NULL DEFAULT 0,  -- net amount that landed in bank
    fee_cents           BIGINT           NOT NULL DEFAULT 0,  -- processor / bank fee deducted
    fee_account_qbo_id  VARCHAR(64)      NULL,
    bank_account_qbo_id VARCHAR(64)      NULL,
    linked_payment_ids  TEXT             NULL,                -- JSON array of QBO Payment ids in this deposit
    qbo_last_updated    DATETIME         NULL,
    raw_payload         MEDIUMTEXT       NULL,
    first_seen_at       DATETIME         NOT NULL,
    last_seen_at        DATETIME         NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_tenant_qbo_deposit (tenant_id, qbo_deposit_id),
    KEY idx_date (tenant_id, deposit_date)
);

-- ===== AP shadow: Bills pulled from QBO =====
CREATE TABLE qbo_inbound_bills (
    id                  BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tenant_id           INT UNSIGNED     NOT NULL,
    qbo_bill_id         VARCHAR(64)      NOT NULL,
    doc_number          VARCHAR(64)      NULL,
    vendor_qbo_id       VARCHAR(64)      NULL,
    vendor_name         VARCHAR(255)     NULL,
    issue_date          DATE             NULL,
    due_date            DATE             NULL,
    total_amount_cents  BIGINT           NOT NULL DEFAULT 0,
    balance_cents       BIGINT           NOT NULL DEFAULT 0, -- 0 = paid in QBO
    currency            VARCHAR(8)       NOT NULL DEFAULT 'USD',
    qbo_last_updated    DATETIME         NULL,
    coreflux_bill_id    BIGINT UNSIGNED  NULL,
    raw_payload         MEDIUMTEXT       NULL,
    first_seen_at       DATETIME         NOT NULL,
    last_seen_at        DATETIME         NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_tenant_qbo_bill (tenant_id, qbo_bill_id),
    KEY idx_vendor (tenant_id, vendor_qbo_id),
    KEY idx_balance (tenant_id, balance_cents),
    KEY idx_coreflux_link (coreflux_bill_id)
);

-- ===== AP shadow: BillPayments pulled from QBO =====
CREATE TABLE qbo_inbound_billpayments (
    id                  BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tenant_id           INT UNSIGNED     NOT NULL,
    qbo_billpayment_id  VARCHAR(64)      NOT NULL,
    vendor_qbo_id       VARCHAR(64)      NULL,
    payment_date        DATE             NULL,
    total_amount_cents  BIGINT           NOT NULL DEFAULT 0,
    pay_type            VARCHAR(32)      NULL,                -- 'Check' | 'CreditCard' | 'Cash' | etc.
    bank_account_qbo_id VARCHAR(64)      NULL,
    linked_bill_ids     TEXT             NULL,                -- JSON array of QBO Bill ids paid by this
    qbo_last_updated    DATETIME         NULL,
    raw_payload         MEDIUMTEXT       NULL,
    first_seen_at       DATETIME         NOT NULL,
    last_seen_at        DATETIME         NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_tenant_qbo_billpayment (tenant_id, qbo_billpayment_id),
    KEY idx_vendor (tenant_id, vendor_qbo_id),
    KEY idx_date (tenant_id, payment_date)
);

-- ===== Drift detection: rows flagged for operator review =====
CREATE TABLE qbo_sync_drift (
    id                  BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tenant_id           INT UNSIGNED     NOT NULL,
    entity_type         VARCHAR(32)      NOT NULL,   -- 'invoice' | 'bill' | 'payment_paid_out_of_band'
    coreflux_id         BIGINT UNSIGNED  NULL,
    qbo_id              VARCHAR(64)      NULL,
    drift_kind          VARCHAR(64)      NOT NULL,   -- 'balance_changed' | 'paid_out_of_band' | 'qbo_only_orphan' | 'amount_changed' | 'voided_in_qbo'
    severity            ENUM('info','warn','critical')
                                          NOT NULL DEFAULT 'warn',
    coreflux_snapshot   MEDIUMTEXT       NULL,       -- JSON: key fields at detection time
    qbo_snapshot        MEDIUMTEXT       NULL,
    summary             VARCHAR(500)     NULL,
    status              ENUM('open','acknowledged','reconciled','dismissed')
                                          NOT NULL DEFAULT 'open',
    resolved_by_user_id INT UNSIGNED     NULL,
    resolved_at         DATETIME         NULL,
    resolution_note     VARCHAR(500)     NULL,
    detected_at         DATETIME         NOT NULL,
    last_seen_at        DATETIME         NOT NULL,   -- re-detected timestamp
    PRIMARY KEY (id),
    UNIQUE KEY uniq_open_drift (tenant_id, entity_type, qbo_id, drift_kind),
    KEY idx_status (tenant_id, status, severity),
    KEY idx_detected (tenant_id, detected_at)
);
