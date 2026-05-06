# Reports Module — Phase 1 Deploy Notes

**Sprint 1 of the holistic plan ratified 2026-02.**
Industry-aware Reports module (Staffing first), per `Reports.docx` spec.

## What shipped

### Schema (1 view)
- `modules/reports/migrations/001_init.sql` — creates the `v_timesheet_day_fin`
  view over `time_entries` ⨝ `placement_rates`. Idempotent
  (`DROP VIEW IF EXISTS` then `CREATE VIEW`). Surfaces:
  `tenant_id, entry_id, timesheet_id, employee_id, placement_id, work_date,
  week_start, week_end, week_year, week_number, hour_type, entry_status,
  hours, bill_rate, pay_rate, multiplier, revenue, cost, gross_profit,
  is_overtime, is_billable`. Excludes superseded entries.

### PHP (lib + APIs)
- `modules/reports/manifest.php` — module registered with 5 actions, 4
  permissions (`reports.view`, `reports.export`, `reports.custom.build`,
  `reports.custom.share`), 5 audit events. Default roles: master_admin,
  tenant_admin, admin, manager.
- `modules/reports/lib/periods.php` — `reportsResolvePeriod($code, $from?, $to?)`
  resolves 12 preset codes (`1w/2w/4w/8w/12w/mtd/last_month/qtd/last_quarter/
  ytd/last_12m/last_year`) plus arbitrary custom ranges. Default = `4w`.
  Returns `[code, label, from, to, weeks]` with Monday-aligned weekly buckets.
- `modules/reports/lib/staffing_metrics.php` — pure metric functions:
  `staffingKpiTotals`, `staffingWeeklySeries`, `staffingHeadcount`,
  `staffingTimesheetHealth`, `staffingRunRate`. All read from
  `v_timesheet_day_fin`.
- 5 endpoints, each `reports.view`-gated, GET-only:
  - `/api/reports/overview.php`             → KPIs + weekly chart + headcount + run rate + timesheet health
  - `/api/reports/executive_snapshot.php`   → printable leadership summary
  - `/api/reports/client_profitability.php` → per-client margin table + low-margin alerts (<20% GP)
  - `/api/reports/rate_spread.php`          → per-placement rate + spread + flags (negative_spread / low_spread)
  - `/api/reports/overtime_watch.php`       → totals + weekly OT trend + top employees by OT% + top clients by OT hours

### React (8 components)
- `modules/reports/ui/ReportsModule.jsx` — router; legacy `/exec /finance /staffing` redirected to spec routes.
- `modules/reports/ui/ReportsSidebar.jsx` — module-only sidebar grouped Overview / Staffing / Build (Reports.docx spec).
- `modules/reports/ui/PeriodSelector.jsx` — shared 12-option dropdown, default 4 weeks.
- `modules/reports/ui/StaffingOverview.jsx` — 6 KPI tiles, SVG line chart for weekly Rev+GP, headcount tiles, run rate, timesheet health.
- `modules/reports/ui/ExecutiveSnapshot.jsx` — 16-tile printable snapshot.
- `modules/reports/ui/ClientProfitability.jsx` — per-client table with low-margin alerts.
- `modules/reports/ui/RateSpreadMonitor.jsx` — per-placement spread table.
- `modules/reports/ui/OvertimeWatch.jsx` — OT totals + weekly trend + employee/client leaderboards.

### Wiring
- `dashboard/src/App.jsx` — repointed `/modules/reports/*` to the new module.
- `core/modules.php` — Reports actions updated to spec routes.
- `tests/sprint6_restructure_smoke.php` — assertion updated for new routes.

## Smoke tests
- New: `tests/reports_phase1_smoke.php` — **141 assertions ✓**
- Full suite: **74 PHP smoke files passing**, no regressions.

## Vite bundle
- `spa-assets/index-D6ICRwjV.js` (970 kB) + `index-Cwhpy62y.css` (21.6 kB), 1806 modules.
- `index.html` updated to point at the new bundle.
- `.deploy-version` `expected_bundle:` + `last_known_features:` updated.

## How to deploy on Cloudways
1. Standard SFTP upload (or `update.php`).
2. Migration runs automatically via the `installer_helpers.php` glob —
   no manual SQL. Verify by running:

       SELECT TABLE_NAME, TABLE_TYPE
         FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'v_timesheet_day_fin';

   Expect `TABLE_TYPE = VIEW`.
3. Visit `/spa.php` → switch the module dropdown to **Reports** → land on
   Staffing Overview at `/modules/reports/overview`. Period dropdown
   defaults to 4 weeks. Confirm KPI tiles render (zero-state OK if no time
   data in the last 4 weeks).
4. Smoke walk:
   - Hit `/modules/reports/executive_snapshot` and click **Print / Save PDF**.
   - Hit `/modules/reports/client_profitability` and verify table loads.
   - Hit `/modules/reports/rate_spread` and confirm "Negative spread" badge appears for any placement with `pay_rate > bill_rate`.
   - Hit `/modules/reports/overtime_watch` and verify weekly OT% calculation.

## Next sprint
Sprint 2 — Accounting depth (multi-entity + dimensions + period close
workflow). Untouched in this sprint:
- Custom Report Builder (Phase 2)
- Other Reports directory (Phase 2)
- Industry selector / Hospitality scaffold (later)
- CSV / XLSX / PDF exports for the new reports (next polish pass)
- Recruiter/AM, Worker Mix, Near-Term Forecast (D4b — next sprint)
