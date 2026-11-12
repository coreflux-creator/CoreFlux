-- Canonical shared definitions use immutable field_name, never the display label.
-- Existing installations with a different legacy schema require explicit review; do not reinterpret their data.
CREATE TABLE IF NOT EXISTS custom_fields (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    module VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    field_name VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    field_label VARCHAR(200) NOT NULL,
    field_type ENUM('text','number','date','boolean','select','multiselect') NOT NULL,
    options TEXT NULL,
    is_required BOOLEAN NOT NULL DEFAULT 0,
    pii BOOLEAN NOT NULL DEFAULT 0,
    visible_to_roles_json TEXT NULL,
    editable_by_roles_json TEXT NULL,
    order_index INT NOT NULL DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    UNIQUE KEY uq_custom_field_key (tenant_id,module,field_name),
    UNIQUE KEY uq_custom_field_owner (tenant_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS custom_values (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    field_id BIGINT UNSIGNED NOT NULL,
    record_id BIGINT UNSIGNED NOT NULL,
    value TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_custom_value (tenant_id,field_id,record_id),
    CONSTRAINT fk_custom_value_owner FOREIGN KEY(tenant_id,field_id) REFERENCES custom_fields(tenant_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
