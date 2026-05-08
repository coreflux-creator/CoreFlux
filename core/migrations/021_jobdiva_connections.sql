-- Sprint 8a — JobDiva integration (Slice A1: connection + webhook foundation).
--
-- Per-tenant connection vault: stores the credentials needed to mint a
-- session token against api.jobdiva.com. Tenant logs in once via the
-- connect form; the server-side client class auto-refreshes the cached
-- session token when it expires, so the operator never has to log in
-- again unless they rotate their JobDiva password.
--
-- Encryption: all *_enc columns are AES-256-GCM ciphertext written via
-- core/encryption.php (COREFLUX_DATA_KEY env var). Webhook secret is
-- HMAC-SHA256.
--
-- Idempotent.

CREATE TABLE IF NOT EXISTS jobdiva_connections (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id         BIGINT UNSIGNED NOT NULL,
    -- Credentials (one mode for now: clientid + username + password,
    -- per discussion. Stored encrypted; the cached session token
    -- is auto-refreshed on demand server-side).
    client_id         VARCHAR(64)     NOT NULL,
    username          VARCHAR(255)    NOT NULL,
    password_enc      VARBINARY(1024) NOT NULL,
    -- Cached session token, refreshed on demand. NULL → next call mints.
    session_token_enc VARBINARY(1024) DEFAULT NULL,
    session_token_exp TIMESTAMP NULL DEFAULT NULL,
    -- Webhook HMAC secret (raw shared secret, encrypted).
    webhook_secret_enc VARBINARY(1024) DEFAULT NULL,
    -- Field-ownership defaults (JSON). A1 leaves empty; A2+ populates.
    field_ownership   JSON DEFAULT NULL,
    -- Connection state machine.
    status            ENUM('connected','degraded','disconnected','error') NOT NULL DEFAULT 'connected',
    last_sync_at      TIMESTAMP NULL DEFAULT NULL,
    last_sync_error   VARCHAR(500) DEFAULT NULL,
    last_ping_at      TIMESTAMP NULL DEFAULT NULL,
    -- Per-entity sync source-of-truth + direction (will be populated in A4
    -- for time, A2 for companies/contacts, A3 for placements). Placeholder
    -- so we don't need a follow-up migration.
    --   { "companies": {"source":"jobdiva","direction":"pull"},
    --     "contacts":  {"source":"jobdiva","direction":"pull"},
    --     "placements":{"source":"jobdiva","direction":"pull"},
    --     "time":      {"source":"coreflux","direction":"off"} }
    sync_config       JSON DEFAULT NULL,
    connected_by_user_id  BIGINT UNSIGNED DEFAULT NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_tenant (tenant_id),
    KEY ix_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Webhook event queue. Signature-verified at ingress, parked here, drained
-- by the sync worker (A2+). Idempotent on (tenant_id, jd_event_id).
CREATE TABLE IF NOT EXISTS jobdiva_webhook_events (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id       BIGINT UNSIGNED NOT NULL,
    jd_event_id     VARCHAR(128) DEFAULT NULL,    -- JobDiva's id from the payload
    event_type      VARCHAR(128) NOT NULL,        -- e.g. 'company.updated'
    payload         JSON NOT NULL,
    signature_ok    TINYINT(1) NOT NULL DEFAULT 0,
    status          ENUM('queued','processing','processed','skipped','failed') NOT NULL DEFAULT 'queued',
    process_error   VARCHAR(500) DEFAULT NULL,
    received_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at    TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_tenant_event (tenant_id, jd_event_id),
    KEY ix_tenant_status (tenant_id, status, received_at),
    KEY ix_event_type (event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sync audit log. Every connect / disconnect / sync / webhook write here.
-- Tenant-scoped, append-only, drives the recent-activity panel on the UI.
CREATE TABLE IF NOT EXISTS jobdiva_sync_audit (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id       BIGINT UNSIGNED NOT NULL,
    action          VARCHAR(64) NOT NULL,        -- connect | disconnect | ping | sync | webhook | refresh_token | error
    entity_type     VARCHAR(64) DEFAULT NULL,    -- company | contact | placement | time | (null)
    direction       ENUM('pull','push','none') NOT NULL DEFAULT 'none',
    ok              TINYINT(1) NOT NULL DEFAULT 1,
    items_processed INT NOT NULL DEFAULT 0,
    items_skipped   INT NOT NULL DEFAULT 0,
    items_failed    INT NOT NULL DEFAULT 0,
    detail          JSON DEFAULT NULL,
    actor_user_id   BIGINT UNSIGNED DEFAULT NULL,
    occurred_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_tenant_time (tenant_id, occurred_at),
    KEY ix_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
