import React, { useMemo } from 'react';
import { useApi } from '../lib/api';
import Sparkline from './Sparkline';
import { CheckCircle2, AlertTriangle } from 'lucide-react';

/**
 * ApprovalMixTile — CFO dashboard tile showing the channel mix for
 * timesheet approvals over the last N weeks.
 *
 * The headline question this tile answers: "Are tenants leaning on
 * `bulk_pre_approved` (which skips client validation entirely)?"
 * High `bulk_pre_approved` concentration in the most recent week is
 * an early-warning signal for collection-risk concentration.
 *
 * Reads /api/v1/time/approval-mix. Silently hides on error
 * — this is a supplementary tile, not a blocker.
 */
const CHANNEL_META = {
  manual:                 { label: 'Manual',        color: '#2563eb' },
  tokenized_client_email: { label: 'Client email',  color: '#16a34a' },
  bulk_pre_approved:      { label: 'Bulk pre-approved', color: '#f59e0b' },
  _other:                 { label: 'Other',         color: '#94a3b8' },
};

// Concentration threshold — when last week's bulk_pre_approved share
// exceeds this fraction we flag yellow. Tuned empirically: < 40% is
// healthy mix, 40–70% leans on bulk but still has client checks, > 70%
// is mostly-bulk and worth a CFO conversation with ops.
const BULK_WARN_THRESHOLD = 0.4;
const BULK_ALERT_THRESHOLD = 0.7;

export default function ApprovalMixTile() {
  const { data, loading, error } = useApi('/api/v1/time/approval-mix?weeks=12');

  const view = useMemo(() => {
    if (!data || !data.weeks?.length) return null;
    const totalsByWeek = data.totals_by_week || [];
    const lastIdx = totalsByWeek.length - 1;
    const lastWeekTotal = totalsByWeek[lastIdx] || 0;
    const bulkLastWeekPct = data.last_week_pct?.bulk_pre_approved || 0;
    let severity = 'ok';
    if (bulkLastWeekPct >= BULK_ALERT_THRESHOLD) severity = 'alert';
    else if (bulkLastWeekPct >= BULK_WARN_THRESHOLD) severity = 'warn';

    // Series for the layered sparkline — render only channels that
    // contributed at least one approval in the window, in stable order.
    const order = ['manual', 'tokenized_client_email', 'bulk_pre_approved', '_other'];
    const series = order
      .filter(ch => (data.totals_by_channel?.[ch] || 0) > 0)
      .map(ch => ({
        channel: ch,
        meta: CHANNEL_META[ch],
        total: data.totals_by_channel[ch],
        data: (data.channels[ch] || []).map((amount, i) => ({
          week: data.weeks[i],
          amount,
        })),
      }));

    return {
      series,
      severity,
      lastWeekTotal,
      bulkLastWeekPct,
      grandTotal: data.grand_total || 0,
      windowWeeks: data.window_weeks || 12,
      lastWeek: data.weeks[lastIdx],
    };
  }, [data]);

  if (loading) return null;
  if (error || !view || view.grandTotal === 0) return null;

  const severityMeta = view.severity === 'alert'
    ? { color: '#dc2626', icon: AlertTriangle, label: 'High bulk-approval concentration' }
    : view.severity === 'warn'
      ? { color: '#f59e0b', icon: AlertTriangle, label: 'Bulk-approval share rising' }
      : { color: '#16a34a', icon: CheckCircle2, label: 'Healthy channel mix' };
  const SeverityIcon = severityMeta.icon;

  return (
    <section
      data-testid="cfo-approval-mix-tile"
      data-severity={view.severity}
      style={{
        marginTop: 'var(--cf-space-6, 24px)',
        padding: 'var(--cf-space-5, 20px)',
        border: '1px solid var(--cf-border, #e5e7eb)',
        borderLeft: `4px solid ${severityMeta.color}`,
        borderRadius: 8,
        background: 'var(--cf-surface, #ffffff)',
      }}
    >
      <header style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 16, marginBottom: 16 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          <div
            style={{
              width: 40, height: 40, borderRadius: 8,
              background: severityMeta.color + '1a',
              color: severityMeta.color,
              display: 'flex', alignItems: 'center', justifyContent: 'center',
            }}
          >
            <SeverityIcon size={20} />
          </div>
          <div>
            <h3 style={{ margin: 0, fontSize: 14, fontWeight: 600 }}>Approval channel mix</h3>
            <p
              style={{ margin: '2px 0 0', fontSize: 12, color: 'var(--cf-text-secondary, #6b7280)' }}
              data-testid="cfo-approval-mix-summary"
            >
              {severityMeta.label} · {view.grandTotal.toLocaleString()} approvals across {view.windowWeeks} weeks
            </p>
          </div>
        </div>
        <div
          data-testid="cfo-approval-mix-bulk-pct"
          style={{
            textAlign: 'right', fontSize: 11, color: 'var(--cf-text-secondary, #6b7280)',
            lineHeight: 1.3,
          }}
        >
          <div style={{ fontSize: 18, fontWeight: 700, color: severityMeta.color }}>
            {(view.bulkLastWeekPct * 100).toFixed(0)}%
          </div>
          <div>bulk last week ({view.lastWeek})</div>
        </div>
      </header>

      <div
        style={{ display: 'grid', gap: 12, gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))' }}
        data-testid="cfo-approval-mix-channels"
      >
        {view.series.map(s => (
          <div
            key={s.channel}
            data-testid={`cfo-approval-mix-channel-${s.channel}`}
            style={{ display: 'flex', flexDirection: 'column', gap: 4 }}
          >
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', fontSize: 12 }}>
              <span style={{ color: s.meta.color, fontWeight: 600 }}>{s.meta.label}</span>
              <span style={{ color: 'var(--cf-text-secondary, #6b7280)', fontSize: 11 }}>
                {s.total.toLocaleString()}
              </span>
            </div>
            <Sparkline
              data={s.data}
              color={s.meta.color}
              height={36}
              format={(n) => `${n} approvals`}
            />
          </div>
        ))}
      </div>
    </section>
  );
}
