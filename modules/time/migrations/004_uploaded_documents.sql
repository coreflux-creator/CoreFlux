-- Time — 004 — Manual timesheet uploads (paper sign-in sheets, PDF templates,
-- phone photos). AI extracts hours per (date, project) and the user confirms
-- placement-mapping before entries land in time_entries.

CREATE TABLE IF NOT EXISTS time_uploaded_documents (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id         BIGINT UNSIGNED NOT NULL,
    uploaded_by_user_id BIGINT UNSIGNED NOT NULL,
    file_name         VARCHAR(255)  NOT NULL,
    storage_object_id BIGINT UNSIGNED NULL,
    storage_key       VARCHAR(500)  NULL,
    mime_type         VARCHAR(100)  NULL,
    week_ending_hint  DATE NULL,
    extraction_status ENUM('pending','extracted','failed','consumed') NOT NULL DEFAULT 'pending',
    ai_extracted_json TEXT          NULL,
    ai_confidence     DECIMAL(4,3)  NULL,
    ai_model          VARCHAR(100)  NULL,
    ai_error          VARCHAR(500)  NULL,
    consumed_at       DATETIME      NULL,
    consumed_entry_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tud_tenant_user (tenant_id, uploaded_by_user_id, created_at),
    INDEX idx_tud_status (tenant_id, extraction_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
