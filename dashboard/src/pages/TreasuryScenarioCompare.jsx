import React, { useState, useMemo, useEffect } from 'react';
import { api, useApi } from '../lib/api';
import { Wallet, GitCompare, AlertTriangle, ArrowRight, Share2, Copy, Check, FileSearch } from 'lucide-react';

/**
 * Treasury Scenario Compare — A/B two saved scenarios on one chart.
 *
 * Operator picks two saved scenarios from the tenant library and we
 * render baseline + A + B as three lines on the same chart, plus a
 * pairwise delta grid so the runway / lowest-balance / cash-impact
 * trade-off is immediately legible.
 */
const fmt = (n) =>
  n == null ? '—' : '$' + Number(n).toLocaleString(undefined, { maximumFractionDigits: 0 });

const fmtSigned = (n) => {
  if (n == null) return '—';
  const v = Number(n);
  return (v >= 0 ? '+' : '−') + '$' + Math.abs(v).toLocaleString(undefined, { maximumFractionDigits: 0 });
};

const SERIES = {
  baseline:   { label: 'Baseline',   color: '#94a3b8', negColor: '#fca5a5' },
  scenario_a: { label: 'Scenario A', color: '#7c3aed', negColor: '#dc2626' },
  scenario_b: { label: 'Scenario B', color: '#0d9488', negColor: '#dc2626' },
};

export default function TreasuryScenarioCompare() {
  const [days, setDays] = useState(90);
  const [aId, setAId] = useState('');
  const [bId, setBId] = useState('');
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  const [shareOpen, setShareOpen] = useState(false);
  const [shareLabel, setShareLabel] = useState('');
  const [shareDays, setShareDays] = useState(7);
  const [shareResult, setShareResult] = useState(null); // {url, expires_at}
  const [shareError, setShareError] = useState(null);
  const [copied, setCopied] = useState(false);

  const savedQuery = useApi('/api/v1/treasury/scenario-presets');
  const presets = savedQuery.data?.presets ?? [];

  // Default to the two most-recently-updated presets when the library
  // loads, so the operator sees something meaningful immediately.
  useEffect(() => {
    if (presets.length >= 2 && !aId && !bId) {
      setAId(String(presets[0].id));
      setBId(String(presets[1].id));
    }
  }, [presets, aId, bId]);

  const presetMap = useMemo(() => {
    const m = new Map();
    for (const p of presets) m.set(String(p.id), p);
    return m;
  }, [presets]);

  const a = presetMap.get(aId);
  const b = presetMap.get(bId);

  useEffect(() => {
    if (!a || !b) return;
    if (a.id === b.id) return; // same scenario picked twice — useless comparison
    let cancelled = false;
    setLoading(true); setError(null);
    api.post('/api/v1/treasury/scenario-compare', {
      days,
      scenario_a: { label: a.name, events: a.events },
      scenario_b: { label: b.name, events: b.events },
    })
      .then((res) => { if (!cancelled) setData(res); })
      .catch((e)   => { if (!cancelled) setError(e); })
      .finally(()  => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [a?.id, b?.id, days]);

  const chart = useMemo(() => {
    if (!data) return null;
    const series = ['baseline', 'scenario_a', 'scenario_b'].map((k) => ({
      key: k,
      points: data[k]?.daily ?? [],
    }));
    const all = series.flatMap((s) => s.points.map((p) => p.closing)).concat([0]);
    const lo = Math.min(...all);
    const hi = Math.max(...all);
    const span = (hi - lo) || 1;
    return { series, lo, hi, span, length: series[0].points.length };
  }, [data]);

  const createShareLink = async () => {
    setShareError(null);
    if (!a || !b || a.id === b.id) {
      setShareError(new Error('Pick two different saved scenarios first.'));
      return;
    }
    try {
      const res = await api.post('/api/v1/treasury/scenario-share?action=create', {
        kind: 'compare',
        preset_a_id: a.id,
        preset_b_id: b.id,
        label: shareLabel.trim(),
        days_horizon: days,
        expires_in_days: shareDays,
      });
      setShareResult(res);
      setCopied(false);
    } catch (e) {
      setShareError(e);
    }
  };

  const copyShareUrl = async () => {
    if (!shareResult?.url) return;
    try {
      await navigator.clipboard.writeText(shareResult.url);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      setShareError(new Error('Could not copy — select the link and copy manually.'));
    }
  };

  return (
    <section data-testid="scenario-compare-page" style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 12, flexWrap: 'wrap' }}>
        <div>
          <h2 style={{ margin: 0, display: 'flex', alignItems: 'center', gap: 8 }}>
            <GitCompare size={20} color="#7c3aed" /> Compare Scenarios
          </h2>
          <p style={{ color: '#64748b', margin: '4px 0 0', fontSize: 13 }}>
            Pick two saved scenarios to A/B test against the live baseline forecast.
          </p>
        </div>
        <select data-testid="scenario-compare-window-select"
                value={days} onChange={(e) => setDays(parseInt(e.target.value, 10))}
                className="input" style={{ fontSize: 13, padding: '4px 8px' }}>
          <option value={30}>30 days</option>
          <option value={60}>60 days</option>
          <option value={90}>90 days</option>
          <option value={180}>180 days</option>
        </select>
        {data && a && b && a.id !== b.id && (
          <button data-testid="scenario-compare-share-open"
                  onClick={() => { setShareOpen(!shareOpen); setShareError(null); setShareResult(null); }}
                  style={{ fontSize: 12, padding: '6px 12px', background: '#7c3aed', color: '#fff',
                           border: 'none', borderRadius: 6, cursor: 'pointer',
                           display: 'inline-flex', alignItems: 'center', gap: 4 }}>
            <Share2 size={12} /> Share this comparison
          </button>
        )}
      </header>

      {presets.length < 2 ? (
        <div data-testid="scenario-compare-need-two"
             style={{ padding: 14, background: '#fffbeb', border: '1px solid #fcd34d', borderRadius: 8, fontSize: 13, color: '#92400e' }}>
          ⚠ You need at least two saved scenarios to compare. Build one in the What-If Scenario tab and click "Save current as preset" — then come back here.
        </div>
      ) : (
        <div data-testid="scenario-compare-pickers"
             style={{ display: 'grid', gridTemplateColumns: '1fr auto 1fr', gap: 12, alignItems: 'center' }}>
          <PickerColumn label="Scenario A" tone="#7c3aed"
                        testid="scenario-compare-pick-a"
                        value={aId} onChange={setAId} presets={presets} />
          <ArrowRight size={20} color="#94a3b8" />
          <PickerColumn label="Scenario B" tone="#0d9488"
                        testid="scenario-compare-pick-b"
                        value={bId} onChange={setBId} presets={presets} />
        </div>
      )}

      {a && b && a.id === b.id && (
        <p data-testid="scenario-compare-same-warning" className="error">
          You've picked the same scenario twice. Choose two different scenarios to compare.
        </p>
      )}

      {error && <p className="error" data-testid="scenario-compare-error">{error.message}</p>}
      {loading && <p data-testid="scenario-compare-loading" style={{ fontSize: 12, color: '#64748b' }}>Projecting…</p>}

      {/* Pairwise delta grid */}
      {data && (
        <div data-testid="scenario-compare-deltas"
             style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 12 }}>
          <DeltaCard testid="scenario-compare-delta-a-vs-baseline"
                     title={`${data.scenario_a.label} vs Baseline`}
                     accent="#7c3aed" delta={data.deltas.a_vs_baseline} />
          <DeltaCard testid="scenario-compare-delta-b-vs-baseline"
                     title={`${data.scenario_b.label} vs Baseline`}
                     accent="#0d9488" delta={data.deltas.b_vs_baseline} />
          <DeltaCard testid="scenario-compare-delta-a-vs-b"
                     title={`A vs B`}
                     accent="#475569" delta={data.deltas.a_vs_b} />
        </div>
      )}

      {data && (
        <div data-testid="scenario-compare-source-detail"
             style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(260px, 1fr))', gap: 12 }}>
          <SourceDetailPanel title="Baseline sources" detail={data.baseline?.source_detail} testid="scenario-compare-source-baseline" />
          <SourceDetailPanel title={`${data.scenario_a.label} sources`} detail={data.scenario_a?.source_detail} testid="scenario-compare-source-a" />
          <SourceDetailPanel title={`${data.scenario_b.label} sources`} detail={data.scenario_b?.source_detail} testid="scenario-compare-source-b" />
        </div>
      )}

      {/* Share form / result panel */}
      {shareOpen && a && b && a.id !== b.id && (
        <div data-testid="scenario-compare-share-panel"
             style={{ padding: 16, border: '1px solid #c4b5fd', borderRadius: 10,
                      background: 'linear-gradient(135deg,#f5f3ff,#ecfeff)' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 10 }}>
            <Share2 size={14} color="#5b21b6" />
            <strong style={{ fontSize: 12, color: '#5b21b6', textTransform: 'uppercase', letterSpacing: '0.04em' }}>
              Read-only share link
            </strong>
            <span style={{ fontSize: 11, color: '#64748b', marginLeft: 'auto' }}>
              Recipients see the chart + deltas + event stacks. No tenant access granted.
            </span>
          </div>
          {!shareResult ? (
            <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
              <input data-testid="scenario-compare-share-label"
                     type="text" placeholder="Label (e.g., Q1 board pack)"
                     value={shareLabel} maxLength={200}
                     onChange={(e) => setShareLabel(e.target.value)}
                     className="input" style={{ fontSize: 12, padding: '4px 8px', flex: 1, minWidth: 200 }} />
              <select data-testid="scenario-compare-share-expiry"
                      value={shareDays} onChange={(e) => setShareDays(parseInt(e.target.value, 10))}
                      className="input" style={{ fontSize: 12, padding: '4px 8px' }}>
                <option value={1}>Expires in 1 day</option>
                <option value={3}>Expires in 3 days</option>
                <option value={7}>Expires in 7 days</option>
                <option value={14}>Expires in 14 days</option>
                <option value={30}>Expires in 30 days</option>
              </select>
              <button data-testid="scenario-compare-share-create"
                      onClick={createShareLink}
                      style={{ fontSize: 12, padding: '6px 12px', background: '#7c3aed', color: '#fff',
                               border: 'none', borderRadius: 6, cursor: 'pointer' }}>
                Create link
              </button>
            </div>
          ) : (
            <div data-testid="scenario-compare-share-result"
                 style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
              <input data-testid="scenario-compare-share-url"
                     type="text" value={shareResult.url} readOnly
                     onFocus={(e) => e.target.select()}
                     style={{ fontSize: 12, padding: '6px 8px', flex: 1, minWidth: 280,
                              fontFamily: 'monospace', background: '#fff',
                              border: '1px solid #c4b5fd', borderRadius: 6 }} />
              <button data-testid="scenario-compare-share-copy"
                      onClick={copyShareUrl}
                      style={{ fontSize: 12, padding: '6px 12px', background: copied ? '#065f46' : '#0d9488',
                               color: '#fff', border: 'none', borderRadius: 6, cursor: 'pointer',
                               display: 'inline-flex', alignItems: 'center', gap: 4 }}>
                {copied ? <Check size={12} /> : <Copy size={12} />}
                {copied ? 'Copied!' : 'Copy URL'}
              </button>
              <span style={{ fontSize: 11, color: '#64748b' }}>
                Expires {new Date(shareResult.expires_at).toLocaleString()}
              </span>
            </div>
          )}
          {shareError && (
            <p data-testid="scenario-compare-share-error" className="error" style={{ fontSize: 12, marginTop: 8 }}>
              {shareError.message}
            </p>
          )}
        </div>
      )}

      {/* Three-series chart */}
      {data && chart && (
        <div data-testid="scenario-compare-chart"
             style={{ padding: 16, border: '1px solid #e2e8f0', borderRadius: 10, background: '#fff' }}>
          <div style={{ display: 'flex', gap: 16, marginBottom: 8, fontSize: 12, color: '#475569', flexWrap: 'wrap' }}>
            {Object.entries(SERIES).map(([key, meta]) => {
              const label = key === 'scenario_a' ? data.scenario_a.label
                          : key === 'scenario_b' ? data.scenario_b.label
                          : meta.label;
              return (
                <span key={key} style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}>
                  <span style={{ display: 'inline-block', width: 12, height: 3, background: meta.color, borderRadius: 2 }} />
                  {label}
                </span>
              );
            })}
          </div>
          <Chart chart={chart} />
        </div>
      )}

      {/* Side-by-side stack of events */}
      {data && (
        <div data-testid="scenario-compare-event-stacks"
             style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
          <EventStack title={data.scenario_a.label} events={data.scenario_a.events} tone="#7c3aed" testid="scenario-compare-events-a" />
          <EventStack title={data.scenario_b.label} events={data.scenario_b.events} tone="#0d9488" testid="scenario-compare-events-b" />
        </div>
      )}
    </section>
  );
}

function PickerColumn({ label, tone, testid, value, onChange, presets }) {
  return (
    <div style={{ padding: 12, border: `1px solid ${tone}33`, borderRadius: 10, background: '#fff' }}>
      <div style={{ fontSize: 11, color: tone, textTransform: 'uppercase', letterSpacing: 0.5, fontWeight: 600, marginBottom: 6 }}>
        {label}
      </div>
      <select data-testid={testid}
              value={value} onChange={(e) => onChange(e.target.value)}
              className="input"
              style={{ width: '100%', fontSize: 13, padding: '6px 8px' }}>
        <option value="">— pick a saved scenario —</option>
        {presets.map((p) => (
          <option key={p.id} value={p.id}>{p.name}</option>
        ))}
      </select>
    </div>
  );
}

function DeltaCard({ title, accent, delta, testid }) {
  const isWorse = (delta?.lowest_balance_shift ?? 0) < 0 || (delta?.runway_days_lost ?? 0) > 0;
  return (
    <div data-testid={testid}
         style={{ padding: 14, border: `1px solid ${accent}33`, borderRadius: 10, background: '#fff' }}>
      <div style={{ fontSize: 11, color: accent, textTransform: 'uppercase', letterSpacing: 0.5, fontWeight: 600 }}>{title}</div>
      <div style={{ fontSize: 14, color: isWorse ? '#b91c1c' : '#065f46', marginTop: 6, display: 'flex', alignItems: 'center', gap: 6 }}>
        <Wallet size={12} />
        Lowest balance: {fmtSigned(delta?.lowest_balance_shift)}
      </div>
      {delta?.runway_days_lost > 0 && (
        <div style={{ fontSize: 12, color: '#b91c1c', marginTop: 4, display: 'flex', alignItems: 'center', gap: 6 }}>
          <AlertTriangle size={12} />
          Runway: −{delta.runway_days_lost} day{delta.runway_days_lost === 1 ? '' : 's'}
        </div>
      )}
      {delta?.runway_days_lost === 0 && delta?.crosses_zero === false && (
        <div style={{ fontSize: 12, color: '#065f46', marginTop: 4 }}>
          Runway: unchanged (cash stays positive)
        </div>
      )}
      {delta?.runway_days_lost < 0 && (
        <div style={{ fontSize: 12, color: '#065f46', marginTop: 4 }}>
          Runway: +{Math.abs(delta.runway_days_lost)} days (improvement)
        </div>
      )}
    </div>
  );
}

function SourceDetailPanel({ title, detail, testid }) {
  const classes = detail?.classification_totals || {};
  const sourceRows = [
    ...(detail?.summary?.inflows || []).map((row) => ({ ...row, direction: 'In' })),
    ...(detail?.summary?.outflows || []).map((row) => ({ ...row, direction: 'Out' })),
  ].slice(0, 6);
  return (
    <div data-testid={testid}
         style={{ padding: 14, border: '1px solid #e2e8f0', borderRadius: 10, background: '#fff', display: 'grid', gap: 10 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
        <FileSearch size={14} color="#0f766e" />
        <strong style={{ fontSize: 13 }}>{title}</strong>
      </div>
      <div data-testid={`${testid}-classes`}
           style={{ display: 'grid', gridTemplateColumns: 'repeat(2, minmax(0, 1fr))', gap: 8 }}>
        {['scheduled', 'expected', 'forecasted', 'actual'].map((key) => {
          const row = classes[key] || {};
          return (
            <div key={key} data-testid={`${testid}-class-${key}`}
                 style={{ padding: 8, background: '#f8fafc', borderRadius: 8, fontSize: 11, color: '#475569' }}>
              <div style={{ textTransform: 'uppercase', letterSpacing: 0.4, color: '#64748b' }}>{key}</div>
              <div style={{ display: 'flex', justifyContent: 'space-between', gap: 6, marginTop: 4 }}>
                <span style={{ color: '#047857' }}>{fmt(row.inflows || 0)}</span>
                <span style={{ color: '#b91c1c' }}>{fmt(row.outflows || 0)}</span>
              </div>
            </div>
          );
        })}
      </div>
      <div data-testid={`${testid}-sources`} style={{ display: 'grid', gap: 4 }}>
        {sourceRows.length === 0 ? (
          <span style={{ color: '#94a3b8', fontSize: 12 }}>No source movements.</span>
        ) : sourceRows.map((row) => (
          <div key={`${row.direction}-${row.source}`}
               style={{ display: 'grid', gridTemplateColumns: 'auto minmax(0, 1fr) auto', gap: 8, fontSize: 12, color: '#475569' }}>
            <span style={{ color: row.direction === 'In' ? '#047857' : '#b91c1c', fontWeight: 700 }}>{row.direction}</span>
            <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{row.source}</span>
            <span style={{ fontVariantNumeric: 'tabular-nums' }}>{fmt(row.amount)}</span>
          </div>
        ))}
      </div>
    </div>
  );
}

function Chart({ chart }) {
  if (!chart || chart.length === 0) return null;
  const w = 1000;
  const h = 160;
  const xStep = w / Math.max(1, chart.length - 1);
  const y = (val) => h - ((val - chart.lo) / chart.span) * (h - 8) - 4;

  const pathFor = (points) =>
    points.map((p, i) => `${i === 0 ? 'M' : 'L'}${(i * xStep).toFixed(2)},${y(p.closing).toFixed(2)}`).join(' ');

  return (
    <svg viewBox={`0 0 ${w} ${h}`} preserveAspectRatio="none"
         style={{ width: '100%', height: 160, display: 'block' }}>
      {/* Zero baseline */}
      {chart.lo < 0 && chart.hi > 0 && (
        <line x1={0} x2={w} y1={y(0)} y2={y(0)} stroke="#cbd5e1" strokeDasharray="4 4" strokeWidth={1} />
      )}
      {chart.series.map((s) => (
        <path key={s.key} d={pathFor(s.points)} fill="none"
              stroke={SERIES[s.key].color} strokeWidth={2} strokeLinejoin="round" />
      ))}
    </svg>
  );
}

function EventStack({ title, events, tone, testid }) {
  return (
    <div data-testid={testid}
         style={{ border: '1px solid #e2e8f0', borderRadius: 10, overflow: 'hidden' }}>
      <div style={{ padding: '8px 12px', background: tone + '11',
                    fontSize: 12, fontWeight: 600, color: tone,
                    textTransform: 'uppercase', letterSpacing: 0.5 }}>
        {title} — {events.length} event{events.length === 1 ? '' : 's'}
      </div>
      <table style={{ width: '100%', fontSize: 12, borderCollapse: 'collapse' }}>
        <tbody>
          {events.map((e, i) => (
            <tr key={i} style={{ borderTop: i === 0 ? 'none' : '1px solid #f1f5f9' }}>
              <td style={{ padding: '4px 12px', color: e.kind === 'inflow' ? '#065f46' : '#b91c1c', width: 70 }}>
                {e.kind === 'inflow' ? '+' : '−'}{fmt(e.amount)}
              </td>
              <td style={{ padding: '4px 12px', color: '#64748b', width: 100 }}>{e.date}</td>
              <td style={{ padding: '4px 12px', color: '#475569' }}>{e.label}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
