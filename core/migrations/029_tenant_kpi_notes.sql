-- Tenant-scoped per-KPI annotation notes.
-- Used by the home-page Cash Cycle Health tile (and reusable for any
-- future "operator can leave context on a number" feature).
-- Idempotent. utf8mb4_unicode_ci. MySQL 5.7+.

CREATE TABLE IF NOT EXISTS tenant_kpi_notes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    note_key  VARCHAR(64)  NOT NULL,
    note_text VARCHAR(280) NOT NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tkn_tenant_key (tenant_id, note_key),
    INDEX idx_tkn_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
