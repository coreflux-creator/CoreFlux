-- =======================================================================
-- Core migration 050 — Mercury Slice 3: Payment Engine + State Machine
-- -----------------------------------------------------------------------
-- payment_instructions = the canonical workflow record for a single AP
-- payout via Mercury. ONE row tracks BOTH legs of the gated workflow:
--
--   1. funding_pull   — Mercury debits external_account (funding_source
--                       recipient) → credits Mercury operating account
--   2. vendor_payout  — Mercury debits operating account → originates
--                       ACH/wire to vendor counterparty
--
-- High-level state machine (9 states + Cancelled terminal):
--
--   Draft → PendingApproval → Approved → Funding → Submitted → Settled
--                                                              → Reconciled
--                                                              → Failed
--                                                              → Returned
--
--   * → Cancelled  (operator cancel; allowed before Submitted)
--
-- Idempotency: every workflow row gets a deterministic idempotency_key,
-- propagated to BOTH Mercury transactions so retries are safe.
--
-- Idempotent SQL. Cloudways MySQL 5.7+ compatible. utf8mb4_unicode_ci.
-- =======================================================================

CREATE TABLE IF NOT EXISTS payment_instructions (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id         INT UNSIGNED NOT NULL,
    idempotency_key   VARCHAR(80) NOT NULL,

    -- Workflow identity
    state             ENUM('Draft','PendingApproval','Approved','Funding',
                          'Submitted','Settled','Reconciled','Failed',
                          'Returned','Cancelled') NOT NULL DEFAULT 'Draft',
    state_reason      VARCHAR(255) NULL,
    state_changed_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Source integration (Slice 3 supports ad-hoc + ap_payments source)
    source_module     VARCHAR(40) NOT NULL DEFAULT 'manual',  -- 'ap' | 'manual'
    source_ref        VARCHAR(80) NULL,                         -- e.g. ap_payment id

    -- Payment target
    recipient_id      INT UNSIGNED NOT NULL,                    -- → mercury_recipients(id) kind='vendor'
    amount_cents      BIGINT NOT NULL,
    currency          VARCHAR(8) NOT NULL DEFAULT 'USD',
    description       VARCHAR(120) NULL,                        -- shown on Mercury bank statement
    notes             VARCHAR(500) NULL,

    -- Approval workflow (SoD enforced at code level: approved_by != created_by)
    created_by_user_id    INT UNSIGNED NULL,
    submitted_for_approval_at DATETIME NULL,
    approved_by_user_id   INT UNSIGNED NULL,
    approved_at           DATETIME NULL,

    -- Funding leg (Mercury debits external_account → credits operating)
    funding_recipient_id        INT UNSIGNED NULL,              -- mercury_recipients(id) kind='funding_source'
    funding_mercury_txn_id      VARCHAR(80) NULL,                -- Mercury transaction id
    funding_mercury_status      VARCHAR(40) NULL,                -- 'pending'|'sent'|'settled'|'failed'|...
    funding_initiated_at        DATETIME NULL,
    funding_settled_at          DATETIME NULL,
    funding_last_polled_at      DATETIME NULL,

    -- Vendor payout leg (Mercury → vendor counterparty)
    operating_mercury_account_id VARCHAR(80) NULL,                -- which Mercury account the payout debits
    payout_mercury_txn_id        VARCHAR(80) NULL,
    payout_mercury_status        VARCHAR(40) NULL,
    payout_initiated_at          DATETIME NULL,
    payout_settled_at            DATETIME NULL,
    payout_last_polled_at        DATETIME NULL,

    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_pi_idem        (tenant_id, idempotency_key),
    KEY        ix_pi_state       (tenant_id, state, state_changed_at),
    KEY        ix_pi_recipient   (tenant_id, recipient_id),
    KEY        ix_pi_source      (tenant_id, source_module, source_ref),
    KEY        ix_pi_funding_txn (tenant_id, funding_mercury_txn_id),
    KEY        ix_pi_payout_txn  (tenant_id, payout_mercury_txn_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_instruction_audit (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    instruction_id  INT UNSIGNED NOT NULL,
    from_state      VARCHAR(40) NULL,
    to_state        VARCHAR(40) NOT NULL,
    reason          VARCHAR(255) NULL,
    actor_user_id   INT UNSIGNED NULL,
    meta_json       JSON NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_pia_instruction (tenant_id, instruction_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
