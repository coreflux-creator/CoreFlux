import React, { useMemo, useState } from 'react';
import { TrendingUp } from 'lucide-react';
import { useApi } from '../lib/api';

/**
 * RevenueStreamWidget — CFO Dashboard tile that splits GMV by source.
 *
 * Backend: GET /api/cfo_revenue_stream.php?weeks=N
 *
 * Renders:
 *   - Headline pie of the four buckets (T&M, Engagements, Manual, QBO recon).
 *   - Stacked-bar week-over-week trend.
 *   - Period picker (4 / 8 / 13 / 26 weeks).
 *   - "% from fixed-fee" pulse — useful KPI for shifting toward
 *     predictable revenue.
 */
export default function RevenueStreamWidget() {
  const [weeks, setWeeks] = useState(4);
  const { data, loading, error } = useApi(`/api/cfo_revenue_stream.php?weeks=${weeks}`, [weeks]);
  const totals = data?.totals || {};
  const weekly = data?.weekly || [];
  const total  = Number(totals.total || 0);

  const fixedFeePct = total > 0 ? (totals.fixed_fee / total) * 100 : 0;

  const buckets = useMemo(() => ([
    { key: 'tm',         label: 'T&M billing',          value: Number(totals.tm        || 0), color: '#0f172a' },
    { key: 'fixed_fee',  label: 'Fixed-fee engagements',value: Number(totals.fixed_fee || 0), color: '#7c3aed' },
    { key: 'manual',     label: 'Manual invoices',      value: Number(totals.manual    || 0), color: '#2563eb' },
    { key: 'qbo_recon',  label: 'QBO recon’d',          value: Number(totals.qbo_recon || 0), color: '#16a34a' },
  ]), [totals]);

  const maxWeek = useMemo(() => weekly.reduce(
    (m, w) => Math.max(m, Number(w.tm || 0) + Number(w.fixed_fee || 0) + Number(w.manual || 0) + Number(w.qbo_recon || 0)),
    0
  ), [weekly]);

  return (
    <section
      data-testid="cfo-revenue-stream"
      style={{
        gridColumn: '1 / -1',
        background: '#fff', border: '1px solid #e2e8f0', borderRadius: 12,
        padding: 20, marginTop: 16,
      }}
    >
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <TrendingUp size={18} />
          <div>
            <h3 style={{ margin: 0, fontSize: 15, fontWeight: 700 }}>Revenue stream mix</h3>
            <p style={{ margin: '2px 0 0', fontSize: 11, color: '#6b7280' }}>
              Where your invoiced revenue is coming from. Last {weeks} weeks.
            </p>
          </div>
        </div>
        <div style={{ display: 'flex', gap: 4 }} data-testid="cfo-revenue-stream-weeks">
          {[4, 8, 13, 26].map((w) => (
            <button
              key={w}
              type="button"
              onClick={() => setWeeks(w)}
              data-testid={`cfo-revenue-stream-weeks-${w}`}
              style={{
                padding: '4px 10px', borderRadius: 4, fontSize: 11, fontWeight: 600,
                border: '1px solid',
                borderColor: weeks === w ? '#0f172a' : '#d1d5db',
                background:  weeks === w ? '#0f172a' : '#fff',
                color:       weeks === w ? '#fff'    : '#374151',
                cursor: 'pointer',
              }}
            >{w}w</button>
          ))}
        </div>
      </header>

      {loading && <div data-testid="cfo-revenue-stream-loading" style={{ fontSize: 12, color: '#6b7280' }}>Loading…</div>}
      {error && <div data-testid="cfo-revenue-stream-error" style={{ fontSize: 12, color: '#991b1b' }}>{error?.message || String(error)}</div>}

      {!loading && !error && (
        <div style={{ display: 'grid', gridTemplateColumns: '240px 1fr', gap: 24, alignItems: 'flex-start' }}>
          {/* Donut + legend */}
          <div>
            <Donut buckets={buckets} total={total} />
            <ul data-testid="cfo-revenue-stream-legend" style={{ listStyle: 'none', padding: 0, margin: '12px 0 0' }}>
              {buckets.map((b) => {
                const pct = total > 0 ? (b.value / total) * 100 : 0;
                return (
                  <li
                    key={b.key}
                    data-testid={`cfo-revenue-stream-bucket-${b.key}`}
                    style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '4px 0', fontSize: 12 }}
                  >
                    <span style={{ width: 10, height: 10, borderRadius: 2, background: b.color, flexShrink: 0 }} />
                    <span style={{ flex: 1 }}>{b.label}</span>
                    <span style={{ fontVariantNumeric: 'tabular-nums', color: '#6b7280' }}>{pct.toFixed(0)}%</span>
                    <span style={{ fontVariantNumeric: 'tabular-nums', minWidth: 70, textAlign: 'right', fontWeight: 600 }}>
                      ${b.value.toLocaleString(undefined, { maximumFractionDigits: 0 })}
                    </span>
                  </li>
                );
              })}
            </ul>
            <div data-testid="cfo-revenue-stream-fixed-pulse" style={{
              marginTop: 12, padding: '8px 10px', borderRadius: 6,
              background: fixedFeePct >= 30 ? '#ecfdf5' : '#fef3c7',
              fontSize: 11, color: fixedFeePct >= 30 ? '#065f46' : '#92400e',
            }}>
              <strong>{fixedFeePct.toFixed(0)}%</strong> from fixed-fee engagements
              {fixedFeePct >= 30 ? ' — strong recurring base.' : ' — opportunity to shift toward predictable revenue.'}
            </div>
          </div>

          {/* Stacked bar trend */}
          <div data-testid="cfo-revenue-stream-trend">
            <div style={{ fontSize: 11, fontWeight: 600, color: '#6b7280', marginBottom: 8 }}>Weekly trend</div>
            {weekly.length === 0 ? (
              <div data-testid="cfo-revenue-stream-trend-empty" style={{ fontSize: 12, color: '#6b7280', padding: 12 }}>
                No invoices in the selected window.
              </div>
            ) : (
              <div style={{ display: 'flex', alignItems: 'flex-end', gap: 6, height: 160, paddingBottom: 24, position: 'relative' }}>
                {weekly.map((w) => {
                  const wTotal = Number(w.tm || 0) + Number(w.fixed_fee || 0) + Number(w.manual || 0) + Number(w.qbo_recon || 0);
                  const h = maxWeek > 0 ? (wTotal / maxWeek) * 140 : 0;
                  return (
                    <div
                      key={w.week}
                      data-testid={`cfo-revenue-stream-bar-${w.week}`}
                      style={{ flex: 1, minWidth: 18, display: 'flex', flexDirection: 'column', alignItems: 'center' }}
                    >
                      <div
                        title={`${w.week}: $${wTotal.toLocaleString(undefined, { maximumFractionDigits: 0 })}`}
                        style={{
                          width: '100%', maxWidth: 38, height: Math.max(2, h),
                          borderRadius: '4px 4px 0 0',
                          background: '#f1f5f9',
                          display: 'flex', flexDirection: 'column-reverse', overflow: 'hidden',
                        }}
                      >
                        {buckets.map((b) => {
                          const v = Number(w[b.key] || 0);
                          const segH = wTotal > 0 ? (v / wTotal) * 100 : 0;
                          return v > 0 ? (
                            <div key={b.key} style={{ height: `${segH}%`, background: b.color }} />
                          ) : null;
                        })}
                      </div>
                      <div style={{ fontSize: 9, color: '#6b7280', marginTop: 4, textAlign: 'center', lineHeight: 1.2 }}>
                        W{w.week.slice(-2)}
                      </div>
                    </div>
                  );
                })}
              </div>
            )}
          </div>
        </div>
      )}
    </section>
  );
}

function Donut({ buckets, total }) {
  const size = 140;
  const radius = 56;
  const innerR = 36;
  const cx = size / 2, cy = size / 2;
  if (total <= 0) {
    return (
      <svg width={size} height={size} data-testid="cfo-revenue-stream-donut-empty">
        <circle cx={cx} cy={cy} r={radius} fill="#f1f5f9" />
        <circle cx={cx} cy={cy} r={innerR} fill="#fff" />
        <text x={cx} y={cy} textAnchor="middle" dominantBaseline="middle" fontSize="11" fill="#6b7280">No data</text>
      </svg>
    );
  }
  let start = -Math.PI / 2;
  const segments = buckets.filter((b) => b.value > 0).map((b) => {
    const angle = (b.value / total) * Math.PI * 2;
    const x1 = cx + Math.cos(start) * radius;
    const y1 = cy + Math.sin(start) * radius;
    const x2 = cx + Math.cos(start + angle) * radius;
    const y2 = cy + Math.sin(start + angle) * radius;
    const large = angle > Math.PI ? 1 : 0;
    const d = `M ${cx} ${cy} L ${x1} ${y1} A ${radius} ${radius} 0 ${large} 1 ${x2} ${y2} Z`;
    start += angle;
    return { d, color: b.color, key: b.key };
  });
  return (
    <svg width={size} height={size} data-testid="cfo-revenue-stream-donut">
      {segments.map((s) => (
        <path key={s.key} d={s.d} fill={s.color} />
      ))}
      <circle cx={cx} cy={cy} r={innerR} fill="#fff" />
      <text x={cx} y={cy - 2} textAnchor="middle" dominantBaseline="middle" fontSize="11" fill="#6b7280">Total</text>
      <text x={cx} y={cy + 12} textAnchor="middle" dominantBaseline="middle" fontSize="13" fontWeight="700" fill="#0f172a">
        ${total >= 1000 ? `${(total / 1000).toFixed(1)}k` : total.toFixed(0)}
      </text>
    </svg>
  );
}
