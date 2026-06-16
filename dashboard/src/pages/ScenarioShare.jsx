import React, { useEffect, useState, useMemo } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Wallet, GitCompare, AlertTriangle, ShieldCheck, Clock, FileSearch } from 'lucide-react';

/**
 * Public Scenario Share viewer.
 *
 * Read-only deep-link view of a saved scenario (or a compare A/B pair).
 * Reads the token from `?token=…` and calls the public action on
 * /api/treasury_scenario_share.php?action=view. NO platform-user
 * authentication required — token resolution is the only gate.
 *
 * Renders the same chart + delta tiles + event stack the dashboard
 * pages use, scoped to whichever shape the share link points at.
 */
const fmt = (n) =>
  n == null ? '—' : '$' + Number(n).toLocaleString(undefined, { maximumFractionDigits: 0 });

const SERIES = {
  baseline:   { label: 'Baseline',   color: '#94a3b8' },
  scenario_a: { label: 'Scenario A', color: '#7c3aed' },
  scenario_b: { label: 'Scenario B', color: '#0d9488' },
};

export default function ScenarioShare() {
  const [params] = useSearchParams();
  const token = params.get('token') || '';
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!token) {
      setError({ message: 'Missing token in URL.' });
      setLoading(false);
      return;
    }
    let cancelled = false;
    fetch(`/api/treasury_scenario_share.php?action=view&token=${encodeURIComponent(token)}`)
      .then(async (r) => {
        const body = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(body?.error || `Request failed (${r.status})`);
        return body;
      })
      .then((body) => { if (!cancelled) setData(body); })
      .catch((e)   => { if (!cancelled) setError(e); })
      .finally(()  => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [token]);

  const chart = useMemo(() => {
    if (!data) return null;
    const series = [
      { key: 'baseline',   points: data.baseline?.daily ?? [] },
      { key: 'scenario_a', points: data.scenario_a?.daily ?? [] },
    ];
    if (data.scenario_b) series.push({ key: 'scenario_b', points: data.scenario_b.daily ?? [] });
    const all = series.flatMap((s) => s.points.map((p) => p.closing)).concat([0]);
    const lo = Math.min(...all);
    const hi = Math.max(...all);
    const span = (hi - lo) || 1;
    return { series, lo, hi, span };
  }, [data]);

  if (loading) {
    return (
      <div data-testid="scenario-share-loading"
           style={{ padding: 40, fontFamily: 'system-ui', color: '#64748b', textAlign: 'center' }}>
        Loading scenario…
      </div>
    );
  }

  if (error) {
    return (
      <div data-testid="scenario-share-error"
           style={{ maxWidth: 480, margin: '40px auto', padding: 24, fontFamily: 'system-ui',
                    border: '1px solid #fca5a5', borderRadius: 12, background: '#fef2f2', color: '#991b1b' }}>
        <strong style={{ display: 'block', marginBottom: 8 }}>This scenario can't be loaded</strong>
        <p style={{ margin: 0, fontSize: 14 }}>{error.message}</p>
        <p style={{ marginTop: 12, fontSize: 12, color: '#7f1d1d' }}>
          Share links expire 7 days after creation by default. Ask whoever sent you the link to generate a new one.
        </p>
      </div>
    );
  }

  if (!data) return null;
  const isCompare = !!data.scenario_b;

  return (
    <div data-testid="scenario-share-page"
         style={{ maxWidth: 1100, margin: '0 auto', padding: '32px 24px', fontFamily: 'system-ui' }}>
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 16, flexWrap: 'wrap', marginBottom: 20 }}>
        <div>
          <h1 style={{ margin: 0, display: 'flex', alignItems: 'center', gap: 10, fontSize: 22, color: '#5b21b6' }}>
            {isCompare ? <GitCompare size={22} /> : <Wallet size={22} />}
            {isCompare
              ? `${data.scenario_a.name}  vs  ${data.scenario_b.name}`
              : data.scenario_a.name}
          </h1>
          {data.label && (
            <p data-testid="scenario-share-label"
               style={{ margin: '4px 0 0', fontSize: 13, color: '#64748b' }}>
              {data.label}
            </p>
          )}
        </div>
        <div style={{ fontSize: 11, color: '#94a3b8', display: 'flex', alignItems: 'center', gap: 6 }}>
          <ShieldCheck size={12} />
          Read-only share link
          <span style={{ margin: '0 6px' }}>·</span>
          <Clock size={12} />
          Expires {new Date(data.expires_at).toLocaleDateString()}
        </div>
      </header>

      {/* KPI tiles per scenario */}
      <div data-testid="scenario-share-tiles"
           style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 12, marginBottom: 20 }}>
        <Tile label="Baseline lowest"      value={fmt(data.baseline.lowest_balance)} />
        <Tile label={`${data.scenario_a.name} lowest`}
              value={fmt(data.scenario_a.lowest_balance)}
              tone={data.scenario_a.lowest_balance < data.baseline.lowest_balance ? 'red' : 'green'} />
        {isCompare && (
          <Tile label={`${data.scenario_b.name} lowest`}
                value={fmt(data.scenario_b.lowest_balance)}
                tone={data.scenario_b.lowest_balance < data.baseline.lowest_balance ? 'red' : 'green'} />
        )}
        <Tile label="Starting cash" value={fmt(data.baseline.starting_cash)} />
      </div>

      <div data-testid="scenario-share-source-detail"
           style={{ display: 'grid', gridTemplateColumns: `repeat(auto-fit, minmax(${isCompare ? 250 : 280}px, 1fr))`, gap: 12, marginBottom: 20 }}>
        <SourceDetailPanel title="Baseline sources" detail={data.baseline?.source_detail} testid="scenario-share-source-baseline" />
        <SourceDetailPanel title={`${data.scenario_a.name} sources`} detail={data.scenario_a?.source_detail} testid="scenario-share-source-a" />
        {isCompare && (
          <SourceDetailPanel title={`${data.scenario_b.name} sources`} detail={data.scenario_b?.source_detail} testid="scenario-share-source-b" />
        )}
      </div>

      {/* Chart */}
      {chart && chart.series[0].points.length > 0 && (
        <div data-testid="scenario-share-chart"
             style={{ padding: 16, border: '1px solid #e2e8f0', borderRadius: 10, background: '#fff', marginBottom: 20 }}>
          <div style={{ display: 'flex', gap: 16, marginBottom: 8, fontSize: 12, color: '#475569', flexWrap: 'wrap' }}>
            {chart.series.map(({ key }) => {
              const label = key === 'scenario_a' ? data.scenario_a.name
                          : key === 'scenario_b' ? data.scenario_b.name
                          : SERIES[key].label;
              return (
                <span key={key} style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}>
                  <span style={{ display: 'inline-block', width: 12, height: 3, background: SERIES[key].color, borderRadius: 2 }} />
                  {label}
                </span>
              );
            })}
          </div>
          <ShareChart chart={chart} />
        </div>
      )}

      {/* Event stacks */}
      <div data-testid="scenario-share-events"
           style={{ display: 'grid', gridTemplateColumns: isCompare ? '1fr 1fr' : '1fr', gap: 12 }}>
        <EventStack title={data.scenario_a.name} description={data.scenario_a.description}
                    events={data.scenario_a.events} tone="#7c3aed"
                    testid="scenario-share-events-a" />
        {isCompare && (
          <EventStack title={data.scenario_b.name} description={data.scenario_b.description}
                      events={data.scenario_b.events} tone="#0d9488"
                      testid="scenario-share-events-b" />
        )}
      </div>

      <footer style={{ marginTop: 32, paddingTop: 16, borderTop: '1px solid #e2e8f0', fontSize: 11, color: '#94a3b8', textAlign: 'center' }}>
        Generated by CoreFlux Treasury · This view is read-only and contains no live tenant data beyond the projection.
      </footer>
    </div>
  );
}

function Tile({ label, value, tone }) {
  const colorMap = { red: '#b91c1c', green: '#065f46' };
  return (
    <div style={{ padding: 14, border: '1px solid #e2e8f0', borderRadius: 10, background: '#fff' }}>
      <div style={{ fontSize: 11, color: '#64748b', textTransform: 'uppercase', letterSpacing: 0.5 }}>{label}</div>
      <div style={{ fontSize: 20, fontWeight: 600, marginTop: 4, fontVariantNumeric: 'tabular-nums', color: tone ? colorMap[tone] : '#1e293b' }}>
        {value}
      </div>
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

function ShareChart({ chart }) {
  const w = 1000, h = 160;
  const pts = chart.series[0].points;
  const xStep = w / Math.max(1, pts.length - 1);
  const y = (val) => h - ((val - chart.lo) / chart.span) * (h - 8) - 4;
  const pathFor = (points) =>
    points.map((p, i) => `${i === 0 ? 'M' : 'L'}${(i * xStep).toFixed(2)},${y(p.closing).toFixed(2)}`).join(' ');
  return (
    <svg viewBox={`0 0 ${w} ${h}`} preserveAspectRatio="none"
         style={{ width: '100%', height: 160, display: 'block' }}>
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

function EventStack({ title, description, events, tone, testid }) {
  return (
    <div data-testid={testid}
         style={{ border: '1px solid #e2e8f0', borderRadius: 10, overflow: 'hidden', background: '#fff' }}>
      <div style={{ padding: '8px 12px', background: tone + '11',
                    fontSize: 12, fontWeight: 600, color: tone,
                    textTransform: 'uppercase', letterSpacing: 0.5 }}>
        {title} — {events.length} event{events.length === 1 ? '' : 's'}
      </div>
      {description && (
        <div style={{ padding: '6px 12px', fontSize: 12, color: '#64748b', borderBottom: '1px solid #f1f5f9' }}>
          {description}
        </div>
      )}
      <table style={{ width: '100%', fontSize: 12, borderCollapse: 'collapse' }}>
        <tbody>
          {events.map((e, i) => (
            <tr key={i} style={{ borderTop: i === 0 ? 'none' : '1px solid #f1f5f9' }}>
              <td style={{ padding: '4px 12px', color: e.kind === 'inflow' ? '#065f46' : '#b91c1c', width: 80 }}>
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
