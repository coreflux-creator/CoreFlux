-- 074_treasury_sweep_runs.sql
--
-- Per-execution audit trail for tenant_sweep_rules.
-- The worker writes one row every time it evaluates a rule (every cron
-- tick the rule is scheduled to fire), regardless of whether the sweep
-- actually moved money. Operators read this in the SweepRulesAdmin UI
-- as "Last 30 days of evaluations" so they can verify the worker is
-- firing as expected and the math matches their mental model BEFORE
-- flipping the worker to live execution.
--
-- Schema is execution-engine-agnostic; both dry-run and live runs land
-- in the same table with `dry_run` distinguishing them.

CREATE TABLE IF NOT EXISTS treasury_sweep_runs (
    id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id                INT UNSIGNED NOT NULL,
    rule_id                  INT UNSIGNED NOT NULL,
    ran_at                   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Balance snapshot at evaluation time (or NULL if balance fetch failed).
    source_balance_cents     BIGINT NULL,
    -- Amount the worker would (or did) sweep. 0 means "skipped under floor".
    sweep_amount_cents       BIGINT NOT NULL DEFAULT 0,
    -- 'swept' / 'skipped_under_floor' / 'skipped_disabled' / 'skipped_not_due'
    -- 'failed_no_connection' / 'failed_balance_fetch' / 'failed_execute'
    outcome                  VARCHAR(48) NOT NULL,
    -- 1 when no Mercury transfer was actually issued (default until
    -- TREASURY_SWEEP_LIVE=1 in env). Lets operators tail the audit log
    -- pre-go-live with full confidence in the engine math.
    dry_run                  TINYINT(1) NOT NULL DEFAULT 1,
    -- Linked payment_instructions.id when outcome='swept' && !dry_run.
    -- The transfer goes through the same approval pipeline as any other
    -- payment_instruction, so this is the audit anchor to the actual
    -- money movement.
    payment_instruction_id   INT UNSIGNED NULL,
    error_message            TEXT NULL,
    PRIMARY KEY (id),
    KEY idx_sweep_runs_rule   (tenant_id, rule_id, ran_at),
    KEY idx_sweep_runs_recent (tenant_id, ran_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
