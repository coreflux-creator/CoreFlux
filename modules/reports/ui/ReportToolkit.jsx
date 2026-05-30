import React from 'react';
import { Link } from 'react-router-dom';

/**
 * ReportToolkit — shared visual primitives for the Reports module
 * overhaul (P1.11).
 *
 * Spec re-audit: "Reports must be enhanced — more visual, sharp,
 * responsive, with click-to-drill-down on every metric."
 *
 * This file ships the shared primitives so the five existing reports
 * (Executive Snapshot, Client Profitability, Overtime Watch, Rate
 * Spread, Staffing Overview) and any future report can adopt them
 * incrementally without duplicating layout / chrome / drill-link
 * markup.
 *
 * Components:
 *   <ReportFrame>      — sticky header + responsive grid wrapper
 *   <KpiTile>          — refreshed metric card: label, big number,
 *                         optional delta + tone + inline sparkline +
 *                         drill-down `to` href.
 *   <Sparkline>        — pure-SVG, no deps. Renders a 28×80 polyline.
 *   <DrillLink>        — accessible drill affordance ("View N rows →").
 *   <SortableTable>    — column-header click sort, dense rows, drill
 *                         column. Replaces ad-hoc tables in reports.
 *
 * Design constraints:
 *   • No charting deps (reports stay deploy-safe).
 *   • All interactive elements get data-testids interpolated from
 *     a `testid` prop so e2e regression hooks are stable.
 *   • Dark/light tones inherit from CSS vars already used elsewhere
 *     (`--cf-border`, `--cf-surface`, `--cf-text`).
 *   • Responsive — grid collapses to single column < 640px.
 */

export function ReportFrame({ title, subtitle, actions, children, testid }) {
  return (
    <section data-testid={testid} style={{ paddingBottom: 32 }}>
      <header
        style={{
          display: 'flex', flexWrap: 'wrap', gap: 12,
          justifyContent: 'space-between', alignItems: 'flex-end',
          padding: '12px 0 14px',
          borderBottom: '1px solid #e2e8f0',
          marginBottom: 20,
          position: 'sticky', top: 0, zIndex: 5,
          background: 'linear-gradient(180deg, #fff 0%, #fff 88%, rgba(255,255,255,0) 100%)',
        }}
      >
        <div style={{ flex: 1, minWidth: 240 }}>
          <h1 style={{ margin: 0, fontSize: 22, fontWeight: 700,
                       color: '#0f172a', letterSpacing: '-0.01em' }}>
            {title}
          </h1>
          {subtitle && (
            <div style={{ color: '#64748b', fontSize: 13, marginTop: 4 }}>
              {subtitle}
            </div>
          )}
        </div>
        {actions && (
          <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
            {actions}
          </div>
        )}
      </header>
      {children}
    </section>
  );
}

export function KpiTile({
  label, value, sub, delta, tone, spark, to, testid,
}) {
  // Tone resolves to a CSS color used for the big number AND the
  // delta arrow. Numeric `tone="auto"` inspects delta sign.
  const resolvedTone = tone === 'auto'
    ? (Number(delta) >= 0 ? 'positive' : 'negative')
    : tone;
  const valueColor =
    resolvedTone === 'positive' ? '#15803d' :
    resolvedTone === 'negative' ? '#b91c1c' :
    resolvedTone === 'muted'    ? '#64748b' :
                                  'var(--cf-text, #111827)';
  const card = (
    <div
      data-testid={testid}
      style={{
        border: '1px solid #e2e8f0',
        borderLeft: `3px solid ${
          resolvedTone === 'positive' ? '#16a34a'
          : resolvedTone === 'negative' ? '#dc2626'
          : '#334155'
        }`,
        borderRadius: 6, padding: '12px 14px',
        background: '#fff',
        display: 'flex', flexDirection: 'column', gap: 4,
        transition: 'transform 120ms ease, box-shadow 120ms ease',
        cursor: to ? 'pointer' : 'default',
      }}
      onMouseEnter={(e) => {
        if (!to) return;
        e.currentTarget.style.transform = 'translateY(-1px)';
        e.currentTarget.style.boxShadow = '0 4px 12px rgba(15, 23, 42, 0.06)';
      }}
      onMouseLeave={(e) => {
        e.currentTarget.style.transform = 'translateY(0)';
        e.currentTarget.style.boxShadow = 'none';
      }}
    >
      <div style={{
        fontSize: 11, color: '#64748b',
        textTransform: 'uppercase', letterSpacing: 0.4, fontWeight: 600,
      }}>{label}</div>
      <div style={{
        display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', gap: 12,
      }}>
        <div data-testid={testid ? `${testid}-value` : undefined}
             style={{ fontSize: 22, fontWeight: 700, color: valueColor,
                      lineHeight: 1.15, letterSpacing: '-0.02em',
                      fontVariantNumeric: 'tabular-nums' }}>
          {value}
        </div>
        {spark && spark.length > 1 && (
          <Sparkline data={spark} tone={resolvedTone || 'neutral'} testid={testid ? `${testid}-spark` : undefined} />
        )}
      </div>
      {(sub || delta !== undefined) && (
        <div style={{ fontSize: 12, color: '#6b7280', display: 'flex', gap: 8 }}>
          {delta !== undefined && delta !== null && (
            <span
              data-testid={testid ? `${testid}-delta` : undefined}
              style={{
                color: resolvedTone === 'positive' ? '#15803d'
                     : resolvedTone === 'negative' ? '#b91c1c'
                     : '#475569',
                fontWeight: 600,
              }}>
              {Number(delta) > 0 ? '▲' : (Number(delta) < 0 ? '▼' : '·')}{' '}
              {typeof delta === 'number' ? delta.toLocaleString(undefined, { maximumFractionDigits: 1 }) + '%' : delta}
            </span>
          )}
          {sub && <span>{sub}</span>}
        </div>
      )}
      {to && (
        <DrillLink to={to} testid={testid ? `${testid}-drill` : undefined}>
          Drill in →
        </DrillLink>
      )}
    </div>
  );
  return card;
}

export function Sparkline({ data, tone = 'neutral', testid }) {
  const w = 80, h = 28, pad = 2;
  if (!data || data.length < 2) return null;
  const min = Math.min(...data), max = Math.max(...data);
  const span = max - min || 1;
  const step = (w - pad * 2) / (data.length - 1);
  const pts = data.map((d, i) => {
    const x = pad + i * step;
    const y = h - pad - ((d - min) / span) * (h - pad * 2);
    return `${x.toFixed(2)},${y.toFixed(2)}`;
  }).join(' ');
  const stroke =
    tone === 'positive' ? '#15803d' :
    tone === 'negative' ? '#b91c1c' :
                          '#475569';
  return (
    <svg
      data-testid={testid}
      width={w} height={h} viewBox={`0 0 ${w} ${h}`} aria-hidden="true"
      style={{ flexShrink: 0 }}
    >
      <polyline points={pts} fill="none" stroke={stroke} strokeWidth="1.5" />
    </svg>
  );
}

export function DrillLink({ to, children, testid }) {
  // External (http/...) → plain anchor; internal → react-router Link.
  const isExternal = typeof to === 'string' && /^https?:\/\//.test(to);
  const baseStyle = {
    color: '#1d4ed8', fontSize: 12, fontWeight: 500,
    textDecoration: 'none', display: 'inline-block', marginTop: 2,
  };
  if (isExternal) {
    return (
      <a href={to} data-testid={testid} target="_blank" rel="noreferrer" style={baseStyle}>
        {children}
      </a>
    );
  }
  return <Link to={to} data-testid={testid} style={baseStyle}>{children}</Link>;
}

export function SortableTable({ columns, rows, defaultSort, drillFor, testid }) {
  const [sort, setSort] = React.useState(defaultSort || { key: columns[0]?.key, dir: 'desc' });
  const sorted = React.useMemo(() => {
    if (!sort?.key) return rows;
    const k = sort.key, dir = sort.dir === 'asc' ? 1 : -1;
    return [...rows].sort((a, b) => {
      const av = a[k], bv = b[k];
      if (av == null && bv == null) return 0;
      if (av == null) return  1 * dir;
      if (bv == null) return -1 * dir;
      if (typeof av === 'number' && typeof bv === 'number') return (av - bv) * dir;
      return String(av).localeCompare(String(bv)) * dir;
    });
  }, [rows, sort]);
  const cycle = (key) => setSort(s => s?.key === key
    ? { key, dir: s.dir === 'asc' ? 'desc' : 'asc' }
    : { key, dir: 'desc' });
  return (
    <table data-testid={testid} className="data-table" style={{ width: '100%', fontSize: 13 }}>
      <thead>
        <tr>
          {columns.map(c => (
            <th
              key={c.key}
              data-testid={testid ? `${testid}-th-${c.key}` : undefined}
              onClick={() => cycle(c.key)}
              style={{ cursor: 'pointer', userSelect: 'none' }}
            >
              {c.label} {sort?.key === c.key ? (sort.dir === 'asc' ? ' ▲' : ' ▼') : ''}
            </th>
          ))}
          {drillFor && <th></th>}
        </tr>
      </thead>
      <tbody>
        {sorted.map((r, i) => (
          <tr key={r.id ?? i} data-testid={testid ? `${testid}-row-${r.id ?? i}` : undefined}>
            {columns.map(c => (
              <td key={c.key} data-testid={testid ? `${testid}-cell-${r.id ?? i}-${c.key}` : undefined}>
                {c.render ? c.render(r) : r[c.key]}
              </td>
            ))}
            {drillFor && (
              <td>
                <DrillLink to={drillFor(r)} testid={testid ? `${testid}-drill-${r.id ?? i}` : undefined}>
                  →
                </DrillLink>
              </td>
            )}
          </tr>
        ))}
      </tbody>
    </table>
  );
}

export const reportFmt = {
  currency: (n) => `$${Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 0 })}`,
  currencyCents: (c) => `$${(Number(c || 0) / 100).toLocaleString(undefined, { maximumFractionDigits: 2 })}`,
  pct: (n, d = 1) => `${Number(n || 0).toFixed(d)}%`,
  num: (n, d = 0) => Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: d }),
  signed: (n) => { const v = Number(n || 0); return (v > 0 ? '+' : '') + v.toLocaleString(); },
};
