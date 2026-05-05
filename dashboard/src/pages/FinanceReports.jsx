import React, { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useApi } from '../lib/api';
import { Card } from '../components/UIComponents';
import LineChart from '../components/LineChart';
import { fmtMoney } from '../lib/format';
import {
  DollarSign, TrendingUp, TrendingDown, Calendar, RefreshCw,
  Search, ArrowUpDown, ArrowUp, ArrowDown, FileText,
} from 'lucide-react';

/**
 * FinanceReports — drill page under /modules/reports/finance.
 *
 * Layout:
 *   1. Period chooser (date range + vs. prior year toggle).
 *   2. P&L summary card with prior-period comparison column.
 *   3. Cash flow waterfall (Beginning → Receipts → -Operating → -Payroll → Ending).
 *   4. AR detail table (filterable, sortable, click-through to invoice).
 *   5. AP detail table (filterable, sortable, click-through to bill).
 *
 * Pulls from /api/reports_finance.php with the same date-range contract
 * as the executive snapshot.
 */
const PRESETS = [
  ['mtd', 'MTD'], ['qtd', 'QTD'], ['ytd', 'YTD'],
  ['last_quarter', 'Last quarter'], ['last_year', 'Last year'],
];

export default function FinanceReports() {
  const today = new Date();
  const iso = (d) => d.toISOString().slice(0, 10);
  const defaultFrom = new Date(today.getFullYear(), today.getMonth(), 1);

  const [from,    setFrom]    = useState(iso(defaultFrom));
  const [to,      setTo]      = useState(iso(today));
  const [compare, setCompare] = useState(false);

  const qs = useMemo(() => {
    const p = new URLSearchParams({ from, to });
    if (compare) p.set('compare', 'prior_year');
    return p.toString();
  }, [from, to, compare]);

  const { data, loading, error, reload } = useApi(`/api/reports_finance.php?${qs}`);

  const applyPreset = (kind) => {
    const t = new Date(); let f, e = t;
    if (kind === 'mtd')  f = new Date(t.getFullYear(), t.getMonth(), 1);
    if (kind === 'qtd')  f = new Date(t.getFullYear(), Math.floor(t.getMonth() / 3) * 3, 1);
    if (kind === 'ytd')  f = new Date(t.getFullYear(), 0, 1);
    if (kind === 'last_quarter') {
      const q = Math.floor(t.getMonth() / 3);
      f = new Date(t.getFullYear(), (q - 1) * 3, 1);
      e = new Date(t.getFullYear(), q * 3, 0);
    }
    if (kind === 'last_year') {
      f = new Date(t.getFullYear() - 1, 0, 1);
      e = new Date(t.getFullYear() - 1, 11, 31);
    }
    setFrom(iso(f)); setTo(iso(e));
  };

  return (
    <div data-testid="finance-reports">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end',
                    marginBottom: 'var(--cf-space-6)', flexWrap: 'wrap', gap: 12 }}>
        <div>
          <h1 style={{ fontSize: 'var(--cf-text-2xl)', fontWeight: 700, marginBottom: 4 }}>
            <DollarSign size={22} style={{ display: 'inline', marginRight: 8 }} />
            Corporate finance
          </h1>
          <p style={{ color: 'var(--cf-text-secondary)', fontSize: 14 }}>
            P&amp;L · cash flow · AR / AP detail. Filter by date and toggle prior-year comparison.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 6, alignItems: 'center', flexWrap: 'wrap' }}>
          {PRESETS.map(([k, l]) => (
            <button key={k} onClick={() => applyPreset(k)}
                    className="btn btn--ghost"
                    data-testid={`finance-preset-${k}`}>{l}</button>
          ))}
          <input type="date" className="input" value={from}
                 onChange={(e) => setFrom(e.target.value)}
                 data-testid="finance-from" style={{ width: 160 }} />
          <span style={{ color: '#94a3b8' }}>→</span>
          <input type="date" className="input" value={to}
                 onChange={(e) => setTo(e.target.value)}
                 data-testid="finance-to" style={{ width: 160 }} />
          <button className={`btn ${compare ? 'btn--primary' : 'btn--ghost'}`}
                  onClick={() => setCompare(!compare)}
                  data-testid="finance-toggle-compare">
            <Calendar size={14} /> vs. prior year
          </button>
          <button className="btn btn--ghost" onClick={reload} data-testid="finance-refresh">
            <RefreshCw size={14} />
          </button>
        </div>
      </div>

      {loading && <Card><p>Loading…</p></Card>}
      {error && <Card><p style={{ color: '#b91c1c' }}>{error.message || String(error)}</p></Card>}

      {!loading && data && (
        <>
          <PnlCard pnl={data.pnl} compareEnabled={compare} />
          <CashFlowCard cashFlow={data.cash_flow} />
          <ArDetailTable rows={data.ar_detail || []} />
          <ApDetailTable rows={data.ap_detail || []} />
        </>
      )}
    </div>
  );
}

function PnlCard({ pnl, compareEnabled }) {
  if (!pnl) return null;
  const prev = pnl.prev_period;
  const diff = (curr, p) => {
    if (!prev || !p) return null;
    const delta = (curr || 0) - (p || 0);
    const pct = p ? (delta / p) * 100 : 0;
    return { delta, pct, up: delta >= 0 };
  };
  const Row = ({ label, value, prevValue, kind }) => {
    const d = compareEnabled ? diff(value, prevValue) : null;
    const isLoss = kind === 'expense';
    return (
      <tr data-testid={`pnl-row-${label.toLowerCase().replace(/[^a-z]+/g, '-')}`}>
        <td style={{ fontWeight: kind === 'total' ? 600 : 500 }}>{label}</td>
        <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums', fontWeight: kind === 'total' ? 600 : 400 }}>
          {fmtMoney(value)}
        </td>
        {compareEnabled && (
          <td style={{ textAlign: 'right', color: '#64748b', fontVariantNumeric: 'tabular-nums' }}>
            {fmtMoney(prevValue || 0)}
          </td>
        )}
        {compareEnabled && (
          <td style={{ textAlign: 'right', fontSize: 12, fontVariantNumeric: 'tabular-nums',
                      color: !d ? '#94a3b8' : (isLoss ? (!d.up ? '#10b981' : '#ef4444') : (d.up ? '#10b981' : '#ef4444')) }}>
            {d
              ? <>{d.up ? <TrendingUp size={11} style={{ display: 'inline', marginRight: 2 }} />
                          : <TrendingDown size={11} style={{ display: 'inline', marginRight: 2 }} />}
                  {d.pct.toFixed(1)}%</>
              : '—'}
          </td>
        )}
      </tr>
    );
  };
  return (
    <Card style={{ marginBottom: 16 }}>
      <h2 style={{ fontSize: 16, fontWeight: 600, marginBottom: 12 }}>Income statement</h2>
      <table className="data-table" data-testid="pnl-table">
        <thead>
          <tr>
            <th></th>
            <th style={{ textAlign: 'right' }}>This period</th>
            {compareEnabled && <th style={{ textAlign: 'right' }}>Prior year</th>}
            {compareEnabled && <th style={{ textAlign: 'right' }}>Δ</th>}
          </tr>
        </thead>
        <tbody>
          <Row label="Revenue"           value={pnl.revenue}      prevValue={prev?.revenue} />
          <Row label="Direct cost"       value={pnl.direct_cost}  prevValue={prev?.direct_cost} kind="expense" />
          <Row label="Gross margin"      value={pnl.gross_margin} prevValue={prev?.gross_margin} kind="total" />
          <Row label="Indirect costs"    value={pnl.indirect}     prevValue={prev?.indirect} kind="expense" />
          <Row label="Net income"        value={pnl.net_income}   prevValue={prev?.net_income} kind="total" />
        </tbody>
      </table>
      <div style={{ display: 'flex', gap: 16, marginTop: 12, fontSize: 13, color: '#64748b' }}>
        <span>Gross margin %: <strong>{pnl.gross_pct?.toFixed(1)}%</strong></span>
        <span>Net %: <strong>{pnl.net_pct?.toFixed(1)}%</strong></span>
      </div>
    </Card>
  );
}

function CashFlowCard({ cashFlow }) {
  if (!cashFlow) return null;
  const tiles = [
    { l: 'Beginning cash',    v: cashFlow.beginning, color: '#64748b' },
    { l: '+ Receipts',        v: cashFlow.receipts,  color: '#10b981' },
    { l: '− Operating',       v: -cashFlow.operating, color: '#f97316' },
    { l: '− Payroll',         v: -cashFlow.payroll,   color: '#ef4444' },
    { l: 'Ending cash',       v: cashFlow.ending,    color: '#2563eb' },
  ];
  return (
    <Card style={{ marginBottom: 16 }}>
      <h2 style={{ fontSize: 16, fontWeight: 600, marginBottom: 12 }}>Cash flow</h2>
      <div style={{ display: 'grid', gridTemplateColumns: `repeat(${tiles.length}, 1fr)`, gap: 8 }}
           data-testid="cash-flow-waterfall">
        {tiles.map((t, i) => (
          <div key={i} style={{
            background: i === 0 || i === tiles.length - 1 ? '#f1f5f9' : '#fff',
            border: '1px solid #e2e8f0', borderRadius: 8, padding: '12px 14px',
            position: 'relative',
          }} data-testid={`cf-${t.l.toLowerCase().replace(/[^a-z]+/g, '-')}`}>
            <div style={{ fontSize: 11, fontWeight: 600, color: '#64748b',
                          textTransform: 'uppercase', letterSpacing: 0.4, marginBottom: 4 }}>
              {t.l}
            </div>
            <div style={{ fontSize: 18, fontWeight: 700, color: t.color, fontVariantNumeric: 'tabular-nums' }}>
              {fmtMoney(t.v)}
            </div>
          </div>
        ))}
      </div>
      {cashFlow.trend?.length > 0 && (
        <div style={{ marginTop: 16 }}>
          <div style={{ fontSize: 11, fontWeight: 600, color: '#64748b',
                        textTransform: 'uppercase', letterSpacing: 0.4, marginBottom: 6 }}>
            Weekly net cash receipts
          </div>
          <LineChart
            height={200}
            format={fmtMoney}
            series={[{ name: 'Net receipts', color: '#10b981', data: cashFlow.trend }]}
          />
        </div>
      )}
    </Card>
  );
}

/* ===================== AR / AP detail tables ===================== */

function useSortableFilter(rows, defaultSortKey) {
  const [q, setQ]       = useState('');
  const [key, setKey]   = useState(defaultSortKey);
  const [dir, setDir]   = useState('desc');

  const filtered = useMemo(() => {
    let out = rows;
    if (q) {
      const ql = q.toLowerCase();
      out = out.filter(r => Object.values(r).some(v => String(v ?? '').toLowerCase().includes(ql)));
    }
    return [...out].sort((a, b) => {
      const av = a[key], bv = b[key];
      if (av === bv) return 0;
      const cmp = av > bv ? 1 : -1;
      return dir === 'asc' ? cmp : -cmp;
    });
  }, [rows, q, key, dir]);

  const sortBy = (k) => {
    if (k === key) setDir(dir === 'asc' ? 'desc' : 'asc');
    else { setKey(k); setDir('desc'); }
  };
  const headerProps = (k) => ({
    onClick: () => sortBy(k),
    style: { cursor: 'pointer', userSelect: 'none' },
    children: (
      <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}>
        {k === key
          ? (dir === 'asc' ? <ArrowUp size={12} /> : <ArrowDown size={12} />)
          : <ArrowUpDown size={12} style={{ opacity: 0.4 }} />}
      </span>
    ),
  });

  return { q, setQ, sortBy, headerProps, filtered };
}

function ArDetailTable({ rows }) {
  const { q, setQ, sortBy, headerProps, filtered } = useSortableFilter(rows, 'days_overdue');
  return (
    <Card style={{ marginBottom: 16 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
        <h2 style={{ fontSize: 16, fontWeight: 600 }}>Outstanding invoices ({rows.length})</h2>
        <div style={{ display: 'flex', alignItems: 'center', gap: 6, color: '#64748b' }}>
          <Search size={14} />
          <input className="input" placeholder="Filter…" value={q}
                 onChange={(e) => setQ(e.target.value)}
                 data-testid="ar-filter" style={{ width: 220 }} />
        </div>
      </div>
      <table className="data-table" data-testid="ar-detail-table">
        <thead>
          <tr>
            <th onClick={() => sortBy('invoice_number')} style={{ cursor: 'pointer' }}>Invoice</th>
            <th onClick={() => sortBy('client_name')}    style={{ cursor: 'pointer' }}>Client</th>
            <th onClick={() => sortBy('issue_date')}     style={{ cursor: 'pointer' }}>Issued</th>
            <th onClick={() => sortBy('due_date')}       style={{ cursor: 'pointer' }}>Due</th>
            <th onClick={() => sortBy('total')}          style={{ cursor: 'pointer', textAlign: 'right' }}>Total</th>
            <th onClick={() => sortBy('outstanding')}    style={{ cursor: 'pointer', textAlign: 'right' }}>Outstanding</th>
            <th onClick={() => sortBy('days_overdue')}   style={{ cursor: 'pointer', textAlign: 'right' }}>Days overdue</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          {filtered.map(r => (
            <tr key={r.id} data-testid={`ar-row-${r.id}`}>
              <td>
                <Link to={`/modules/billing/invoices/${r.id}`} className="link"
                      data-testid={`ar-link-${r.id}`}>
                  <FileText size={12} style={{ display: 'inline', marginRight: 4 }} />
                  {r.invoice_number}
                </Link>
              </td>
              <td>{r.client_name}</td>
              <td>{r.issue_date}</td>
              <td>{r.due_date}</td>
              <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{fmtMoney(r.total)}</td>
              <td style={{ textAlign: 'right', fontWeight: 500, fontVariantNumeric: 'tabular-nums' }}>{fmtMoney(r.outstanding)}</td>
              <td style={{ textAlign: 'right', color: r.days_overdue > 30 ? '#b91c1c' : '#64748b', fontVariantNumeric: 'tabular-nums' }}>
                {r.days_overdue > 0 ? r.days_overdue : '—'}
              </td>
              <td><span className={`badge badge--${r.status === 'partially_paid' ? 'warn' : 'info'}`}>{r.status}</span></td>
            </tr>
          ))}
          {filtered.length === 0 && (
            <tr><td colSpan={8} style={{ textAlign: 'center', padding: 24, color: '#64748b' }}>
              No outstanding invoices.
            </td></tr>
          )}
        </tbody>
      </table>
    </Card>
  );
}

function ApDetailTable({ rows }) {
  const { q, setQ, sortBy, headerProps, filtered } = useSortableFilter(rows, 'days_overdue');
  return (
    <Card>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
        <h2 style={{ fontSize: 16, fontWeight: 600 }}>Outstanding bills ({rows.length})</h2>
        <div style={{ display: 'flex', alignItems: 'center', gap: 6, color: '#64748b' }}>
          <Search size={14} />
          <input className="input" placeholder="Filter…" value={q}
                 onChange={(e) => setQ(e.target.value)}
                 data-testid="ap-filter" style={{ width: 220 }} />
        </div>
      </div>
      <table className="data-table" data-testid="ap-detail-table">
        <thead>
          <tr>
            <th onClick={() => sortBy('bill_number')}    style={{ cursor: 'pointer' }}>Bill</th>
            <th onClick={() => sortBy('vendor_name')}    style={{ cursor: 'pointer' }}>Vendor</th>
            <th onClick={() => sortBy('issue_date')}     style={{ cursor: 'pointer' }}>Issued</th>
            <th onClick={() => sortBy('due_date')}       style={{ cursor: 'pointer' }}>Due</th>
            <th onClick={() => sortBy('total')}          style={{ cursor: 'pointer', textAlign: 'right' }}>Total</th>
            <th onClick={() => sortBy('outstanding')}    style={{ cursor: 'pointer', textAlign: 'right' }}>Outstanding</th>
            <th onClick={() => sortBy('days_overdue')}   style={{ cursor: 'pointer', textAlign: 'right' }}>Days overdue</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          {filtered.map(r => (
            <tr key={r.id} data-testid={`ap-row-${r.id}`}>
              <td>
                <Link to={`/modules/ap/bills/${r.id}`} className="link"
                      data-testid={`ap-link-${r.id}`}>
                  <FileText size={12} style={{ display: 'inline', marginRight: 4 }} />
                  {r.bill_number}
                </Link>
              </td>
              <td>{r.vendor_name}</td>
              <td>{r.issue_date}</td>
              <td>{r.due_date}</td>
              <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{fmtMoney(r.total)}</td>
              <td style={{ textAlign: 'right', fontWeight: 500, fontVariantNumeric: 'tabular-nums' }}>{fmtMoney(r.outstanding)}</td>
              <td style={{ textAlign: 'right', color: r.days_overdue > 30 ? '#b91c1c' : '#64748b', fontVariantNumeric: 'tabular-nums' }}>
                {r.days_overdue > 0 ? r.days_overdue : '—'}
              </td>
              <td><span className={`badge badge--${r.status === 'partially_paid' ? 'warn' : 'info'}`}>{r.status}</span></td>
            </tr>
          ))}
          {filtered.length === 0 && (
            <tr><td colSpan={8} style={{ textAlign: 'center', padding: 24, color: '#64748b' }}>
              No outstanding bills.
            </td></tr>
          )}
        </tbody>
      </table>
    </Card>
  );
}
