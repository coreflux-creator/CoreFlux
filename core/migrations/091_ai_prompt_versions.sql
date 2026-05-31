-- 091_ai_prompt_versions.sql
--
-- AI Tool Gateway — Slice 2: versioned prompt store.
--
-- Spec §15: prompts are versioned platform assets. Production
-- activation requires AI-admin review. One row per (agent_name,
-- version); `is_active = 1` on at most one row per agent.
--
-- Slice 2 ships built-in defaults (orchestrator/2026-02-default,
-- accounting/2026-02-default) seeded into this table. Tenant overrides
-- are a future concern; for now agent prompts are platform-wide.
--
-- Idempotent. MySQL 5.7+ (Cloudways), utf8mb4_unicode_ci.

CREATE TABLE IF NOT EXISTS ai_prompt_versions (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    agent_name          VARCHAR(80) NOT NULL,
    version             VARCHAR(40) NOT NULL,                   -- e.g. '2026-02-default'
    system_prompt       MEDIUMTEXT NOT NULL,
    developer_prompt    MEDIUMTEXT NULL,
    params_json         JSON NULL,                              -- temperature, top_p, max_tokens
    is_active           TINYINT(1) NOT NULL DEFAULT 0,
    created_by_user_id  INT UNSIGNED NULL,
    notes               VARCHAR(255) NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_apv_agent_version (agent_name, version),
    KEY ix_apv_active (agent_name, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
