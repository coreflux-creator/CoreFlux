-- Retain the exact rendered close packet for each recorded build.
-- Existing metadata-only packets remain historical records, not saved documents.
CREATE TABLE IF NOT EXISTS accounting_close_packet_snapshots (
    packet_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    period_id BIGINT UNSIGNED NOT NULL,
    html_snapshot LONGTEXT NOT NULL,
    content_sha256 CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_acc_packet_snapshot_scope (tenant_id, period_id, packet_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
