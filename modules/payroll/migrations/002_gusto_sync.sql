-- =======================================================================
-- Payroll Migration 002 — Gusto Sync polish
-- -----------------------------------------------------------------------
-- Tracks the round-trip after a tenant downloads a Gusto-import CSV from
-- CoreFlux, uploads it to Gusto, and pastes back the resulting Gusto
-- run identifier so we know "this run is now Gusto-managed".
--
-- Once a run has a `gusto_run_id`, future post-to-GL flows must be aware
-- that Gusto is the system of record for cash movement, so we don't
-- double-post wages payable / taxes payable.
--
-- All new columns nullable + defaulted so the migration is idempotent
-- and back-compat; runs that never hit Gusto continue to behave exactly
-- as they did before.
-- =======================================================================

ALTER TABLE payroll_runs
    ADD COLUMN gusto_run_id           VARCHAR(120) NULL AFTER notes,
    ADD COLUMN gusto_payroll_url      VARCHAR(500) NULL AFTER gusto_run_id,
    ADD COLUMN gusto_status           ENUM('linked','submitted','paid','voided') NULL AFTER gusto_payroll_url,
    ADD COLUMN gusto_synced_at        TIMESTAMP NULL DEFAULT NULL AFTER gusto_status,
    ADD COLUMN gusto_synced_by        INT UNSIGNED NULL AFTER gusto_synced_at,
    ADD COLUMN gusto_paid_at          TIMESTAMP NULL DEFAULT NULL AFTER gusto_synced_by,
    ADD INDEX idx_run_tenant_gusto (tenant_id, gusto_run_id);
