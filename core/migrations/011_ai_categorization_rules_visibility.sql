-- 011_ai_categorization_rules_visibility.sql
--
-- Surface ai_categorization_history as "saved rules" the user can audit
-- and disable. The accept-loop already populates this table; we add:
--
--   • reject_count    — bumped when the user overrides an AI suggestion
--                       (override path is not "accept-as-is"). High reject
--                       count → don't auto-apply, even if a few accepts.
--   • disabled_at     — soft-mute set by the user from the saved-rules UI.
--                       When non-null, aiCategorizationFromHistory() skips
--                       this row entirely.
--   • disabled_reason — free-text optional note ("Wrong category for refunds")
--   • last_rejected_at — timestamp parity with last_accepted_at
--
-- All idempotent — split into atomic ALTERs so the migration runner's
-- "Duplicate column name" safe-pattern handles partial application.

ALTER TABLE ai_categorization_history ADD COLUMN reject_count      INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE ai_categorization_history ADD COLUMN last_rejected_at  TIMESTAMP NULL;
ALTER TABLE ai_categorization_history ADD COLUMN disabled_at       TIMESTAMP NULL;
ALTER TABLE ai_categorization_history ADD COLUMN disabled_reason   VARCHAR(255) NULL;
ALTER TABLE ai_categorization_history ADD COLUMN disabled_by_user  INT UNSIGNED NULL;
