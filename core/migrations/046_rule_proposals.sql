-- Phase 2 AI — rule-competing AI proposals (2026-02).
--
-- Stores AI-generated rule candidates. The AI proposes tweaks to existing
-- business rules (e.g., "post 'consulting' expenses to 6010 instead of 6000
-- based on recent vendor patterns"). Each proposal is competed against the
-- current rule by replaying the last N relevant events through both rules,
-- then scored.
--
-- v1 ships ONE rule_type: 'ap_expense_category_map' (AP bill category →
-- expense account code). Generic schema so future rule_types can be added
-- without migrations.
--
-- Lifecycle: proposed → (competed, scored) → reviewed → accepted | rejected
--                                                      ↓
--                                                   applied (FUTURE — manual for P1)
--
-- Idempotent. Cloudways MySQL 5.7+ compatible.

CREATE TABLE IF NOT EXISTS rule_proposals (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    rule_type           VARCHAR(80)     NOT NULL,   -- 'ap_expense_category_map' for v1
    rule_scope          VARCHAR(120)    NULL,       -- optional sub-scope (e.g., entity_id, vendor pattern)

    current_rule_json   JSON            NOT NULL,   -- the rule shape today
    proposed_rule_json  JSON            NOT NULL,   -- AI's proposed tweak
    rationale           TEXT            NULL,       -- LLM-generated explanation

    -- Competition output (populated by aiRuleCompete()):
    comparison_json     JSON            NULL,       -- per-event diff + aggregates
    score               DECIMAL(6,4)    NULL,       -- 0..1; higher = bigger expected improvement
    events_compared     INT UNSIGNED    DEFAULT 0,
    events_changed      INT UNSIGNED    DEFAULT 0,
    dollars_changed     DECIMAL(18,2)   DEFAULT 0,

    -- Lifecycle:
    status              ENUM('proposed','competed','accepted','rejected','applied','error')
                                          NOT NULL DEFAULT 'proposed',
    status_reason       VARCHAR(255)    NULL,
    reviewed_by_user_id BIGINT UNSIGNED NULL,
    reviewed_at         DATETIME        NULL,
    review_notes        TEXT            NULL,

    created_by_user_id  BIGINT UNSIGNED NULL,
    ai_model            VARCHAR(80)     NULL,
    ai_interaction_id   BIGINT UNSIGNED NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY ix_rp_tenant_status      (tenant_id, status, created_at),
    KEY ix_rp_tenant_type        (tenant_id, rule_type, status),
    KEY ix_rp_updated_at         (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
