/**
 * ComparisonTable — financial-statement row primitive supporting up to
 * three value columns (current / prior period / prior year) plus a
 * variance pill, with an optional drill chevron on each row.
 *
 * Rows are flat OR nested (depth controls indent). Subtotal rows render
 * with bold + top border. Total rows render with double top border.
 *
 * Props:
 *   columns        (req)  — [{key:'current', label:'2026 YTD'}, {key:'prior_period', label:'Prior'}, ...]
 *   rows           (req)  — [{
 *                              code, label, depth=0,
 *                              values: { current, prior_period?, prior_year? },
 *                              kind: 'row'|'subtotal'|'total'  (default 'row'),
 *                              inverse: bool,                  (true for expense lines)
 *                              onDrill: fn() | null,
 *                              testIdPrefix: 'rpt-pnl-revenue-row-4000'
 *                            }]
 *   format         (opt)  — fn(n) → string (default money 2dp)
 *   testIdPrefix   (req)  — e.g. 'rpt-pnl-revenue-table'
 *   showVariance   (opt)  — true (default) — render variance % vs prior_period if present, else vs prior_year
 *   emptyText      (opt)  — default 'No activity'
 */
import React from 'react';
import { ChevronRight } from 'lucide-react';
import { fmtMoney } from '../lib/format';
import { variance } from '../lib/useReportPeriod';

export default function ComparisonTable({
  columns,
  rows,
  format = fmtMoney,
  testIdPrefix,
  showVariance = true,
  emptyText = 'No activity',
}) {
  const baselineKey = columns.find(c => c.key === 'prior_period')?.key
                   ?? columns.find(c => c.key === 'prior_year')?.key
                   ?? null;

  return (
    <table data-testid={testIdPrefix} style={tableStyle}>
      <thead>
        <tr>
          <th style={thLeft}>Code</th>
          <th style={thLeft}>Account</th>
          {columns.map(col => (
            <th key={col.key} style={thRight} data-testid={`${testIdPrefix}-col-${col.key}`}>
              {col.label}
            </th>
          ))}
          {showVariance && baselineKey && (
            <th style={thRight} data-testid={`${testIdPrefix}-col-variance`}>Var %</th>
          )}
          <th style={{ ...thRight, width: 28 }} />
        </tr>
      </thead>
      <tbody>
        {(!rows || rows.length === 0) && (
          <tr>
            <td colSpan={2 + columns.length + (showVariance && baselineKey ? 2 : 1)}
                style={{ ...tdCell, color: '#94a3b8', textAlign: 'center', padding: 18 }}
                data-testid={`${testIdPrefix}-empty`}>
              {emptyText}
            </td>
          </tr>
        )}

        {rows && rows.map((r, idx) => {
          const kind = r.kind || 'row';
          const indent = (r.depth || 0) * 16;
          const isTotal    = kind === 'total';
          const isSubtotal = kind === 'subtotal';
          const trStyle = isTotal
            ? { borderTop: '2px solid #0f172a', borderBottom: '2px solid #0f172a',
                fontWeight: 700, background: '#f8fafc' }
            : isSubtotal
              ? { borderTop: '1px solid #cbd5e1', fontWeight: 600, background: '#fafbfc' }
              : { borderTop: '1px solid #f1f5f9' };

          const curr     = r.values?.current;
          const baseline = baselineKey ? r.values?.[baselineKey] : null;
          const v = (showVariance && baselineKey && baseline !== undefined && baseline !== null)
            ? variance(curr, baseline, { inverse: !!r.inverse })
            : null;

          const clickable = typeof r.onDrill === 'function';

          return (
            <tr key={r.code || `${kind}-${idx}`} style={trStyle}
                data-testid={r.testIdPrefix || `${testIdPrefix}-row-${r.code || idx}`}>
              <td style={tdCell}>
                {r.code ? <code style={{ fontSize: 11, color: '#475569' }}>{r.code}</code> : ''}
              </td>
              <td style={{ ...tdCell, paddingLeft: 10 + indent }}>
                {r.label}
              </td>
              {columns.map(col => (
                <td key={col.key} style={{ ...tdCell, textAlign: 'right',
                                            fontVariantNumeric: 'tabular-nums' }}>
                  {r.values?.[col.key] === undefined || r.values?.[col.key] === null
                    ? <span style={{ color: '#cbd5e1' }}>—</span>
                    : format(r.values[col.key])}
                </td>
              ))}
              {showVariance && baselineKey && (
                <td style={{ ...tdCell, textAlign: 'right',
                              fontVariantNumeric: 'tabular-nums',
                              color: v?.color || '#cbd5e1', fontWeight: 600 }}
                    data-testid={(r.testIdPrefix || `${testIdPrefix}-row-${r.code || idx}`) + '-variance'}>
                  {v === null
                    ? '—'
                    : v.pct === null ? '∞'
                    : `${v.pct > 0 ? '+' : ''}${v.pct.toFixed(1)}%`}
                </td>
              )}
              <td style={tdCell}>
                {clickable && (
                  <button
                    onClick={r.onDrill}
                    data-testid={(r.testIdPrefix || `${testIdPrefix}-row-${r.code || idx}`) + '-drill'}
                    title="Drill into this metric"
                    style={drillBtn}>
                    <ChevronRight size={14} />
                  </button>
                )}
              </td>
            </tr>
          );
        })}
      </tbody>
    </table>
  );
}

const tableStyle = {
  width: '100%', borderCollapse: 'collapse', fontSize: 13,
  background: '#fff',
};
const thLeft = {
  textAlign: 'left', padding: '8px 10px',
  fontSize: 10, textTransform: 'uppercase', letterSpacing: 0.4,
  color: '#64748b', borderBottom: '1px solid #e2e8f0', fontWeight: 600,
};
const thRight = { ...thLeft, textAlign: 'right' };
const tdCell = {
  padding: '7px 10px', color: '#1e293b', verticalAlign: 'top',
};
const drillBtn = {
  background: 'transparent', border: 'none', cursor: 'pointer',
  color: '#0ea5e9', padding: 2, display: 'inline-flex',
};
