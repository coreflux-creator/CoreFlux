import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api'; // eslint-disable-line no-unused-vars
import { useBulkSelection } from '../../../dashboard/src/lib/useBulkSelection';
import { useActiveEntity } from '../../../dashboard/src/lib/useActiveEntity';
import BillFromTimeBundleModal from './BillFromTimeBundleModal';

const STATUS_FILTERS = ['all','pending_approval','approved','partially_paid','paid','disputed','void'];

export default function BillsList() {
  const [status, setStatus] = useState('all');
  const [showFromBundle, setShowFromBundle] = useState(false);
  const { activeEntityId, activeEntity } = useActiveEntity();
  const qs = new URLSearchParams();
  if (status !== 'all')    qs.set('status', status);
  if (activeEntityId)      qs.set('entity_id', String(activeEntityId));
  const path = '/modules/ap/api/bills.php' + (qs.toString() ? `?${qs}` : '');
  const { data, loading, error, reload } = useApi(path);
  const rows = data?.rows ?? [];
  const sel = useBulkSelection(rows.map(r => r.id));

  const exportSelected = () => {
    if (!sel.size) return;
    const a = document.createElement('a');
    a.href = `/modules/ap/api/export.php?type=bills&ids=${sel.ids.join(',')}`;
    a.rel  = 'noopener';
    a.click();
  };

  return (
    <section data-testid="ap-bills-list">
      {activeEntity && (
        <div data-testid="ap-bills-entity-scope"
             style={{ fontSize: 12, color: '#1e40af', marginBottom: 8 }}>
          Scoped to entity <code>{activeEntity.code}</code> — switch in the header to see another.
        </div>
      )}
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
          <a className="btn" href={`/modules/ap/api/bills_csv_export.php${status ? `?status=${status}` : ''}`} data-testid="ap-bills-export-all-csv">Export all (CSV)</a>
        </div>
      </div>

      {sel.size > 0 && (
        <div data-testid="ap-bills-bulk-bar" style={billsBulkBar}>
          <span><strong>{sel.size}</strong> selected</span>
          <button className="btn btn--primary" onClick={exportSelected} data-testid="ap-bills-export-selected">
            Export selected (CSV)
          </button>
          <button className="btn btn--ghost" onClick={sel.clear} data-testid="ap-bills-clear-selection">Clear</button>
        </div>
      )}

      {loading && <p>Loading…</p>}
      {error && <p className="error" data-testid="ap-bills-error">Error: {error.message}</p>}

      <table className="data-table" data-testid="ap-bills-table">
        <thead>
          <tr>
            <th style={{ width: 32 }}>
              <input
                type="checkbox"
                checked={sel.allSelected}
                ref={el => { if (el) el.indeterminate = sel.someSelected; }}
                onChange={sel.toggleAll}
                disabled={!rows.length}
                data-testid="ap-bills-select-all"
              />
            </th>
            <th>Ref</th><th>Vendor</th><th>Type</th><th>Bill date</th><th>Due</th>
            <th style={{textAlign:'right'}}>Total</th>
            <th style={{textAlign:'right'}}>Due</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          {rows.length === 0 && !loading && <tr><td colSpan={9} className="empty" data-testid="ap-bills-empty">No bills yet.</td></tr>}
          {rows.map(r => (
            <tr key={r.id} data-testid={`ap-bill-row-${r.id}`} style={sel.has(r.id) ? { background: 'var(--cf-surface-alt, #f9fafb)' } : null}>
              <td>
                <input
                  type="checkbox"
                  checked={sel.has(r.id)}
                  onChange={() => sel.toggle(r.id)}
                  data-testid={`ap-bill-select-${r.id}`}
                />
              </td>
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

const billsBulkBar = {
  display: 'flex', alignItems: 'center', gap: 12, padding: '10px 14px',
  background: 'var(--cf-surface-alt, #f3f4f6)', borderRadius: 8,
  marginBottom: 12, flexWrap: 'wrap',
};
