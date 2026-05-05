import React, { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useApi } from '../lib/api';
import { Card } from '../components/UIComponents';
import { fmtMoney } from '../lib/format';
import {
  Users, Trophy, Briefcase, RefreshCw, Search,
  ArrowUp, ArrowDown, ArrowUpDown,
} from 'lucide-react';

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
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end',
                    marginBottom: 'var(--cf-space-6)', flexWrap: 'wrap', gap: 12 }}>
        <div>
          <h1 style={{ fontSize: 'var(--cf-text-2xl)', fontWeight: 700, marginBottom: 4 }}>
            <Users size={22} style={{ display: 'inline', marginRight: 8 }} />
            Staffing operations
          </h1>
          <p style={{ color: 'var(--cf-text-secondary)', fontSize: 14 }}>
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
  const [key, setKey] = useState('period_margin');
  const [dir, setDir] = useState('desc');
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
          </tr>
        </thead>
        <tbody>
          {sorted.map((r, i) => (
            <tr key={r.recruiter_id} data-testid={`recruiter-row-${r.recruiter_id}`}>
              <td style={{ fontWeight: 500 }}>{i + 1}</td>
              <td style={{ fontWeight: 500 }}>{r.name}</td>
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
            </tr>
          ))}
          {sorted.length === 0 && (
            <tr><td colSpan={8} style={{ textAlign: 'center', padding: 24, color: '#64748b' }}>
              No recruiter activity in the selected period.
            </td></tr>
          )}
        </tbody>
      </table>
    </Card>
  );
}

/* ===================== Placement margin table ===================== */

function PlacementMarginTable({ rows }) {
  const [q, setQ]     = useState('');
  const [key, setKey] = useState('period_margin');
  const [dir, setDir] = useState('desc');

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

  // Aggregate footer
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
          </tr>
        </thead>
        <tbody>
          {sorted.map(r => (
            <tr key={r.id} data-testid={`placement-row-${r.id}`}>
              <td>
                <Link to={`/modules/placements/list/${r.id}`} className="link"
                      data-testid={`placement-link-${r.id}`}>{r.candidate}</Link>
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
            </tr>
          ))}
          {sorted.length === 0 && (
            <tr><td colSpan={11} style={{ textAlign: 'center', padding: 24, color: '#64748b' }}>
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
            </tr>
          </tfoot>
        )}
      </table>
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
