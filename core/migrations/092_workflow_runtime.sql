-- 092_workflow_runtime.sql
--
-- AI Tool Gateway — Slice 3: durable workflow runtime.
--
-- Spec §6 (LangGraph MVP). We don't run Python LangGraph here — we
-- ship a PHP-native state-machine that gives us the same operational
-- semantics: nodes, edges, persisted state, approval interrupts, and
-- resume after pause. Same observable contract, no sidecar.
--
-- Three tables:
--   workflow_runs        — one row per workflow invocation, status +
--                          current node + assembled state.
--   workflow_checkpoints — one row per node-completion. Lets us
--                          replay or resume.
--   workflow_approvals   — one row per pause-for-approval. Linked
--                          back to the run that's waiting.
--
-- Cross-link to AI runs: workflow_runs.ai_run_id forward-points at
-- ai_runs (Slice 1). One AI run can spawn many workflow runs; one
-- workflow run belongs to at most one AI run.
--
-- Idempotent. MySQL 5.7+ (Cloudways), utf8mb4_unicode_ci.

CREATE TABLE IF NOT EXISTS workflow_runs (
    id                  CHAR(36) NOT NULL PRIMARY KEY,          -- UUIDv4
    tenant_id           INT UNSIGNED NOT NULL,
    sub_tenant_id       INT UNSIGNED NULL,
    user_id             INT UNSIGNED NULL,                       -- initiator
    ai_run_id           CHAR(36) NULL,                           -- spec §2A link
    graph_name          VARCHAR(80) NOT NULL,                    -- e.g. 'transaction_classification'
    graph_version       VARCHAR(40) NOT NULL,                    -- e.g. '2026-02-r1'
    status              ENUM('queued','running','awaiting_approval','completed','failed','cancelled')
                            NOT NULL DEFAULT 'queued',
    current_node        VARCHAR(80) NULL,                        -- name of last node entered
    input_json          JSON NULL,                               -- initial state
    state_json          JSON NULL,                               -- assembled state (latest)
    output_json         JSON NULL,                               -- final output (when completed)
    error_code          VARCHAR(60) NULL,
    error_message       VARCHAR(255) NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    completed_at        TIMESTAMP NULL DEFAULT NULL,
    KEY ix_wfr_tenant_recent (tenant_id, created_at),
    KEY ix_wfr_status        (tenant_id, status),
    KEY ix_wfr_graph         (tenant_id, graph_name, status),
    KEY ix_wfr_ai_run        (ai_run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workflow_checkpoints (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workflow_run_id     CHAR(36) NOT NULL,
    tenant_id           INT UNSIGNED NOT NULL,                   -- denormalised for tenant-leak sentry
    node_name           VARCHAR(80) NOT NULL,
    status              ENUM('entered','completed','skipped','failed','paused')
                            NOT NULL DEFAULT 'entered',
    state_hash          CHAR(64) NOT NULL,                       -- sha256 of state_json at checkpoint
    state_json          JSON NULL,                               -- state snapshot for this node
    duration_ms         INT UNSIGNED NULL,
    error_code          VARCHAR(60) NULL,
    error_message       VARCHAR(255) NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_wfc_run     (workflow_run_id, id),
    KEY ix_wfc_tenant  (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workflow_approvals (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workflow_run_id     CHAR(36) NOT NULL,
    tenant_id           INT UNSIGNED NOT NULL,
    node_name           VARCHAR(80) NOT NULL,                    -- node that requested approval
    approval_type       VARCHAR(60) NOT NULL,                    -- e.g. 'classify_transaction'
    risk_level          TINYINT UNSIGNED NOT NULL DEFAULT 3,     -- 1..5 per spec
    assigned_to_role    VARCHAR(80) NULL,                        -- e.g. 'accounting_reviewer'
    request_payload     JSON NOT NULL,                            -- what's being approved
    status              ENUM('pending','approved','rejected','expired','cancelled')
                            NOT NULL DEFAULT 'pending',
    decision_payload    JSON NULL,                                -- reviewer-edited values (if any)
    decided_by_user_id  INT UNSIGNED NULL,
    decided_at          TIMESTAMP NULL DEFAULT NULL,
    expires_at          TIMESTAMP NULL DEFAULT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_wfa_pending  (tenant_id, status, created_at),
    KEY ix_wfa_run      (workflow_run_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
