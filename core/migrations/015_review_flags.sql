-- 015_review_flags.sql
--
-- Generic "flag for review" trail. Sprint 8 (2026-02): the Placement
-- Margin table needs actionable rows — flag a placement that looks
-- under-margin or stale, attach a reason + optional AI insight, route
-- it through the same accept/reject moat the AI categorization uses.
--
-- Polymorphic by `entity_type` so future report rows (Invoices, Bills,
-- People) can flag through the same table.

CREATE TABLE IF NOT EXISTS review_flags (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    entity_type     ENUM('placement','invoice','bill','person','recruiter') NOT NULL,
    entity_id       BIGINT UNSIGNED NOT NULL,
    reason_code     VARCHAR(64) NOT NULL,
    notes           TEXT NULL,
    severity        ENUM('info','warn','critical') NOT NULL DEFAULT 'warn',
    status          ENUM('open','resolved','dismissed') NOT NULL DEFAULT 'open',
    flagged_by      INT UNSIGNED NULL,
    resolved_by     INT UNSIGNED NULL,
    resolved_at     DATETIME NULL DEFAULT NULL,
    ai_summary      TEXT NULL,
    ai_confidence   DECIMAL(4,3) NULL,
    ai_source       VARCHAR(40) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_rf_tenant_entity (tenant_id, entity_type, entity_id),
    INDEX idx_rf_tenant_status (tenant_id, status),
    INDEX idx_rf_tenant_reason (tenant_id, reason_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
