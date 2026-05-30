-- 081_report_drilldown_log.sql
--
-- Reports Overhaul follow-up: per-tenant drill-through audit trail.
--
-- Every time an operator opens GlDetailDrilldown (or MetricDrilldown)
-- from a report, the frontend fires a fire-and-forget POST to
-- /api/admin/reports/log_drilldown.php which lands a row here.
--
-- Two uses for the data:
--   1. "Recent drills" surface in the report header — one-click
--      restore the same drill scope (account_code + window) on a
--      subsequent visit.
--   2. CFO-level audit answer to "which accounts did Finance inspect
--      between Feb 14-19?" — for SOX-style evidence trails.
--
-- Append-only. No PII in the row itself; user_id + tenant_id are the
-- only joinable columns. Rotate / archive via TenantHousekeeping if
-- the table outgrows its index.
--
-- Idempotent.

DO 0;

CREATE TABLE IF NOT EXISTS report_drilldown_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id   BIGINT UNSIGNED NOT NULL,
    user_id     BIGINT UNSIGNED NOT NULL,
    report_key  VARCHAR(40)     NOT NULL,        -- 'rpt-pnl' | 'rpt-bs' | 'rpt-tb' | 'rpt-cf' | 'cfo' | 'exec' | ...
    account_code VARCHAR(40)    NULL,            -- GL account code if GL drill (NULL for non-GL metric drills)
    period_from DATE            NULL,
    period_to   DATE            NULL,
    label       VARCHAR(255)    NULL,            -- denormalised account/metric label for cheap surfacing
    opened_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Cheap composite index for "recent N drills by this user on this
    -- report" — the surface lookup pattern.
    INDEX idx_rdl_recent (tenant_id, user_id, report_key, opened_at DESC),

    -- For the audit answer "which accounts inspected in window X-Y".
    INDEX idx_rdl_account_window (tenant_id, account_code, opened_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
