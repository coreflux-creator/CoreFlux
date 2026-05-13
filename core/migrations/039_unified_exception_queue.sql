-- Phase 1d — Unified Exception Queue (Live Books Rails, 2026-02-14).
--
-- One inbox over every "needs human attention" signal in the platform:
--   1. AI interpretations with requires_review=1 and status='proposed'
--   2. accounting_events with status='error' (posting failed)
--   3. Manually opened exceptions from external feeds (bank match miss,
--      duplicate-risk, period-locked attempts, unusual amounts) — added
--      as `exception_open()` calls land in their respective module code.
--
-- Schema for the source-of-truth table (open/closed lifecycle):
--   exception_queue
--     id, tenant_id, source, severity, opened_at, opened_by, payload,
--     subject_type, subject_id, status, assigned_user_id, closed_at,
--     closed_by_user_id, resolution_note
--
-- Plus a SQL VIEW (`v_unified_exception_queue`) that fans-in the AI
-- interpretation + event-error feeds so the UI hits a single query.
--
-- Idempotent.

DO 0;

CREATE TABLE IF NOT EXISTS exception_queue (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,

    -- Origin of the signal.  free-form so modules can extend.
    --   'ai.low_confidence'  — AI proposal needs review
    --   'event.error'        — accounting_events.status = error
    --   'bank.unmatched'     — treasury bank line no rule matched
    --   'duplicate.risk'     — possible duplicate AP bill / AR invoice
    --   'missing.docs'       — bill or invoice missing supporting docs
    --   'period.locked'      — emit attempted on a locked period
    --   'related_party'      — vendor + customer share a counterparty hint
    source VARCHAR(60) NOT NULL,
    severity ENUM('info','warn','high','critical') NOT NULL DEFAULT 'warn',

    -- Polymorphic subject pointer.
    subject_type VARCHAR(60) NULL,                -- 'accounting_event', 'ap_bill', 'billing_invoice', etc.
    subject_id   BIGINT UNSIGNED NULL,

    title       VARCHAR(255) NOT NULL,
    payload     JSON NULL,                        -- module-specific structured context

    status ENUM('open','snoozed','resolved','dismissed') NOT NULL DEFAULT 'open',
    assigned_user_id  BIGINT UNSIGNED NULL,
    opened_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    opened_by_user_id BIGINT UNSIGNED NULL,
    snoozed_until     DATETIME NULL,
    resolved_at       DATETIME NULL,
    resolved_by_user_id BIGINT UNSIGNED NULL,
    resolution_note   TEXT NULL,

    INDEX idx_exq_tenant_status (tenant_id, status, opened_at),
    INDEX idx_exq_subject       (tenant_id, subject_type, subject_id),
    INDEX idx_exq_source        (tenant_id, source),
    INDEX idx_exq_assignment    (tenant_id, assigned_user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The unified view: open exception_queue rows UNION fan-in feeds from
-- accounting_ai_interpretations (low-confidence proposals) and
-- accounting_events (errored emits). The UI reads this view ONLY.
--
-- We DROP first because views aren't IF-NOT-EXISTS-friendly across MySQL
-- versions; CREATE OR REPLACE is the cleanest fallback.

DROP VIEW IF EXISTS v_unified_exception_queue;

CREATE VIEW v_unified_exception_queue AS
    SELECT
        CONCAT('q:', id)                           AS unified_id,
        'queue'                                     AS feed,
        tenant_id,
        source,
        severity,
        subject_type,
        subject_id,
        title,
        opened_at                                   AS surfaced_at,
        status,
        assigned_user_id,
        id                                          AS source_row_id
    FROM exception_queue
    WHERE status IN ('open', 'snoozed')

    UNION ALL

    SELECT
        CONCAT('ai:', aai.id)                       AS unified_id,
        'ai_interpretation'                         AS feed,
        aai.tenant_id,
        'ai.low_confidence'                         AS source,
        CASE WHEN aai.confidence < 0.50 THEN 'high'
             WHEN aai.confidence < 0.75 THEN 'warn'
             ELSE 'info' END                        AS severity,
        'accounting_event'                          AS subject_type,
        aai.event_id                                AS subject_id,
        CONCAT('AI proposal needs review (',
               ROUND(aai.confidence * 100, 0), '%) on ',
               ae.event_type)                       AS title,
        aai.proposed_at                             AS surfaced_at,
        'open'                                      AS status,
        NULL                                        AS assigned_user_id,
        aai.id                                      AS source_row_id
    FROM accounting_ai_interpretations aai
    JOIN accounting_events ae
      ON ae.id = aai.event_id AND ae.tenant_id = aai.tenant_id
    WHERE aai.status = 'proposed'
      AND aai.requires_review = 1

    UNION ALL

    SELECT
        CONCAT('ev:', ae.id)                        AS unified_id,
        'event_error'                               AS feed,
        ae.tenant_id,
        'event.error'                               AS source,
        'high'                                      AS severity,
        'accounting_event'                          AS subject_type,
        ae.id                                       AS subject_id,
        CONCAT('Posting failed: ', ae.event_type,
               COALESCE(CONCAT(' (', SUBSTRING(ae.error_message, 1, 80), ')'), '')) AS title,
        ae.created_at                               AS surfaced_at,
        'open'                                      AS status,
        NULL                                        AS assigned_user_id,
        ae.id                                       AS source_row_id
    FROM accounting_events ae
    WHERE ae.status = 'failed';
