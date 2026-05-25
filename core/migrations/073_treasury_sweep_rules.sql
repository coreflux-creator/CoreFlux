-- 073_treasury_sweep_rules.sql
--
-- Cash-allocation sweep rules — operator-defined recipes for "keep X in
-- the operating account, sweep everything above into the high-yield
-- account on Friday". Schema is execution-engine-agnostic so the
-- forthcoming worker can lift it without further migrations.
--
-- The actual sweep execution (creating a Mercury transfer payment
-- instruction whose source = source_account_id, dest = destination_
-- account_id, amount = current_balance - target_min_balance_cents) is
-- deferred to a follow-up fork because it requires the live Mercury
-- balance API + a scheduled worker. This migration ships the
-- definition layer so operators can author rules now.

CREATE TABLE IF NOT EXISTS tenant_sweep_rules (
    id                          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id                   INT UNSIGNED NOT NULL,
    name                        VARCHAR(160) NOT NULL,
    enabled                     TINYINT(1) NOT NULL DEFAULT 1,
    source_account_id           VARCHAR(64) NOT NULL,
    destination_account_id      VARCHAR(64) NOT NULL,
    target_min_balance_cents    BIGINT NULL,
    sweep_above_cents           BIGINT NULL,
    -- Schedule shorthand the worker maps to cron-equivalent.
    -- 'daily', 'weekly_mon'..'weekly_fri', 'monthly_1', 'monthly_15'.
    frequency                   VARCHAR(32) NOT NULL DEFAULT 'weekly_fri',
    -- Approval-policy hook — when set, the resulting payment_instruction
    -- is created with policy_id stamped so the approval engine routes it
    -- through the same N-of-M chain the operator already configured.
    require_approval_policy_id  INT UNSIGNED NULL,
    last_run_at                 DATETIME NULL,
    last_outcome                VARCHAR(32) NULL,  -- 'swept' / 'skipped_under_floor' / 'failed'
    last_run_amount_cents       BIGINT NULL,
    sort_order                  INT NOT NULL DEFAULT 100,
    notes                       TEXT NULL,
    created_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sweep_tenant_enabled (tenant_id, enabled, frequency),
    KEY idx_sweep_last_run       (tenant_id, last_run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
