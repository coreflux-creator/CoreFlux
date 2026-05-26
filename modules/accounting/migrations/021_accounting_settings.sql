-- Migration 021 — Accounting settings (per-tenant defaults).
--
-- Backs the multi-period JE split helper. Lets each tenant override
-- the AR Unbilled + AP Accrued account codes (defaults to 13100 /
-- 21500 — common US-GAAP COA conventions) and gate the feature
-- behind an explicit opt-in flag so existing post flows stay
-- byte-identical until the operator says "yes, split my JEs".
--
-- Idempotent: re-running this migration is a no-op.

CREATE TABLE IF NOT EXISTS accounting_settings (
    tenant_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    -- COA codes for the accrual-bridge accounts. Account rows with
    -- these codes MUST exist in `accounting_accounts` before the
    -- multi-period split can post — the helper loud-fails otherwise
    -- (per operator preference: setup mistakes should surface fast).
    ar_unbilled_account_code   VARCHAR(40) NOT NULL DEFAULT '13100',
    ap_accrued_account_code    VARCHAR(40) NOT NULL DEFAULT '21500',
    -- Feature flag — defaults OFF so existing tenants see no
    -- behavior change until they explicitly enable. Flipping to 1
    -- means: any invoice/bill whose underlying work_dates span more
    -- than one accounting_period will post as N JEs instead of 1.
    multi_period_split_enabled TINYINT(1)  NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
