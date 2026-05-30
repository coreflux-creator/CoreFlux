import React, { useState, useEffect } from 'react';
import { api } from '../lib/api';

/**
 * Consolidated Reports across sub-tenants of a master.
 *
 * Master-only view: rolls up Income Statement / Balance Sheet / Cash Flow
 * across every active sub-tenant (and optionally the master itself).
 * Each report shows the consolidated total + a per-sub breakdown, so a
 * holding company can see fleet-wide P&L without leaving CoreFlux.
 */
export default function SubTenantConsolidatedReports() {
  const [type, setType] = useState('income_statement');
  const today = new Date().toISOString().slice(0, 10);
  const yearStart = today.slice(0, 4) + '-01-01';
  const [from, setFrom] = useState(yearStart);
  const [to, setTo] = useState(today);
  const [asOf, setAsOf] = useState(today);
  const [includeMaster, setIncludeMaster] = useState(false);
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  const load = async () => {
    setLoading(true);
    setError(null);
    setData(null);
    const qs = new URLSearchParams({ type });
    if (type === 'balance_sheet') qs.set('as_of', asOf);
    else { qs.set('from', from); qs.set('to', to); }
    if (includeMaster) qs.set('include_master', '1');
    try {
      const r = await api.get('/api/sub_tenant_consolidated_reports.php?' + qs.toString());
      setData(r);
    } catch (e) {
      setError(e);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { load(); }, []); // eslint-disable-line react-hooks/exhaustive-deps

  return (
    <section data-testid="sub-tenant-consolidated-reports" style={{ padding: 'var(--cf-space-4)' }}>
      <header style={{
        display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end',
        flexWrap: 'wrap', gap: 12,
        position: 'sticky', top: 0, zIndex: 5,
        background: 'linear-gradient(180deg, #fff 0%, #fff 88%, rgba(255,255,255,0) 100%)',
        padding: '12px 0 14px',
        borderBottom: '1px solid #e2e8f0',
        marginBottom: 16,
      }}>
        <div style={{ flex: 1, minWidth: 260 }}>
          <h1 style={{ margin: 0, fontSize: 22, fontWeight: 700,
                       color: '#0f172a', letterSpacing: '-0.01em' }}>
            Consolidated Reports
          </h1>
          <p style={{ margin: '4px 0 0', fontSize: 13, color: '#64748b' }}>
            Income statement · balance sheet · cash flow rolled up across all active sub-tenants.
          </p>
        </div>
      </header>

      <div style={{ display: 'flex', gap: 12, alignItems: 'flex-end', flexWrap: 'wrap', marginBottom: 'var(--cf-space-4)' }}>
        <label style={{ display: 'flex', flexDirection: 'column', fontSize: 12 }}>
          <span style={{ color: 'var(--cf-text-secondary)' }}>Report</span>
          <select className="input" value={type} onChange={(e) => setType(e.target.value)} data-testid="cr-type">
            <option value="income_statement">Income Statement</option>
            <option value="balance_sheet">Balance Sheet</option>
            <option value="cash_flow_indirect">Cash Flow (indirect)</option>
          </select>
        </label>
        {type === 'balance_sheet' ? (
          <label style={{ display: 'flex', flexDirection: 'column', fontSize: 12 }}>
            <span style={{ color: 'var(--cf-text-secondary)' }}>As of</span>
            <input type="date" className="input" value={asOf} onChange={(e) => setAsOf(e.target.value)} data-testid="cr-asof" />
          </label>
        ) : (
          <>
            <label style={{ display: 'flex', flexDirection: 'column', fontSize: 12 }}>
              <span style={{ color: 'var(--cf-text-secondary)' }}>From</span>
              <input type="date" className="input" value={from} onChange={(e) => setFrom(e.target.value)} data-testid="cr-from" />
            </label>
            <label style={{ display: 'flex', flexDirection: 'column', fontSize: 12 }}>
              <span style={{ color: 'var(--cf-text-secondary)' }}>To</span>
              <input type="date" className="input" value={to} onChange={(e) => setTo(e.target.value)} data-testid="cr-to" />
            </label>
          </>
        )}
        <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13 }}>
          <input type="checkbox" checked={includeMaster} onChange={(e) => setIncludeMaster(e.target.checked)} data-testid="cr-include-master" />
          Include master tenant
        </label>
        <button className="btn btn--primary" onClick={load} disabled={loading} data-testid="cr-refresh">
          {loading ? 'Loading…' : 'Refresh'}
        </button>
      </div>

      {error && <p className="error" data-testid="cr-error">Error: {error.message}</p>}
      {data && data.tenants && data.tenants.length === 0 && (
        <p data-testid="cr-empty" style={{ color: 'var(--cf-text-secondary)' }}>
          No active sub-tenants under this master. Provision sub-tenants from <a href="/admin/sub-tenants">Admin → Sub-Tenants</a>.
        </p>
      )}

      {data && data.consolidated && type === 'income_statement' && (
        <IncomeStatementView data={data} />
      )}
      {data && data.consolidated && type === 'balance_sheet' && (
        <BalanceSheetView data={data} />
      )}
      {data && data.consolidated && (type === 'cash_flow_indirect' || type === 'cash_flow') && (
        <CashFlowView data={data} />
      )}
    </section>
  );
}

const money = (v) => Number(v ?? 0).toLocaleString('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 2 });

function IncomeStatementView({ data }) {
  const c = data.consolidated;
  return (
    <>
      <div data-testid="cr-totals" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 12, marginBottom: 16 }}>
        <KPI label="Total revenue" value={money(c.total_revenue)} />
        <KPI label="Total expense" value={money(c.total_expense)} />
        <KPI label="Net income" value={money(c.net_income)} highlight={c.net_income >= 0 ? 'positive' : 'negative'} />
      </div>
      <SectionTable title="Revenue" rows={c.revenue} testid="cr-revenue" />
      <SectionTable title="Expense" rows={c.expense} testid="cr-expense" />
      <PerTenantTable byTenant={data.by_tenant} tenants={data.tenants}
        cols={[['total_revenue','Revenue'], ['total_expense','Expense'], ['net_income','Net income']]} />
    </>
  );
}

function BalanceSheetView({ data }) {
  const c = data.consolidated;
  return (
    <>
      <div data-testid="cr-totals" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 12, marginBottom: 16 }}>
        <KPI label="Total assets" value={money(c.total_assets)} />
        <KPI label="Liabilities" value={money(c.total_liabilities)} />
        <KPI label="Equity" value={money(c.total_equity)} />
        <KPI label="Balanced?" value={c.balanced ? 'Yes' : 'No'} highlight={c.balanced ? 'positive' : 'negative'} />
      </div>
      <SectionTable title="Assets" rows={c.assets} testid="cr-assets" />
      <SectionTable title="Liabilities" rows={c.liabilities} testid="cr-liabilities" />
      <SectionTable title="Equity" rows={c.equity} testid="cr-equity" />
      <PerTenantTable byTenant={data.by_tenant} tenants={data.tenants}
        cols={[['total_assets','Assets'], ['total_liabilities','Liab'], ['total_equity','Equity']]} />
    </>
  );
}

function CashFlowView({ data }) {
  const c = data.consolidated;
  return (
    <>
      <div data-testid="cr-totals" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 12, marginBottom: 16 }}>
        <KPI label="Net income" value={money(c.net_income)} />
        <KPI label="Operating" value={money(c.operating)} />
        <KPI label="Investing" value={money(c.investing)} />
        <KPI label="Financing" value={money(c.financing)} />
        <KPI label="Net change" value={money(c.net_change_in_cash)} highlight={c.net_change_in_cash >= 0 ? 'positive' : 'negative'} />
      </div>
      <PerTenantTable byTenant={data.by_tenant} tenants={data.tenants}
        cols={[['net_income','Net income'], ['operating','Operating'], ['investing','Investing'], ['financing','Financing'], ['net_change_in_cash','Net change']]} />
    </>
  );
}

function KPI({ label, value, highlight }) {
  const accent = highlight === 'positive' ? '#16a34a' : highlight === 'negative' ? '#dc2626' : '#334155';
  const color  = highlight === 'positive' ? '#16a34a' : highlight === 'negative' ? '#dc2626' : '#0f172a';
  return (
    <div data-testid={`cr-kpi-${label.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`}
         style={{
           background:'#fff', border:'1px solid #e2e8f0',
           borderLeft: `3px solid ${accent}`,
           borderRadius: 6, padding: '12px 14px',
         }}>
      <div style={{ fontSize: 11, color: '#64748b',
                    textTransform: 'uppercase', letterSpacing: 0.4,
                    fontWeight: 600 }}>{label}</div>
      <div style={{ fontSize: 22, fontWeight: 700, color, marginTop: 4,
                    letterSpacing: '-0.02em', lineHeight: 1.15,
                    fontVariantNumeric: 'tabular-nums' }}>{value}</div>
    </div>
  );
}

function SectionTable({ title, rows, testid }) {
  if (!rows || rows.length === 0) return null;
  return (
    <div style={{ marginBottom: 16 }}>
      <h3 style={{ fontSize: 14, marginBottom: 8 }}>{title}</h3>
      <table className="data-table" data-testid={testid} style={{ width: '100%' }}>
        <thead>
          <tr>
            <th style={{ width: 90 }}>Code</th>
            <th>Name</th>
            <th style={{ textAlign: 'right', width: 80 }}>Tenants</th>
            <th style={{ textAlign: 'right', width: 140 }}>Amount</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r, i) => (
            <tr key={r.code || i} data-testid={`${testid}-row-${i}`}>
              <td><code style={{ fontSize: 12 }}>{r.code}</code></td>
              <td>{r.name}</td>
              <td style={{ textAlign: 'right' }}>{r.tenant_count}</td>
              <td style={{ textAlign: 'right' }}>{money(r.amount)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function PerTenantTable({ byTenant, tenants, cols }) {
  if (!byTenant || !tenants) return null;
  return (
    <div style={{ marginTop: 24 }}>
      <h3 style={{ fontSize: 14, marginBottom: 8 }}>Per-tenant breakdown</h3>
      <table className="data-table" data-testid="cr-per-tenant" style={{ width: '100%' }}>
        <thead>
          <tr>
            <th>Tenant</th>
            {cols.map(([k, l]) => <th key={k} style={{ textAlign: 'right' }}>{l}</th>)}
          </tr>
        </thead>
        <tbody>
          {tenants.map(t => {
            const row = byTenant[t.id] || {};
            return (
              <tr key={t.id} data-testid={`cr-per-tenant-row-${t.id}`}>
                <td>{t.name || `#${t.id}`}{row.error && <small style={{ color: '#b45309', marginLeft: 6 }}>· {row.error}</small>}</td>
                {cols.map(([k]) => (
                  <td key={k} style={{ textAlign: 'right' }} data-testid={`cr-per-tenant-${t.id}-${k}`}>
                    {row.error ? '—' : money(row[k])}
                  </td>
                ))}
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}
