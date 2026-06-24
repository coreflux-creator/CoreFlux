-- Report Builder saved definitions.
--
-- Stores governed report definitions only. Definitions reference registered
-- report builder datasets and field keys; execution remains code-side and
-- permission-gated by the source dataset contract.

CREATE TABLE IF NOT EXISTS report_builder_reports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    owner_user_id INT UNSIGNED NOT NULL,
    dataset VARCHAR(64) NOT NULL,
    name VARCHAR(160) NOT NULL,
    description VARCHAR(500) NULL,
    visibility ENUM('private','shared') NOT NULL DEFAULT 'private',
    definition_json MEDIUMTEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by_user_id INT UNSIGNED NULL,
    updated_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_rbr_tenant_visibility (tenant_id, visibility, is_active),
    INDEX idx_rbr_tenant_owner (tenant_id, owner_user_id, is_active),
    INDEX idx_rbr_tenant_dataset (tenant_id, dataset, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
