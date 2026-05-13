-- Phase 1b — AI Interpretation Records (Live Books Rails, 2026-02-14).
--
-- One row per AI-or-rule interpretation of an `accounting_events` row.
-- Captures:
--   • Who proposed the JE (AI agent ID or 'posting_rule:<id>' for deterministic)
--   • The proposed JE structure (json: lines[] with account/debit/credit/dims)
--   • Confidence (0.000 — 1.000)
--   • Evidence pointers (documents, bank txns, related entities)
--   • Reasoning narrative
--   • Reviewer disposition (accepted / corrected / rejected / superseded)
--
-- 1-to-many with accounting_events: same event can be re-interpreted as
-- AI models improve or after reviewer corrections. Only one row per event
-- is `status='accepted'` at a time. Older proposals get `status='superseded'`.
--
-- Idempotent.

DO 0;

CREATE TABLE IF NOT EXISTS accounting_ai_interpretations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    event_id  BIGINT UNSIGNED NOT NULL,        -- FK to accounting_events.id

    -- Source / authorship
    proposed_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    proposed_by      VARCHAR(80) NOT NULL,     -- 'ai:bookkeeper-v1' | 'posting_rule:42' | 'human:42'
    model            VARCHAR(120) NULL,        -- LLM model id when AI-generated

    -- Proposal payload
    confidence       DECIMAL(4,3) NOT NULL DEFAULT 1.000,
    proposed_je_json JSON NOT NULL,            -- { je_number?, lines: [{account, debit, credit, dims, memo}] }
    reasoning        TEXT NULL,
    evidence_json    JSON NULL,                -- [{type, id, label, hash}]
    typical_accounting_hint TEXT NULL,         -- snapshot of event_registry.typical_accounting at propose time

    -- Reviewer disposition
    status ENUM('proposed','accepted','overridden','rejected','superseded')
           NOT NULL DEFAULT 'proposed',
    requires_review     TINYINT(1) NOT NULL DEFAULT 0,
    reviewer_user_id    BIGINT UNSIGNED NULL,
    reviewed_at         DATETIME NULL,
    review_disposition  VARCHAR(60) NULL,      -- free-text reason ('amount differs by >$50','vendor recoded',...)

    -- Link to the actual posted JE if the proposal was accepted (or the
    -- reviewer's corrected version if overridden).
    journal_entry_id    BIGINT UNSIGNED NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_aai_event       (event_id),
    INDEX idx_aai_tenant_event (tenant_id, event_id),
    INDEX idx_aai_status      (tenant_id, status),
    INDEX idx_aai_review_q    (tenant_id, requires_review, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
