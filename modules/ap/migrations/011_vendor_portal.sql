-- AP — 011 — Vendor self-service portal: magic-link tokens
-- Idempotent. Vendor portal access is via per-vendor magic-link tokens
-- (no password). Each token has a TTL (default 7 days), is single-use for
-- LOGIN (consumed on first hit, sets a session cookie), and has a list of
-- allowed actions (read-only by default; can be extended to {upload_w9,
-- update_banking}).

CREATE TABLE IF NOT EXISTS ap_vendor_portal_tokens (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED  NOT NULL,
    vendor_id       INT UNSIGNED  NOT NULL,
    token_hash      VARCHAR(64)   NOT NULL,        -- SHA-256(token), so the bare token never lives in the DB
    issued_to_email VARCHAR(255)  NOT NULL,
    issued_by_user  INT UNSIGNED  NULL,
    expires_at      TIMESTAMP     NOT NULL,
    consumed_at     TIMESTAMP     NULL,            -- when first redeemed (login established)
    revoked_at      TIMESTAMP     NULL,
    last_used_at    TIMESTAMP     NULL,
    created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_vptok (token_hash),
    INDEX idx_vptok_vendor (tenant_id, vendor_id, revoked_at, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Each consumed token spawns a session row that keeps the vendor logged in
-- after the magic link's single-use redemption.
CREATE TABLE IF NOT EXISTS ap_vendor_portal_sessions (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    vendor_id       INT UNSIGNED NOT NULL,
    session_id      VARCHAR(64)  NOT NULL,         -- random; stored in HttpOnly cookie cf_vp_sid
    expires_at      TIMESTAMP    NOT NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_vps_session (session_id),
    INDEX idx_vps_vendor (tenant_id, vendor_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
