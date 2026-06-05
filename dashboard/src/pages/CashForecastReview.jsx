import React, { useState, useEffect, useCallback } from 'react';
import { api } from '../lib/api';

/**
 * <CashForecastReview /> — Slice E / Spec §11 ("Cash Agent")
 *
 * 13-week cash forecast reviewer. Two-column layout:
 *   left   — historical forecast runs (newest first)
 *   right  — drill-down: per-week bucket table with totals row
 *
 * "Run new forecast" button kicks off coreflux.run_cash_forecast via
 * /api/ai/forecasts.php?action=run.
 *
 * Mounted at /modules/accounting/cash-forecast.
 */
export default function CashForecastReview() {
  const [rows, setRows]         = useState([]);
  const [loading, setLoading]   = useState(true);
  const [error, setError]       = useState(null);
  const [selectedId, setSel]    = useState(null);
  const [detail, setDetail]     = useState(null);
  const [detailLoading, setDL]  = useState(false);
  const [busy, setBusy]         = useState(false);
  const [weeks, setWeeks]       = useState(13);

  const loadList = useCallback(async () => {
    setLoading(true); setError(null);
    try {
      const r = await api.get('/api/ai/forecasts.php');
      setRows(r.forecasts || []);
    } catch (e) { setError(e.message || String(e)); }
    finally { setLoading(false); }
  }, []);

  const loadDetail = useCallback(async (id) => {
    if (!id) { setDetail(null); return; }
    setDL(true);
    try {
      const r = await api.get(`/api/ai/forecasts.php?action=detail&id=${id}`);
      setDetail(r.forecast || null);
    } catch (e) { setError(e.message || String(e)); }
    finally { setDL(false); }
  }, []);

  useEffect(() => { loadList(); },           [loadList]);
  useEffect(() => { loadDetail(selectedId); }, [selectedId, loadDetail]);

  const runNew = async () => {
    setBusy(true); setError(null);
    try {
      const r = await api.post('/api/ai/forecasts.php?action=run', { weeks: parseInt(weeks, 10) || 13 });
      await loadList();
      if (r.forecast?.forecast_id) setSel(r.forecast.forecast_id);
    } catch (e) { setError(e.message || String(e)); }
    finally { setBusy(false); }
  };

  return (
    <div data-testid="cash-forecast-page" style={{ padding: '0 8px' }}>
      <header style={{ marginBottom: 16 }}>
        <h2 style={{ fontSize: 'var(--cf-text-xl)', margin: 0 }}
            data-testid="cash-forecast-title">
          13-week cash forecast
        </h2>
        <p style={{ color: 'var(--cf-text-secondary)', fontSize: 13, margin: '4px 0 0' }}>
          Heuristic forecast: opening cash from <code>accounting_bank_accounts</code>,
          weekly AP outflow from <code>ap_bills.due_date</code>, AR inflow from
          {' '}<code>billing_invoices.due_date</code>, payroll from
          {' '}<code>payroll_runs.pay_date</code>.
        </p>
      </header>

      {error && (
        <div className="alert alert--error"
             data-testid="cash-forecast-error"
             style={{ marginBottom: 12 }}>{error}</div>
      )}

      <div style={{ display: 'flex', gap: 8, marginBottom: 12, alignItems: 'flex-end' }}>
        <label style={{ fontSize: 12 }}>Weeks
          <input className="input" type="number" min="1" max="52" value={weeks}
                 onChange={e => setWeeks(e.target.value)}
                 data-testid="cash-forecast-weeks-input"
                 style={{ marginLeft: 6, width: 70 }} />
        </label>
        <button type="button" className="btn btn--primary"
                disabled={busy}
                onClick={runNew}
                data-testid="cash-forecast-run">
          Run new forecast
        </button>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'minmax(360px, 1fr) 2fr', gap: 16 }}>
        <ForecastList rows={rows} loading={loading}
                      selectedId={selectedId} onSelect={setSel} />
        <ForecastDetail detail={detail} loading={detailLoading} selectedId={selectedId} />
      </div>
    </div>
  );
}

/* ─────────────────────────────────────────────────────── */
function ForecastList({ rows, loading, selectedId, onSelect }) {
  if (loading) return <p data-testid="cash-forecast-list-loading">Loading…</p>;
  if (rows.length === 0) {
    return (
      <p data-testid="cash-forecast-list-empty"
         style={{ color: 'var(--cf-text-secondary)', fontSize: 13 }}>
        No forecasts yet — run the first one above.
      </p>
    );
  }
  return (
    <div data-testid="cash-forecast-list"
         style={{ border: '1px solid var(--cf-border)', borderRadius: 6, overflow: 'hidden' }}>
      {rows.map(r => (
        <button key={r.id}
                type="button"
                onClick={() => onSelect(r.id)}
                data-testid={`cash-forecast-row-${r.id}`}
                style={{
                  display: 'block', width: '100%', textAlign: 'left',
                  padding: '10px 12px', cursor: 'pointer',
                  background: r.id === selectedId ? 'var(--cf-bg-selected, #eff6ff)' : 'transparent',
                  borderBottom: '1px solid var(--cf-border-muted, #f1f5f9)',
                  border: 'none', borderTop: '1px solid var(--cf-border-muted, #f1f5f9)',
                }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8 }}>
            <span style={{ fontWeight: 600, fontSize: 13 }}>
              Run #{r.id} · {r.starting_at} · {r.weeks_count}w
            </span>
            <span style={{ fontFamily: 'ui-monospace, monospace', fontSize: 12,
                           color: r.ending_balance_cents < 0 ? '#dc2626' : '#16a34a' }}>
              {fmtMoney(r.ending_balance_cents, r.currency)}
            </span>
          </div>
          <div style={{ display: 'flex', gap: 8, fontSize: 11, color: 'var(--cf-text-secondary)', marginTop: 4 }}>
            <span>Start {fmtMoney(r.starting_balance_cents, r.currency)}</span>
            {r.min_week_balance_cents !== null && r.min_week_balance_cents < 0 && (
              <span data-testid={`cash-forecast-row-${r.id}-shortfall`} style={{ color: '#dc2626' }}>
                ⚠ low {fmtMoney(r.min_week_balance_cents, r.currency)}
              </span>
            )}
            <span style={{ marginLeft: 'auto' }}>{(r.created_at || '').slice(0, 16).replace('T', ' ')}</span>
          </div>
        </button>
      ))}
    </div>
  );
}

function ForecastDetail({ detail, loading, selectedId }) {
  if (!selectedId) {
    return (
      <div data-testid="cash-forecast-detail-placeholder"
           style={{ padding: 24, color: 'var(--cf-text-secondary)', fontSize: 13,
                    border: '1px dashed var(--cf-border)', borderRadius: 6 }}>
        Select a forecast on the left to drill in.
      </div>
    );
  }
  if (loading) return <p data-testid="cash-forecast-detail-loading">Loading…</p>;
  if (!detail) return <p data-testid="cash-forecast-detail-empty">Forecast not found.</p>;

  const weeks = detail.weeks || [];

  return (
    <div data-testid="cash-forecast-detail"
         style={{ border: '1px solid var(--cf-border)', borderRadius: 6, padding: 16 }}>
      <header style={{ marginBottom: 12 }}>
        <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)' }}>
          Forecast #<code>{detail.id}</code> · {detail.weeks_count} weeks from {detail.starting_at} · {detail.currency}
        </div>
        <div style={{ display: 'flex', gap: 24, marginTop: 4 }}>
          <Stat label="Opening" value={fmtMoney(detail.starting_balance_cents, detail.currency)}
                testId="cash-forecast-detail-opening" />
          <Stat label="Closing" value={fmtMoney(detail.ending_balance_cents, detail.currency)}
                testId="cash-forecast-detail-closing"
                color={detail.ending_balance_cents < 0 ? '#dc2626' : '#16a34a'} />
          {detail.min_week_balance_cents !== null && (
            <Stat label="Weekly low"
                  value={fmtMoney(detail.min_week_balance_cents, detail.currency)}
                  testId="cash-forecast-detail-min"
                  color={detail.min_week_balance_cents < 0 ? '#dc2626' : undefined} />
          )}
        </div>
      </header>

      <table data-testid="cash-forecast-detail-weeks"
             style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
        <thead>
          <tr style={{ background: 'var(--cf-bg-muted, #f8fafc)', textAlign: 'left' }}>
            <th style={cellTH}>Wk</th>
            <th style={cellTH}>Week</th>
            <th style={{ ...cellTH, textAlign: 'right' }}>Opening</th>
            <th style={{ ...cellTH, textAlign: 'right' }}>AR in</th>
            <th style={{ ...cellTH, textAlign: 'right' }}>AP out</th>
            <th style={{ ...cellTH, textAlign: 'right' }}>Payroll</th>
            <th style={{ ...cellTH, textAlign: 'right' }}>Closing</th>
            <th style={cellTH}>Notes</th>
          </tr>
        </thead>
        <tbody>
          {weeks.map((w) => (
            <tr key={w.week_no} data-testid={`cash-forecast-week-${w.week_no}`}
                style={{ background: w.closing_balance_cents < 0 ? '#fef2f2' : 'transparent' }}>
              <td style={cellTD}>{w.week_no}</td>
              <td style={cellTD}><code>{w.week_start}</code></td>
              <td style={{ ...cellTD, textAlign: 'right', fontFamily: 'ui-monospace, monospace' }}>
                {fmtMoney(w.opening_balance_cents, detail.currency)}
              </td>
              <td style={{ ...cellTD, textAlign: 'right', fontFamily: 'ui-monospace, monospace', color: '#16a34a' }}>
                +{fmtMoney(w.ar_inflow_cents, detail.currency)}
              </td>
              <td style={{ ...cellTD, textAlign: 'right', fontFamily: 'ui-monospace, monospace', color: '#dc2626' }}>
                −{fmtMoney(w.ap_outflow_cents, detail.currency)}
              </td>
              <td style={{ ...cellTD, textAlign: 'right', fontFamily: 'ui-monospace, monospace', color: '#dc2626' }}>
                −{fmtMoney(w.payroll_outflow_cents, detail.currency)}
              </td>
              <td style={{ ...cellTD, textAlign: 'right', fontFamily: 'ui-monospace, monospace', fontWeight: 600,
                           color: w.closing_balance_cents < 0 ? '#dc2626' : '#16a34a' }}>
                {fmtMoney(w.closing_balance_cents, detail.currency)}
              </td>
              <td style={{ ...cellTD, fontSize: 11, color: '#7f1d1d' }}>
                {w.notes || ''}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function Stat({ label, value, color, testId }) {
  return (
    <div data-testid={testId}>
      <div style={{ fontSize: 10, color: 'var(--cf-text-secondary)', textTransform: 'uppercase' }}>{label}</div>
      <div style={{ fontFamily: 'ui-monospace, monospace', fontSize: 14, fontWeight: 600, color: color || 'inherit' }}>
        {value}
      </div>
    </div>
  );
}

function fmtMoney(cents, currency) {
  const sign = cents < 0 ? '−' : '';
  const abs  = Math.abs(cents || 0) / 100;
  return `${sign}${currency || 'USD'} ${abs.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

const cellTH = { padding: '6px 8px', borderBottom: '1px solid var(--cf-border-muted, #e2e8f0)', fontWeight: 600 };
const cellTD = { padding: '6px 8px', borderBottom: '1px solid var(--cf-border-muted, #f1f5f9)' };
