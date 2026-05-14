-- Simulation Harness (2026-02-XX) — Phase H1
--
-- Deterministic financial wind tunnel: a sim-flagged tenant runs the
-- EXACT production posting engine against scenario-defined synthetic
-- activity, with every event hash + JE hash captured for replay
-- reproducibility.
--
-- Per spec (CoreFlux Unified Simulation Harness Design §6, §8, §18):
--   • Determinism is paramount — every run is seeded + replayable.
--   • Sim tenant uses the same accounting_events + posting_rules layer
--     as production. Only the tenant_id differs.
--   • Outputs are persisted for forensic debugging and CI regression.
--
-- Idempotent.

-- 1. Sim tenant flag — tells SPA + middlewares that this tenant is
--    a synthetic environment (suppresses external integrations,
--    disables real money movement, allows resets).
ALTER TABLE tenants
    ADD COLUMN IF NOT EXISTS is_simulation TINYINT(1) NOT NULL DEFAULT 0 AFTER name;

-- 2. simulation_runs — one row per scenario execution.
CREATE TABLE IF NOT EXISTS simulation_runs (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       BIGINT UNSIGNED NOT NULL,
    scenario_name   VARCHAR(120)    NOT NULL,
    seed            BIGINT          NOT NULL,
    status          ENUM('running','passed','failed','aborted') NOT NULL DEFAULT 'running',

    events_emitted  INT UNSIGNED NOT NULL DEFAULT 0,
    je_posted       INT UNSIGNED NOT NULL DEFAULT 0,
    assertions_run  INT UNSIGNED NOT NULL DEFAULT 0,
    assertions_failed INT UNSIGNED NOT NULL DEFAULT 0,

    summary         JSON NULL,                -- runner-supplied totals / metrics
    started_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    finished_at     TIMESTAMP NULL,
    duration_ms     INT UNSIGNED NULL,

    KEY ix_tenant_scenario_started (tenant_id, scenario_name, started_at),
    KEY ix_tenant_status_started   (tenant_id, status, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. simulation_assertions — one row per invariant check (debits=credits,
--    replay reproducibility, balance parity, no orphan events, etc.).
CREATE TABLE IF NOT EXISTS simulation_assertions (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_id      BIGINT UNSIGNED NOT NULL,
    name        VARCHAR(160)    NOT NULL,
    ok          TINYINT(1)      NOT NULL,
    severity    ENUM('info','warning','error') NOT NULL DEFAULT 'error',
    details     JSON NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    KEY ix_run_ok (run_id, ok),
    KEY ix_run_name (run_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. simulation_failures — denormalized view of every assertion that
--    came back ok=0. Cheaper queries from the harness dashboard.
CREATE TABLE IF NOT EXISTS simulation_failures (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_id      BIGINT UNSIGNED NOT NULL,
    invariant   VARCHAR(160) NOT NULL,
    message     TEXT NOT NULL,
    context     JSON NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    KEY ix_run_invariant (run_id, invariant)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. replay_logs — event-by-event trace of what was emitted, what JE
--    it produced, and SHA-256 hashes for byte-identical replay diffing.
--    Two runs with the same scenario + seed MUST produce identical
--    payload_hash + je_hash sequences.
CREATE TABLE IF NOT EXISTS replay_logs (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_id       BIGINT UNSIGNED NOT NULL,
    event_index  INT UNSIGNED    NOT NULL,
    event_type   VARCHAR(120)    NOT NULL,
    payload_hash CHAR(64)        NOT NULL,
    je_id        BIGINT UNSIGNED NULL,
    je_hash      CHAR(64)        NULL,
    occurred_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_run_event_index (run_id, event_index),
    KEY ix_run_event_type (run_id, event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
