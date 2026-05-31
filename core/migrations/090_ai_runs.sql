-- 090_ai_runs.sql
--
-- AI Tool Gateway — Slice 1 / spec §2A: ai_runs is the higher-level
-- envelope above the existing ai_tool_invocations ledger (089). One
-- "run" represents a user (or worker) intent that the Gateway picks
-- up; that run can call zero or more tools, each landing as a row in
-- ai_tool_invocations. We persist the run separately so:
--
--   • The admin trace UI can show "user said X → run #42 → these 5
--     tool calls", not just an orphaned list of tool calls.
--   • A future LangGraph workflow_run_id can be cross-linked to its
--     originating AI run.
--   • Status/agent/prompt-version/model live at the run level so
--     individual tool calls don't repeat them.
--
-- Spec §2A:ai_runs columns map directly:
--   agent_name, workflow_run_id, model_name, prompt_version, status,
--   input_summary, output_summary, worker_id, artifact_id.
--
-- Idempotent. MySQL 5.7+ (Cloudways), utf8mb4_unicode_ci.

CREATE TABLE IF NOT EXISTS ai_runs (
    id                  CHAR(36) NOT NULL PRIMARY KEY,        -- UUIDv4 from PHP
    tenant_id           INT UNSIGNED NOT NULL,
    sub_tenant_id       INT UNSIGNED NULL,
    user_id             INT UNSIGNED NULL,                    -- requesting user; NULL for worker-originated
    agent_name          VARCHAR(80) NOT NULL,                 -- e.g. 'accounting', 'treasury', 'orchestrator'
    workflow_run_id     CHAR(36) NULL,                        -- forward link to LangGraph (Phase 2)
    model_name          VARCHAR(80) NULL,                     -- e.g. 'gpt-5.1', null in Slice 1
    prompt_version      VARCHAR(40) NULL,                     -- e.g. 'accounting/2026-02-01'
    status              ENUM('queued','running','completed','failed','cancelled','awaiting_approval')
                            NOT NULL DEFAULT 'queued',
    input_summary       TEXT NULL,                            -- non-sensitive summary of user input
    output_summary      TEXT NULL,                            -- non-sensitive summary of output
    worker_id           CHAR(36) NULL,                        -- AI Worker Runtime id (Phase 7)
    artifact_id         CHAR(36) NULL,                        -- primary artifact context (Phase 3+)
    error_code          VARCHAR(60) NULL,
    error_message       VARCHAR(255) NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at        TIMESTAMP NULL DEFAULT NULL,
    KEY ix_air_tenant_recent (tenant_id, created_at),
    KEY ix_air_user          (tenant_id, user_id, created_at),
    KEY ix_air_agent         (tenant_id, agent_name, status),
    KEY ix_air_workflow      (workflow_run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ai_tool_invocations was created in 089. Slice 1 needs a forward
-- pointer from each tool call back to its parent run so the admin
-- trace UI can render a single run with all its tool calls. Add as
-- a separate column so 089 doesn't have to be re-run on existing
-- deployments where the column is already absent.
ALTER TABLE ai_tool_invocations
    ADD COLUMN IF NOT EXISTS ai_run_id CHAR(36) NULL AFTER tenant_id,
    ADD KEY IF NOT EXISTS ix_aiti_run (ai_run_id);
