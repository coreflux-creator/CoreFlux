-- Unified digest schedule registry.
--
-- One row per (tenant_id, digest_key). The cron drivers (Money Movement,
-- Dunning, eventually AP) check this table before sending.
--
-- digest_key values used by Sprint:
--   • money_movement   — Monday morning CFO digest
--   • dunning          — daily AR dunning send-time
--   • ap_weekly_queue  — AP weekly digest (fallback: ap_settings.weekly_queue_email_*)
--
-- Idempotent. The AP fallback means deleting a row simply reverts to the
-- existing ap_settings value — no breaking migration needed.

CREATE TABLE IF NOT EXISTS tenant_digest_schedules (
    tenant_id       BIGINT       NOT NULL,
    digest_key      VARCHAR(64)  NOT NULL,
    dow             TINYINT      NOT NULL DEFAULT 1
                            COMMENT '0 = disabled, 1..7 = Mon..Sun (ISO 8601). Daily digests ignore this and use hour only.',
    hour            TINYINT      NOT NULL DEFAULT 13
                            COMMENT '0..23 UTC. Cron must run at least hourly.',
    enabled         TINYINT(1)   NOT NULL DEFAULT 1,
    recipients_json JSON         NULL
                            COMMENT 'Optional override of the auto-resolved recipient list. NULL = use heuristic.',
    updated_by_user_id BIGINT NULL,
    updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, digest_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
