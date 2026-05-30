import React, { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { api, useApi } from '../lib/api';
import { Card } from '../components/UIComponents';
import { fmtMoney } from '../lib/format';
import {
  Users, Trophy, Briefcase, RefreshCw, Search,
  ArrowUp, ArrowDown, ArrowUpDown, Flag, X,
  CheckCircle2, AlertTriangle, Loader2,
} from 'lucide-react';
import Swirl from '../components/Swirl';

/**
 * StaffingReports — drill page under /modules/reports/staffing.
 *
 * Layout:
 *   1. Period chooser (date range).
 *   2. Recruiter leaderboard (sortable by margin / placements / hours).
 *   3. Placement margin table — the core staffing P&L view, one row per
 *      placement with bill rate, pay rate, weekly hours, period & lifetime
 *      margin. Click-through to placement detail page.
 *   4. Headcount breakdown by classification + state.
 */
const PRESETS = [
  ['mtd', 'MTD'], ['qtd', 'QTD'], ['ytd', 'YTD'],
  ['12w', '12 weeks'], ['52w', '52 weeks'],
];

export default function StaffingReports() {
  const today = new Date();
  const iso = (d) => d.toISOString().slice(0, 10);
  const defaultFrom = new Date();
  defaultFrom.setDate(defaultFrom.getDate() - 84); // 12 weeks

  const [from, setFrom] = useState(iso(defaultFrom));
  const [to,   setTo]   = useState(iso(today));

  const qs = useMemo(() => new URLSearchParams({ from, to }).toString(), [from, to]);
  const { data, loading, error, reload } = useApi(`/api/reports_staffing.php?${qs}`);

  const applyPreset = (kind) => {
    const t = new Date(); let f, e = t;
    if (kind === 'mtd') f = new Date(t.getFullYear(), t.getMonth(), 1);
    if (kind === 'qtd') f = new Date(t.getFullYear(), Math.floor(t.getMonth() / 3) * 3, 1);
    if (kind === 'ytd') f = new Date(t.getFullYear(), 0, 1);
    if (kind === '12w') { f = new Date(); f.setDate(t.getDate() - 84); }
    if (kind === '52w') { f = new Date(); f.setDate(t.getDate() - 365); }
    setFrom(iso(f)); setTo(iso(e));
  };

  return (
    <div data-testid="staffing-reports">
      <div style={{
        display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end',
        flexWrap: 'wrap', gap: 12,
        position: 'sticky', top: 0, zIndex: 5,
        background: 'linear-gradient(180deg, #fff 0%, #fff 88%, rgba(255,255,255,0) 100%)',
        padding: '12px 0 14px',
        borderBottom: '1px solid #e2e8f0',
        marginBottom: 'var(--cf-space-5)',
      }}>
        <div style={{ flex: 1, minWidth: 260 }}>
          <h1 data-testid="staffing-rpt-title"
              style={{ margin: 0, fontSize: 22, fontWeight: 700,
                       color: '#0f172a', letterSpacing: '-0.01em',
                       display: 'flex', alignItems: 'center', gap: 8 }}>
            <Users size={20} />
            Staffing Operations
          </h1>
          <p style={{ color: '#64748b', fontSize: 13, margin: '4px 0 0' }}>
            Per-placement margins, recruiter leaderboard, headcount breakdown.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 6, alignItems: 'center', flexWrap: 'wrap' }}>
          {PRESETS.map(([k, l]) => (
            <button key={k} onClick={() => applyPreset(k)} className="btn btn--ghost"
                    data-testid={`staffing-preset-${k}`}>{l}</button>
          ))}
          <input type="date" className="input" value={from}
                 onChange={(e) => setFrom(e.target.value)}
                 data-testid="staffing-from" style={{ width: 160 }} />
          <span style={{ color: '#94a3b8' }}>→</span>
          <input type="date" className="input" value={to}
                 onChange={(e) => setTo(e.target.value)}
                 data-testid="staffing-to" style={{ width: 160 }} />
          <button className="btn btn--ghost" onClick={reload} data-testid="staffing-refresh">
            <RefreshCw size={14} />
          </button>
        </div>
      </div>

      {loading && <Card><p>Loading…</p></Card>}
      {error && <Card><p style={{ color: '#b91c1c' }}>{error.message || String(error)}</p></Card>}

      {!loading && data && (
        <>
          <RecruiterBoard rows={data.recruiter_board || []} />
          <PlacementMarginTable rows={data.placement_margin || []} />
          <HeadcountBreakdown breakdown={data.headcount_breakdown || {}} />
        </>
      )}
    </div>
  );
}

/* ===================== Recruiter leaderboard ===================== */

function RecruiterBoard({ rows }) {
  const [key, setKey]     = useState('period_margin');
  const [dir, setDir]     = useState('desc');
  const [aiFor, setAiFor] = useState(null);
  const [flagFor, setFlagFor] = useState(null);

  const flagsApi = useApi('/api/review_flags.php?entity_type=recruiter&status=open');
  const flagsByRecruiter = useMemo(() => {
    const m = {};
    for (const f of (flagsApi.data?.flags || [])) {
      (m[f.entity_id] = m[f.entity_id] || []).push(f);
    }
    return m;
  }, [flagsApi.data]);

  const sorted = useMemo(() => {
    return [...rows].sort((a, b) => {
      const av = a[key], bv = b[key];
      if (av === bv) return 0;
      const cmp = av > bv ? 1 : -1;
      return dir === 'asc' ? cmp : -cmp;
    });
  }, [rows, key, dir]);
  const sortBy = (k) => k === key ? setDir(dir === 'asc' ? 'desc' : 'asc') : (setKey(k), setDir('desc'));
  const Caret = ({ k }) => k === key ? (dir === 'asc' ? <ArrowUp size={12} /> : <ArrowDown size={12} />)
                                     : <ArrowUpDown size={12} style={{ opacity: 0.4 }} />;

  return (
    <Card style={{ marginBottom: 16 }}>
      <h2 style={{ fontSize: 16, fontWeight: 600, marginBottom: 12 }}>
        <Trophy size={16} style={{ display: 'inline', marginRight: 6 }} />
        Recruiter leaderboard ({rows.length})
      </h2>
      <table className="data-table" data-testid="recruiter-board">
        <thead>
          <tr>
            <th style={{ width: 50 }}>#</th>
            <th>Recruiter</th>
            <th onClick={() => sortBy('active_placements')} style={{ cursor: 'pointer', textAlign: 'right' }}>
              Active <Caret k="active_placements" />
            </th>
            <th onClick={() => sortBy('new_placements')} style={{ cursor: 'pointer', textAlign: 'right' }}>
              New (period) <Caret k="new_placements" />
            </th>
            <th onClick={() => sortBy('period_hours')} style={{ cursor: 'pointer', textAlign: 'right' }}>
              Period hours <Caret k="period_hours" />
            </th>
            <th onClick={() => sortBy('period_margin')} style={{ cursor: 'pointer', textAlign: 'right' }}>
              Period margin <Caret k="period_margin" />
            </th>
            <th onClick={() => sortBy('avg_margin_per_hour')} style={{ cursor: 'pointer', textAlign: 'right' }}>
              Avg margin / hr <Caret k="avg_margin_per_hour" />
            </th>
            <th onClick={() => sortBy('lifetime_margin')} style={{ cursor: 'pointer', textAlign: 'right' }}>
              Lifetime margin <Caret k="lifetime_margin" />
            </th>
            <th style={{ textAlign: 'right', width: 110 }}>Actions</th>
          </tr>
        </thead>
        <tbody>
          {sorted.map((r, i) => {
            const flags = flagsByRecruiter[r.recruiter_id] || [];
            const flagged = flags.length > 0;
            return (
              <tr key={r.recruiter_id} data-testid={`recruiter-row-${r.recruiter_id}`}
                  style={{ background: flagged ? '#fef9c3' : undefined }}>
                <td style={{ fontWeight: 500 }}>{i + 1}</td>
                <td style={{ fontWeight: 500 }}>
                  {r.name}
                  {flagged && (
                    <span style={{ marginLeft: 6, fontSize: 11, color: '#b45309' }}
                          title={flags.map(f => f.reason_code).join(', ')}
                          data-testid={`recruiter-flag-badge-${r.recruiter_id}`}>
                      <Flag size={11} fill="#f59e0b" /> {flags.length}
                    </span>
                  )}
                </td>
                <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{r.active_placements}</td>
                <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{r.new_placements}</td>
                <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{r.period_hours.toFixed(1)}</td>
                <td style={{ textAlign: 'right', fontWeight: 600, color: '#10b981', fontVariantNumeric: 'tabular-nums' }}>
                  {fmtMoney(r.period_margin)}
                </td>
                <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{fmtMoney(r.avg_margin_per_hour)}</td>
                <td style={{ textAlign: 'right', color: '#64748b', fontVariantNumeric: 'tabular-nums' }}>
                  {fmtMoney(r.lifetime_margin)}
                </td>
                <td style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
                  <button className="btn btn--ghost"
                          title="Ask AI about this recruiter"
                          onClick={() => setAiFor(r)}
                          data-testid={`recruiter-ai-btn-${r.recruiter_id}`}
                          style={{ padding: '4px 6px' }}>
                    <Swirl size={14} />
                  </button>
                  <button className="btn btn--ghost"
                          title={flagged ? 'View / resolve flags' : 'Flag for review'}
                          onClick={() => setFlagFor(r)}
                          data-testid={`recruiter-flag-btn-${r.recruiter_id}`}
                          style={{ padding: '4px 6px',
                                  color: flagged ? '#b45309' : 'inherit' }}>
                    <Flag size={14} fill={flagged ? '#f59e0b' : 'none'} />
                  </button>
                </td>
              </tr>
            );
          })}
          {sorted.length === 0 && (
            <tr><td colSpan={9} style={{ textAlign: 'center', padding: 24, color: '#64748b' }}>
              No recruiter activity in the selected period.
            </td></tr>
          )}
        </tbody>
      </table>

      {aiFor && (
        <RecruiterAiPanel
          recruiter={aiFor}
          onClose={() => setAiFor(null)}
          onFlagged={() => { flagsApi.reload(); setAiFor(null); }}
          onRequestFlag={() => { setFlagFor(aiFor); setAiFor(null); }}
        />
      )}
      {flagFor && (
        <RecruiterFlagModal
          recruiter={flagFor}
          existingFlags={flagsByRecruiter[flagFor.recruiter_id] || []}
          onClose={() => setFlagFor(null)}
          onSaved={() => { flagsApi.reload(); setFlagFor(null); }}
        />
      )}
    </Card>
  );
}

/* ===================== Placement margin table ===================== */

function PlacementMarginTable({ rows }) {
  const [q, setQ]     = useState('');
  const [key, setKey] = useState('period_margin');
  const [dir, setDir] = useState('desc');
  const [aiFor, setAiFor]     = useState(null);   // placement row currently in AI panel
  const [flagFor, setFlagFor] = useState(null);   // placement row in flag modal

  // Pull all open flags for placements so we can render a 🚩 indicator.
  const flagsApi = useApi('/api/review_flags.php?entity_type=placement&status=open');
  const flagsByPlacement = useMemo(() => {
    const m = {};
    for (const f of (flagsApi.data?.flags || [])) {
      (m[f.entity_id] = m[f.entity_id] || []).push(f);
    }
    return m;
  }, [flagsApi.data]);

  const sorted = useMemo(() => {
    let out = rows;
    if (q) {
      const ql = q.toLowerCase();
      out = out.filter(r =>
        (r.candidate || '').toLowerCase().includes(ql) ||
        (r.client    || '').toLowerCase().includes(ql) ||
        (r.recruiter || '').toLowerCase().includes(ql) ||
        (r.state     || '').toLowerCase().includes(ql));
    }
    return [...out].sort((a, b) => {
      const av = a[key], bv = b[key];
      if (av === bv) return 0;
      const cmp = av > bv ? 1 : -1;
      return dir === 'asc' ? cmp : -cmp;
    });
  }, [rows, q, key, dir]);

  const sortBy = (k) => k === key ? setDir(dir === 'asc' ? 'desc' : 'asc') : (setKey(k), setDir('desc'));
  const Caret = ({ k }) => k === key ? (dir === 'asc' ? <ArrowUp size={12} /> : <ArrowDown size={12} />)
                                     : <ArrowUpDown size={12} style={{ opacity: 0.4 }} />;

  const totals = useMemo(() => sorted.reduce((acc, r) => ({
    period_hours:    acc.period_hours    + (r.period_hours    || 0),
    period_margin:   acc.period_margin   + (r.period_margin   || 0),
    lifetime_margin: acc.lifetime_margin + (r.lifetime_margin || 0),
  }), { period_hours: 0, period_margin: 0, lifetime_margin: 0 }), [sorted]);

  return (
    <Card style={{ marginBottom: 16 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
        <h2 style={{ fontSize: 16, fontWeight: 600 }}>
          <Briefcase size={16} style={{ display: 'inline', marginRight: 6 }} />
          Placement margin ({rows.length})
        </h2>
        <div style={{ display: 'flex', alignItems: 'center', gap: 6, color: '#64748b' }}>
          <Search size={14} />
          <input className="input" placeholder="candidate / client / recruiter / state…"
                 value={q} onChange={(e) => setQ(e.target.value)}
                 data-testid="placement-margin-filter" style={{ width: 320 }} />
        </div>
      </div>
      <table className="data-table" data-testid="placement-margin-table">
        <thead>
          <tr>
            <th onClick={() => sortBy('candidate')}      style={{ cursor: 'pointer' }}>Candidate</th>
            <th onClick={() => sortBy('client')}         style={{ cursor: 'pointer' }}>Client</th>
            <th onClick={() => sortBy('recruiter')}      style={{ cursor: 'pointer' }}>Recruiter</th>
            <th onClick={() => sortBy('engagement_type')} style={{ cursor: 'pointer' }}>Type</th>
            <th onClick={() => sortBy('state')}          style={{ cursor: 'pointer' }}>State</th>
            <th onClick={() => sortBy('bill_rate')}      style={{ cursor: 'pointer', textAlign: 'right' }}>Bill <Caret k="bill_rate" /></th>
            <th onClick={() => sortBy('pay_rate')}       style={{ cursor: 'pointer', textAlign: 'right' }}>Pay <Caret k="pay_rate" /></th>
            <th onClick={() => sortBy('margin_per_hour')} style={{ cursor: 'pointer', textAlign: 'right' }}>$/hr <Caret k="margin_per_hour" /></th>
            <th onClick={() => sortBy('period_hours')}   style={{ cursor: 'pointer', textAlign: 'right' }}>Hours <Caret k="period_hours" /></th>
            <th onClick={() => sortBy('period_margin')}  style={{ cursor: 'pointer', textAlign: 'right' }}>Period margin <Caret k="period_margin" /></th>
            <th onClick={() => sortBy('lifetime_margin')} style={{ cursor: 'pointer', textAlign: 'right' }}>Lifetime <Caret k="lifetime_margin" /></th>
            <th style={{ textAlign: 'right', width: 110 }}>Actions</th>
          </tr>
        </thead>
        <tbody>
          {sorted.map(r => {
            const flags = flagsByPlacement[r.id] || [];
            const flagged = flags.length > 0;
            return (
              <tr key={r.id} data-testid={`placement-row-${r.id}`}
                  style={{ background: flagged ? '#fef9c3' : undefined }}>
                <td>
                  <Link to={`/modules/placements/list/${r.id}`} className="link"
                        data-testid={`placement-link-${r.id}`}>{r.candidate || '—'}</Link>
                  {flagged && (
                    <span style={{ marginLeft: 6, fontSize: 11, color: '#b45309' }}
                          title={flags.map(f => f.reason_code).join(', ')}
                          data-testid={`placement-flag-badge-${r.id}`}>
                      <Flag size={11} fill="#f59e0b" /> {flags.length}
                    </span>
                  )}
                </td>
                <td>{r.client}</td>
                <td style={{ color: '#64748b' }}>{r.recruiter || '—'}</td>
                <td><span className="badge badge--info">{r.engagement_type}</span></td>
                <td>{r.state || '—'}</td>
                <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{fmtMoney(r.bill_rate)}</td>
                <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{fmtMoney(r.pay_rate)}</td>
                <td style={{ textAlign: 'right', fontWeight: 500, fontVariantNumeric: 'tabular-nums',
                            color: r.margin_per_hour > 0 ? '#10b981' : '#ef4444' }}>{fmtMoney(r.margin_per_hour)}</td>
                <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{r.period_hours.toFixed(1)}</td>
                <td style={{ textAlign: 'right', fontWeight: 600, fontVariantNumeric: 'tabular-nums' }}>{fmtMoney(r.period_margin)}</td>
                <td style={{ textAlign: 'right', color: '#64748b', fontVariantNumeric: 'tabular-nums' }}>{fmtMoney(r.lifetime_margin)}</td>
                <td style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
                  <button className="btn btn--ghost"
                          title="Ask AI about this placement"
                          onClick={() => setAiFor(r)}
                          data-testid={`placement-ai-btn-${r.id}`}
                          style={{ padding: '4px 6px' }}>
                    <Swirl size={14} />
                  </button>
                  <button className="btn btn--ghost"
                          title={flagged ? 'View / resolve flags' : 'Flag for review'}
                          onClick={() => setFlagFor(r)}
                          data-testid={`placement-flag-btn-${r.id}`}
                          style={{ padding: '4px 6px',
                                  color: flagged ? '#b45309' : 'inherit' }}>
                    <Flag size={14} fill={flagged ? '#f59e0b' : 'none'} />
                  </button>
                </td>
              </tr>
            );
          })}
          {sorted.length === 0 && (
            <tr><td colSpan={12} style={{ textAlign: 'center', padding: 24, color: '#64748b' }}>
              No placements match.
            </td></tr>
          )}
        </tbody>
        {sorted.length > 0 && (
          <tfoot>
            <tr style={{ background: '#f8fafc', fontWeight: 600 }} data-testid="placement-margin-totals">
              <td colSpan={8} style={{ textAlign: 'right' }}>Totals ({sorted.length} placements)</td>
              <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{totals.period_hours.toFixed(1)}</td>
              <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{fmtMoney(totals.period_margin)}</td>
              <td style={{ textAlign: 'right', color: '#64748b', fontVariantNumeric: 'tabular-nums' }}>{fmtMoney(totals.lifetime_margin)}</td>
              <td></td>
            </tr>
          </tfoot>
        )}
      </table>

      {aiFor && (
        <PlacementAiPanel
          placement={aiFor}
          onClose={() => setAiFor(null)}
          onFlagged={() => { flagsApi.reload(); setAiFor(null); }}
          onRequestFlag={() => { setFlagFor(aiFor); setAiFor(null); }}
        />
      )}
      {flagFor && (
        <PlacementFlagModal
          placement={flagFor}
          existingFlags={flagsByPlacement[flagFor.id] || []}
          onClose={() => setFlagFor(null)}
          onSaved={() => { flagsApi.reload(); setFlagFor(null); }}
        />
      )}
    </Card>
  );
}

/* ===================== Headcount breakdown ===================== */

function HeadcountBreakdown({ breakdown }) {
  const cls = breakdown.by_classification || [];
  const states = breakdown.by_state || [];
  return (
    <Card>
      <h2 style={{ fontSize: 16, fontWeight: 600, marginBottom: 12 }}>Headcount breakdown</h2>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
        <div>
          <div style={{ fontSize: 11, fontWeight: 600, color: '#64748b',
                        textTransform: 'uppercase', letterSpacing: 0.4, marginBottom: 6 }}>By classification</div>
          <table className="data-table" data-testid="headcount-classification">
            <tbody>
              {cls.map(r => (
                <tr key={r.classification}>
                  <td style={{ textTransform: 'uppercase' }}>{r.classification || '—'}</td>
                  <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums', fontWeight: 500 }}>{r.c}</td>
                </tr>
              ))}
              {cls.length === 0 && <tr><td colSpan={2} style={{ color: '#64748b', textAlign: 'center', padding: 12 }}>—</td></tr>}
            </tbody>
          </table>
        </div>
        <div>
          <div style={{ fontSize: 11, fontWeight: 600, color: '#64748b',
                        textTransform: 'uppercase', letterSpacing: 0.4, marginBottom: 6 }}>By home state</div>
          <table className="data-table" data-testid="headcount-state">
            <tbody>
              {states.map(r => (
                <tr key={r.state}>
                  <td>{r.state}</td>
                  <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums', fontWeight: 500 }}>{r.c}</td>
                </tr>
              ))}
              {states.length === 0 && <tr><td colSpan={2} style={{ color: '#64748b', textAlign: 'center', padding: 12 }}>—</td></tr>}
            </tbody>
          </table>
        </div>
      </div>
    </Card>
  );
}

/* ===================== AI panel + Flag modal (placement actions) ===================== */

const REASON_OPTIONS = [
  { v: 'low_margin',                l: 'Low / negative margin' },
  { v: 'stale_unsigned_timesheet',  l: 'Stale or unsigned timesheet' },
  { v: 'rate_outdated',             l: 'Rate looks outdated' },
  { v: 'missing_data',              l: 'Missing recruiter / rate / data' },
  { v: 'other',                     l: 'Other' },
];

function PlacementAiPanel({ placement, onClose, onFlagged, onRequestFlag }) {
  const [busy,    setBusy]    = useState(true);
  const [err,     setErr]     = useState(null);
  const [insight, setInsight] = useState(null);
  const [flagging,setFlagging]= useState(false);

  React.useEffect(() => {
    let cancelled = false;
    (async () => {
      setBusy(true); setErr(null);
      try {
        const res = await api.post('/api/reports_ai_explain.php', {
          entity_type: 'placement', entity_id: placement.id,
        });
        if (!cancelled) setInsight(res);
      } catch (e) { if (!cancelled) setErr(e.message || 'AI unavailable'); }
      finally    { if (!cancelled) setBusy(false); }
    })();
    return () => { cancelled = true; };
  }, [placement.id]);

  const acceptRecommendedFlag = async () => {
    if (!insight?.recommended_flag) return;
    setFlagging(true);
    try {
      await api.post('/api/review_flags.php', {
        entity_type: 'placement',
        entity_id:   placement.id,
        reason_code: insight.recommended_flag.reason_code,
        severity:    insight.recommended_flag.severity,
        notes:       insight.recommended_flag.rationale,
      });
      onFlagged();
    } catch (e) { setErr(e.message || 'Could not flag'); }
    finally    { setFlagging(false); }
  };

  return (
    <ModalShell title={`AI insight — ${placement.candidate || 'placement'} @ ${placement.client}`}
                onClose={onClose} testid="placement-ai-panel" wide>
      {busy && (
        <div style={{ display: 'flex', gap: 8, alignItems: 'center', color: '#64748b' }}>
          <Loader2 size={14} className="spin" /> Asking the model…
        </div>
      )}
      {err && <p style={{ color: '#b91c1c' }}>{err}</p>}
      {insight && (
        <>
          <div style={{
            background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: 8,
            padding: 14, marginBottom: 12, fontSize: 14, lineHeight: 1.55,
            whiteSpace: 'pre-wrap',
          }} data-testid="placement-ai-answer">
            {insight.answer}
          </div>

          <div style={{ display: 'flex', gap: 12, alignItems: 'center', fontSize: 12, color: '#64748b', marginBottom: 12 }}>
            <span>
              <Swirl size={12} style={{ marginRight: 4 }} />
              source: <strong>{insight.source}</strong>
            </span>
            {insight.confidence != null && (
              <span>confidence: <strong>{Math.round(insight.confidence * 100)}%</strong></span>
            )}
          </div>

          {insight.recommended_flag && (
            <div style={{
              background: '#fef3c7', border: '1px solid #f59e0b', borderRadius: 8,
              padding: 12, marginBottom: 12,
            }} data-testid="placement-ai-recommended-flag">
              <div style={{ fontSize: 12, fontWeight: 600, color: '#92400e', marginBottom: 4 }}>
                <AlertTriangle size={12} style={{ display: 'inline', marginRight: 4 }} />
                Recommended flag: {insight.recommended_flag.reason_code.replace(/_/g, ' ')}
              </div>
              <div style={{ fontSize: 13, color: '#78350f' }}>
                {insight.recommended_flag.rationale}
              </div>
            </div>
          )}

          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
            <Link to={`/modules/placements/list/${placement.id}`}
                  className="btn btn--ghost"
                  data-testid="placement-ai-open-placement">
              Open placement
            </Link>
            {insight.recommended_flag && (
              <button className="btn btn--primary"
                      onClick={acceptRecommendedFlag}
                      disabled={flagging}
                      data-testid="placement-ai-accept-flag">
                <Flag size={14} /> {flagging ? 'Flagging…' : 'Apply this flag'}
              </button>
            )}
            <button className="btn btn--ghost"
                    onClick={onRequestFlag}
                    data-testid="placement-ai-custom-flag">
              <Flag size={14} /> Custom flag…
            </button>
          </div>
        </>
      )}
    </ModalShell>
  );
}

function PlacementFlagModal({ placement, existingFlags, onClose, onSaved }) {
  const [reason,   setReason]   = useState(REASON_OPTIONS[0].v);
  const [severity, setSeverity] = useState('warn');
  const [notes,    setNotes]    = useState('');
  const [busy,     setBusy]     = useState(false);
  const [err,      setErr]      = useState(null);

  const onSubmit = async (e) => {
    e.preventDefault();
    setBusy(true); setErr(null);
    try {
      await api.post('/api/review_flags.php', {
        entity_type: 'placement', entity_id: placement.id,
        reason_code: reason, severity, notes,
      });
      onSaved();
    } catch (e) { setErr(e.message || 'Save failed'); }
    finally    { setBusy(false); }
  };

  const onResolve = async (id) => {
    try {
      await api.patch(`/api/review_flags.php?id=${id}`, { status: 'resolved' });
      onSaved();
    } catch (e) { alert(e.message || 'Resolve failed'); }
  };

  return (
    <ModalShell title={`Flags — ${placement.candidate || 'placement'} @ ${placement.client}`}
                onClose={onClose} testid="placement-flag-modal">
      {existingFlags.length > 0 && (
        <div style={{ marginBottom: 16 }}>
          <div style={{ fontSize: 12, fontWeight: 600, color: '#64748b',
                        textTransform: 'uppercase', letterSpacing: 0.4, marginBottom: 6 }}>
            Open flags
          </div>
          {existingFlags.map(f => (
            <div key={f.id} style={{
              border: '1px solid #e2e8f0', borderRadius: 8, padding: 10, marginBottom: 8,
              display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8,
            }} data-testid={`placement-flag-existing-${f.id}`}>
              <div>
                <div style={{ fontSize: 13, fontWeight: 600, textTransform: 'capitalize' }}>
                  {String(f.reason_code).replace(/_/g, ' ')}
                  <span className={`badge badge--${f.severity === 'critical' ? 'critical' : 'warn'}`}
                        style={{ marginLeft: 8, fontSize: 10 }}>{f.severity}</span>
                </div>
                {f.notes && <div style={{ fontSize: 12, color: '#64748b', marginTop: 2 }}>{f.notes}</div>}
                <div style={{ fontSize: 11, color: '#94a3b8', marginTop: 2 }}>
                  Flagged by {f.flagged_by_name || '—'} · {f.created_at}
                </div>
              </div>
              <button className="btn btn--ghost" onClick={() => onResolve(f.id)}
                      data-testid={`placement-flag-resolve-${f.id}`}
                      style={{ color: '#10b981' }}>
                <CheckCircle2 size={14} /> Resolve
              </button>
            </div>
          ))}
        </div>
      )}

      <form onSubmit={onSubmit} data-testid="placement-flag-form">
        <div style={{ fontSize: 12, fontWeight: 600, color: '#64748b',
                      textTransform: 'uppercase', letterSpacing: 0.4, marginBottom: 6 }}>
          New flag
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: 8, marginBottom: 10 }}>
          <select className="input" value={reason} onChange={(e) => setReason(e.target.value)}
                  data-testid="placement-flag-reason">
            {REASON_OPTIONS.map(o => <option key={o.v} value={o.v}>{o.l}</option>)}
          </select>
          <select className="input" value={severity} onChange={(e) => setSeverity(e.target.value)}
                  data-testid="placement-flag-severity">
            <option value="info">Info</option>
            <option value="warn">Warn</option>
            <option value="critical">Critical</option>
          </select>
        </div>
        <textarea className="input" rows={3} value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                  placeholder="Optional context for the team…"
                  data-testid="placement-flag-notes"
                  style={{ width: '100%', marginBottom: 10 }} />
        {err && <p style={{ color: '#b91c1c', marginBottom: 8 }}>{err}</p>}
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
          <button type="button" className="btn btn--ghost" onClick={onClose}>Cancel</button>
          <button type="submit" className="btn btn--primary" disabled={busy}
                  data-testid="placement-flag-submit">
            <Flag size={14} /> {busy ? 'Saving…' : 'Add flag'}
          </button>
        </div>
      </form>
    </ModalShell>
  );
}

function ModalShell({ title, onClose, children, testid, wide }) {
  return (
    <div style={{
      position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.4)', zIndex: 1000,
      display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16,
    }} onClick={onClose} data-testid={testid}>
      <div style={{
        background: '#fff', borderRadius: 12, padding: 24,
        maxWidth: wide ? 640 : 480, width: '100%',
        boxShadow: '0 10px 40px rgba(0,0,0,0.2)',
      }} onClick={(e) => e.stopPropagation()}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
          <h2 style={{ fontSize: 16, fontWeight: 600 }}>{title}</h2>
          <button onClick={onClose} className="btn btn--ghost"><X size={14} /></button>
        </div>
        {children}
      </div>
    </div>
  );
}


/* ===================== Recruiter-row actions ===================== */

function RecruiterAiPanel({ recruiter, onClose, onFlagged, onRequestFlag }) {
  const [busy, setBusy]       = useState(true);
  const [err, setErr]         = useState(null);
  const [insight, setInsight] = useState(null);
  const [flagging, setFlagging] = useState(false);

  React.useEffect(() => {
    let cancelled = false;
    (async () => {
      setBusy(true); setErr(null);
      try {
        const res = await api.post('/api/reports_ai_explain.php', {
          entity_type: 'recruiter', entity_id: recruiter.recruiter_id,
        });
        if (!cancelled) setInsight(res);
      } catch (e) { if (!cancelled) setErr(e.message || 'AI unavailable'); }
      finally    { if (!cancelled) setBusy(false); }
    })();
    return () => { cancelled = true; };
  }, [recruiter.recruiter_id]);

  const acceptRecommendedFlag = async () => {
    if (!insight?.recommended_flag) return;
    setFlagging(true);
    try {
      await api.post('/api/review_flags.php', {
        entity_type: 'recruiter',
        entity_id:   recruiter.recruiter_id,
        reason_code: insight.recommended_flag.reason_code,
        severity:    insight.recommended_flag.severity,
        notes:       insight.recommended_flag.rationale,
      });
      onFlagged();
    } catch (e) { setErr(e.message || 'Could not flag'); }
    finally    { setFlagging(false); }
  };

  return (
    <ModalShell title={`AI insight — ${recruiter.name}`} onClose={onClose}
                testid="recruiter-ai-panel" wide>
      {busy && (
        <div style={{ display: 'flex', gap: 8, alignItems: 'center', color: '#64748b' }}>
          <Loader2 size={14} className="spin" /> Asking the model…
        </div>
      )}
      {err && <p style={{ color: '#b91c1c' }}>{err}</p>}
      {insight && (
        <>
          <div style={{
            background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: 8,
            padding: 14, marginBottom: 12, fontSize: 14, lineHeight: 1.55,
            whiteSpace: 'pre-wrap',
          }} data-testid="recruiter-ai-answer">
            {insight.answer}
          </div>
          <div style={{ display: 'flex', gap: 12, alignItems: 'center', fontSize: 12, color: '#64748b', marginBottom: 12 }}>
            <span>
              <Swirl size={12} style={{ marginRight: 4 }} />
              source: <strong>{insight.source}</strong>
            </span>
            {insight.confidence != null && (
              <span>confidence: <strong>{Math.round(insight.confidence * 100)}%</strong></span>
            )}
            {insight.context?.team_median_margin_per_hour_90d != null && (
              <span>team median: <strong>{fmtMoney(insight.context.team_median_margin_per_hour_90d)}/hr</strong></span>
            )}
          </div>
          {insight.recommended_flag && (
            <div style={{
              background: '#fef3c7', border: '1px solid #f59e0b', borderRadius: 8,
              padding: 12, marginBottom: 12,
            }} data-testid="recruiter-ai-recommended-flag">
              <div style={{ fontSize: 12, fontWeight: 600, color: '#92400e', marginBottom: 4 }}>
                <AlertTriangle size={12} style={{ display: 'inline', marginRight: 4 }} />
                Recommended flag: {insight.recommended_flag.reason_code.replace(/_/g, ' ')}
              </div>
              <div style={{ fontSize: 13, color: '#78350f' }}>
                {insight.recommended_flag.rationale}
              </div>
            </div>
          )}
          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
            {insight.recommended_flag && (
              <button className="btn btn--primary"
                      onClick={acceptRecommendedFlag}
                      disabled={flagging}
                      data-testid="recruiter-ai-accept-flag">
                <Flag size={14} /> {flagging ? 'Flagging…' : 'Apply this flag'}
              </button>
            )}
            <button className="btn btn--ghost"
                    onClick={onRequestFlag}
                    data-testid="recruiter-ai-custom-flag">
              <Flag size={14} /> Custom flag…
            </button>
          </div>
        </>
      )}
    </ModalShell>
  );
}

function RecruiterFlagModal({ recruiter, existingFlags, onClose, onSaved }) {
  const [reason,   setReason]   = useState(REASON_OPTIONS[0].v);
  const [severity, setSeverity] = useState('warn');
  const [notes,    setNotes]    = useState('');
  const [busy,     setBusy]     = useState(false);
  const [err,      setErr]      = useState(null);

  const onSubmit = async (e) => {
    e.preventDefault();
    setBusy(true); setErr(null);
    try {
      await api.post('/api/review_flags.php', {
        entity_type: 'recruiter', entity_id: recruiter.recruiter_id,
        reason_code: reason, severity, notes,
      });
      onSaved();
    } catch (e) { setErr(e.message || 'Save failed'); }
    finally    { setBusy(false); }
  };

  const onResolve = async (id) => {
    try {
      await api.patch(`/api/review_flags.php?id=${id}`, { status: 'resolved' });
      onSaved();
    } catch (e) { alert(e.message || 'Resolve failed'); }
  };

  return (
    <ModalShell title={`Flags — ${recruiter.name}`} onClose={onClose}
                testid="recruiter-flag-modal">
      {existingFlags.length > 0 && (
        <div style={{ marginBottom: 16 }}>
          <div style={{ fontSize: 12, fontWeight: 600, color: '#64748b',
                        textTransform: 'uppercase', letterSpacing: 0.4, marginBottom: 6 }}>
            Open flags
          </div>
          {existingFlags.map(f => (
            <div key={f.id} style={{
              border: '1px solid #e2e8f0', borderRadius: 8, padding: 10, marginBottom: 8,
              display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8,
            }} data-testid={`recruiter-flag-existing-${f.id}`}>
              <div>
                <div style={{ fontSize: 13, fontWeight: 600, textTransform: 'capitalize' }}>
                  {String(f.reason_code).replace(/_/g, ' ')}
                  <span className={`badge badge--${f.severity === 'critical' ? 'critical' : 'warn'}`}
                        style={{ marginLeft: 8, fontSize: 10 }}>{f.severity}</span>
                </div>
                {f.notes && <div style={{ fontSize: 12, color: '#64748b', marginTop: 2 }}>{f.notes}</div>}
                <div style={{ fontSize: 11, color: '#94a3b8', marginTop: 2 }}>
                  Flagged by {f.flagged_by_name || '—'} · {f.created_at}
                </div>
              </div>
              <button className="btn btn--ghost" onClick={() => onResolve(f.id)}
                      data-testid={`recruiter-flag-resolve-${f.id}`}
                      style={{ color: '#10b981' }}>
                <CheckCircle2 size={14} /> Resolve
              </button>
            </div>
          ))}
        </div>
      )}
      <form onSubmit={onSubmit} data-testid="recruiter-flag-form">
        <div style={{ fontSize: 12, fontWeight: 600, color: '#64748b',
                      textTransform: 'uppercase', letterSpacing: 0.4, marginBottom: 6 }}>
          New flag
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: 8, marginBottom: 10 }}>
          <select className="input" value={reason} onChange={(e) => setReason(e.target.value)}
                  data-testid="recruiter-flag-reason">
            {REASON_OPTIONS.map(o => <option key={o.v} value={o.v}>{o.l}</option>)}
          </select>
          <select className="input" value={severity} onChange={(e) => setSeverity(e.target.value)}
                  data-testid="recruiter-flag-severity">
            <option value="info">Info</option>
            <option value="warn">Warn</option>
            <option value="critical">Critical</option>
          </select>
        </div>
        <textarea className="input" rows={3} value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                  placeholder="Optional context for the team…"
                  data-testid="recruiter-flag-notes"
                  style={{ width: '100%', marginBottom: 10 }} />
        {err && <p style={{ color: '#b91c1c', marginBottom: 8 }}>{err}</p>}
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
          <button type="button" className="btn btn--ghost" onClick={onClose}>Cancel</button>
          <button type="submit" className="btn btn--primary" disabled={busy}
                  data-testid="recruiter-flag-submit">
            <Flag size={14} /> {busy ? 'Saving…' : 'Add flag'}
          </button>
        </div>
      </form>
    </ModalShell>
  );
}

