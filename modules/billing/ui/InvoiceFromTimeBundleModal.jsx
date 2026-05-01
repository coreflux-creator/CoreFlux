import React, { useState, useEffect } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';

/**
 * Modal: pick a closed period + placements with ready AR bundles, choose
 * aggregation, confirm to draft N invoices in one transaction.
 */
export default function InvoiceFromTimeBundleModal({ onClose, onCreated }) {
  const { data: periods } = useApi('/modules/time/api/periods.php?status=closed&per_page=20');
  const [periodId, setPeriodId] = useState(null);
  const [aggregation, setAggregation] = useState('per_placement');
  const [bundles, setBundles] = useState([]);
  const [selected, setSelected] = useState(new Set());
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  useEffect(() => {
    if (!periodId) { setBundles([]); return; }
    api.get(`/modules/time/api/feed.php?period_id=${periodId}&bundle_type=ar&status=ready`)
      .then(r => {
        setBundles(r.rows || []);
        setSelected(new Set((r.rows || []).map(b => b.placement_id)));
      })
      .catch(e => setError(e));
  }, [periodId]);

  const togglePlacement = (pid) => {
    setSelected(prev => {
      const next = new Set(prev);
      next.has(pid) ? next.delete(pid) : next.add(pid);
      return next;
    });
  };

  const submit = async () => {
    if (!periodId || selected.size === 0) return;
    setBusy(true); setError(null);
    try {
      const res = await api.post('/modules/billing/api/invoices.php?action=from-time-bundle', {
        period_id: Number(periodId),
        placement_ids: Array.from(selected),
        aggregation,
      });
      onCreated?.(res);
    } catch (e) { setError(e); }
    finally { setBusy(false); }
  };

  const totalAmount = bundles.filter(b => selected.has(b.placement_id))
    .reduce((s, b) => s + parseFloat(b.total_amount_bill || 0), 0);

  return (
    <div data-testid="billing-from-time-modal" style={{ position: 'fixed', inset: 0, background: 'rgba(15,18,28,0.5)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }} onClick={(e) => e.target === e.currentTarget && !busy && onClose?.()}>
      <div style={{ background: 'var(--cf-surface, #fff)', borderRadius: 12, width: 'min(720px, 100%)', maxHeight: '90vh', display: 'flex', flexDirection: 'column' }}>
        <header style={{ padding: 20, borderBottom: '1px solid var(--cf-border, #e5e7eb)' }}>
          <h3 style={{ margin: 0 }}>Create invoices from time bundle</h3>
          <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--cf-text-secondary)' }}>Pick a closed period and placements with approved AR bundles. Each placement (or client, if you choose per-client) becomes one draft invoice.</p>
        </header>

        <div style={{ overflow: 'auto', padding: 20, flex: 1 }}>
          <label style={{ display: 'block', marginBottom: 12 }}>
            <span style={{ fontSize: 13, color: 'var(--cf-text-secondary)' }}>Closed period</span>
            <select className="input" value={periodId || ''} onChange={(e) => setPeriodId(e.target.value)} data-testid="billing-from-time-period" style={{ width: '100%', marginTop: 4 }}>
              <option value="">— Select a closed period —</option>
              {(periods?.rows || []).map(p => (
                <option key={p.id} value={p.id}>{p.label} ({p.start_date} → {p.end_date})</option>
              ))}
            </select>
          </label>

          <fieldset style={{ border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8, padding: 12, marginBottom: 12 }}>
            <legend style={{ fontSize: 12, color: 'var(--cf-text-secondary)', padding: '0 8px' }}>Aggregation</legend>
            <label style={{ display: 'inline-flex', gap: 6, marginRight: 16, fontSize: 14 }}>
              <input type="radio" name="agg" value="per_placement" checked={aggregation === 'per_placement'} onChange={() => setAggregation('per_placement')} data-testid="billing-from-time-agg-placement" />
              One invoice per placement
            </label>
            <label style={{ display: 'inline-flex', gap: 6, fontSize: 14 }}>
              <input type="radio" name="agg" value="per_client" checked={aggregation === 'per_client'} onChange={() => setAggregation('per_client')} data-testid="billing-from-time-agg-client" />
              One invoice per client
            </label>
          </fieldset>

          {periodId && bundles.length === 0 && <p style={{ color: 'var(--cf-text-secondary)', fontSize: 14 }} data-testid="billing-from-time-no-bundles">No ready AR bundles for this period.</p>}

          {bundles.length > 0 && (
            <table className="data-table" data-testid="billing-from-time-bundles">
              <thead><tr><th></th><th>Placement</th><th>Client</th><th style={{textAlign:'right'}}>Hours</th><th style={{textAlign:'right'}}>Amount</th></tr></thead>
              <tbody>
                {bundles.map(b => (
                  <tr key={b.id} data-testid={`billing-from-time-row-${b.placement_id}`}>
                    <td><input type="checkbox" checked={selected.has(b.placement_id)} onChange={() => togglePlacement(b.placement_id)} data-testid={`billing-from-time-check-${b.placement_id}`} /></td>
                    <td>{b.placement_title || `#${b.placement_id}`}</td>
                    <td>{b.end_client_name || '—'}</td>
                    <td style={{textAlign:'right'}}>{Number(b.total_hours_billable).toFixed(2)}</td>
                    <td style={{textAlign:'right'}}>{Number(b.total_amount_bill).toFixed(2)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
          {error && <p className="error" data-testid="billing-from-time-error" style={{ marginTop: 12 }}>Error: {error.message}</p>}
        </div>

        <footer style={{ padding: 16, borderTop: '1px solid var(--cf-border, #e5e7eb)', display: 'flex', justifyContent: 'space-between', alignItems: 'center', background: 'var(--cf-surface-alt, #f9fafb)' }}>
          <span style={{ fontSize: 14, color: 'var(--cf-text-secondary)' }}>
            <strong data-testid="billing-from-time-selected-count">{selected.size}</strong> selected · ${totalAmount.toFixed(2)} subtotal
          </span>
          <div style={{ display: 'flex', gap: 8 }}>
            <button className="btn btn--ghost" onClick={onClose} disabled={busy} data-testid="billing-from-time-cancel">Cancel</button>
            <button className="btn btn--primary" onClick={submit} disabled={busy || !periodId || selected.size === 0} data-testid="billing-from-time-confirm">
              {busy ? 'Creating…' : `Create ${selected.size} draft${selected.size !== 1 ? 's' : ''}`}
            </button>
          </div>
        </footer>
      </div>
    </div>
  );
}
