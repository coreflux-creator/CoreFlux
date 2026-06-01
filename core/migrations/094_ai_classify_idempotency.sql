-- 094_ai_classify_idempotency.sql
--
-- AI Tool Gateway — Slice 6: idempotency markers so the
-- transaction-classification cron worker doesn't reprocess
-- already-classified rows.
--
-- We add a thin `ai_classified_at` timestamp + `ai_workflow_run_id`
-- back-link to BOTH bank transaction tables. The cron worker picks
-- up rows WHERE ai_classified_at IS NULL.
--
-- Idempotent. MySQL 5.7+, utf8mb4_unicode_ci.

ALTER TABLE accounting_bank_statement_lines
    ADD COLUMN IF NOT EXISTS ai_classified_at   TIMESTAMP NULL DEFAULT NULL AFTER posted_at,
    ADD COLUMN IF NOT EXISTS ai_workflow_run_id CHAR(36) NULL DEFAULT NULL,
    ADD KEY IF NOT EXISTS ix_abst_ai_pending (tenant_id, ai_classified_at);

ALTER TABLE mercury_transactions
    ADD COLUMN IF NOT EXISTS ai_classified_at   TIMESTAMP NULL DEFAULT NULL AFTER posted_at,
    ADD COLUMN IF NOT EXISTS ai_workflow_run_id CHAR(36) NULL DEFAULT NULL,
    ADD KEY IF NOT EXISTS ix_mtx_ai_pending (tenant_id, ai_classified_at);
