-- Sprint 7g+ — Treasury Scenario Share Links.
--
-- Tokenized read-only deep links to saved scenarios + compare pairs so a
-- CFO can email a board member or investor without granting tenant
-- access. Audit-logged (view_count, last_viewed_at, last_viewed_ip).
-- Token at-rest is SHA-256 hashed; only the URL the operator copies
-- contains the cleartext.
--
-- Idempotent. utf8mb4_unicode_ci. Cloudways MySQL 5.7+ compatible.

CREATE TABLE IF NOT EXISTS treasury_scenario_share_links (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    -- 'single' = link to one preset; 'compare' = link to a 2-preset A/B view.
    kind                ENUM('single','compare') NOT NULL,
    preset_a_id         BIGINT UNSIGNED NOT NULL,
    preset_b_id         BIGINT UNSIGNED NULL,
    -- SHA-256(token) — cleartext token never lives in the DB.
    token_hash          CHAR(64) NOT NULL,
    -- Operator-facing label so the share-link list is readable.
    label               VARCHAR(200) NULL,
    days_horizon        INT UNSIGNED NOT NULL DEFAULT 90,
    created_by_user_id  BIGINT UNSIGNED NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at          TIMESTAMP NOT NULL,
    revoked_at          TIMESTAMP NULL,
    view_count          INT UNSIGNED NOT NULL DEFAULT 0,
    last_viewed_at      TIMESTAMP NULL,
    last_viewed_ip      VARCHAR(64) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_token_hash (token_hash),
    INDEX idx_tssl_tenant (tenant_id, created_at),
    INDEX idx_tssl_active (tenant_id, revoked_at, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
