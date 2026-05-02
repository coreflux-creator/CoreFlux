-- =======================================================================
-- Core migration 005 — Payment Rails (NACHA + Plaid Transfer scaffold)
-- -----------------------------------------------------------------------
-- Adds per-module disbursement-rail settings + per-row override columns
-- per AP SPEC §12.2 / Payroll SPEC §2.2 (PaymentRailsDriver abstraction).
--
-- Touches:
--   - new table   ap_settings              (mirror of payroll_settings)
--   - alter       payroll_settings         (+ disbursement_rail)
--   - alter       ap_payments              (+ disbursement_rail, rail_external_ref, rail_status, rail_originated_at)
--   - alter       payroll_runs             (+ disbursement_rail, rail_external_ref, rail_status, rail_originated_at)
--   - new table   tenant_payment_rails     (Phase B: holds Plaid access_token + funding account_id)
--
-- All new columns nullable; default rail resolved at runtime by
-- paymentRailsResolveRail() in /app/core/payment_rails.php — falling back
-- to 'nacha' when nothing is configured. Idempotent + back-compat.
-- =======================================================================

CREATE TABLE IF NOT EXISTS ap_settings (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           INT UNSIGNED NOT NULL,
    disbursement_rail   VARCHAR(40) NULL,                -- 'nacha' | 'plaid_transfer' | NULL (falls back to nacha)
    nacha_company_id    VARCHAR(10) NULL,                -- 10-char ACH originator ID
    nacha_company_name  VARCHAR(40) NULL,
    nacha_origin_routing VARCHAR(9)  NULL,               -- ODFI ABA
    plaid_access_token_ct VARBINARY(512) NULL,           -- encrypted Plaid access_token (Phase B)
    plaid_account_id    VARCHAR(80) NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_apset_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payroll_settings
    ADD COLUMN disbursement_rail   VARCHAR(40) NULL AFTER futa_credit_rate_bps,
    ADD COLUMN nacha_company_id    VARCHAR(10) NULL AFTER disbursement_rail,
    ADD COLUMN nacha_origin_routing VARCHAR(9) NULL AFTER nacha_company_id,
    ADD COLUMN plaid_access_token_ct VARBINARY(512) NULL AFTER nacha_origin_routing,
    ADD COLUMN plaid_account_id    VARCHAR(80) NULL AFTER plaid_access_token_ct;

ALTER TABLE ap_payments
    ADD COLUMN disbursement_rail    VARCHAR(40) NULL AFTER status,
    ADD COLUMN rail_external_ref    VARCHAR(120) NULL AFTER disbursement_rail,
    ADD COLUMN rail_status          VARCHAR(40) NULL AFTER rail_external_ref,
    ADD COLUMN rail_originated_at   DATETIME NULL AFTER rail_status,
    ADD INDEX idx_ap_payments_rail (tenant_id, rail_external_ref);

ALTER TABLE payroll_runs
    ADD COLUMN disbursement_rail    VARCHAR(40) NULL AFTER gusto_paid_at,
    ADD COLUMN rail_external_ref    VARCHAR(120) NULL AFTER disbursement_rail,
    ADD COLUMN rail_status          VARCHAR(40) NULL AFTER rail_external_ref,
    ADD COLUMN rail_originated_at   DATETIME NULL AFTER rail_status,
    ADD INDEX idx_payroll_runs_rail (tenant_id, rail_external_ref);

-- Phase B: persistence for the Plaid round-trip per tenant.
-- (Allowed here so we don't fragment migrations later. Currently unused.)
CREATE TABLE IF NOT EXISTS tenant_payment_rails (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           INT UNSIGNED NOT NULL,
    rail                VARCHAR(40) NOT NULL,            -- 'plaid_transfer' (only one row per rail per tenant)
    access_token_ct     VARBINARY(512) NULL,             -- encrypted
    item_id             VARCHAR(80) NULL,
    account_id          VARCHAR(80) NULL,
    status              ENUM('pending_link','linked','revoked','error') NOT NULL DEFAULT 'pending_link',
    last_event_id       VARCHAR(120) NULL,               -- /transfer/event/sync cursor
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tpr_tenant_rail (tenant_id, rail)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
