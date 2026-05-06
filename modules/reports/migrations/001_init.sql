-- Reports Module — Phase 1 schema
-- Creates the shared reporting view over Time + Placements.
-- Idempotent: DROP VIEW IF EXISTS then CREATE VIEW (MySQL 5.7 / 8 compatible).
-- All downstream report APIs read from this view so metric math stays consistent.

DROP VIEW IF EXISTS v_timesheet_day_fin;

CREATE VIEW v_timesheet_day_fin AS
SELECT
    te.tenant_id           AS tenant_id,
    te.id                  AS entry_id,
    te.period_id           AS timesheet_id,
    te.person_id           AS employee_id,
    te.placement_id        AS placement_id,
    te.work_date           AS work_date,
    /* ISO week: Monday = 0, so Monday-of-week = work_date - WEEKDAY(work_date). */
    DATE_SUB(te.work_date, INTERVAL WEEKDAY(te.work_date) DAY)                          AS week_start,
    DATE_ADD(DATE_SUB(te.work_date, INTERVAL WEEKDAY(te.work_date) DAY), INTERVAL 6 DAY) AS week_end,
    YEAR(te.work_date)                                                                  AS week_year,
    /* MySQL 5.7 WEEK mode 3 = ISO 8601 week number. */
    WEEK(te.work_date, 3)                                                               AS week_number,
    te.category            AS hour_type,
    te.status              AS entry_status,
    te.hours               AS hours,
    COALESCE(pr.bill_rate, 0) AS bill_rate,
    COALESCE(pr.pay_rate,  0) AS pay_rate,
    CASE
        WHEN te.category IN ('OT_billable','OT_nonbillable') THEN COALESCE(pr.ot_multiplier, 1.50)
        ELSE 1.00
    END AS multiplier,
    /* Revenue only accrues on billable categories. */
    CASE
        WHEN te.category = 'regular_billable'
            THEN te.hours * COALESCE(pr.bill_rate, 0)
        WHEN te.category = 'OT_billable'
            THEN te.hours * COALESCE(pr.bill_rate, 0) * COALESCE(pr.ot_multiplier, 1.50)
        ELSE 0
    END AS revenue,
    /* Cost accrues on any paid hours (billable + nonbillable worked), not PTO/unpaid. */
    CASE
        WHEN te.category = 'regular_billable' OR te.category = 'regular_nonbillable'
            THEN te.hours * COALESCE(pr.pay_rate, 0)
        WHEN te.category = 'OT_billable' OR te.category = 'OT_nonbillable'
            THEN te.hours * COALESCE(pr.pay_rate, 0) * COALESCE(pr.ot_multiplier, 1.50)
        ELSE 0
    END AS cost,
    /* Convenience column. Consumers SUM revenue - SUM cost themselves for aggregated queries. */
    (
      CASE
        WHEN te.category = 'regular_billable'
            THEN te.hours * COALESCE(pr.bill_rate, 0)
        WHEN te.category = 'OT_billable'
            THEN te.hours * COALESCE(pr.bill_rate, 0) * COALESCE(pr.ot_multiplier, 1.50)
        ELSE 0
      END
      -
      CASE
        WHEN te.category = 'regular_billable' OR te.category = 'regular_nonbillable'
            THEN te.hours * COALESCE(pr.pay_rate, 0)
        WHEN te.category = 'OT_billable' OR te.category = 'OT_nonbillable'
            THEN te.hours * COALESCE(pr.pay_rate, 0) * COALESCE(pr.ot_multiplier, 1.50)
        ELSE 0
      END
    ) AS gross_profit,
    CASE WHEN te.category IN ('OT_billable','OT_nonbillable') THEN 1 ELSE 0 END AS is_overtime,
    CASE WHEN te.category IN ('regular_billable','OT_billable') THEN 1 ELSE 0 END AS is_billable
FROM time_entries te
LEFT JOIN placement_rates pr ON pr.id = te.rate_snapshot_id
WHERE te.status <> 'superseded';
