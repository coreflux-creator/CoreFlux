-- 107_accounting_close_runs.sql
--
-- Slice D — Period-close orchestrator wrapper.
--
-- Spec §11 ("Close Agent") + scan section "Phase 4 — Close MVP":
-- today we have accounting_close_tasks + accounting_close_packets but
-- no run-level wrapper to tie a single period-close attempt together
-- with its checklist progress, build-packet artifact id, lock event,
-- and reopen history.
--
-- A `close_run` is the unit a Close Agent operates on:
--   started → in_progress → packet_built → locked
--          ↘ reopened (back to in_progress)
--
-- One ACTIVE run per (tenant, period) — enforced by UNIQUE on
-- (tenant_id, period_id) WHERE status <> 'reopened'.  Reopens write a
-- NEW row that supersedes the previous one (history kept).
--
-- Idempotent.  MySQL 5.7+, utf8mb4_unicode_ci.

CREATE TABLE IF NOT EXISTS accounting_close_runs (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id             BIGINT UNSIGNED NOT NULL,
    sub_tenant_id         BIGINT UNSIGNED NULL,
    period_id             BIGINT UNSIGNED NOT NULL,
    -- Lifecycle.
    status                ENUM('initiated','in_progress','packet_built','locked','reopened') NOT NULL DEFAULT 'initiated',
    started_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at          DATETIME NULL,            -- when all tasks reached `done`
    packet_built_at       DATETIME NULL,
    locked_at             DATETIME NULL,
    reopened_at           DATETIME NULL,
    reopen_reason         VARCHAR(500) NULL,
    -- Actors.
    started_by_user_id    BIGINT UNSIGNED NULL,
    locked_by_user_id     BIGINT UNSIGNED NULL,
    reopened_by_user_id   BIGINT UNSIGNED NULL,
    -- Computed counters, refreshed by closeRunRefreshProgress().
    -- Stored so the dashboard renders fast without a sub-query per row.
    total_tasks           INT UNSIGNED NOT NULL DEFAULT 0,
    completed_tasks       INT UNSIGNED NOT NULL DEFAULT 0,
    -- Linkage to Slice A artifact + Slice B workflow.
    packet_artifact_id    CHAR(36) NULL,            -- artifact_objects.id (CHAR(36) UUIDv4)
    packet_id             BIGINT UNSIGNED NULL,     -- accounting_close_packets.id (the legacy row)
    workflow_run_id       CHAR(36) NULL,            -- workflow_runs.id when the close graph kicks off
    -- Free-form notes (e.g. reopen rationale, audit narrative).
    notes                 VARCHAR(2000) NULL,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY ix_close_run_tenant_period (tenant_id, period_id),
    KEY ix_close_run_tenant_status (tenant_id, status, id),
    KEY ix_close_run_packet        (packet_artifact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
