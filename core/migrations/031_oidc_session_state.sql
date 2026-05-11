-- OIDC server-side session state — Slice 2 runtime support.
--
-- One row per in-flight OIDC authorization request. Tracks the (state,
-- nonce, PKCE code_verifier) tuple so the callback can prove the redirect
-- came from a request we actually originated.
--
-- Rows are short-lived (15-minute TTL) and deleted as soon as the callback
-- consumes them — so this table stays tiny in production. A `consumed_at`
-- column lets us soft-delete instead of hard-delete for audit purposes.
--
-- Idempotent.

CREATE TABLE IF NOT EXISTS oidc_session_state (
    id                BIGINT       NOT NULL AUTO_INCREMENT,
    tenant_id         BIGINT       NOT NULL,
    sso_slug          VARCHAR(64)  NOT NULL,
    state             CHAR(64)     NOT NULL  COMMENT 'CSRF protection — random 32 bytes hex.',
    nonce             CHAR(64)     NOT NULL  COMMENT 'Replay protection — random 32 bytes hex; must match id_token.nonce.',
    code_verifier     VARCHAR(128) NOT NULL  COMMENT 'PKCE — base64url(random_bytes(32)). 43-128 chars per RFC 7636.',
    return_path       VARCHAR(512) NULL      COMMENT 'Optional ?return= passthrough so user lands where they tried to go.',
    created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at        TIMESTAMP    NOT NULL,
    consumed_at       TIMESTAMP    NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_oss_state (state),
    KEY        idx_oss_expires (expires_at),
    KEY        idx_oss_tenant_slug (tenant_id, sso_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- JWKS cache (one row per issuer URL, refreshed hourly). The full key set
-- is stored as JSON; lookup is by `kid` at verification time.
CREATE TABLE IF NOT EXISTS oidc_jwks_cache (
    id          BIGINT       NOT NULL AUTO_INCREMENT,
    issuer_url  VARCHAR(255) NOT NULL,
    jwks_json   MEDIUMTEXT   NOT NULL,
    cached_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at  TIMESTAMP    NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_oj_issuer (issuer_url)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- OIDC discovery cache (one row per issuer URL, 24-hour TTL).
CREATE TABLE IF NOT EXISTS oidc_discovery_cache (
    id              BIGINT       NOT NULL AUTO_INCREMENT,
    issuer_url      VARCHAR(255) NOT NULL,
    discovery_json  MEDIUMTEXT   NOT NULL,
    cached_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      TIMESTAMP    NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_od_issuer (issuer_url)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
