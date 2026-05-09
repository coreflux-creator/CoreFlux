-- Sprint 7g — AI agent settings (Slices 2 + 3 follow-ons).
--
-- Per-agent execution mode (advisory vs auto-log) and tenant-level
-- weekly digest opt-in for the AI agent suite.
--
-- Modes:
--   advisory  → result requires human review via <AISuggestion /> (default,
--               matches platform AI rules — operator must accept/edit/reject).
--   auto_log  → result is auto-saved as accepted in `ai_suggestions` so it
--               flows into a passive "AI insights feed" without blocking.
--               STILL strictly advisory in nature — the narrative is filed,
--               not "applied" anywhere. Provided for tenants who want a
--               chronicle without per-result review friction.
--
-- Note: a future `auto_apply` mode (one that mutates books) is explicitly
-- NOT introduced here — none of the current 5 agents emit values, formulas,
-- or actions the app could apply, and adding such an enum value now would
-- prematurely sanction it. Keeping the enum tight protects platform AI
-- rules at the schema layer.

CREATE TABLE IF NOT EXISTS ai_agent_settings (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id     BIGINT UNSIGNED NOT NULL,
    agent_key     VARCHAR(64)  NOT NULL,
    mode          ENUM('advisory','auto_log') NOT NULL DEFAULT 'advisory',
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_tenant_agent (tenant_id, agent_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tenant-level weekly digest toggle + recipient + last-sent timestamp
-- (idempotency for the cron — never send twice in one rolling week).
CREATE TABLE IF NOT EXISTS ai_agent_digest_settings (
    tenant_id          BIGINT UNSIGNED NOT NULL,
    enabled            TINYINT(1) NOT NULL DEFAULT 0,
    -- Comma-separated list of email addresses; null → defaults to tenant
    -- master_admin's email at send time.
    recipients         VARCHAR(500) DEFAULT NULL,
    -- Day of week (1=Mon..7=Sun) the cron should send. Cron runs daily.
    send_dow           TINYINT NOT NULL DEFAULT 1,
    last_sent_at       TIMESTAMP NULL DEFAULT NULL,
    last_send_error    VARCHAR(500) DEFAULT NULL,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
