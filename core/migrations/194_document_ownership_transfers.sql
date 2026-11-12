-- An authorized merge changes a document's current owner, never its original file.
CREATE TABLE IF NOT EXISTS document_ownership_transfers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    operating_tenant_id BIGINT UNSIGNED NOT NULL,
    module VARCHAR(32) NOT NULL,
    document_id BIGINT UNSIGNED NOT NULL,
    storage_object_id BIGINT UNSIGNED NOT NULL,
    sequence_no INT UNSIGNED NOT NULL,
    from_record_id BIGINT UNSIGNED NOT NULL,
    to_record_id BIGINT UNSIGNED NOT NULL,
    object_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    previous_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    receipt_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    source VARCHAR(40) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_document_transfer_sequence (tenant_id,module,document_id,sequence_no),
    KEY idx_document_transfer_object (tenant_id,module,storage_object_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
