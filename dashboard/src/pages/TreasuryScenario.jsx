import React, { useState, useMemo } from 'react';
import { api } from '../lib/api';
import { Wallet, Plus, Trash2, TrendingUp, TrendingDown, AlertTriangle, FlaskConical } from 'lucide-react';

/**
 * Treasury What-If Scenario Builder.
 *
 * Operator stacks a list of hypothetical inflows/outflows on top of the
 * baseline forecast and sees the combined runway impact in one view.
 * Same projection engine the Liquidity Forecast and per-bill what-if
 * panels use — different surface, different question.
 */
const today = () => new Date().toISOString().slice(0, 10);

const fmt = (n) =>
  n == null ? '—' : '$' + Number(n).toLocaleString(undefined, { maximumFractionDigits: 0 });

const fmtSigned = (n) => {
  if (n == null) return '—';
  const v = Number(n);
  const sign = v >= 0 ? '+' : '';
  return sign + '$' + Math.abs(v).toLocaleString(undefined, { maximumFractionDigits: 0 });
};

export default function TreasuryScenario() {
  const [days, setDays] = useState(90);
  const [events, setEvents] = useState([]);
  const [draft, setDraft] = useState({ kind: 'outflow', amount: '', date: today(), label: '' });
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  const run = async (eventList = events, daysVal = days) => {
    setLoading(true); setError(null);
    try {
      const res = await api.post('/api/treasury_scenario.php', { days: daysVal, events: eventList });
      setData(res);
    } catch (e) {
      setError(e);
    } finally {
      setLoading(false);
    }
  };

  // Auto-run baseline once on mount so the operator immediately sees their
  // current cash trajectory before adding any events.
  React.useEffect(() => { run([], days); /* eslint-disable-next-line */ }, []);

  const addEvent = () => {
    const amount = parseFloat(draft.amount);
    if (!amount || amount <= 0) {
      setError(new Error('Amount must be a positive number'));
      return;
    }
    const next = [...events, {
      kind:   draft.kind,
      amount,
      date:   draft.date || today(),
      label:  draft.label?.trim() || (draft.kind === 'inflow' ? 'Inflow' : 'Outflow'),
    }];
    setEvents(next);
    setDraft({ kind: draft.kind, amount: '', date: today(), label: '' });
    run(next, days);
  };

  const removeEvent = (idx) => {
    const next = events.filter((_, i) => i !== idx);
    setEvents(next);
    run(next, days);
  };

  const onWindowChange = (newDays) => {
    setDays(newDays);
    run(events, newDays);
  };

  const baseline  = data?.baseline  || {};
  const simulated = data?.simulated || {};
  const delta     = data?.delta     || {};
  const guards    = data?.guards    || {};

  // Bar-chart prep: walk daily closings for both series so we can visualise
  // the divergence the events introduce.
  const chart = useMemo(() => {
    const b = baseline.daily || [];
    const s = simulated.daily || [];
    const all = [...b.map(d => d.closing), ...s.map(d => d.closing), 0];
    const lo = Math.min(...all);
    const hi = Math.max(...all);
    const span = (hi - lo) || 1;
    return { b, s, lo, hi, span };
  }, [baseline, simulated]);

  return (
    <section data-testid="treasury-scenario-page" style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 12, flexWrap: 'wrap' }}>
        <div>
          <h2 style={{ margin: 0, display: 'flex', alignItems: 'center', gap: 8 }}>
            <FlaskConical size={20} color="#7c3aed" /> What-If Scenario Builder
          </h2>
          <p style={{ color: '#64748b', margin: '4px 0 0', fontSize: 13 }}>
            Stack hypothetical inflows and outflows on top of your live forecast to see the combined runway impact before committing.
          </p>
        </div>
        <select data-testid="scenario-window-select"
                value={days} onChange={(e) => onWindowChange(parseInt(e.target.value, 10))}
                className="input" style={{ fontSize: 13, padding: '4px 8px' }}>
          <option value={30}>30 days</option>
          <option value={60}>60 days</option>
          <option value={90}>90 days</option>
          <option value={180}>180 days</option>
        </select>
      </header>

      {error && <p className="error" data-testid="scenario-error">{error.message}</p>}

      {guards.has_bank_accounts === false && (
        <div data-testid="scenario-no-banks-nudge"
             style={{ padding: 12, background: '#fffbeb', border: '1px solid #fcd34d', borderRadius: 8, fontSize: 13, color: '#92400e' }}>
          ⚠ No active bank accounts found — the projection starts from a $0 cash position. Connect a bank to anchor it to your real balance.
        </div>
      )}

      {/* Add-event composer */}
      <div data-testid="scenario-event-composer"
           style={{ padding: 14, border: '1px solid #c4b5fd', borderRadius: 10, background: 'linear-gradient(135deg,#f5f3ff,#ecfeff)' }}>
        <strong style={{ fontSize: 13, color: '#5b21b6' }}>Add a what-if event</strong>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap', marginTop: 8 }}>
          <select data-testid="scenario-event-kind"
                  value={draft.kind} onChange={(e) => setDraft({ ...draft, kind: e.target.value })}
                  className="input" style={{ fontSize: 13, padding: '4px 8px' }}>
            <option value="outflow">Outflow (pay out)</option>
            <option value="inflow">Inflow (receive)</option>
          </select>
          <input data-testid="scenario-event-amount"
                 type="number" step="0.01" min="0"
                 value={draft.amount} placeholder="Amount"
                 onChange={(e) => setDraft({ ...draft, amount: e.target.value })}
                 className="input" style={{ fontSize: 13, padding: '4px 8px', width: 140 }} />
          <input data-testid="scenario-event-date"
                 type="date" value={draft.date} min={today()}
                 onChange={(e) => setDraft({ ...draft, date: e.target.value })}
                 className="input" style={{ fontSize: 13, padding: '4px 8px' }} />
          <input data-testid="scenario-event-label"
                 type="text" value={draft.label} placeholder="Label (optional)"
                 onChange={(e) => setDraft({ ...draft, label: e.target.value })}
                 className="input" style={{ fontSize: 13, padding: '4px 8px', flex: 1, minWidth: 180 }} />
          <button data-testid="scenario-event-add"
                  onClick={addEvent} className="btn btn--primary"
                  style={{ fontSize: 13, padding: '6px 12px', background: '#7c3aed', color: '#fff' }}>
            <Plus size={14} style={{ verticalAlign: 'middle', marginRight: 4 }} /> Add
          </button>
        </div>
      </div>

      {/* Stack of events */}
      {events.length > 0 && (
        <div data-testid="scenario-event-stack"
             style={{ border: '1px solid #e2e8f0', borderRadius: 10, overflow: 'hidden' }}>
          <table className="data-table" style={{ width: '100%', fontSize: 13 }}>
            <thead style={{ background: '#f8fafc' }}>
              <tr>
                <th style={{ textAlign: 'left', padding: '8px 12px' }}>Kind</th>
                <th style={{ textAlign: 'left', padding: '8px 12px' }}>Date</th>
                <th style={{ textAlign: 'right', padding: '8px 12px' }}>Amount</th>
                <th style={{ textAlign: 'left', padding: '8px 12px' }}>Label</th>
                <th style={{ width: 40 }}></th>
              </tr>
            </thead>
            <tbody>
              {events.map((e, idx) => (
                <tr key={idx} data-testid={`scenario-event-row-${idx}`}>
                  <td style={{ padding: '6px 12px' }}>
                    {e.kind === 'inflow'
                      ? <span style={{ color: '#065f46' }}><TrendingUp size={12} style={{ verticalAlign: 'middle' }} /> Inflow</span>
                      : <span style={{ color: '#b91c1c' }}><TrendingDown size={12} style={{ verticalAlign: 'middle' }} /> Outflow</span>}
                  </td>
                  <td style={{ padding: '6px 12px' }}>{e.date}</td>
                  <td style={{ padding: '6px 12px', textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{fmt(e.amount)}</td>
                  <td style={{ padding: '6px 12px', color: '#475569' }}>{e.label}</td>
                  <td style={{ padding: '6px 12px' }}>
                    <button data-testid={`scenario-event-remove-${idx}`}
                            onClick={() => removeEvent(idx)}
                            style={{ background: 'transparent', border: 'none', color: '#94a3b8', cursor: 'pointer' }}
                            title="Remove">
                      <Trash2 size={14} />
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* KPI tiles — baseline vs simulated */}
      {data && (
        <div data-testid="scenario-summary-tiles"
             style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 12 }}>
          <Tile label="Starting cash" value={fmt(baseline.starting_cash)} testid="scenario-tile-starting" />
          <Tile label="Ending cash (baseline)" value={fmt(baseline.ending_cash)} testid="scenario-tile-baseline-end" />
          <Tile label="Ending cash (simulated)" value={fmt(simulated.ending_cash)}
                tone={(simulated.ending_cash ?? 0) < (baseline.ending_cash ?? 0) ? 'red' : 'green'}
                testid="scenario-tile-simulated-end" />
          <Tile label="Lowest-balance shift"
                value={fmtSigned(delta.lowest_balance_shift)}
                tone={(delta.lowest_balance_shift ?? 0) < 0 ? 'red' : 'green'}
                testid="scenario-tile-shift" />
          <Tile label="Net event impact"
                value={fmtSigned(delta.net_event_impact)}
                tone={(delta.net_event_impact ?? 0) < 0 ? 'red' : 'green'}
                testid="scenario-tile-net-impact" />
        </div>
      )}

      {/* Runway alert */}
      {delta.runway_days_lost > 0 && (
        <div data-testid="scenario-runway-alert"
             style={{ padding: 12, background: '#fef2f2', border: '1px solid #fca5a5', borderRadius: 8, fontSize: 13, color: '#991b1b', display: 'flex', alignItems: 'center', gap: 8 }}>
          <AlertTriangle size={16} />
          <span>
            Your scenario shortens runway by <strong>{delta.runway_days_lost} day{delta.runway_days_lost === 1 ? '' : 's'}</strong>.
            {simulated.runway_days_to_zero !== null && (
              <> Balance projected to cross zero in <strong>{simulated.runway_days_to_zero} day{simulated.runway_days_to_zero === 1 ? '' : 's'}</strong>.</>
            )}
          </span>
        </div>
      )}
      {data && delta.crosses_zero === false && events.length > 0 && (
        <div data-testid="scenario-safe-banner"
             style={{ padding: 12, background: '#f0fdf4', border: '1px solid #86efac', borderRadius: 8, fontSize: 13, color: '#065f46' }}>
          ✓ Your cash stays positive across the {data.window_days}-day horizon even with all {events.length} event{events.length === 1 ? '' : 's'} applied.
        </div>
      )}

      {/* Dual-line bar chart — baseline (slate) vs simulated (purple) */}
      {data && chart.b.length > 0 && (
        <div data-testid="scenario-chart"
             style={{ padding: 16, border: '1px solid #e2e8f0', borderRadius: 10, background: '#fff' }}>
          <div style={{ fontSize: 12, color: '#475569', marginBottom: 8, display: 'flex', gap: 16, alignItems: 'center' }}>
            <span><span style={{ display: 'inline-block', width: 10, height: 10, background: '#94a3b8', marginRight: 4, verticalAlign: 'middle' }} /> Baseline</span>
            <span><span style={{ display: 'inline-block', width: 10, height: 10, background: '#7c3aed', marginRight: 4, verticalAlign: 'middle' }} /> Simulated</span>
          </div>
          <div style={{ position: 'relative', height: 140, display: 'flex', gap: 1, alignItems: 'flex-end', borderTop: '1px dashed #e2e8f0', borderBottom: '1px dashed #e2e8f0' }}>
            {chart.b.map((bv, idx) => {
              const sv = chart.s[idx]?.closing ?? bv;
              const bh = ((bv - chart.lo) / chart.span) * 130;
              const sh = ((sv - chart.lo) / chart.span) * 130;
              return (
                <div key={idx} style={{ flex: 1, position: 'relative', height: 130 }}>
                  <div style={{
                    position: 'absolute', bottom: 0, left: '15%', width: '35%',
                    height: Math.max(2, Math.abs(bh)) + 'px',
                    background: bv < 0 ? '#fca5a5' : '#94a3b8',
                  }} />
                  <div style={{
                    position: 'absolute', bottom: 0, left: '50%', width: '35%',
                    height: Math.max(2, Math.abs(sh)) + 'px',
                    background: sv < 0 ? '#dc2626' : '#7c3aed',
                  }} />
                </div>
              );
            })}
          </div>
          <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 11, color: '#94a3b8', marginTop: 4 }}>
            <span>{chart.b[0]?.date}</span>
            <span>{chart.b[chart.b.length - 1]?.date}</span>
          </div>
        </div>
      )}

      {loading && <p data-testid="scenario-loading" style={{ fontSize: 12, color: '#64748b' }}>Projecting…</p>}
    </section>
  );
}

function Tile({ label, value, tone, testid }) {
  const colorMap = { red: '#b91c1c', green: '#065f46' };
  return (
    <div data-testid={testid}
         style={{ padding: 14, border: '1px solid #e2e8f0', borderRadius: 10, background: '#fff' }}>
      <div style={{ fontSize: 11, color: '#64748b', textTransform: 'uppercase', letterSpacing: 0.5 }}>
        <Wallet size={10} style={{ verticalAlign: 'middle', marginRight: 4 }} />
        {label}
      </div>
      <div style={{ fontSize: 20, fontWeight: 600, marginTop: 4, fontVariantNumeric: 'tabular-nums', color: tone ? colorMap[tone] : '#1e293b' }}>
        {value}
      </div>
    </div>
  );
}
