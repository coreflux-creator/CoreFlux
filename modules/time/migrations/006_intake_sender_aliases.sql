-- Time — 006 — Sender aliases (caller-ID style learning).
--
-- Every time a user confirms a `from_address → person_id` mapping in the
-- intake/upload review flow, we remember it. Next time that address shows
-- up (even from a slightly different sender), we auto-resolve without
-- needing the email to match `users.email` exactly.
--
-- Idempotent. Last-write-wins on (tenant_id, from_address).

CREATE TABLE IF NOT EXISTS time_intake_sender_aliases (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id          BIGINT UNSIGNED NOT NULL,
    from_address       VARCHAR(255) NOT NULL,
    person_id          BIGINT UNSIGNED NOT NULL,
    confirmed_by_user_id BIGINT UNSIGNED NULL,
    use_count          INT UNSIGNED NOT NULL DEFAULT 1,
    last_used_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tisa_tenant_from (tenant_id, from_address),
    INDEX idx_tisa_tenant_person (tenant_id, person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Link uploaded docs to the intake row that created them, so the consume /
-- record-alias flow can recover the sender from_address from a doc id.
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME   = 'time_uploaded_documents'
                      AND COLUMN_NAME  = 'intake_event_id');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE time_uploaded_documents ADD COLUMN intake_event_id BIGINT UNSIGNED NULL AFTER storage_object_id, ADD INDEX idx_tud_intake (intake_event_id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
