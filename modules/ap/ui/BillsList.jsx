import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api'; // eslint-disable-line no-unused-vars
import BillFromTimeBundleModal from './BillFromTimeBundleModal';

const STATUS_FILTERS = ['all','pending_approval','approved','partially_paid','paid','disputed','void'];

export default function BillsList() {
  const [status, setStatus] = useState('all');
  const [showFromBundle, setShowFromBundle] = useState(false);
  const path = '/modules/ap/api/bills.php' + (status !== 'all' ? `?status=${status}` : '');
  const { data, loading, error, reload } = useApi(path);
  const rows = data?.rows ?? [];

  return (
    <section data-testid="ap-bills-list">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-4)', flexWrap: 'wrap', gap: 8 }}>
        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
          {STATUS_FILTERS.map(s => (
            <button
              key={s}
              data-testid={`ap-bills-filter-${s}`}
              onClick={() => setStatus(s)}
              style={{
                padding: '4px 10px', borderRadius: 999, border: '1px solid var(--cf-border, #e5e7eb)',
                background: status === s ? 'var(--cf-text, #111827)' : 'transparent',
                color: status === s ? '#fff' : 'var(--cf-text-secondary, #6b7280)',
                fontSize: 12, cursor: 'pointer',
              }}
            >{s.replace('_', ' ')}</button>
          ))}
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <Link to="new" className="btn btn--primary" data-testid="ap-new-bill">+ New bill</Link>
          <button className="btn btn--ghost" onClick={() => setShowFromBundle(true)} data-testid="ap-new-from-time-bundle">
            New from time bundle
          </button>
        </div>
      </div>

      {loading && <p>Loading…</p>}
      {error && <p className="error" data-testid="ap-bills-error">Error: {error.message}</p>}

      <table className="data-table" data-testid="ap-bills-table">
        <thead><tr><th>Ref</th><th>Vendor</th><th>Type</th><th>Bill date</th><th>Due</th><th style={{textAlign:'right'}}>Total</th><th style={{textAlign:'right'}}>Due</th><th>Status</th></tr></thead>
        <tbody>
          {rows.length === 0 && !loading && <tr><td colSpan={8} className="empty" data-testid="ap-bills-empty">No bills yet.</td></tr>}
          {rows.map(r => (
            <tr key={r.id} data-testid={`ap-bill-row-${r.id}`}>
              <td><Link to={`/modules/ap/bills/${r.id}`} data-testid={`ap-bill-link-${r.id}`}>{r.internal_ref}</Link></td>
              <td>{r.vendor_name}</td>
              <td><span className="badge">{r.vendor_type}</span></td>
              <td>{r.bill_date}</td>
              <td>{r.due_date}</td>
              <td style={{ textAlign: 'right' }}>{Number(r.total).toFixed(2)} {r.currency}</td>
              <td style={{ textAlign: 'right' }}>{Number(r.amount_due).toFixed(2)}</td>
              <td><span className={`badge badge--${r.status}`}>{r.status.replace('_',' ')}</span></td>
            </tr>
          ))}
        </tbody>
      </table>

      {showFromBundle && (
        <BillFromTimeBundleModal
          onClose={() => setShowFromBundle(false)}
          onCreated={() => { setShowFromBundle(false); reload(); }}
        />
      )}
    </section>
  );
}
