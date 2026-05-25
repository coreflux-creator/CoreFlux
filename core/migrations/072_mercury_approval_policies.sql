-- 072_mercury_approval_policies.sql
--
-- Approval rules / SoD threshold engine for Mercury payment_instructions.
-- Extends the dual-leg workflow (migration 050) so tenants can encode:
--   - amount thresholds → required_role
--   - N-of-M co-approver chains (e.g. >$10k requires 2 approvers)
--   - cool-off window between approval and worker auto-advance
--   - per-vendor / per-source-account scoping
--
-- Resolution: when mpApprove() runs, we pick the FIRST policy where the
-- payment amount falls in [min_amount_cents, max_amount_cents], ordered
-- by specificity (vendor_id NOT NULL > account_id NOT NULL > unspecified)
-- then sort_order ASC. NULL min/max acts as -∞ / +∞ respectively.

CREATE TABLE IF NOT EXISTS tenant_approval_policies (
    id                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id                INT UNSIGNED NOT NULL,
    name                     VARCHAR(160) NOT NULL,
    integration              VARCHAR(40) NOT NULL DEFAULT 'mercury',
    enabled                  TINYINT(1) NOT NULL DEFAULT 1,
    min_amount_cents         BIGINT NULL,
    max_amount_cents         BIGINT NULL,
    required_approver_role   VARCHAR(40) NULL,
    min_approvers            TINYINT UNSIGNED NOT NULL DEFAULT 1,
    cool_off_minutes         INT UNSIGNED NOT NULL DEFAULT 0,
    applies_to_recipient_id  INT UNSIGNED NULL,
    applies_to_account_id    VARCHAR(64) NULL,
    sort_order               INT NOT NULL DEFAULT 100,
    notes                    TEXT NULL,
    created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_policy_tenant_integration (tenant_id, integration, enabled),
    KEY idx_policy_specificity (tenant_id, applies_to_recipient_id, applies_to_account_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Co-approval chain — one row per (instruction, approver). The Nth row
-- (where N = policy.min_approvers) is the one that flips the row to
-- Approved. Refuses duplicate approvals from the same user.
CREATE TABLE IF NOT EXISTS payment_instruction_approvals (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id         INT UNSIGNED NOT NULL,
    instruction_id    INT UNSIGNED NOT NULL,
    user_id           INT UNSIGNED NOT NULL,
    note              VARCHAR(500) NULL,
    policy_id         INT UNSIGNED NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inst_user (tenant_id, instruction_id, user_id),
    KEY idx_inst (tenant_id, instruction_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cool-off support — when a policy mandates a cool-off window, the
-- worker MUST not advance past Approved before approved_at + window.
-- Existing payment_instructions table already carries approved_at, so
-- we only need a derived column to make queries cheap. Cool-off enforced
-- at the mpAdvance() entry point (cron worker WHERE-clauses can also
-- use this).
ALTER TABLE payment_instructions
    ADD COLUMN cool_off_until DATETIME NULL AFTER approved_at;
CREATE INDEX idx_pi_cool_off ON payment_instructions (tenant_id, state, cool_off_until);
