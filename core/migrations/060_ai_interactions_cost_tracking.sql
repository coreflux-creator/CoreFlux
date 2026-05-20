-- =======================================================================
-- 060_ai_interactions_cost_tracking.sql
--
-- Adds token + cost columns to ai_interactions so the AI usage panel can
-- surface real spend (instead of leaving cost intentionally blank).
--
--   token_count_prompt   — input tokens reported by the provider (best-effort)
--   token_count_response — output tokens reported by the provider
--   cost_cents           — integer cents, computed at write time from the
--                          per-model cents-per-1k-tokens rate card. Stored
--                          as an integer to avoid float drift on aggregations.
-- =======================================================================
ALTER TABLE ai_interactions
    ADD COLUMN IF NOT EXISTS token_count_prompt   INT UNSIGNED NULL DEFAULT NULL AFTER response_hash,
    ADD COLUMN IF NOT EXISTS token_count_response INT UNSIGNED NULL DEFAULT NULL AFTER token_count_prompt,
    ADD COLUMN IF NOT EXISTS cost_cents           INT UNSIGNED NULL DEFAULT NULL AFTER token_count_response;
