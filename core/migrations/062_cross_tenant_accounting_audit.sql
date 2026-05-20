-- Migration 062 — Cross-tenant accounting audit trail (2026-02).
--
-- Captures every save that crosses tenant boundaries on the consolidation
-- and intercompany surfaces. Provides a single chronological feed for
-- SOX-style attestations and a rollback signal when an edge was wired
-- across the wrong sub-tenant.

CREATE TABLE IF NOT EXISTS cross_tenant_accounting_audit (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- The user-perspective context for the operation. This is the tenant
    -- the actor was viewing when they made the change (often the master).
    acting_tenant_id    INT UNSIGNED  NOT NULL,
    actor_user_id       INT UNSIGNED  NULL,                -- nullable for system / auditor sessions
    actor_label         VARCHAR(120)  NULL,                -- "kunal@…" / "External Auditor"

    -- The actual two tenants that were involved on each side of the edge.
    -- left/right naming so the schema can describe BOTH directed (parent→child)
    -- and undirected (intercompany A↔B) operations.
    left_tenant_id      INT UNSIGNED  NOT NULL,
    right_tenant_id     INT UNSIGNED  NOT NULL,
    left_entity_id      INT UNSIGNED  NULL,
    right_entity_id     INT UNSIGNED  NULL,

    action              VARCHAR(64)   NOT NULL,            -- e.g. 'consolidation.edge_upsert'
    payload             JSON          NULL,                -- snapshot of the saved row
    ip                  VARCHAR(64)   NULL,
    user_agent          VARCHAR(255)  NULL,
    occurred_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_xtaudit_acting   (acting_tenant_id, occurred_at),
    INDEX idx_xtaudit_left     (left_tenant_id,   occurred_at),
    INDEX idx_xtaudit_right    (right_tenant_id,  occurred_at),
    INDEX idx_xtaudit_action   (action,           occurred_at),
    INDEX idx_xtaudit_actor    (actor_user_id,    occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
