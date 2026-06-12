-- Tenant-scoped custom-field layout overrides.
--
-- Module manifests declare default form/list/export/report layouts. Tenant
-- admins can override a surface through the platform custom-field layout API
-- without moving layout ownership into People, Staffing, Placements, or Reports.

CREATE TABLE IF NOT EXISTS custom_field_layout_overrides (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    entity_type VARCHAR(80) NOT NULL,
    surface VARCHAR(40) NOT NULL,
    layout_json TEXT NOT NULL,
    created_by_user_id INT NULL,
    updated_by_user_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_cflo_tenant_entity_surface (tenant_id, entity_type, surface),
    INDEX idx_cflo_tenant_entity (tenant_id, entity_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
