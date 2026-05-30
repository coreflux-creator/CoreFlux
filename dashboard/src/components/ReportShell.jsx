/**
 * ReportShell — shared layout primitive for every report view.
 *
 * Renders a sticky header with title, subtitle, optional KPI band slot,
 * date-range pickers, comparison-mode toggle, and an actions slot (export,
 * refresh, print). Body is the report content.
 *
 * Mounted by Tier-1/2/3 reports so every page in the suite shares the same
 * visual rhythm — sharp typography, generous whitespace, responsive grid.
 *
 * Props:
 *   title          (req) — page title
 *   subtitle       (opt) — one-liner under the title
 *   testIdPrefix   (req) — e.g. 'rpt-pnl' so children inherit consistent ids
 *   period         (req) — return value of useReportPeriod()
 *   showCompareToggle = true
 *   actions        (opt) — JSX rendered on the top-right (export buttons etc.)
 *   kpis           (opt) — JSX rendered as a KPI band above the report body
 *   children       (req) — the report body
 */
import React from 'react';

const COMPARE_MODES = [
  { id: 'none',         label: 'No comparison' },
  { id: 'prior_period', label: 'vs Prior period' },
  { id: 'prior_year',   label: 'vs Prior year' },
  { id: 'both',         label: 'vs Both' },
];

export default function ReportShell({
  title,
  subtitle,
  testIdPrefix,
  period,
  showCompareToggle = true,
  singleDate = false,
  customControls,
  actions,
  kpis,
  children,
}) {
  return (
    <section data-testid={`${testIdPrefix}-shell`} style={shellStyle}>
      <header data-testid={`${testIdPrefix}-header`} style={headerStyle}>
        <div style={{ flex: 1, minWidth: 240 }}>
          <h1 style={titleStyle} data-testid={`${testIdPrefix}-title`}>{title}</h1>
          {subtitle && (
            <p style={subtitleStyle} data-testid={`${testIdPrefix}-subtitle`}>{subtitle}</p>
          )}
        </div>

        <div style={controlsStyle}>
          {customControls}
          {period && (
            <>
              {!singleDate && (
                <label style={dateLabelStyle}>
                  <span style={dateCapStyle}>From</span>
                  <input type="date" className="input"
                         value={period.from}
                         onChange={(e) => period.setFrom(e.target.value)}
                         data-testid={`${testIdPrefix}-from`}
                         style={dateInputStyle} />
                </label>
              )}
              <label style={dateLabelStyle}>
                <span style={dateCapStyle}>{singleDate ? 'As of' : 'To'}</span>
                <input type="date" className="input"
                       value={period.to}
                       onChange={(e) => period.setTo(e.target.value)}
                       data-testid={`${testIdPrefix}-to`}
                       style={dateInputStyle} />
              </label>
              {showCompareToggle && (
                <label style={dateLabelStyle}>
                  <span style={dateCapStyle}>Compare</span>
                  <select className="input"
                          value={period.compareMode}
                          onChange={(e) => period.setCompareMode(e.target.value)}
                          data-testid={`${testIdPrefix}-compare-mode`}
                          style={selectStyle}>
                    {COMPARE_MODES.map(m => (
                      <option key={m.id} value={m.id}>{m.label}</option>
                    ))}
                  </select>
                </label>
              )}
            </>
          )}
          {actions}
        </div>
      </header>

      {kpis && (
        <div data-testid={`${testIdPrefix}-kpi-band`} style={kpiBandStyle}>
          {kpis}
        </div>
      )}

      <div data-testid={`${testIdPrefix}-body`} style={bodyStyle}>
        {children}
      </div>
    </section>
  );
}

const shellStyle = {
  display: 'flex', flexDirection: 'column', gap: 16,
  paddingBottom: 32,
};
const headerStyle = {
  position: 'sticky', top: 0, zIndex: 5,
  background: 'linear-gradient(180deg, #fff 0%, #fff 88%, rgba(255,255,255,0) 100%)',
  padding: '12px 0 14px',
  display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end',
  gap: 16, flexWrap: 'wrap',
  borderBottom: '1px solid #e2e8f0',
  marginBottom: 4,
};
const titleStyle = {
  margin: 0,
  fontSize: 22, fontWeight: 700,
  color: '#0f172a',
  letterSpacing: '-0.01em',
};
const subtitleStyle = {
  margin: '4px 0 0', fontSize: 13, color: '#64748b',
};
const controlsStyle = {
  display: 'flex', alignItems: 'flex-end', gap: 10, flexWrap: 'wrap',
};
const dateLabelStyle = {
  display: 'flex', flexDirection: 'column', gap: 2,
};
const dateCapStyle = {
  fontSize: 10, color: '#64748b', textTransform: 'uppercase',
  letterSpacing: 0.4, fontWeight: 600,
};
const dateInputStyle = {
  fontSize: 13, padding: '5px 8px',
};
const selectStyle = {
  fontSize: 13, padding: '5px 8px',
};
const kpiBandStyle = {
  display: 'grid',
  gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))',
  gap: 12,
};
const bodyStyle = {
  display: 'flex', flexDirection: 'column', gap: 20,
};
