import React, { useState, useEffect, useCallback } from 'react';
import { api } from '../lib/api';

/**
 * <PayrollReviewPacket /> — Slice E / Spec §11 ("Payroll Agent")
 *
 * Weekly packet a controller / CFO reviews before approving timesheets.
 * Surfaces 4 rule-based anomalies from
 * `coreflux.detect_timesheet_anomalies`:
 *   - SPIKE          (>1.5x baseline + ≥ 50 hrs)
 *   - ZERO_WEEK      (zero hours where baseline was ≥ 1/wk)
 *   - CATEGORY_DRIFT (billable share dropped > 30 pp)
 *   - OVERLAP        (any person+day with > 24 hrs entered)
 *
 * Mounted at /admin/ai/payroll-review.
 */
export default function PayrollReviewPacket() {
  const [weekStart, setWeekStart] = useState(defaultMonday());
  const [packet, setPacket]       = useState(null);
  const [loading, setLoading]     = useState(false);
  const [error, setError]         = useState(null);

  const load = useCallback(async () => {
    setLoading(true); setError(null);
    try {
      const r = await api.get(`/api/ai/payroll_review.php?week_start=${encodeURIComponent(weekStart)}`);
      setPacket(r.packet || null);
    } catch (e) { setError(e.message || String(e)); setPacket(null); }
    finally { setLoading(false); }
  }, [weekStart]);

  useEffect(() => { load(); }, [load]);

  return (
    <div data-testid="payroll-review-page" style={{ padding: '0 8px' }}>
      <header style={{ marginBottom: 16 }}>
        <h2 style={{ fontSize: 'var(--cf-text-xl)', margin: 0 }}
            data-testid="payroll-review-title">
          Payroll review packet
        </h2>
        <p style={{ color: 'var(--cf-text-secondary)', fontSize: 13, margin: '4px 0 0' }}>
          Rule-based anomalies on top of the timesheet data for one week.
          Resolve each finding before approving payroll for the period.
        </p>
      </header>

      <div style={{ display: 'flex', gap: 8, alignItems: 'flex-end', marginBottom: 12 }}>
        <label style={{ fontSize: 12 }}>Week starting (Monday)
          <input className="input" type="date" value={weekStart}
                 onChange={e => setWeekStart(e.target.value)}
                 data-testid="payroll-review-week-input"
                 style={{ marginLeft: 6 }} />
        </label>
        <button type="button" className="btn btn--ghost"
                disabled={loading}
                onClick={load}
                data-testid="payroll-review-refresh">
          Refresh
        </button>
      </div>

      {error && (
        <div className="alert alert--error"
             data-testid="payroll-review-error"
             style={{ marginBottom: 12 }}>{error}</div>
      )}

      {loading && <p data-testid="payroll-review-loading">Loading…</p>}

      {!loading && packet && (
        <>
          {/* Summary bar */}
          <div data-testid="payroll-review-summary"
               style={{ display: 'flex', gap: 18, marginBottom: 16, padding: 12,
                        background: 'var(--cf-bg-muted, #f8fafc)',
                        border: '1px solid var(--cf-border)', borderRadius: 6 }}>
            <Stat label="Week"   value={`${packet.week_start} – ${packet.week_end}`}
                  testId="payroll-review-summary-week" />
            <Stat label="Scanned" value={String(packet.scanned_people)}
                  testId="payroll-review-summary-scanned" />
            <Stat label="Spikes"          value={String(packet.summary_by_rule?.spike          ?? 0)}
                  testId="payroll-review-summary-spike" />
            <Stat label="Zero weeks"      value={String(packet.summary_by_rule?.zero_week      ?? 0)}
                  testId="payroll-review-summary-zero-week" />
            <Stat label="Category drift"  value={String(packet.summary_by_rule?.category_drift ?? 0)}
                  testId="payroll-review-summary-category-drift" />
            <Stat label="Overlaps"        value={String(packet.summary_by_rule?.overlap        ?? 0)}
                  testId="payroll-review-summary-overlap" />
          </div>

          {packet.note && (
            <div data-testid="payroll-review-note"
                 className="alert alert--info" style={{ marginBottom: 12 }}>
              {packet.note}
            </div>
          )}

          {packet.findings.length === 0 ? (
            <p data-testid="payroll-review-findings-empty"
               style={{ color: 'var(--cf-text-secondary)', fontSize: 13 }}>
              ✓ No anomalies detected for this week. Inbox zero.
            </p>
          ) : (
            <table data-testid="payroll-review-findings"
                   style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
              <thead>
                <tr style={{ background: 'var(--cf-bg-muted, #f8fafc)', textAlign: 'left' }}>
                  <th style={cellTH}>Person</th>
                  <th style={cellTH}>Rule</th>
                  <th style={cellTH}>Severity</th>
                  <th style={cellTH}>Reason</th>
                  <th style={{ ...cellTH, textAlign: 'right' }}>Current</th>
                  <th style={{ ...cellTH, textAlign: 'right' }}>Baseline</th>
                  <th style={{ ...cellTH, textAlign: 'right' }}>Score</th>
                </tr>
              </thead>
              <tbody>
                {packet.findings.map((f, i) => (
                  <tr key={i} data-testid={`payroll-review-finding-${i}`}
                      style={{ background: f.severity === 'high' ? '#fef2f2' : 'transparent' }}>
                    <td style={cellTD}><code>#{f.person_id}</code></td>
                    <td style={cellTD}>
                      <RuleChip rule={f.rule} />
                    </td>
                    <td style={cellTD}>
                      <SeverityChip severity={f.severity} />
                    </td>
                    <td style={cellTD}>{f.reason}</td>
                    <td style={{ ...cellTD, textAlign: 'right', fontFamily: 'ui-monospace, monospace' }}>
                      {f.current_value}
                    </td>
                    <td style={{ ...cellTD, textAlign: 'right', fontFamily: 'ui-monospace, monospace', color: 'var(--cf-text-secondary)' }}>
                      {f.baseline_value}
                    </td>
                    <td style={{ ...cellTD, textAlign: 'right', fontFamily: 'ui-monospace, monospace', fontWeight: 600 }}>
                      {(f.score * 100).toFixed(0)}%
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </>
      )}
    </div>
  );
}

function defaultMonday() {
  // Most-recent fully-elapsed week — Monday of the prior week.
  const d = new Date();
  const day = d.getDay() || 7;
  // Last Monday: go back (day - 1 + 7) days.
  d.setDate(d.getDate() - (day - 1) - 7);
  return d.toISOString().slice(0, 10);
}

function Stat({ label, value, testId }) {
  return (
    <div data-testid={testId}>
      <div style={{ fontSize: 10, color: 'var(--cf-text-secondary)', textTransform: 'uppercase' }}>{label}</div>
      <div style={{ fontSize: 14, fontWeight: 600 }}>{value}</div>
    </div>
  );
}

function RuleChip({ rule }) {
  const labels = {
    spike: 'Spike',
    zero_week: 'Zero week',
    category_drift: 'Category drift',
    overlap: 'Overlap >24h',
  };
  return (
    <span data-testid={`payroll-review-rule-${rule}`}
          style={{
            display: 'inline-block', padding: '1px 6px', borderRadius: 999,
            background: '#e0e7ff', color: '#3730a3', fontSize: 10, fontWeight: 600,
          }}>{labels[rule] || rule}</span>
  );
}

function SeverityChip({ severity }) {
  const colors = {
    low:    { bg: '#dcfce7', fg: '#166534' },
    medium: { bg: '#fef3c7', fg: '#92400e' },
    high:   { bg: '#fee2e2', fg: '#991b1b' },
  };
  const c = colors[severity] || { bg: '#e5e7eb', fg: '#374151' };
  return (
    <span data-testid={`payroll-review-severity-${severity}`}
          style={{
            display: 'inline-block', padding: '1px 6px', borderRadius: 999,
            background: c.bg, color: c.fg, fontSize: 10, fontWeight: 600,
          }}>{severity}</span>
  );
}

const cellTH = { padding: '6px 8px', borderBottom: '1px solid var(--cf-border-muted, #e2e8f0)', fontWeight: 600 };
const cellTD = { padding: '6px 8px', borderBottom: '1px solid var(--cf-border-muted, #f1f5f9)' };
