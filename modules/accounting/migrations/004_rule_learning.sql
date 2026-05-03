-- =======================================================================
-- Accounting Phase 2 Sprint A.2.1 — Rule-learning from accepted AI suggestions
-- -----------------------------------------------------------------------
-- When a user clicks "accept" on an AI categorization suggestion, we
-- stamp the chosen account_code onto the bank line itself. The learner
-- (bank_rules.php?action=learn) then walks accepted lines grouped by
-- category, finds common substrings shared by ≥3 distinct lines, and
-- drafts new accounting_bank_rules rows with is_approved=0 and
-- created_via='ai_learned' so the user can one-click Approve them into
-- auto-apply rules. Closes the loop between today's <AISuggestion />
-- accepts and tomorrow's auto-applied rules.
-- =======================================================================

ALTER TABLE accounting_bank_statement_lines
    ADD COLUMN categorized_account_code  VARCHAR(40)  NULL  AFTER applied_rule_id,
    ADD COLUMN categorized_at            TIMESTAMP    NULL  AFTER categorized_account_code,
    ADD COLUMN categorized_by_user_id    INT UNSIGNED NULL  AFTER categorized_at,
    ADD COLUMN categorized_via           VARCHAR(20)  NULL  AFTER categorized_by_user_id, -- 'ai_accepted' | 'manual' | 'rule'
    ADD INDEX idx_absl_categorized (tenant_id, bank_account_id, categorized_account_code, categorized_at);

-- Allow created_via='ai_learned' alongside the existing 'manual' / 'ai_suggested'.
ALTER TABLE accounting_bank_rules
    MODIFY COLUMN created_via ENUM('manual','ai_suggested','ai_learned') NOT NULL DEFAULT 'manual';
