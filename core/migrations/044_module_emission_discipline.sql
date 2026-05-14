-- Module Emission Discipline Log (2026-02-XX) — Phase 2a
--
-- Audit trail of every direct-GL fallback fire. Built by the SPA / API
-- wrappers around the legacy accountingPostJe() callers in AP / Billing /
-- Treasury. The goal is to prove ZERO fallback fires for N consecutive
-- days before Phase 2a step 5 flips the fallback path to a hard error.
--
-- Querying the trail:
--
--   SELECT source_module, event_type, COUNT(*), MAX(created_at)
--     FROM module_emission_discipline_log
--    WHERE tenant_id = ? AND created_at > NOW() - INTERVAL 7 DAY
--    GROUP BY source_module, event_type;
--
-- Idempotent.

CREATE TABLE IF NOT EXISTS module_emission_discipline_log (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    source_module       VARCHAR(60)     NOT NULL,
    event_type          VARCHAR(120)    NOT NULL,
    context             JSON            NULL,
    created_by_user_id  BIGINT UNSIGNED NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    KEY ix_tenant_module_created (tenant_id, source_module, created_at),
    KEY ix_tenant_event_created  (tenant_id, event_type,    created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
