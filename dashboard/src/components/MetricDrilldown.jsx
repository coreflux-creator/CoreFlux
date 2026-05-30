/**
 * MetricDrilldown — generic right-side slide-over for any metric.
 *
 * Wraps arbitrary content (the caller decides what the drill shows —
 * timesheet lines, AP bills, placements, etc.). Use this for non-GL
 * drills; for GL/account drills keep using GlDetailDrilldown.
 *
 * Props:
 *   title          (req) — header text
 *   subtitle       (opt) — small caption above title
 *   onClose        (req) — handler when overlay/X clicked
 *   loading        (opt) — bool — shows "Loading…" instead of children
 *   error          (opt) — string — shows error instead of children
 *   width          (opt) — CSS width (default 'min(960px, 100%)')
 *   testIdPrefix   (opt) — default 'metric-drilldown'
 *   summary        (opt) — JSX rendered as a sticky stat strip below header
 *   children       (req) — body content
 */
import React from 'react';
import { X } from 'lucide-react';

export default function MetricDrilldown({
  title,
  subtitle,
  onClose,
  loading = false,
  error = null,
  width = 'min(960px, 100%)',
  testIdPrefix = 'metric-drilldown',
  summary,
  children,
}) {
  return (
    <div data-testid={`${testIdPrefix}-modal`}
         style={overlayStyle}
         onClick={onClose}>
      <div onClick={(e) => e.stopPropagation()}
           style={{ ...panelStyle, width }}>
        <header style={headerStyle}>
          <div style={{ minWidth: 0, flex: 1 }}>
            {subtitle && (
              <div style={subtitleStyle} data-testid={`${testIdPrefix}-subtitle`}>
                {subtitle}
              </div>
            )}
            <h3 style={titleStyle} data-testid={`${testIdPrefix}-title`}>
              {title}
            </h3>
          </div>
          <button onClick={onClose}
                  data-testid={`${testIdPrefix}-close`}
                  className="btn btn--ghost"
                  style={closeBtnStyle}>
            <X size={14} style={{ marginRight: 4, verticalAlign: 'middle' }} />
            Close
          </button>
        </header>

        {summary && (
          <div style={summaryStyle} data-testid={`${testIdPrefix}-summary`}>
            {summary}
          </div>
        )}

        <div style={bodyStyle}>
          {loading && <p data-testid={`${testIdPrefix}-loading`} style={{ padding: 20 }}>Loading…</p>}
          {error   && <p data-testid={`${testIdPrefix}-error`}
                          style={{ padding: 20, color: '#b91c1c' }}>{error}</p>}
          {!loading && !error && children}
        </div>
      </div>
    </div>
  );
}

const overlayStyle = {
  position: 'fixed', inset: 0, zIndex: 250,
  background: 'rgba(15,23,42,0.45)',
  display: 'flex', justifyContent: 'flex-end',
};
const panelStyle = {
  background: '#fff',
  boxShadow: '-12px 0 40px rgba(15,23,42,0.25)',
  display: 'flex', flexDirection: 'column',
  height: '100%',
};
const headerStyle = {
  padding: 14, borderBottom: '1px solid #e2e8f0',
  display: 'flex', justifyContent: 'space-between', alignItems: 'center',
  gap: 12,
};
const subtitleStyle = {
  fontSize: 11, color: '#64748b',
  textTransform: 'uppercase', letterSpacing: 0.3,
  marginBottom: 2,
};
const titleStyle = {
  margin: 0, fontSize: 18, fontWeight: 700, color: '#0f172a',
  letterSpacing: '-0.01em',
};
const closeBtnStyle = {
  fontSize: 13, whiteSpace: 'nowrap',
};
const summaryStyle = {
  padding: '10px 14px', background: '#f8fafc',
  borderBottom: '1px solid #e2e8f0',
  fontSize: 12, color: '#475569',
  display: 'flex', gap: 14, flexWrap: 'wrap',
};
const bodyStyle = {
  flex: 1, overflow: 'auto',
};
