-- 111_agent_registry_and_handoffs.sql
--
-- Slice 7C — Agent Registry + Handoffs.
--
-- Spec §7 ("Agent Coordination"): named agents that bundle a set of
-- tools / permissions / system prompts. Handoffs let one agent
-- delegate to another (e.g. Close Agent finishes a packet → hands off
-- to Cash Agent to produce the forecast).
--
-- Each agent registers a default set of tools it knows how to call,
-- and the tool gateway can scope a given invocation to a specific
-- agent via callerCtx.agent_key (read by aiToolInvoke for future
-- per-agent permission scoping in Phase 8).
--
-- Idempotent.

CREATE TABLE IF NOT EXISTS agent_registry (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id            BIGINT UNSIGNED NULL,              -- NULL = platform-shared
    agent_key            VARCHAR(120) NOT NULL,             -- "close_agent", "cash_agent", …
    label                VARCHAR(200) NOT NULL,
    description          VARCHAR(2000) NULL,
    owner_module         VARCHAR(80) NULL,                  -- accounting, ap, staffing
    -- Tools the agent is permitted to invoke; JSON array of registry keys.
    default_tools_json   LONGTEXT NULL,
    -- The system prompt / persona description.  May be Markdown.
    system_prompt        MEDIUMTEXT NULL,
    -- Status.
    status               ENUM('draft','active','retired') NOT NULL DEFAULT 'active',
    created_by_user_id   BIGINT UNSIGNED NULL,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_ar_tenant_key (tenant_id, agent_key),
    KEY ix_ar_owner (owner_module, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_handoffs (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id               BIGINT UNSIGNED NOT NULL,
    from_agent_id           BIGINT UNSIGNED NOT NULL,           -- agent_registry.id (FK by convention)
    to_agent_id             BIGINT UNSIGNED NOT NULL,
    reason                  VARCHAR(500) NULL,
    payload_json            LONGTEXT NULL,                      -- arbitrary context for the receiver
    status                  ENUM('pending','accepted','refused','completed','cancelled') NOT NULL DEFAULT 'pending',
    -- Linkage so the timeline UI can chain handoffs into a single
    -- workflow story.
    parent_workflow_run_id  CHAR(36) NULL,
    parent_handoff_id       BIGINT UNSIGNED NULL,
    -- Audit.
    initiated_by_user_id    BIGINT UNSIGNED NULL,
    resolved_by_user_id     BIGINT UNSIGNED NULL,
    resolution_note         VARCHAR(1000) NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at             DATETIME NULL,

    KEY ix_ah_tenant_status (tenant_id, status, id),
    KEY ix_ah_from          (tenant_id, from_agent_id, id),
    KEY ix_ah_to            (tenant_id, to_agent_id,   id),
    KEY ix_ah_workflow      (parent_workflow_run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
