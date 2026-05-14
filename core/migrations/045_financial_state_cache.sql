-- Phase 2 — Unified Financial State Cache (2026-02-XX).
--
-- Denormalized, fast-read projection of the financial state. Built by
-- subscribing to the (now strictly clean post-Phase 2a) accounting event
-- log and journal entries. Read path for:
--   - CFO Dashboard scalar widgets (revenue/margin/aging/payroll)
--   - Phase 2 AI rule competition (needs sub-second outcome reads)
--   - Period-close materialized views
--   - External Auditor read-only snapshot (P3)
--
-- Architecture: generic (scope_key, scope_value, metric_key) → numeric/json.
-- One row per metric. Avoids per-metric schema migrations.
--
-- Examples:
--   ('period_id', '42', 'account_balance.123')           → 12345.67
--   ('period_id', '42', 'revenue.posted')                → 50000.00
--   ('period_id', '42', 'kpi.net_income', json: {...})
--   ('tenant',    '7',  'revenue.ytd')                   → 100000.00
--
-- Source-hash column busts cache when the underlying inputs change
-- (concatenated max(updated_at) + sum(amount) of contributing rows).
--
-- Idempotent. Cloudways MySQL 5.7+ compatible.

CREATE TABLE IF NOT EXISTS financial_state_cache (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       BIGINT UNSIGNED NOT NULL,
    scope_key       VARCHAR(40)     NOT NULL,   -- 'period_id' | 'tenant' | 'entity_id' | ...
    scope_value     VARCHAR(80)     NOT NULL,   -- stringified pk or 'all'
    metric_key      VARCHAR(120)    NOT NULL,   -- 'account_balance.123' | 'revenue.ytd' | ...
    numeric_value   DECIMAL(18,4)   NULL,
    json_value      JSON            NULL,
    source_hash     CHAR(64)        NULL,       -- sha256 of inputs (for invalidation)
    computed_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    computed_ms     INT UNSIGNED    NULL,       -- builder runtime, for SLO tracking

    UNIQUE KEY uq_fsc_scope_metric (tenant_id, scope_key, scope_value, metric_key),
    KEY ix_fsc_tenant_scope        (tenant_id, scope_key, scope_value),
    KEY ix_fsc_tenant_metric       (tenant_id, metric_key),
    KEY ix_fsc_computed_at         (computed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Append-only log of which (tenant, scope_key, scope_value) tuples need
-- recompute. Writers (accountingPostJe, accountingReverseJe, event
-- listeners) insert rows; rebuilders delete them after a successful
-- rebuild pass. Multiple rows per scope are fine — the rebuilder collapses.
--
-- Why not just a `dirty TINYINT` column on financial_state_cache?
--   The dirty mark fires BEFORE the cache row exists (first build for a
--   period happens lazy-on-read). Separate log = clean semantics.
CREATE TABLE IF NOT EXISTS financial_state_cache_dirty (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       BIGINT UNSIGNED NOT NULL,
    scope_key       VARCHAR(40)     NOT NULL,
    scope_value     VARCHAR(80)     NOT NULL,
    reason          VARCHAR(120)    NULL,       -- 'je_posted' | 'je_reversed' | 'manual' | ...
    marked_by_user_id BIGINT UNSIGNED NULL,
    marked_at       TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,

    KEY ix_fscd_scope (tenant_id, scope_key, scope_value, marked_at),
    KEY ix_fscd_marked_at (marked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
