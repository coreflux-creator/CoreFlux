-- AP — 012 — Vendor portal Phase 2: documents uploaded by vendors.
-- Idempotent. Vendors use the magic-link portal to upload W-9s, COIs,
-- banking forms, contracts. AP reviews and approves/rejects.

CREATE TABLE IF NOT EXISTS ap_vendor_portal_documents (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id         INT UNSIGNED  NOT NULL,
    vendor_id         INT UNSIGNED  NOT NULL,
    document_type     ENUM('w9','coi','banking_form','contract','other') NOT NULL,
    file_name         VARCHAR(255)  NOT NULL,
    storage_object_id BIGINT UNSIGNED NULL,
    status            ENUM('pending_review','approved','rejected') NOT NULL DEFAULT 'pending_review',
    notes             VARCHAR(500)  NULL,
    uploaded_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at       DATETIME      NULL,
    reviewed_by       INT UNSIGNED  NULL,
    INDEX idx_apvpd_vendor (tenant_id, vendor_id, status),
    INDEX idx_apvpd_status (tenant_id, status, uploaded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add contact_email to ap_vendors_index for direct vendor-portal invites
-- (independent of remit_to_email which is for payment notifications).
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME   = 'ap_vendors_index'
                      AND COLUMN_NAME  = 'contact_email');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE ap_vendors_index ADD COLUMN contact_email VARCHAR(255) NULL AFTER vendor_name',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
