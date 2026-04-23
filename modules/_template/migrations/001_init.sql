-- Template Module — Initial Schema
-- Copy to: /modules/<module>/migrations/001_init.sql
-- Run manually on the target database (Cloudways) for now.
-- A migration runner will replace manual execution in a later phase.

-- Every module table should include:
--   tenant_id  INT NOT NULL          (FK -> tenants.id, enforced in code)
--   created_at TIMESTAMP default now
--   updated_at TIMESTAMP nullable
-- And index tenant_id first in compound indexes.

CREATE TABLE IF NOT EXISTS template_records (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id   INT UNSIGNED NOT NULL,
    name        VARCHAR(255) NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_template_records_tenant (tenant_id),
    INDEX idx_template_records_tenant_name (tenant_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
