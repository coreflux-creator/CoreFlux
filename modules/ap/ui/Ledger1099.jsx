import React, { useState } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';

const fmtMoney = (n) =>
  (Number(n) || 0).toLocaleString(undefined, { style: 'currency', currency: 'USD' });

export default function Ledger1099() {
  const [year, setYear] = useState(new Date().getFullYear());
  const { data, loading, error, reload } = useApi(`/modules/ap/api/1099.php?tax_year=${year}`);
  const { data: ready, reload: reloadReady } = useApi(`/modules/ap/api/1099.php?action=readiness&tax_year=${year}`);
  const rows = data?.rows ?? [];
  const totals = data?.totals ?? { vendors: 0, total_paid: 0, requires_nec: 0 };
  const readiness = ready?.summary || { ready: 0, blocked: 0, total: 0 };
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState(null);
  const [showReadiness, setShowReadiness] = useState(false);

  const rebuild = async () => {
    setBusy(true); setActionError(null);
    try {
      await api.post(`/modules/ap/api/1099.php?action=rebuild&tax_year=${year}`, {});
      reload(); reloadReady();
    }
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
          <button
            className="btn btn--ghost"
            onClick={() => setShowReadiness((s) => !s)}
            data-testid="ap-1099-readiness-toggle"
          >
            {showReadiness ? 'Hide readiness' : `Readiness: ${readiness.ready}/${readiness.total} ready`}
          </button>
          <button className="btn btn--primary" onClick={rebuild} disabled={busy} data-testid="ap-1099-rebuild">{busy ? 'Rebuilding…' : 'Rebuild from cleared payments'}</button>
          <button
            className="btn btn--ghost"
            onClick={() => window.open(`/api/ap/1099.php?action=print&tax_year=${year}`, '_blank')}
            data-testid="ap-1099-print"
          >
            Print 1099-NEC forms →
          </button>
        </div>
      </div>

      {showReadiness && <ReadinessPanel rows={ready?.rows || []} summary={readiness} />}

      {actionError && <p className="error">Error: {actionError.message}</p>}
      {loading && <p>Loading…</p>}
      {error && <p className="error">Error: {error.message}</p>}

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 12, marginBottom: 'var(--cf-space-4)' }}>
        <Box label="Vendors" value={totals.vendors} />
        <Box label="Total paid (1099 eligible)" value={fmtMoney(totals.total_paid)} highlight />
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
              <td style={{textAlign:'right'}}>{fmtMoney(r.total_paid)}</td>
              <td>{Number(r.requires_1099_nec) ? <span className="badge badge--approved">Yes</span> : 'No'}</td>
              <td>{r.computed_at}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  );
}

function ReadinessPanel({ rows, summary }) {
  const blocked = rows.filter((r) => !r.ready);
  return (
    <div data-testid="ap-1099-readiness" style={{ marginBottom: 16, padding: 12, border: '1px solid #e5e7eb', borderRadius: 8, background: '#f8fafc' }}>
      <h4 style={{ margin: '0 0 6px', fontSize: 13 }}>1099 filing readiness</h4>
      <p className="muted" style={{ fontSize: 12, margin: '0 0 8px' }}>
        <strong>{summary.ready}</strong> ready, <strong>{summary.blocked}</strong> blocked of {summary.total}.
        Blockers must be resolved before the IRS will accept the form.
      </p>
      {blocked.length === 0 && <p className="muted" data-testid="ap-1099-readiness-clean">All eligible vendors are 1099-ready.</p>}
      {blocked.length > 0 && (
        <table className="data-table" data-testid="ap-1099-readiness-table" style={{ fontSize: 12 }}>
          <thead><tr><th>Vendor</th><th>Type</th><th>TIN last-4</th><th>W-9</th><th>Blockers</th></tr></thead>
          <tbody>
            {blocked.map((r) => (
              <tr key={r.ledger_id} data-testid={`ap-1099-readiness-row-${r.ledger_id}`}>
                <td>{r.vendor_name}</td>
                <td><span className="badge">{r.vendor_type}</span></td>
                <td>{r.tin_last4 || '—'}</td>
                <td>{r.has_w9 ? <span className="badge badge--approved">on file</span> : <span className="badge" style={{ background: '#fee2e2', color: '#991b1b' }}>missing</span>}</td>
                <td><ul style={{ margin: 0, paddingLeft: 16 }}>{r.blockers.map((b, i) => <li key={i}>{b}</li>)}</ul></td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
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
