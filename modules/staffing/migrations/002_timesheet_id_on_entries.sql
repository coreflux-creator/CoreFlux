-- CoreStaffing Phase 1 — extend time_entries to be the timesheet ROW.
--
-- Adds:
--   • timesheet_id  → FK to timesheets header (NULL until first save under v2).
--   • hour_type     → regular / overtime / doubletime / holiday / pto / unpaid / nonbillable.
--                     The legacy `category` enum is preserved (mapped 1:1 below).
--   • billable, payable booleans.
--
-- Backfills timesheet headers for every existing (tenant, person, ISO-week)
-- so the new Staffing UI can render historical weeks immediately.
--
-- Idempotent — every DDL is information_schema-guarded, one statement per
-- line so PDO::exec() handles each individually (no multi-statement).

SET @te_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'time_entries')
;
SET @ts_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'timesheets')
;

-- Add timesheet_id column.
SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'timesheet_id')
;
SET @sql := IF(@te_exists = 1 AND @col = 0, 'ALTER TABLE time_entries ADD COLUMN timesheet_id BIGINT UNSIGNED NULL AFTER period_id, ADD INDEX idx_te_timesheet (timesheet_id)', 'SELECT "time_entries.timesheet_id already exists" AS note')
;
PREPARE stmt FROM @sql
;
EXECUTE stmt
;
DEALLOCATE PREPARE stmt
;

-- Add hour_type column (mirrors spec §10.7 enum).
SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'hour_type')
;
SET @sql := IF(@te_exists = 1 AND @col = 0, "ALTER TABLE time_entries ADD COLUMN hour_type ENUM('regular','overtime','doubletime','holiday','pto','sick','bereavement','unpaid','nonbillable') NOT NULL DEFAULT 'regular' AFTER category", 'SELECT "time_entries.hour_type already exists" AS note')
;
PREPARE stmt FROM @sql
;
EXECUTE stmt
;
DEALLOCATE PREPARE stmt
;

-- Add billable / payable flags.
SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'billable')
;
SET @sql := IF(@te_exists = 1 AND @col = 0, "ALTER TABLE time_entries ADD COLUMN billable TINYINT(1) NOT NULL DEFAULT 1 AFTER hour_type, ADD COLUMN payable TINYINT(1) NOT NULL DEFAULT 1 AFTER billable", 'SELECT "time_entries.billable already exists" AS note')
;
PREPARE stmt FROM @sql
;
EXECUTE stmt
;
DEALLOCATE PREPARE stmt
;

-- Backfill hour_type from legacy `category` enum. Only run if hour_type
-- defaults are still 'regular' on every row (i.e. we haven't already backfilled).
SET @sql := IF(@te_exists = 1, "UPDATE time_entries SET hour_type = CASE WHEN category = 'OT_billable' OR category = 'OT_nonbillable' THEN 'overtime' WHEN category = 'holiday' THEN 'holiday' WHEN category = 'vacation' THEN 'pto' WHEN category = 'sick' THEN 'sick' WHEN category = 'bereavement' THEN 'bereavement' WHEN category = 'unpaid_leave' THEN 'unpaid' WHEN category = 'regular_nonbillable' THEN 'nonbillable' ELSE 'regular' END, billable = IF(category IN ('regular_nonbillable','OT_nonbillable','holiday','vacation','sick','bereavement','unpaid_leave'), 0, 1) WHERE hour_type = 'regular'", 'SELECT "time_entries missing — skip hour_type backfill" AS note')
;
PREPARE stmt FROM @sql
;
EXECUTE stmt
;
DEALLOCATE PREPARE stmt
;

-- Backfill timesheets headers: one row per (tenant, person, ISO Monday-week)
-- derived from existing time_entries. SUBDATE() shifts work_date back to the
-- Monday of its ISO week (DAYOFWEEK returns 1-7 Sun-Sat; (DAYOFWEEK - 2 + 7) % 7
-- gives days since Monday). Uses INSERT IGNORE so re-running is safe.
SET @sql := IF(@te_exists = 1 AND @ts_exists = 1, "INSERT IGNORE INTO timesheets (tenant_id, person_id, period_start, period_end, status, total_hours) SELECT tenant_id, person_id, SUBDATE(work_date, MOD(DAYOFWEEK(work_date) - 2 + 7, 7)) AS ps, DATE_ADD(SUBDATE(work_date, MOD(DAYOFWEEK(work_date) - 2 + 7, 7)), INTERVAL 6 DAY) AS pe, CASE WHEN MIN(status) = 'approved' AND MAX(status) = 'approved' THEN 'approved' WHEN MAX(status) = 'pending_review' THEN 'submitted' WHEN MAX(status) = 'rejected' THEN 'rejected' ELSE 'draft' END AS hs, SUM(CASE WHEN status != 'superseded' THEN hours ELSE 0 END) FROM time_entries WHERE person_id IS NOT NULL GROUP BY tenant_id, person_id, ps, pe", 'SELECT "skip header backfill — table missing" AS note')
;
PREPARE stmt FROM @sql
;
EXECUTE stmt
;
DEALLOCATE PREPARE stmt
;

-- Link every existing time_entries row to its newly-created timesheet header.
SET @sql := IF(@te_exists = 1 AND @ts_exists = 1, "UPDATE time_entries te JOIN timesheets t ON t.tenant_id = te.tenant_id AND t.person_id = te.person_id AND te.work_date BETWEEN t.period_start AND t.period_end SET te.timesheet_id = t.id WHERE te.timesheet_id IS NULL", 'SELECT "skip te.timesheet_id backfill" AS note')
;
PREPARE stmt FROM @sql
;
EXECUTE stmt
;
DEALLOCATE PREPARE stmt
;
