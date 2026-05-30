/**
 * ReportShell — shared layout primitive for every report view.
 *
 * Renders a sticky header with title, subtitle, optional KPI band slot,
 * date-range pickers, comparison-mode toggle, and an actions slot (export,
 * refresh, print). Body is the report content.
 *
 * Also wires the two cross-report features added in Reports Overhaul
 * follow-up:
 *   • Save Snapshot — `data-testid="${prefix}-save-snapshot"` button
 *     that POSTs the current `snapshotEnvelope` to
 *     /api/admin/reports/save_snapshot.php and toasts the id back.
 *     Hidden when the report doesn't pass `snapshotEnvelope`.
 *   • Recent Drills — `data-testid="${prefix}-recent-drills"` chevron
 *     button that opens a popover listing the operator's last 10
 *     distinct GL drills on this report. Each entry is a one-click
 *     re-open via the `onReplayDrill` callback. Hidden when no
 *     `onReplayDrill` is wired.
 *
 * Mounted by Tier-1/2/3 reports so every page in the suite shares the
 * same visual rhythm — sharp typography, generous whitespace,
 * responsive grid.
 *
 * Props:
 *   title          (req) — page title
 *   subtitle       (opt) — one-liner under the title
 *   testIdPrefix   (req) — e.g. 'rpt-pnl' so children inherit consistent ids
 *   period         (req) — return value of useReportPeriod()
 *   showCompareToggle = true
 *   singleDate     (opt) — hide "From" picker (BS / TB use as_of mode)
 *   customControls (opt) — JSX replacing the date pickers (Tier-2 PeriodSelector)
 *   actions        (opt) — JSX rendered on the top-right (export buttons etc.)
 *   kpis           (opt) — JSX rendered as a KPI band above the report body
 *   snapshotEnvelope (opt) — { params, envelope } — when set, Save Snapshot button shows
 *   onReplayDrill  (opt) — fn({account_code, period_from, period_to, label}) — when set, Recent Drills shows
 *   children       (req) — the report body
 */
import React, { useEffect, useState } from 'react';
import { History, Save } from 'lucide-react';
import { api } from '../lib/api';

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
  snapshotEnvelope = null,
  onReplayDrill = null,
  children,
}) {
  const [flash, setFlash] = useState(null);
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
          {snapshotEnvelope && (
            <SaveSnapshotButton
              testIdPrefix={testIdPrefix}
              reportKey={testIdPrefix}
              snapshotEnvelope={snapshotEnvelope}
              onFlash={setFlash} />
          )}
          {onReplayDrill && (
            <RecentDrillsPicker
              testIdPrefix={testIdPrefix}
              reportKey={testIdPrefix}
              onReplay={onReplayDrill} />
          )}
          {actions}
        </div>
      </header>

      {flash && (
        <div data-testid={`${testIdPrefix}-flash`}
             style={{
               background: flash.kind === 'ok' ? '#dcfce7' : '#fee2e2',
               color:      flash.kind === 'ok' ? '#166534' : '#991b1b',
               border: '1px solid ' + (flash.kind === 'ok' ? '#86efac' : '#fca5a5'),
               borderRadius: 4, padding: '6px 10px', fontSize: 13,
               display: 'flex', justifyContent: 'space-between', alignItems: 'center',
             }}>
          <span>{flash.text}</span>
          <button onClick={() => setFlash(null)}
                  data-testid={`${testIdPrefix}-flash-close`}
                  style={{ background: 'transparent', border: 'none', cursor: 'pointer',
                           color: 'inherit', fontSize: 16, padding: 0 }}>
            ×
          </button>
        </div>
      )}

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

// ---------------------------------------------------------------------------
// Save Snapshot — captures the current data envelope as an audit row.
// ---------------------------------------------------------------------------
function SaveSnapshotButton({ testIdPrefix, reportKey, snapshotEnvelope, onFlash }) {
  const [busy, setBusy] = useState(false);
  return (
    <button
      data-testid={`${testIdPrefix}-save-snapshot`}
      onClick={async () => {
        if (busy) return;
        setBusy(true);
        try {
          const label = (snapshotEnvelope.label
                          || `${reportKey} · ${new Date().toISOString().slice(0, 16).replace('T', ' ')}`);
          const r = await api.post('/api/admin/reports/save_snapshot.php', {
            report_key: reportKey,
            label,
            params:    snapshotEnvelope.params   || {},
            envelope:  snapshotEnvelope.envelope || {},
          });
          onFlash?.({ kind: 'ok', text: `Snapshot saved · #${r.id} — ${r.label}` });
        } catch (e) {
          onFlash?.({ kind: 'err', text: 'Snapshot failed: ' + (e?.message || e) });
        } finally {
          setBusy(false);
        }
      }}
      className="btn"
      style={{
        ...iconButton,
        opacity: busy ? 0.6 : 1, cursor: busy ? 'wait' : 'pointer',
      }}
      title="Save a snapshot of this report for audit"
    >
      <Save size={13} style={{ verticalAlign: '-2px', marginRight: 4 }} />
      {busy ? 'Saving…' : 'Save snapshot'}
    </button>
  );
}

// ---------------------------------------------------------------------------
// Recent Drills — popover surfacing the operator's last N distinct drills
// on this report, with one-click replay.
// ---------------------------------------------------------------------------
function RecentDrillsPicker({ testIdPrefix, reportKey, onReplay }) {
  const [open, setOpen]     = useState(false);
  const [items, setItems]   = useState([]);
  const [loaded, setLoaded] = useState(false);

  useEffect(() => {
    if (!open || loaded) return;
    api.get(`/api/admin/reports/log_drilldown.php?report_key=${encodeURIComponent(reportKey)}&limit=10`)
       .then(r => setItems(Array.isArray(r?.recent) ? r.recent : []))
       .catch(() => setItems([]))
       .finally(() => setLoaded(true));
  }, [open, loaded, reportKey]);

  return (
    <div style={{ position: 'relative' }}>
      <button
        data-testid={`${testIdPrefix}-recent-drills`}
        onClick={() => setOpen(v => !v)}
        className="btn"
        style={iconButton}
        title="Recent drill-throughs on this report"
      >
        <History size={13} style={{ verticalAlign: '-2px', marginRight: 4 }} />
        Recent drills
      </button>
      {open && (
        <div data-testid={`${testIdPrefix}-recent-drills-popover`} style={popoverStyle}>
          <div style={popoverHeaderStyle}>
            <span>Recent drills</span>
            <button onClick={() => setOpen(false)}
                    data-testid={`${testIdPrefix}-recent-drills-close`}
                    style={popoverCloseStyle}>×</button>
          </div>
          {!loaded && (
            <div style={{ padding: 12, color: '#64748b', fontSize: 12 }}
                 data-testid={`${testIdPrefix}-recent-drills-loading`}>
              Loading…
            </div>
          )}
          {loaded && items.length === 0 && (
            <div style={{ padding: 12, color: '#94a3b8', fontSize: 12 }}
                 data-testid={`${testIdPrefix}-recent-drills-empty`}>
              No drills yet on this report.
            </div>
          )}
          {items.map((it, i) => (
            <button
              key={i}
              data-testid={`${testIdPrefix}-recent-drills-item-${i}`}
              onClick={() => {
                onReplay({
                  account_code: it.account_code,
                  period_from:  it.period_from,
                  period_to:    it.period_to,
                  label:        it.label,
                });
                setOpen(false);
              }}
              style={popoverItemStyle}
            >
              <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8 }}>
                <strong style={{ color: '#0f172a', fontSize: 13 }}>
                  {it.label || it.account_code || '(metric)'}
                </strong>
                {it.open_count > 1 && (
                  <span style={{ fontSize: 10, color: '#64748b',
                                 background: '#f1f5f9', padding: '1px 6px', borderRadius: 8 }}>
                    ×{it.open_count}
                  </span>
                )}
              </div>
              <div style={{ fontSize: 11, color: '#64748b', marginTop: 2 }}>
                {it.account_code && <code style={{ marginRight: 6 }}>{it.account_code}</code>}
                {it.period_from} → {it.period_to}
              </div>
            </button>
          ))}
        </div>
      )}
    </div>
  );
}

const iconButton = {
  fontSize: 12, padding: '5px 10px', fontWeight: 600,
  background: '#fff', border: '1px solid #cbd5e1', borderRadius: 4,
  color: '#0f172a', cursor: 'pointer',
};
const popoverStyle = {
  position: 'absolute', top: '110%', right: 0, zIndex: 30,
  background: '#fff', border: '1px solid #e2e8f0',
  borderRadius: 6, boxShadow: '0 12px 32px rgba(15,23,42,0.12)',
  minWidth: 280, maxWidth: 360, maxHeight: 360, overflow: 'auto',
};
const popoverHeaderStyle = {
  padding: '8px 12px', borderBottom: '1px solid #e2e8f0',
  fontSize: 11, fontWeight: 700, color: '#475569',
  textTransform: 'uppercase', letterSpacing: 0.4,
  display: 'flex', justifyContent: 'space-between', alignItems: 'center',
};
const popoverCloseStyle = {
  background: 'transparent', border: 'none', cursor: 'pointer',
  fontSize: 16, color: '#64748b', padding: 0, lineHeight: 1,
};
const popoverItemStyle = {
  width: '100%', textAlign: 'left',
  padding: '8px 12px', background: 'transparent',
  border: 'none', borderTop: '1px solid #f1f5f9', cursor: 'pointer',
};
