-- Keep the shared profitability view aligned with assignment economics.
-- Revenue follows billable hours and the frozen commercial rate. Cost follows
-- payable hours and includes recurring W2/C2C burden. Referral assignments
-- use their dated vendor payout instead of a fabricated worker pay rate.

DROP VIEW IF EXISTS v_timesheet_day_fin;

CREATE VIEW v_timesheet_day_fin AS
SELECT
    te.tenant_id AS tenant_id,
    te.id AS entry_id,
    te.period_id AS timesheet_id,
    te.person_id AS employee_id,
    te.placement_id AS placement_id,
    te.work_date AS work_date,
    DATE_SUB(te.work_date, INTERVAL WEEKDAY(te.work_date) DAY) AS week_start,
    DATE_ADD(DATE_SUB(te.work_date, INTERVAL WEEKDAY(te.work_date) DAY), INTERVAL 6 DAY) AS week_end,
    YEAR(te.work_date) AS week_year,
    WEEK(te.work_date, 3) AS week_number,
    te.category AS hour_type,
    te.status AS entry_status,
    te.hours AS hours,
    COALESCE(
        pr.adjusted_bill_rate,
        GREATEST(0,
            COALESCE(pr.bill_rate, 0)
            * (1 + COALESCE(pr.bill_adder_pct, 0) - COALESCE(pr.bill_discount_pct, 0))
            + COALESCE(pr.bill_adder_flat, 0)
            - COALESCE(pr.bill_discount_flat, 0)
        )
    ) AS bill_rate,
    CASE
        WHEN pl.engagement_type = 'referral' THEN COALESCE((
            SELECT SUM(ref.fee_flat)
              FROM placement_referrals ref
             WHERE ref.tenant_id = pl.tenant_id
               AND ref.placement_id = pl.id
               AND ref.fee_basis = 'per_hour'
               AND ref.start_date <= te.work_date
               AND (ref.end_date IS NULL OR ref.end_date >= te.work_date)
        ), 0)
        ELSE COALESCE(pr.pay_rate, 0)
    END AS pay_rate,
    CASE te.hour_type
        WHEN 'overtime' THEN COALESCE(pr.ot_multiplier, 1.50)
        WHEN 'doubletime' THEN COALESCE(pr.dt_multiplier, 2.00)
        ELSE 1.00
    END AS multiplier,
    CASE WHEN te.billable = 1 THEN
        te.hours
        * COALESCE(
            pr.adjusted_bill_rate,
            GREATEST(0,
                COALESCE(pr.bill_rate, 0)
                * (1 + COALESCE(pr.bill_adder_pct, 0) - COALESCE(pr.bill_discount_pct, 0))
                + COALESCE(pr.bill_adder_flat, 0)
                - COALESCE(pr.bill_discount_flat, 0)
            )
        )
        * CASE te.hour_type
            WHEN 'overtime' THEN COALESCE(pr.ot_multiplier, 1.50)
            WHEN 'doubletime' THEN COALESCE(pr.dt_multiplier, 2.00)
            ELSE 1.00
          END
        ELSE 0 END AS revenue,
    CASE
        WHEN te.payable <> 1 THEN 0
        WHEN pl.engagement_type = 'referral' THEN te.hours * COALESCE((
            SELECT SUM(ref.fee_flat)
              FROM placement_referrals ref
             WHERE ref.tenant_id = pl.tenant_id
               AND ref.placement_id = pl.id
               AND ref.fee_basis = 'per_hour'
               AND ref.start_date <= te.work_date
               AND (ref.end_date IS NULL OR ref.end_date >= te.work_date)
        ), 0)
        WHEN pl.engagement_type IN ('w2','temp_to_perm','internal') THEN
            te.hours * (
                COALESCE(pr.pay_rate, 0)
                * CASE te.hour_type
                    WHEN 'overtime' THEN COALESCE(pr.ot_multiplier, 1.50)
                    WHEN 'doubletime' THEN COALESCE(pr.dt_multiplier, 2.00)
                    ELSE 1.00
                  END
                * (1 + COALESCE(pr.adder_pct, 0)
                     + COALESCE(pr.workers_comp_pct, 0)
                     + COALESCE(pr.benefits_load_pct, 0))
                + COALESCE(pr.other_cost_per_hour, 0)
            )
        WHEN pl.engagement_type = 'c2c' THEN
            te.hours * (
                COALESCE(pr.pay_rate, 0)
                * CASE te.hour_type
                    WHEN 'overtime' THEN COALESCE(pr.ot_multiplier, 1.50)
                    WHEN 'doubletime' THEN COALESCE(pr.dt_multiplier, 2.00)
                    ELSE 1.00
                  END
                * (1 + COALESCE(pr.c2c_overhead_pct, 0))
                + COALESCE(pr.other_cost_per_hour, 0)
            )
        ELSE
            te.hours * (
                COALESCE(pr.pay_rate, 0)
                * CASE te.hour_type
                    WHEN 'overtime' THEN COALESCE(pr.ot_multiplier, 1.50)
                    WHEN 'doubletime' THEN COALESCE(pr.dt_multiplier, 2.00)
                    ELSE 1.00
                  END
                + COALESCE(pr.other_cost_per_hour, 0)
            )
    END AS cost,
    (
        CASE WHEN te.billable = 1 THEN
            te.hours
            * COALESCE(
                pr.adjusted_bill_rate,
                GREATEST(0,
                    COALESCE(pr.bill_rate, 0)
                    * (1 + COALESCE(pr.bill_adder_pct, 0) - COALESCE(pr.bill_discount_pct, 0))
                    + COALESCE(pr.bill_adder_flat, 0)
                    - COALESCE(pr.bill_discount_flat, 0)
                )
            )
            * CASE te.hour_type
                WHEN 'overtime' THEN COALESCE(pr.ot_multiplier, 1.50)
                WHEN 'doubletime' THEN COALESCE(pr.dt_multiplier, 2.00)
                ELSE 1.00
              END
            ELSE 0 END
        -
        CASE
            WHEN te.payable <> 1 THEN 0
            WHEN pl.engagement_type = 'referral' THEN te.hours * COALESCE((
                SELECT SUM(ref.fee_flat)
                  FROM placement_referrals ref
                 WHERE ref.tenant_id = pl.tenant_id
                   AND ref.placement_id = pl.id
                   AND ref.fee_basis = 'per_hour'
                   AND ref.start_date <= te.work_date
                   AND (ref.end_date IS NULL OR ref.end_date >= te.work_date)
            ), 0)
            WHEN pl.engagement_type IN ('w2','temp_to_perm','internal') THEN
                te.hours * (
                    COALESCE(pr.pay_rate, 0)
                    * CASE te.hour_type
                        WHEN 'overtime' THEN COALESCE(pr.ot_multiplier, 1.50)
                        WHEN 'doubletime' THEN COALESCE(pr.dt_multiplier, 2.00)
                        ELSE 1.00
                      END
                    * (1 + COALESCE(pr.adder_pct, 0)
                         + COALESCE(pr.workers_comp_pct, 0)
                         + COALESCE(pr.benefits_load_pct, 0))
                    + COALESCE(pr.other_cost_per_hour, 0)
                )
            WHEN pl.engagement_type = 'c2c' THEN
                te.hours * (
                    COALESCE(pr.pay_rate, 0)
                    * CASE te.hour_type
                        WHEN 'overtime' THEN COALESCE(pr.ot_multiplier, 1.50)
                        WHEN 'doubletime' THEN COALESCE(pr.dt_multiplier, 2.00)
                        ELSE 1.00
                      END
                    * (1 + COALESCE(pr.c2c_overhead_pct, 0))
                    + COALESCE(pr.other_cost_per_hour, 0)
                )
            ELSE
                te.hours * (
                    COALESCE(pr.pay_rate, 0)
                    * CASE te.hour_type
                        WHEN 'overtime' THEN COALESCE(pr.ot_multiplier, 1.50)
                        WHEN 'doubletime' THEN COALESCE(pr.dt_multiplier, 2.00)
                        ELSE 1.00
                      END
                    + COALESCE(pr.other_cost_per_hour, 0)
                )
        END
    ) AS gross_profit,
    CASE WHEN te.hour_type IN ('overtime','doubletime') THEN 1 ELSE 0 END AS is_overtime,
    CASE WHEN te.billable = 1 THEN 1 ELSE 0 END AS is_billable
FROM time_entries te
LEFT JOIN placement_rates pr ON pr.id = te.rate_snapshot_id
LEFT JOIN placements pl ON pl.id = te.placement_id
WHERE te.status <> 'superseded';
