-- Push notification primitive (Sprint 3 / CORE add-on)
-- A persistent outbox of pushes destined for tenant_mobile_devices.
-- Real APNs/FCM dispatch happens via a worker (cron / queue) reading
-- this table; until creds are configured the default driver is "log"
-- which writes the row + an error_log line and marks status='delivered'.
--
-- VERTICAL-AGNOSTIC. tenant + user scoped only.

CREATE TABLE IF NOT EXISTS tenant_push_outbox (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    device_id VARCHAR(128) NULL,
    title VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    data_json TEXT NULL,
    category VARCHAR(80) NULL,
    deep_link VARCHAR(500) NULL,
    driver ENUM('log','apns','fcm') NOT NULL DEFAULT 'log',
    status ENUM('queued','sending','delivered','failed','suppressed') NOT NULL DEFAULT 'queued',
    attempts INT NOT NULL DEFAULT 0,
    last_error VARCHAR(1000) NULL,
    delivered_at DATETIME NULL,
    failed_at DATETIME NULL,
    source_module VARCHAR(40) NOT NULL DEFAULT 'system',
    source_event VARCHAR(80) NULL,
    source_ref_type VARCHAR(60) NULL,
    source_ref_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tpo_tenant_status (tenant_id, status, created_at),
    INDEX idx_tpo_tenant_user (tenant_id, user_id, created_at),
    INDEX idx_tpo_source (source_module, source_event, source_ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
