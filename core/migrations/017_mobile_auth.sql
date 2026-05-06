-- Mobile foundation (Sprint 2 / Mobile track)
-- M1: JWT auth alongside existing session cookie. Stores refresh tokens server-side
--     so we can revoke per-device. Access tokens are stateless (signed JWTs).
-- M2: device registry (apns + fcm) for push notifications.
-- VERTICAL-AGNOSTIC.

CREATE TABLE IF NOT EXISTS tenant_mobile_devices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    device_id VARCHAR(128) NOT NULL,
    platform ENUM('ios','android','web') NOT NULL,
    apns_token VARCHAR(255) NULL,
    fcm_token VARCHAR(255) NULL,
    app_version VARCHAR(40) NULL,
    os_version VARCHAR(40) NULL,
    locale VARCHAR(20) NULL,
    last_seen_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mob_dev_tenant_device (tenant_id, device_id),
    INDEX idx_mob_dev_tenant_user (tenant_id, user_id, revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_refresh_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    device_id VARCHAR(128) NULL,
    token_hash CHAR(64) NOT NULL,
    issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    last_used_at DATETIME NULL,
    user_agent VARCHAR(255) NULL,
    ip VARCHAR(64) NULL,
    UNIQUE KEY uq_arf_token (token_hash),
    INDEX idx_arf_tenant_user (tenant_id, user_id, revoked_at),
    INDEX idx_arf_tenant_device (tenant_id, device_id, revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
