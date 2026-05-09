-- Sprint 7g Phase A.2 + A.3 + A.4 — digest customization.
--
-- Phase A.2: per-tenant included_agents JSON column. NULL → all agents
--            included (existing behaviour preserved). Non-null array of
--            agent_keys → digest only stitches those agents.
-- Phase A.3: subject_override + intro_override columns so each tenant can
--            brand the email with their own subject line and lead-in copy
--            instead of the generic platform default.
-- Phase A.4: weekly context-bucket snapshot table so the digest builder can
--            compute "last week's bucket → this week's bucket" diffs per
--            agent, surfacing what actually changed week over week instead
--            of restating the same narrative the operator already saw.
--
-- All ALTERs information_schema-guarded, idempotent, utf8mb4_unicode_ci,
-- Cloudways MySQL 5.7+8 compatible.

SELECT COUNT(*) INTO @col_exists
  FROM information_schema.columns
 WHERE table_schema = DATABASE()
   AND table_name   = 'ai_agent_digest_settings'
   AND column_name  = 'included_agents';
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ai_agent_digest_settings ADD COLUMN included_agents JSON NULL AFTER recipients',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists
  FROM information_schema.columns
 WHERE table_schema = DATABASE()
   AND table_name   = 'ai_agent_digest_settings'
   AND column_name  = 'subject_override';
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ai_agent_digest_settings ADD COLUMN subject_override VARCHAR(200) NULL AFTER included_agents',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists
  FROM information_schema.columns
 WHERE table_schema = DATABASE()
   AND table_name   = 'ai_agent_digest_settings'
   AND column_name  = 'intro_override';
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ai_agent_digest_settings ADD COLUMN intro_override VARCHAR(1000) NULL AFTER subject_override',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Phase A.4 — per-agent qualitative bucket snapshots, indexed for quick
-- "last week" lookup. JSON column stores the same context_fn output the
-- agent received that run, so future signals can be compared without
-- re-querying historical data.
CREATE TABLE IF NOT EXISTS ai_agent_context_snapshots (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id     BIGINT UNSIGNED NOT NULL,
    agent_key     VARCHAR(64) NOT NULL,
    snapshot_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    context_json  JSON NOT NULL,
    PRIMARY KEY (id),
    INDEX idx_tac_lookup (tenant_id, agent_key, snapshot_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
