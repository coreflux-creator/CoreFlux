-- CoreFlux AI Platform — Core Schema Additions
-- Run against the main CoreFlux MySQL database.
--
-- Adds:
--   1. Per-tenant AI toggles (master + full-content-logging)
--   2. Per-feature-class toggles per tenant
--   3. ai_interactions audit log (metadata always, content optional)
--   4. ai_suggestions table — AI draft → human review → commit workflow

-- 1. Tenant-level toggles ---------------------------------------------------
ALTER TABLE tenants
    ADD COLUMN IF NOT EXISTS ai_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS ai_full_content_logging TINYINT(1) NOT NULL DEFAULT 0;

-- 2. Per-feature-class toggles ---------------------------------------------
CREATE TABLE IF NOT EXISTS ai_tenant_features (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id      INT UNSIGNED NOT NULL,
    feature_class  VARCHAR(64)  NOT NULL,    -- summary | narrative | draft | classification | deep_reasoning
    enabled        TINYINT(1)   NOT NULL DEFAULT 1,
    updated_at     TIMESTAMP    NULL DEFAULT NULL,
    UNIQUE KEY uq_tenant_feature (tenant_id, feature_class),
    INDEX idx_aitf_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Audit log -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_interactions (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id      INT UNSIGNED NULL,
    user_id        INT UNSIGNED NULL,
    feature_class  VARCHAR(64)  NOT NULL,
    feature_key    VARCHAR(128) NOT NULL,    -- e.g. 'payroll.pay_period_summary'
    kind           VARCHAR(32)  NOT NULL,    -- narrative|summary|suggestion|classification|question
    status         ENUM('ok','error','disabled') NOT NULL,
    http_status    SMALLINT UNSIGNED NULL,
    model          VARCHAR(64)  NULL,
    latency_ms     INT UNSIGNED NULL,
    prompt_hash    CHAR(64)     NULL,        -- sha256 hex
    response_hash  CHAR(64)     NULL,        -- sha256 hex
    prompt         MEDIUMTEXT   NULL,        -- only populated when tenant opted in to full-content logging
    response       MEDIUMTEXT   NULL,        -- same
    error          TEXT         NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ai_tenant_time (tenant_id, created_at),
    INDEX idx_ai_feature     (feature_key, created_at),
    INDEX idx_ai_status      (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Suggestion workflow ---------------------------------------------------
-- Every AI output a module wants to treat as a "draft" goes here, and only
-- a human-approved row may be read by deterministic business logic.
CREATE TABLE IF NOT EXISTS ai_suggestions (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id        INT UNSIGNED NOT NULL,
    user_id          INT UNSIGNED NULL,               -- who will review (owner)
    interaction_id   BIGINT UNSIGNED NULL,            -- FK -> ai_interactions.id
    module           VARCHAR(64)  NOT NULL,           -- 'payroll', 'accounting', ...
    feature_key      VARCHAR(128) NOT NULL,
    subject_type     VARCHAR(64)  NULL,               -- 'paystub','journal_entry','employee' ...
    subject_id       BIGINT UNSIGNED NULL,
    draft_content    MEDIUMTEXT   NOT NULL,           -- AI-generated draft text
    final_content    MEDIUMTEXT   NULL,               -- human-edited text (source of truth after approval)
    status           ENUM('draft','approved','rejected','superseded') NOT NULL DEFAULT 'draft',
    reviewed_by      INT UNSIGNED NULL,
    reviewed_at      TIMESTAMP    NULL DEFAULT NULL,
    review_notes     TEXT         NULL,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP    NULL DEFAULT NULL,
    INDEX idx_ais_tenant_status (tenant_id, status, created_at),
    INDEX idx_ais_subject       (tenant_id, subject_type, subject_id),
    INDEX idx_ais_feature       (feature_key, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
