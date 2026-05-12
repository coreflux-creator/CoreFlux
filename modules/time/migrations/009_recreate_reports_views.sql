-- Time module — recreate Reports views AFTER time_entries columns exist.
--
-- Lives in modules/time/ (not modules/reports/) so it runs AFTER
-- modules/time/migrations/008_ensure_columns.sql in the migration
-- runner's natural-sort order. The view body is identical to
-- modules/reports/migrations/001_init.sql.
--
-- IDEMPOTENT — DROP VIEW IF EXISTS → CREATE VIEW. Conditional on
-- te.placement_id existing as the schema-health sentinel.

SET @te_ok := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'placement_id')
;
SET @pr_ok := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'placement_rates')
;
SET @drop := IF(@te_ok = 1 AND @pr_ok = 1, 'DROP VIEW IF EXISTS v_timesheet_day_fin', 'DO 0')
;
PREPARE s FROM @drop
;
EXECUTE s
;
DEALLOCATE PREPARE s
;
SET @create := IF(@te_ok = 1 AND @pr_ok = 1, "CREATE VIEW v_timesheet_day_fin AS SELECT te.tenant_id AS tenant_id, te.id AS entry_id, te.period_id AS timesheet_id, te.person_id AS employee_id, te.placement_id AS placement_id, te.work_date AS work_date, DATE_SUB(te.work_date, INTERVAL WEEKDAY(te.work_date) DAY) AS week_start, DATE_ADD(DATE_SUB(te.work_date, INTERVAL WEEKDAY(te.work_date) DAY), INTERVAL 6 DAY) AS week_end, YEAR(te.work_date) AS week_year, WEEK(te.work_date, 3) AS week_number, te.category AS hour_type, te.status AS entry_status, te.hours AS hours, COALESCE(pr.bill_rate, 0) AS bill_rate, COALESCE(pr.pay_rate, 0) AS pay_rate, CASE WHEN te.category IN ('OT_billable','OT_nonbillable') THEN COALESCE(pr.ot_multiplier, 1.50) ELSE 1.00 END AS multiplier, CASE WHEN te.category = 'regular_billable' THEN te.hours * COALESCE(pr.bill_rate, 0) WHEN te.category = 'OT_billable' THEN te.hours * COALESCE(pr.bill_rate, 0) * COALESCE(pr.ot_multiplier, 1.50) ELSE 0 END AS revenue, CASE WHEN te.category = 'regular_billable' OR te.category = 'regular_nonbillable' THEN te.hours * COALESCE(pr.pay_rate, 0) WHEN te.category = 'OT_billable' OR te.category = 'OT_nonbillable' THEN te.hours * COALESCE(pr.pay_rate, 0) * COALESCE(pr.ot_multiplier, 1.50) ELSE 0 END AS cost, (CASE WHEN te.category = 'regular_billable' THEN te.hours * COALESCE(pr.bill_rate, 0) WHEN te.category = 'OT_billable' THEN te.hours * COALESCE(pr.bill_rate, 0) * COALESCE(pr.ot_multiplier, 1.50) ELSE 0 END - CASE WHEN te.category = 'regular_billable' OR te.category = 'regular_nonbillable' THEN te.hours * COALESCE(pr.pay_rate, 0) WHEN te.category = 'OT_billable' OR te.category = 'OT_nonbillable' THEN te.hours * COALESCE(pr.pay_rate, 0) * COALESCE(pr.ot_multiplier, 1.50) ELSE 0 END) AS gross_profit, CASE WHEN te.category IN ('OT_billable','OT_nonbillable') THEN 1 ELSE 0 END AS is_overtime, CASE WHEN te.category IN ('regular_billable','OT_billable') THEN 1 ELSE 0 END AS is_billable FROM time_entries te LEFT JOIN placement_rates pr ON pr.id = te.rate_snapshot_id WHERE te.status <> 'superseded'", 'DO 0')
;
PREPARE s FROM @create
;
EXECUTE s
;
DEALLOCATE PREPARE s
;
