-- Tenant SSO domain configuration — Slice 1 storage.
--
-- One row per tenant that has self-service SSO enabled. The actual OIDC
-- redirect/callback dance lives in Slice 2 — this migration just builds
-- the shape needed by the admin UI so a tenant can register their IdP
-- creds (Okta / Microsoft Entra) ahead of the rollout.
--
-- Idempotent: re-running this migration is safe.

CREATE TABLE IF NOT EXISTS tenant_sso_domains (
    id                       BIGINT       NOT NULL AUTO_INCREMENT,
    tenant_id                BIGINT       NOT NULL,
    provider_type            ENUM('okta','entra','generic_oidc') NOT NULL DEFAULT 'generic_oidc'
                                COMMENT 'IdP family for UI hints; OIDC discovery is the source of truth at runtime.',
    issuer_url               VARCHAR(255) NOT NULL
                                COMMENT 'OIDC issuer / discovery base, e.g. https://acme.okta.com or https://login.microsoftonline.com/{tid}/v2.0',
    client_id                VARCHAR(255) NOT NULL,
    client_secret_enc        VARBINARY(2048) NULL
                                COMMENT 'AES-256-GCM via core/encryption.php (encryptField/decryptField). NULL when secret unset.',
    client_secret_last4      CHAR(4)         NULL
                                COMMENT 'Display-only confirmation that a secret is stored; never expose the full secret.',
    allowed_email_domains    JSON            NULL
                                COMMENT 'Optional whitelist of email domains that may JIT-create a user, e.g. ["acme.com","acme.co.uk"].',
    is_enabled               TINYINT(1)   NOT NULL DEFAULT 0
                                COMMENT 'Master toggle. When 0 the /sso/{slug}/start endpoint short-circuits with 403.',
    sso_slug                 VARCHAR(64)  NOT NULL
                                COMMENT 'URL slug used in /sso/{slug}/start and /sso/{slug}/callback. Globally unique.',
    notes                    VARCHAR(500) NULL,
    updated_by_user_id       BIGINT       NULL,
    created_at               TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tsd_tenant     (tenant_id),
    UNIQUE KEY uq_tsd_slug       (sso_slug),
    KEY        idx_tsd_enabled   (is_enabled, tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
