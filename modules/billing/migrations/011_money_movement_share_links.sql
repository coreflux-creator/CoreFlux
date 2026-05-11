-- Public Money Movement share links.
--
-- Same pattern as scenario_share_links: sha256(token) at rest so a DB
-- breach doesn't yield usable links; auto-expire after 30 days; revoke
-- by setting revoked_at; every view audited. Token holders see read-only
-- HTML — never the React SPA, never any private endpoint.

CREATE TABLE IF NOT EXISTS billing_money_movement_share_links (
    id              BIGINT       NOT NULL AUTO_INCREMENT,
    tenant_id       BIGINT       NOT NULL,
    as_of           DATE         NOT NULL  COMMENT 'Which snapshot week this link unlocks. References tenant_money_movement_snapshots.',
    token_sha256    CHAR(64)     NOT NULL  COMMENT 'sha256(raw_token). Raw token is shown to creator once and never again.',
    created_by_user_id BIGINT    NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      TIMESTAMP    NOT NULL,
    revoked_at      TIMESTAMP    NULL,
    view_count      INT          NOT NULL DEFAULT 0,
    last_viewed_at  TIMESTAMP    NULL,
    label           VARCHAR(120) NULL  COMMENT 'Optional human label, e.g. "Q1 board prep".',
    PRIMARY KEY (id),
    UNIQUE KEY uq_bmmsl_token (token_sha256),
    KEY        idx_bmmsl_tenant (tenant_id, as_of)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
