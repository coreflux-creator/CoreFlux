-- 089_ai_tool_invocations.sql
--
-- AI Tool Gateway (spec §18) audit ledger. Every call an AI agent
-- makes to a curated CoreFlux tool gets a row here so operators can
-- replay, audit, and rate-limit agent behaviour.
--
-- Tools are looked up by `tool_name` in core/ai/tool_gateway.php; the
-- table just records the invocation, the actor (agent + on-behalf-of
-- user), latency, status, and a truncated args+result so PII budgets
-- stay manageable.

CREATE TABLE IF NOT EXISTS ai_tool_invocations (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           INT UNSIGNED NOT NULL,
    sub_tenant_id       INT UNSIGNED NULL,
    actor_user_id       INT UNSIGNED NULL,
    agent_session_id    VARCHAR(80) NULL,            -- conversation/session id from caller
    tool_name           VARCHAR(120) NOT NULL,       -- e.g. 'coreflux.get_trial_balance'
    args_json           JSON NULL,
    status              ENUM('ok','denied','validation_failed','provider_error','internal_error') NOT NULL,
    http_status         INT UNSIGNED NULL,
    latency_ms          INT UNSIGNED NULL,
    error_code          VARCHAR(60) NULL,
    error_message       VARCHAR(255) NULL,
    result_summary      JSON NULL,                   -- compact projection; full body NOT stored
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_aiti_tenant_recent (tenant_id, created_at),
    KEY ix_aiti_tool          (tenant_id, tool_name, created_at),
    KEY ix_aiti_session       (agent_session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
