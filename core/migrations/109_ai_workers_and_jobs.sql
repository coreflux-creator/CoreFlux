-- 109_ai_workers_and_jobs.sql
--
-- Slice 7A — AI Worker Runtime durable queue + worker registry.
--
-- Spec §2 ("AI Worker Runtime"): a durable job queue lets long-running
-- agent graphs (close, forecast, AP extraction) run async + survive
-- restarts.  Today every tool call is synchronous against the request
-- thread; this slice unblocks that.
--
-- Tables:
--   ai_workers       Registered worker processes.  A CLI worker
--                    process registers on boot with a key (machine
--                    id) and capabilities (which queues it serves).
--                    Heartbeat freshness determines `online` vs
--                    `stalled` in the admin UI.
--
--   ai_worker_jobs   Durable job queue.  Status lifecycle:
--                      queued → claimed → running → succeeded
--                                      ↘ failed (retryable)
--                                      ↘ dead   (max attempts hit)
--                                      ↘ cancelled
--                    Each job is keyed by queue (e.g. 'default',
--                    'long_running', 'close_agent') so workers can
--                    bind to a subset.
--
-- Idempotent.  utf8mb4_unicode_ci.

CREATE TABLE IF NOT EXISTS ai_workers (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    worker_key          VARCHAR(120) NOT NULL,           -- "host:pid" or stable id
    label               VARCHAR(200) NULL,
    status              ENUM('online','draining','stalled','offline') NOT NULL DEFAULT 'online',
    capabilities_json   LONGTEXT NULL,                   -- {"queues":["default","close_agent"], "max_concurrency":1}
    last_heartbeat_at   DATETIME NULL,
    registered_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_ip        VARCHAR(45) NULL,
    notes               VARCHAR(500) NULL,

    UNIQUE KEY uq_ai_worker_key (worker_key),
    KEY ix_ai_worker_heartbeat (status, last_heartbeat_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_worker_jobs (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id               BIGINT UNSIGNED NOT NULL,
    sub_tenant_id           BIGINT UNSIGNED NULL,
    queue                   VARCHAR(80) NOT NULL DEFAULT 'default',
    -- What to run.  payload_json shape:
    --   {tool_name, args, caller_ctx, source_module?, source_record_id?}
    tool_name               VARCHAR(120) NOT NULL,
    payload_json            LONGTEXT NOT NULL,
    -- Lifecycle.
    status                  ENUM('queued','claimed','running','succeeded','failed','dead','cancelled') NOT NULL DEFAULT 'queued',
    attempt                 INT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts            INT UNSIGNED NOT NULL DEFAULT 3,
    -- Scheduling.
    scheduled_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    claimed_by_worker_id    BIGINT UNSIGNED NULL,
    claimed_at              DATETIME NULL,
    started_at              DATETIME NULL,
    finished_at             DATETIME NULL,
    next_attempt_at         DATETIME NULL,                   -- exponential backoff
    -- Result + error capture.
    result_json             LONGTEXT NULL,
    error_message           VARCHAR(2000) NULL,
    error_code              VARCHAR(80) NULL,
    -- Provenance.
    ai_run_id               CHAR(36) NULL,                   -- ai_tool_invocations.id once dispatched
    artifact_id             CHAR(36) NULL,                   -- linked artifact_objects (Slice A)
    idempotency_key         VARCHAR(120) NULL,               -- caller-supplied; uq below
    -- Audit.
    enqueued_by_user_id     BIGINT UNSIGNED NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Idempotency: same (tenant, key) returns the existing job.
    UNIQUE KEY uq_ai_job_idem (tenant_id, idempotency_key),
    -- Dequeue index — the worker hot path:
    KEY ix_ai_job_dequeue (status, queue, scheduled_at, id),
    KEY ix_ai_job_tenant_status (tenant_id, status, id),
    KEY ix_ai_job_worker (claimed_by_worker_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
