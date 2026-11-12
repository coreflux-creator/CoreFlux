-- Supplemental workbook evidence is separate from provider facts and financial postings.
CREATE TABLE IF NOT EXISTS staffing_workbook_sources (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    file_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    file_name VARCHAR(200) NOT NULL,
    file_bytes INT UNSIGNED NOT NULL,
    content_ct MEDIUMBLOB NOT NULL,
    uploaded_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_staffing_workbook_source (tenant_id,file_sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staffing_financial_evidence (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    connection_id INT UNSIGNED NOT NULL,
    association_id BIGINT UNSIGNED NOT NULL,
    previous_id BIGINT UNSIGNED NULL,
    source_id BIGINT UNSIGNED NULL,
    action VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    evidence_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_ct MEDIUMBLOB NOT NULL,
    reviewed_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_staffing_evidence_request (tenant_id,request_id),
    KEY ix_staffing_evidence_history (tenant_id,connection_id,association_id,id),
    CONSTRAINT fk_staffing_evidence_association FOREIGN KEY(association_id) REFERENCES purepay_financial_line_links(id),
    CONSTRAINT fk_staffing_evidence_source FOREIGN KEY(source_id) REFERENCES staffing_workbook_sources(id),
    CONSTRAINT fk_staffing_evidence_previous FOREIGN KEY(previous_id) REFERENCES staffing_financial_evidence(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
