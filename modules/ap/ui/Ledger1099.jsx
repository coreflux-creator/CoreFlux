import React, { useState } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';

export default function Ledger1099() {
  const [year, setYear] = useState(new Date().getFullYear());
  const { data, loading, error, reload } = useApi(`/modules/ap/api/1099.php?tax_year=${year}`);
  const rows = data?.rows ?? [];
  const totals = data?.totals ?? { vendors: 0, total_paid: 0, requires_nec: 0 };
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState(null);

  const rebuild = async () => {
    setBusy(true); setActionError(null);
    try { await api.post(`/modules/ap/api/1099.php?action=rebuild&tax_year=${year}`, {}); reload(); }
    catch (e) { setActionError(e); } finally { setBusy(false); }
  };

  return (
    <section data-testid="ap-1099-ledger">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-4)', flexWrap: 'wrap', gap: 8 }}>
        <h3 style={{ margin: 0, fontSize: 16 }}>1099-NEC ledger</h3>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <label style={{ fontSize: 13 }}>Tax year
            <input className="input" type="number" value={year} onChange={(e) => setYear(Number(e.target.value))} data-testid="ap-1099-year" style={{ marginLeft: 8, width: 100 }} />
          </label>
          <button className="btn btn--primary" onClick={rebuild} disabled={busy} data-testid="ap-1099-rebuild">{busy ? 'Rebuilding…' : 'Rebuild from cleared payments'}</button>
        </div>
      </div>

      {actionError && <p className="error">Error: {actionError.message}</p>}
      {loading && <p>Loading…</p>}
      {error && <p className="error">Error: {error.message}</p>}

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 12, marginBottom: 'var(--cf-space-4)' }}>
        <Box label="Vendors" value={totals.vendors} />
        <Box label="Total paid (1099 eligible)" value={`$${Number(totals.total_paid).toFixed(2)}`} highlight />
        <Box label="Requires 1099-NEC" value={totals.requires_nec} highlight />
      </div>

      <table className="data-table" data-testid="ap-1099-table">
        <thead><tr><th>Vendor</th><th>Type</th><th>Tax ID</th><th style={{textAlign:'right'}}>Total paid</th><th>Requires 1099-NEC?</th><th>Computed</th></tr></thead>
        <tbody>
          {rows.length === 0 && !loading && <tr><td colSpan={6} className="empty" data-testid="ap-1099-empty">No 1099-eligible payments in {year}. Click "Rebuild" after any payments clear.</td></tr>}
          {rows.map(r => (
            <tr key={r.id} data-testid={`ap-1099-row-${r.id}`}>
              <td>{r.vendor_name}</td>
              <td><span className="badge">{r.vendor_type}</span></td>
              <td>{r.tax_id_last4 ? `••${r.tax_id_last4}` : '—'}</td>
              <td style={{textAlign:'right'}}>${Number(r.total_paid).toFixed(2)}</td>
              <td>{Number(r.requires_1099_nec) ? <span className="badge badge--approved">Yes</span> : 'No'}</td>
              <td>{r.computed_at}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  );
}

function Box({ label, value, highlight }) {
  return (
    <div style={{ padding: 12, border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8, background: highlight ? 'var(--cf-surface-alt, #f9fafb)' : 'transparent' }}>
      <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)', textTransform: 'uppercase', letterSpacing: 0.5 }}>{label}</div>
      <div style={{ fontSize: 16, fontWeight: 600, marginTop: 4 }}>{value}</div>
    </div>
  );
}
