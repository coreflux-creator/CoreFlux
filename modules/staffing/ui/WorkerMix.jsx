import React, { useMemo, useState } from 'react';
import { useApi } from '../../../dashboard/src/lib/api';

/**
 * Staffing → Profitability → Worker Mix.
 *
 * Stacked bar chart: weekly hours (or cost) broken down by engagement_type
 * (W2 / 1099 / C2C / internal / other). Plus a flag list of workers seen
 * on placements of more than one engagement_type — potential
 * misclassification / 1099-K/W2 reissue triggers.
 */
const COLORS = {
  w2:       { label: 'W2',       color: '#2563eb' },
  c1099:    { label: '1099',     color: '#f59e0b' },
  c2c:      { label: 'C2C',      color: '#a855f7' },
  internal: { label: 'Internal', color: '#10b981' },
  other:    { label: 'Other',    color: '#94a3b8' },
};

export default function WorkerMix() {
  const [metric, setMetric] = useState('cost'); // 'cost' or 'hours'
  const [weeks, setWeeks]   = useState(12);
  const { data, loading, error } = useApi(`/modules/staffing/api/classification_mix.php?weeks=${weeks}`, [weeks]);
  const weeksRows = data?.weeks ?? [];
  const changes   = data?.classification_changes ?? [];

  return (
    <section className="people-directory" data-testid="staffing-worker-mix">
      <header style={{ display:'flex', justifyContent:'space-between', alignItems:'flex-start', marginBottom:'var(--cf-space-3)', flexWrap:'wrap', gap:'var(--cf-space-3)' }}>
        <div>
          <h2 style={{ margin: 0 }}>Worker Classification Mix</h2>
          <p style={{ color:'var(--cf-text-secondary)', margin:'4px 0 0' }}>
            Labor {metric} composition by engagement type. Flags workers with mixed classifications across the window.
          </p>
        </div>
        <div style={{ display:'flex', gap:'var(--cf-space-2)' }}>
          <select value={metric} onChange={e => setMetric(e.target.value)} data-testid="worker-mix-metric"
                  style={{ padding:'4px 8px', borderRadius: 4, border:'1px solid var(--cf-border, #e5e7eb)' }}>
            <option value="cost">By cost</option>
            <option value="hours">By hours</option>
          </select>
          <select value={weeks} onChange={e => setWeeks(parseInt(e.target.value, 10))} data-testid="worker-mix-weeks"
                  style={{ padding:'4px 8px', borderRadius: 4, border:'1px solid var(--cf-border, #e5e7eb)' }}>
            <option value={4}>4 weeks</option>
            <option value={8}>8 weeks</option>
            <option value={12}>12 weeks</option>
            <option value={26}>26 weeks</option>
            <option value={52}>52 weeks</option>
          </select>
        </div>
      </header>

      {loading && <p>Loading…</p>}
      {error && <p className="error">Error: {error.message}</p>}
      {!loading && weeksRows.length === 0 && <p className="empty" data-testid="worker-mix-empty">No labor data in the selected window.</p>}

      {weeksRows.length > 0 && <StackedBars rows={weeksRows} metric={metric} />}

      {weeksRows.length > 0 && <MixLegend rows={weeksRows} metric={metric} />}

      {changes.length > 0 && (
        <section style={{ marginTop:'var(--cf-space-4)' }} data-testid="worker-mix-changes">
          <h3 style={{ marginBottom: 8 }}>⚠ Mixed-classification workers in this window</h3>
          <p style={{ fontSize:'0.85em', color:'var(--cf-text-muted)' }}>
            These workers have been placed under more than one engagement type. Worth confirming whether the classification change was deliberate (and properly papered) — a worker moving from 1099 to W2 mid-year typically requires a re-issue at year-end.
          </p>
          <table className="data-table" data-testid="worker-mix-changes-table">
            <thead><tr><th>Worker</th><th>Types seen</th><th>First placement</th><th>Latest placement</th></tr></thead>
            <tbody>
              {changes.map(c => (
                <tr key={c.person_id} data-testid={`worker-mix-change-${c.person_id}`}>
                  <td><strong>{c.name?.trim() || `Worker #${c.person_id}`}</strong></td>
                  <td><code>{c.types_seen}</code></td>
                  <td>{c.first_start}</td>
                  <td>{c.latest_start}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      )}
    </section>
  );
}

function StackedBars({ rows, metric }) {
  const keys = ['w2','c1099','c2c','internal','other'];
  const fieldOf = (k) => k + '_' + metric;        // e.g. w2_cost, w2_hours

  const { yMax } = useMemo(() => {
    const max = rows.reduce((m, r) => {
      const tot = keys.reduce((s, k) => s + (parseFloat(r[fieldOf(k)]) || 0), 0);
      return Math.max(m, tot);
    }, 0);
    return { yMax: Math.max(max, 1) };
  }, [rows, metric]);

  const W = 720, H = 280, pad = { l: 56, r: 16, t: 12, b: 36 };
  const cw = (W - pad.l - pad.r) / rows.length;
  const barW = Math.max(8, cw * 0.7);
  const yScale = (v) => H - pad.b - ((v / yMax) * (H - pad.t - pad.b));
  const fmt = (v) => metric === 'cost' ? '$' + Math.round(v).toLocaleString() : Math.round(v).toLocaleString() + 'h';

  return (
    <div style={{ overflowX:'auto' }}>
      <svg width={W} height={H} role="img" aria-label="Worker classification mix over time" data-testid="worker-mix-chart"
           style={{ maxWidth:'100%', height:'auto' }}>
        {/* Y-axis grid + labels */}
        {[0, 0.25, 0.5, 0.75, 1.0].map(t => {
          const y = pad.t + (1 - t) * (H - pad.t - pad.b);
          return (
            <g key={t}>
              <line x1={pad.l} x2={W - pad.r} y1={y} y2={y} stroke="#e5e7eb" strokeDasharray={t === 0 ? '' : '3,3'} />
              <text x={pad.l - 6} y={y + 4} textAnchor="end" fontSize="10" fill="#94a3b8">{fmt(yMax * t)}</text>
            </g>
          );
        })}

        {/* Stacked bars */}
        {rows.map((r, i) => {
          const x  = pad.l + i * cw + (cw - barW) / 2;
          let yTop = H - pad.b;
          return (
            <g key={r.week_start}>
              {keys.map(k => {
                const v = parseFloat(r[fieldOf(k)]) || 0;
                if (v <= 0) return null;
                const segH = (v / yMax) * (H - pad.t - pad.b);
                yTop -= segH;
                return (
                  <rect key={k} x={x} y={yTop} width={barW} height={segH}
                        fill={COLORS[k].color} opacity="0.85"
                        data-testid={`worker-mix-bar-${r.week_start}-${k}`}>
                    <title>{`${COLORS[k].label}: ${fmt(v)} (week of ${r.week_start})`}</title>
                  </rect>
                );
              })}
              {/* X label every nth week */}
              {(i % Math.ceil(rows.length / 8) === 0 || i === rows.length - 1) && (
                <text x={x + barW / 2} y={H - pad.b + 16} textAnchor="middle" fontSize="10" fill="#64748b">
                  {String(r.week_start).slice(5)}
                </text>
              )}
            </g>
          );
        })}
      </svg>
    </div>
  );
}

function MixLegend({ rows, metric }) {
  const keys = ['w2','c1099','c2c','internal','other'];
  const totals = keys.map(k => ({
    k, label: COLORS[k].label, color: COLORS[k].color,
    value: rows.reduce((s, r) => s + (parseFloat(r[k + '_' + metric]) || 0), 0)
  }));
  const grand = totals.reduce((s, t) => s + t.value, 0);
  const fmt = (v) => metric === 'cost' ? '$' + Math.round(v).toLocaleString() : Math.round(v).toLocaleString() + 'h';

  return (
    <div style={{ display:'flex', flexWrap:'wrap', gap:'var(--cf-space-3)', marginTop:'var(--cf-space-2)', padding:12, background:'var(--cf-surface-subtle, #f9fafb)', borderRadius: 6 }} data-testid="worker-mix-legend">
      {totals.filter(t => t.value > 0).map(t => {
        const pct = grand > 0 ? Math.round(t.value / grand * 100) : 0;
        return (
          <div key={t.k} style={{ display:'flex', alignItems:'center', gap: 8 }} data-testid={`worker-mix-legend-${t.k}`}>
            <span style={{ display:'inline-block', width: 12, height: 12, background: t.color, borderRadius: 2 }} />
            <strong>{t.label}</strong>
            <span style={{ color:'var(--cf-text-muted)' }}>{fmt(t.value)} · {pct}%</span>
          </div>
        );
      })}
    </div>
  );
}
