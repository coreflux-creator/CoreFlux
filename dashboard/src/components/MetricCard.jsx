/**
 * MetricCard — KPI tile with label, current value, optional comparison
 * deltas (prior period / prior year), optional sparkline, optional
 * onClick that fires a drilldown.
 *
 * Props:
 *   label         (req) — KPI name
 *   value         (req) — current value (number or already-formatted string)
 *   format        (opt) — fn(n) → string (default integer locale)
 *   priorPeriod   (opt) — { value, label? }
 *   priorYear     (opt) — { value, label? }
 *   inverse       (opt) — true for expense KPIs (drops are favourable)
 *   sparkline     (opt) — [{week, amount}] passed to <Sparkline>
 *   sparklineColor(opt)
 *   onClick       (opt) — drill-down handler (renders chevron)
 *   testIdPrefix  (req) — e.g. 'rpt-pnl-kpi-net-income'
 *   tone          (opt) — 'neutral'|'positive'|'negative' (border accent)
 */
import React from 'react';
import { ArrowDown, ArrowUp, Minus, ChevronRight } from 'lucide-react';
import Sparkline from './Sparkline';
import { variance } from '../lib/useReportPeriod';

export default function MetricCard({
  label,
  value,
  format = (n) => Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 0 }),
  priorPeriod = null,
  priorYear   = null,
  inverse     = false,
  sparkline   = null,
  sparklineColor,
  onClick,
  testIdPrefix,
  tone = 'neutral',
}) {
  const clickable = typeof onClick === 'function';
  const accentColor =
    tone === 'positive' ? '#16a34a'
    : tone === 'negative' ? '#dc2626'
    : '#334155';

  const displayValue = typeof value === 'string' ? value : format(value);

  return (
    <div
      data-testid={testIdPrefix}
      onClick={clickable ? onClick : undefined}
      style={{
        ...cardStyle,
        borderLeftColor: accentColor,
        cursor: clickable ? 'pointer' : 'default',
      }}
    >
      <div style={topRowStyle}>
        <span style={labelStyle} data-testid={`${testIdPrefix}-label`}>{label}</span>
        {clickable && (
          <ChevronRight size={14} style={{ color: '#94a3b8' }}
                        data-testid={`${testIdPrefix}-drill`} />
        )}
      </div>

      <div style={valueStyle} data-testid={`${testIdPrefix}-value`}>
        {displayValue}
      </div>

      {(priorPeriod || priorYear) && (
        <div style={comparisonsStyle}>
          {priorPeriod && (
            <DeltaPill testId={`${testIdPrefix}-vs-prior-period`}
                       curr={value} prior={priorPeriod.value}
                       label={priorPeriod.label || 'vs prior period'}
                       inverse={inverse} format={format} />
          )}
          {priorYear && (
            <DeltaPill testId={`${testIdPrefix}-vs-prior-year`}
                       curr={value} prior={priorYear.value}
                       label={priorYear.label || 'vs prior year'}
                       inverse={inverse} format={format} />
          )}
        </div>
      )}

      {sparkline && Array.isArray(sparkline) && sparkline.length > 0 && (
        <div style={{ marginTop: 10 }}>
          <Sparkline data={sparkline}
                     format={format}
                     height={36}
                     color={sparklineColor || accentColor} />
        </div>
      )}
    </div>
  );
}

function DeltaPill({ testId, curr, prior, label, inverse, format }) {
  if (prior === null || prior === undefined) return null;
  const v = variance(curr, prior, { inverse });
  const Icon = v.direction === 'up' ? ArrowUp
            : v.direction === 'down' ? ArrowDown
            : Minus;
  const pctText = v.pct === null ? '∞'
                : v.pct === 0    ? '0%'
                                 : `${v.pct > 0 ? '+' : ''}${v.pct.toFixed(1)}%`;
  return (
    <div data-testid={testId} style={{
      display: 'inline-flex', alignItems: 'center', gap: 4,
      fontSize: 11, color: v.color, fontWeight: 600,
    }}>
      <Icon size={11} />
      <span data-testid={`${testId}-pct`}>{pctText}</span>
      <span style={{ color: '#94a3b8', fontWeight: 400 }}>{label}</span>
      <span style={{ color: '#94a3b8', fontWeight: 400, fontSize: 10 }}
            title={`Prior: ${format(prior)}`}>
        ({format(prior)})
      </span>
    </div>
  );
}

const cardStyle = {
  background: '#fff',
  border: '1px solid #e2e8f0',
  borderLeft: '3px solid #334155',
  borderRadius: 6,
  padding: '12px 14px',
  display: 'flex', flexDirection: 'column', gap: 4,
  transition: 'box-shadow 120ms ease, transform 120ms ease',
};
const topRowStyle = {
  display: 'flex', justifyContent: 'space-between', alignItems: 'center',
};
const labelStyle = {
  fontSize: 11, color: '#64748b', textTransform: 'uppercase',
  letterSpacing: 0.4, fontWeight: 600,
};
const valueStyle = {
  fontSize: 22, fontWeight: 700, color: '#0f172a',
  letterSpacing: '-0.02em',
  lineHeight: 1.15,
};
const comparisonsStyle = {
  display: 'flex', flexDirection: 'column', gap: 4, marginTop: 2,
};
