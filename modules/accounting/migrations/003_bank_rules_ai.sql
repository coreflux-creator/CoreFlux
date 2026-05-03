-- =======================================================================
-- Accounting Phase 2 Sprint A.2 — Bank rules + AI assist plumbing
-- -----------------------------------------------------------------------
-- Builds on 002_phase2.sql. Adds:
--   1. accounting_bank_rules — categorization / match rules with an
--      is_approved flag. When `is_approved=1`, future bank-line imports
--      that match the rule are auto-applied. When `is_approved=0`, the
--      rule fires as a "suggested match" only — reviewer must accept
--      through <AISuggestion /> before anything is posted.
--   2. flags on accounting_bank_statement_lines: ai_suggested_account_code,
--      ai_suggested_je_id, ai_suggested_rule_id, applied_rule_id —
--      lets the UI render "✨ Suggested" or "⚙ Auto-matched by rule" pills.
-- =======================================================================

CREATE TABLE IF NOT EXISTS accounting_bank_rules (
    id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id                INT UNSIGNED NOT NULL,
    bank_account_id          INT UNSIGNED NULL,           -- NULL = applies to all accounts
    name                     VARCHAR(160) NOT NULL,
    pattern_kind             ENUM('contains','starts_with','equals','regex') NOT NULL DEFAULT 'contains',
    pattern                  VARCHAR(255) NOT NULL,
    amount_min_cents         BIGINT NULL,
    amount_max_cents         BIGINT NULL,
    direction                ENUM('any','credit','debit') NOT NULL DEFAULT 'any',
    target_account_code      VARCHAR(40) NULL,            -- for categorization rules
    target_offset_account    VARCHAR(40) NULL,            -- balance the GL JE — usually the bank GL account
    is_approved              TINYINT(1) NOT NULL DEFAULT 0,    -- 0 = staged as suggestion, 1 = auto-apply
    created_via              ENUM('manual','ai_suggested') NOT NULL DEFAULT 'manual',
    ai_interaction_id        INT UNSIGNED NULL,
    times_applied            INT UNSIGNED NOT NULL DEFAULT 0,
    last_applied_at          TIMESTAMP NULL,
    status                   ENUM('active','paused','archived') NOT NULL DEFAULT 'active',
    created_by_user_id       INT UNSIGNED NULL,
    created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_abr_tenant_status (tenant_id, status, is_approved)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE accounting_bank_statement_lines
    ADD COLUMN ai_suggested_account_code  VARCHAR(40)  NULL  AFTER match_status,
    ADD COLUMN ai_suggested_je_id         INT UNSIGNED NULL AFTER ai_suggested_account_code,
    ADD COLUMN ai_suggested_rule_id       INT UNSIGNED NULL AFTER ai_suggested_je_id,
    ADD COLUMN ai_suggested_confidence    DECIMAL(4,3) NULL AFTER ai_suggested_rule_id,
    ADD COLUMN ai_suggested_at            TIMESTAMP    NULL AFTER ai_suggested_confidence,
    ADD COLUMN applied_rule_id            INT UNSIGNED NULL AFTER ai_suggested_at,
    ADD INDEX idx_absl_ai_pending (tenant_id, bank_account_id, ai_suggested_at);
