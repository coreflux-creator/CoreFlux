-- 010_plaid_account_balances.sql
--
-- Cache the balance + currency Plaid returns on /accounts/get so the
-- Treasury list pages can show a live "Bank balance" column without firing
-- off an extra /accounts/balance/get on every page load. Refreshed on
-- initial exchange and on every transactions/sync.
--
-- Idempotent: uses IF NOT EXISTS where possible, and runtime ALTERs in the
-- API layer for tenants that haven't run migrations yet.

ALTER TABLE plaid_accounts
    ADD COLUMN IF NOT EXISTS current_balance_cents   BIGINT NULL,
    ADD COLUMN IF NOT EXISTS available_balance_cents BIGINT NULL,
    ADD COLUMN IF NOT EXISTS limit_balance_cents     BIGINT NULL,
    ADD COLUMN IF NOT EXISTS iso_currency_code       CHAR(3) NULL,
    ADD COLUMN IF NOT EXISTS balance_as_of           TIMESTAMP NULL;
