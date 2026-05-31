-- 093_accounting_exceptions.sql
--
-- AI Tool Gateway — Slice 4 (Accounting MVP).
-- Spec §7 + §15: Accounting Agent ships an exception lane for
-- transactions the workflow can't auto-classify or that the
-- reviewer escalates. accounting_journal_entries is reused for
-- draft JEs (status='draft' already exists in the schema), so
-- Slice 4 only introduces this one new table.
--
-- Idempotent. MySQL 5.7+, utf8mb4_unicode_ci.

CREATE TABLE IF NOT EXISTS accounting_exceptions (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           INT UNSIGNED NOT NULL,
    sub_tenant_id       INT UNSIGNED NULL,
    workflow_run_id     CHAR(36) NULL,                          -- forward link to workflow_runs (092)
    ai_run_id           CHAR(36) NULL,                          -- forward link to ai_runs (090)
    exception_type      VARCHAR(60) NOT NULL,                    -- 'classify_low_confidence', 'unbalanced_je', 'missing_period', …
    severity            ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    status              ENUM('open','assigned','resolved','dismissed') NOT NULL DEFAULT 'open',
    related_ref_type    VARCHAR(60) NULL,                        -- 'bank_transaction', 'journal_entry', …
    related_ref_id      BIGINT UNSIGNED NULL,
    summary             VARCHAR(255) NOT NULL,
    detail_json         JSON NULL,                                -- structured detail (proposed account, confidence, …)
    assigned_to_user_id INT UNSIGNED NULL,
    resolved_by_user_id INT UNSIGNED NULL,
    resolved_at         TIMESTAMP NULL DEFAULT NULL,
    created_by_user_id  INT UNSIGNED NULL,                        -- NULL when created by AI
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY ix_ae_tenant_open    (tenant_id, status, created_at),
    KEY ix_ae_workflow       (workflow_run_id),
    KEY ix_ae_related        (tenant_id, related_ref_type, related_ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
