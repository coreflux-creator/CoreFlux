-- Magic-link (passwordless) login support.
--
-- Token model:
--   • Server generates random_bytes(32), base64url-encodes → raw token (never stored)
--   • SHA-256 of the raw token is what lives in `auth_magic_links.token_hash`
--   • Single-use: `consumed_at` is set on first verify, second verify fails
--   • TTL: 15 minutes default (configurable via expires_at)
--   • Optional `tenant_id` so a link can deep-link the user into a specific
--     workspace they're invited to
--   • Optional `redirect_path` so workflow emails (timesheet reminder, AP
--     bill approval) can drop a one-tap link that both authenticates AND
--     deep-links to the right page
--
-- Rate limit:
--   • `auth_magic_link_attempts` keyed by sha256(ip + lower(email)) so a
--     hostile actor can't enumerate accounts by spamming this endpoint.
--   • 5 issues per hour per (ip,email) pair, then 1-hour cool-down.

CREATE TABLE IF NOT EXISTS auth_magic_links (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    token_hash      CHAR(64)        NOT NULL,
    email           VARCHAR(190)    NOT NULL,
    tenant_id       INT UNSIGNED    NULL,
    user_id         INT UNSIGNED    NULL,
    redirect_path   VARCHAR(500)    NOT NULL DEFAULT '/',
    ip_issued       VARBINARY(16)   NULL,
    ua_hash         CHAR(64)        NULL,
    expires_at      TIMESTAMP       NOT NULL,
    consumed_at     TIMESTAMP       NULL DEFAULT NULL,
    consumed_ip     VARBINARY(16)   NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_token_hash (token_hash),
    KEY idx_email_created (email, created_at),
    KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_magic_link_attempts (
    ip_email_hash   CHAR(64)        NOT NULL,
    email           VARCHAR(190)    NOT NULL,
    attempts        INT UNSIGNED    NOT NULL DEFAULT 1,
    first_attempt   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_attempt    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_until    TIMESTAMP       NULL DEFAULT NULL,
    PRIMARY KEY (ip_email_hash),
    KEY idx_locked_until (locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
