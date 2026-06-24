-- Migration 118 — Engagements module (fixed-fee project accounting).
-- =======================================================================
-- An "engagement" is a fixed-fee project with optional milestones. Each
-- milestone can independently flip from `pending` → `ready_to_invoice`
-- → `invoiced` → `paid`, generating a billing_invoice and tracking it
-- back to the engagement for revenue-recognition + WIP reporting.
--
-- Why this matters:
--   - Hours billing already lives in the time/billing modules (T&M).
--   - Engagements covers the OTHER half: scope-fixed work where revenue
--     is recognised on milestone completion, not on hours-billed.
--
-- All tables follow CoreFlux conventions: tenant_id-scoped, soft-delete
-- via `archived_at`, audit log, ON UPDATE CURRENT_TIMESTAMP on updated_at.
--
-- Idempotent. Cloudways MySQL 5.7+ compatible.
-- =======================================================================

CREATE TABLE IF NOT EXISTS engagements (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id       INT UNSIGNED    NOT NULL,
    entity_id       INT UNSIGNED    NULL,                          -- legal entity, NULL = tenant default
    client_name     VARCHAR(255)    NOT NULL,
    project_name    VARCHAR(255)    NOT NULL,
    description     TEXT            NULL,
    currency        CHAR(3)         NOT NULL DEFAULT 'USD',
    total_fee       DECIMAL(14, 2)  NOT NULL DEFAULT 0,
    invoiced_amount DECIMAL(14, 2)  NOT NULL DEFAULT 0,
    paid_amount     DECIMAL(14, 2)  NOT NULL DEFAULT 0,

    -- Lifecycle: draft (planning) → active (work in progress) → completed
    -- (all milestones invoiced) → archived (closed; read-only).
    status          ENUM('draft','active','completed','archived')
                    NOT NULL DEFAULT 'draft',

    start_date      DATE            NULL,
    end_date        DATE            NULL,
    notes           TEXT            NULL,
    metadata        JSON            NULL,                          -- free-form for future extensibility

    archived_at     DATETIME        NULL,
    created_by_user_id INT UNSIGNED NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_engagements_tenant         (tenant_id),
    KEY idx_engagements_tenant_status  (tenant_id, status),
    KEY idx_engagements_tenant_client  (tenant_id, client_name),
    KEY idx_engagements_tenant_entity  (tenant_id, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS engagement_milestones (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    engagement_id   BIGINT UNSIGNED NOT NULL,
    tenant_id       INT UNSIGNED    NOT NULL,                      -- denormalised for tenant-leak guard
    sort_order      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    name            VARCHAR(255)    NOT NULL,
    description     TEXT            NULL,
    amount          DECIMAL(14, 2)  NOT NULL DEFAULT 0,
    target_date     DATE            NULL,

    -- Lifecycle:
    --   pending          → operator hasn't marked it done
    --   ready_to_invoice → ready to be billed (operator-triggered)
    --   invoiced         → billing_invoice generated; FK in invoice_id
    --   paid             → underlying invoice fully settled
    --   cancelled        → killed without invoicing
    status          ENUM('pending','ready_to_invoice','invoiced','paid','cancelled')
                    NOT NULL DEFAULT 'pending',

    invoice_id      BIGINT UNSIGNED NULL,                          -- billing_invoices.id (set on invoicing)
    completed_at    DATETIME        NULL,
    invoiced_at     DATETIME        NULL,
    paid_at         DATETIME        NULL,
    notes           TEXT            NULL,

    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_milestones_engagement (engagement_id, sort_order),
    KEY idx_milestones_tenant     (tenant_id),
    KEY idx_milestones_status     (tenant_id, status),
    KEY idx_milestones_invoice    (tenant_id, invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS engagement_audit_log (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    engagement_id   BIGINT UNSIGNED NULL,
    milestone_id    BIGINT UNSIGNED NULL,
    tenant_id       INT UNSIGNED    NOT NULL,
    event           VARCHAR(64)     NOT NULL,
    actor_user_id   INT UNSIGNED    NULL,
    meta_json       JSON            NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_eng_audit_tenant     (tenant_id, created_at),
    KEY idx_eng_audit_engagement (engagement_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
