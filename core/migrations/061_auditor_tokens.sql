-- Migration 061 — External Auditor tokens (2026-02).
--
-- Issues tokenized, read-only URLs that let an external auditor view
-- finance/reports surfaces on a specific tenant for a bounded window
-- without provisioning a real user account. Tokens are revocable, scoped
-- per-tenant, and stored hashed (sha256) so a DB read doesn't expose
-- live tokens.

CREATE TABLE IF NOT EXISTS auditor_tokens (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    label           VARCHAR(120)  NOT NULL,
    email           VARCHAR(255)  NULL,
    token_hash      CHAR(64)      NOT NULL UNIQUE,          -- sha256 hex
    scope_modules   JSON          NULL,                     -- ["reports","accounting"] – null = default read-set
    expires_at      DATETIME      NOT NULL,
    last_used_at    DATETIME      NULL,
    revoked_at      DATETIME      NULL,
    created_by_user INT UNSIGNED  NOT NULL,
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_auditor_tokens_tenant   (tenant_id),
    INDEX idx_auditor_tokens_expires  (expires_at),
    INDEX idx_auditor_tokens_revoked  (revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Audit trail of every auditor session (token redeemed, page viewed).
CREATE TABLE IF NOT EXISTS auditor_access_log (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token_id        INT UNSIGNED  NOT NULL,
    tenant_id       INT UNSIGNED  NOT NULL,
    action          VARCHAR(40)   NOT NULL,                  -- 'redeem', 'view', 'expired', 'revoked_hit'
    path            VARCHAR(255)  NULL,
    ip              VARCHAR(64)   NULL,
    user_agent      VARCHAR(255)  NULL,
    occurred_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_auditor_log_token   (token_id),
    INDEX idx_auditor_log_tenant  (tenant_id),
    INDEX idx_auditor_log_time    (occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
