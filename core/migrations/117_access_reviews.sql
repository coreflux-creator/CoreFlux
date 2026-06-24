-- 117_access_reviews.sql
--
-- Enterprise access review / certification controls.
--
-- Campaigns snapshot high-risk RBAC memberships, module grants, and People
-- Graph permission grants so tenant admins can certify, revoke, or exception
-- them with an auditable decision trail.

CREATE TABLE IF NOT EXISTS access_review_campaigns (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    campaign_key        VARCHAR(120) NOT NULL,
    name                VARCHAR(200) NOT NULL,
    description         TEXT NULL,
    status              ENUM('draft','open','completed','cancelled') NOT NULL DEFAULT 'draft',
    scope_json          LONGTEXT NULL,
    due_at              DATETIME NULL,
    created_by_user_id  BIGINT UNSIGNED NULL,
    opened_by_user_id   BIGINT UNSIGNED NULL,
    completed_by_user_id BIGINT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    opened_at           DATETIME NULL,
    completed_at        DATETIME NULL,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_access_review_campaign_key (tenant_id, campaign_key),
    KEY ix_access_review_campaign_status (tenant_id, status, due_at),
    KEY ix_access_review_campaign_created (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS access_review_items (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    campaign_id         BIGINT UNSIGNED NOT NULL,
    subject_user_id     BIGINT UNSIGNED NULL,
    subject_actor_type  VARCHAR(40) NULL,
    subject_actor_id    BIGINT UNSIGNED NULL,
    membership_id       BIGINT UNSIGNED NULL,
    permission_key      VARCHAR(180) NULL,
    module_key          VARCHAR(80) NULL,
    access_level        ENUM('none','read','write','admin') NULL,
    source              ENUM('rbac_role_permission','membership_module_access','people_graph_permission_grant','manual') NOT NULL,
    source_ref_type     VARCHAR(80) NOT NULL,
    source_ref_id       VARCHAR(160) NOT NULL,
    risk_level          ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    entitlement_snapshot_json LONGTEXT NULL,
    decision            ENUM('pending','certified','revoked','exception','needs_change') NOT NULL DEFAULT 'pending',
    decision_by_user_id BIGINT UNSIGNED NULL,
    decision_at         DATETIME NULL,
    decision_note       TEXT NULL,
    remediation_status  ENUM('not_required','pending','completed','failed') NOT NULL DEFAULT 'not_required',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_access_review_item_source (campaign_id, source, source_ref_type, source_ref_id),
    KEY ix_access_review_items_campaign (tenant_id, campaign_id, decision, risk_level),
    KEY ix_access_review_items_subject (tenant_id, subject_user_id),
    KEY ix_access_review_items_source (tenant_id, source, source_ref_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS access_review_audit (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    campaign_id         BIGINT UNSIGNED NULL,
    item_id             BIGINT UNSIGNED NULL,
    actor_user_id       BIGINT UNSIGNED NULL,
    event               VARCHAR(100) NOT NULL,
    payload_json        LONGTEXT NULL,
    occurred_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_access_review_audit_campaign (tenant_id, campaign_id, occurred_at),
    KEY ix_access_review_audit_item (tenant_id, item_id, occurred_at),
    KEY ix_access_review_audit_event (event, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
