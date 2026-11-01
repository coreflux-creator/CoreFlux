-- Immutable encrypted read-only AP/AR captures; no payment or posting authority.
CREATE TABLE IF NOT EXISTS purepay_financial_observations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    connection_id INT UNSIGNED NOT NULL,
    organization_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    document_kind ENUM('bill','invoice') NOT NULL,
    document_id VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,
    capture_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_ct MEDIUMBLOB NOT NULL,
    observed_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pp_financial_capture(tenant_id,connection_id,capture_hash),
    KEY ix_pp_financial_history(tenant_id,connection_id,document_kind,document_id,id),
    CONSTRAINT fk_pp_financial_observation_connection FOREIGN KEY(connection_id) REFERENCES purepay_connections(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
