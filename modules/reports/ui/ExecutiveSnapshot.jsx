import React, { useState } from 'react';
import { useApi } from '../../../dashboard/src/lib/api';
import PeriodSelector from './PeriodSelector';
import { ReportFrame, KpiTile, reportFmt } from './ReportToolkit';

/**
 * Executive Snapshot — leadership one-page summary.
 *
 * P1.11 — Reports overhaul. Migrated to the new ReportToolkit:
 *   - <ReportFrame> with sticky header + period selector + print action.
 *   - <KpiTile> per metric with optional sparkline trend + delta +
 *     tone + drill-down link to the underlying data screen.
 *
 * Sparkline arrays come from `data.snapshot.spark_*` (backend
 * already exposes a few; remaining tiles get null and gracefully
 * omit the sparkline). Drill links route to the canonical detail
 * surface for each metric so leadership can move from "the number
 * looks off" to "show me the rows" in one click.
 */
export default function ExecutiveSnapshot() {
  const [period, setPeriod] = useState('4w');
  const { data, loading, error } = useApi(`/modules/reports/api/executive_snapshot.php?period=${period}`);
  const handlePrint = () => window.print();
  const s = data?.snapshot || {};
  const periodSub = data
    ? `${data.period.label} • ${data.period.from} → ${data.period.to}`
    : null;

  return (
    <ReportFrame
      testid="reports-executive"
      title="Executive Snapshot"
      subtitle={periodSub}
      actions={
        <>
          <PeriodSelector value={period} onChange={setPeriod} testid="reports-exec-period" />
          <button className="btn btn--ghost" onClick={handlePrint} data-testid="reports-exec-print">
            Print / Save PDF
          </button>
        </>
      }
    >
      {error && <p className="error" data-testid="reports-exec-error">Failed to load: {String(error)}</p>}
      {loading && !data && <p className="empty">Loading…</p>}
      {data && (
        <div
          data-testid="reports-exec-snapshot"
          style={{
            display: 'grid',
            gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))',
            gap: 'var(--cf-space-4, 16px)',
          }}
        >
          <KpiTile
            testid="exec-revenue"
            label="Revenue"
            value={reportFmt.currency(s.revenue)}
            spark={s.spark_revenue}
            delta={s.revenue_run_rate_delta_pct}
            tone="auto"
            to="/billing/invoices"
          />
          <KpiTile
            testid="exec-gp"
            label="Gross profit"
            value={reportFmt.currency(s.gross_profit)}
            spark={s.spark_gp}
            delta={s.gp_run_rate_delta_pct}
            tone="auto"
            to="/reports/client-profitability"
          />
          <KpiTile
            testid="exec-gp-pct"
            label="GP %"
            value={reportFmt.pct(s.gross_profit_pct)}
            tone="muted"
          />
          <KpiTile
            testid="exec-hours"
            label="Hours"
            value={reportFmt.num(s.hours)}
            spark={s.spark_hours}
            to="/time"
          />
          <KpiTile
            testid="exec-ot"
            label="Overtime %"
            value={reportFmt.pct(s.ot_pct)}
            sub={`${reportFmt.num(s.ot_hours)} OT hrs`}
            tone={s.ot_pct > 10 ? 'negative' : 'muted'}
            to="/reports/overtime-watch"
          />
          <KpiTile
            testid="exec-spread"
            label="Spread / hr"
            value={reportFmt.currency(s.spread_per_hour)}
            to="/reports/rate-spread"
          />
          <KpiTile
            testid="exec-headcount"
            label="Active headcount"
            value={reportFmt.num(s.headcount_active)}
            sub={`${reportFmt.signed(s.net_headcount_change)} this period`}
            tone={s.net_headcount_change >= 0 ? 'positive' : 'negative'}
            to="/people"
          />
          <KpiTile
            testid="exec-new-starts"
            label="New starts"
            value={reportFmt.num(s.new_starts)}
            to="/placements?filter=new"
          />
          <KpiTile
            testid="exec-terminations"
            label="Terminations"
            value={reportFmt.num(s.terminations)}
            tone={s.terminations > 0 ? 'negative' : 'muted'}
            to="/placements?filter=terminated"
          />
          <KpiTile
            testid="exec-rev-runrate"
            label="Revenue run rate"
            value={reportFmt.currency(s.revenue_run_rate_now)}
            delta={s.revenue_run_rate_delta_pct}
            tone="auto"
          />
          <KpiTile
            testid="exec-gp-runrate"
            label="GP run rate"
            value={reportFmt.currency(s.gp_run_rate_now)}
            delta={s.gp_run_rate_delta_pct}
            tone="auto"
          />
          <KpiTile
            testid="exec-lag"
            label="Median approval lag"
            value={s.median_approval_lag_hours == null ? '—' : `${s.median_approval_lag_hours}h`}
            tone={(s.median_approval_lag_hours ?? 0) > 24 ? 'negative' : 'muted'}
            to="/time?filter=submitted"
          />
          <KpiTile
            testid="exec-pending"
            label="Pending review"
            value={reportFmt.num(s.submitted_pending)}
            to="/time?filter=submitted"
          />
          <KpiTile
            testid="exec-approved"
            label="Approved entries"
            value={reportFmt.num(s.approved)}
            to="/time?filter=approved"
          />
          <KpiTile
            testid="exec-rejected"
            label="Rejected entries"
            value={reportFmt.num(s.rejected)}
            tone={s.rejected > 0 ? 'negative' : 'muted'}
            to="/time?filter=rejected"
          />
        </div>
      )}
    </ReportFrame>
  );
}
