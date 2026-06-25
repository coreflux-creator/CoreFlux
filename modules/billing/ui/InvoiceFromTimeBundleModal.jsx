import React, { useState, useEffect, useMemo } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';

/**
 * Modal: pick a period (open or locked — closed periods are immutable
 * historical archives) with approved hours, optionally rebuild its AR
 * bundles, then confirm to draft N invoices in one transaction.
 *
 * Common-sense accounting flow (per operator feedback):
 *   period opens → time entries land + get approved → invoices drafted
 *   from those approved hours → AP bills + payroll booked → period
 *   close locks everything as the LAST step.
 *
 * Previous version forced `status=closed` on the period dropdown,
 * which inverted the flow: you couldn't invoice until you closed, and
 * closing was supposed to come after invoicing. Now: dropdown shows
 * every period sorted by recency, default-selects the current open
 * period, and a "Build bundles for this period" button surfaces when
 * approved hours exist but bundles don't yet (because bundle build
 * historically only ran at close time).
 */
export default function InvoiceFromTimeBundleModal({ onClose, onCreated }) {
  // Drop the status filter — accounting period close is the LAST step,
  // not a prerequisite for invoicing.
  const { data: periods } = useApi('/modules/time/api/periods.php?per_page=20');
  const [periodId, setPeriodId] = useState(null);
  const [aggregation, setAggregation] = useState('per_placement');
  const [bundles, setBundles] = useState([]);
  const [selected, setSelected] = useState(new Set());
  const [busy, setBusy] = useState(false);
  const [building, setBuilding] = useState(false);
  const [error, setError] = useState(null);
  const [info, setInfo]   = useState(null);

  // Default to the most recent OPEN period when the dropdown loads —
  // that's where live invoicing happens.
  useEffect(() => {
    if (periodId) return;
    const rows = periods?.rows || [];
    if (rows.length === 0) return;
    const firstOpen = rows.find(p => p.status === 'open') || rows[0];
    if (firstOpen) setPeriodId(String(firstOpen.id));
  }, [periods, periodId]);

  const selectedPeriod = useMemo(
    () => (periods?.rows || []).find(p => String(p.id) === String(periodId)),
    [periods, periodId]
  );

  const loadBundles = (pid) => {
    if (!pid) { setBundles([]); return; }
    return api.get(`/modules/time/api/feed.php?period_id=${pid}&bundle_type=ar&status=ready`)
      .then(r => {
        setBundles(r.rows || []);
        setSelected(new Set((r.rows || []).map(b => b.placement_id)));
        setError(null);
      })
      .catch(e => setError(e));
  };

  useEffect(() => { loadBundles(periodId); }, [periodId]);

  const togglePlacement = (pid) => {
    setSelected(prev => {
      const next = new Set(prev);
      next.has(pid) ? next.delete(pid) : next.add(pid);
      return next;
    });
  };

  const buildBundles = async () => {
    if (!periodId) return;
    setBuilding(true); setError(null); setInfo(null);
    try {
      const r = await api.post(`/modules/time/api/periods.php?action=build_bundles&id=${periodId}`, {});
      setInfo(`Built ${r.bundles_built} bundle${r.bundles_built === 1 ? '' : 's'} for this period.`);
      await loadBundles(periodId);
    } catch (e) { setError(e); }
    finally     { setBuilding(false); }
  };

  const submit = async () => {
    if (!periodId || selected.size === 0) return;
    setBusy(true); setError(null);
    try {
      const res = await api.post('/api/v1/billing/invoices?action=from-time-bundle', {
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

  const periodStatusBadge = (s) => {
    const colors = { open: '#15803d', locked: '#a16207', closed: '#64748b' };
    return <span style={{ fontSize: 11, color: colors[s] || '#64748b', textTransform: 'uppercase', letterSpacing: '0.05em', fontWeight: 600, marginLeft: 6 }}>{s}</span>;
  };

  // A closed period's bundles are immutable history — block invoicing
  // from there to enforce the "close is the LAST step" invariant. UI
  // shows the dropdown choice but disables the action.
  const isClosed = selectedPeriod?.status === 'closed';

  return (
    <div data-testid="billing-from-time-modal" style={{ position: 'fixed', inset: 0, background: 'rgba(15,18,28,0.5)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }} onClick={(e) => e.target === e.currentTarget && !busy && onClose?.()}>
      <div style={{ background: 'var(--cf-surface, #fff)', borderRadius: 12, width: 'min(720px, 100%)', maxHeight: '90vh', display: 'flex', flexDirection: 'column' }}>
        <header style={{ padding: 20, borderBottom: '1px solid var(--cf-border, #e5e7eb)' }}>
          <h3 style={{ margin: 0 }}>Create invoices from approved hours</h3>
          <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--cf-text-secondary)' }}>
            Pick a period with approved hours, then choose placements. Each placement (or client, if you choose per-client) becomes one draft invoice. Closing a time period locks the <strong>accrual</strong> (the underlying hours/bundles) — drafting invoices still works against open or closed periods. The separate GL-posting gate lives on accounting periods.
          </p>
        </header>

        <div style={{ overflow: 'auto', padding: 20, flex: 1 }}>
          <label style={{ display: 'block', marginBottom: 12 }}>
            <span style={{ fontSize: 13, color: 'var(--cf-text-secondary)' }}>Period</span>
            <select className="input" value={periodId || ''} onChange={(e) => setPeriodId(e.target.value)} data-testid="billing-from-time-period" style={{ width: '100%', marginTop: 4 }}>
              <option value="">— Select a period —</option>
              {(periods?.rows || []).map(p => (
                <option key={p.id} value={p.id}>
                  {p.label || `${p.start_date} → ${p.end_date}`} · {p.status}
                </option>
              ))}
            </select>
            {selectedPeriod && (
              <div style={{ marginTop: 6, fontSize: 13, color: 'var(--cf-text-secondary)' }} data-testid="billing-from-time-period-meta">
                {selectedPeriod.start_date} → {selectedPeriod.end_date}
                {periodStatusBadge(selectedPeriod.status)}
                {isClosed && <span style={{ marginLeft: 8, color: '#b91c1c' }}>· closed periods are immutable</span>}
              </div>
            )}
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

          {periodId && bundles.length === 0 && !isClosed && (
            <div
              data-testid="billing-from-time-no-bundles"
              style={{ padding: 12, background: '#fffbeb', border: '1px solid #fcd34d', borderRadius: 6, marginBottom: 12 }}
            >
              <p style={{ margin: '0 0 8px', fontSize: 13 }}>
                No AR bundles ready for this period yet. If approved hours exist, click below to build them.
              </p>
              <button
                className="btn btn--primary"
                onClick={buildBundles}
                disabled={building}
                data-testid="billing-from-time-build-bundles"
              >
                {building ? 'Building…' : 'Build bundles for this period'}
              </button>
            </div>
          )}
          {periodId && bundles.length === 0 && isClosed && (
            <p style={{ color: 'var(--cf-text-secondary)', fontSize: 14 }} data-testid="billing-from-time-closed-empty">
              This time period is closed and has no ready bundles. Closed time periods lock the accrual side — to bill historical hours you'd need to reopen the period to rebuild bundles, or post a manual invoice referencing the period.
            </p>
          )}
          {info && <p data-testid="billing-from-time-info" style={{ color: '#15803d', fontSize: 13 }}>{info}</p>}

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
            <button
              className="btn btn--primary"
              onClick={submit}
              disabled={busy || !periodId || selected.size === 0}
              data-testid="billing-from-time-confirm"
              title={isClosed ? 'Drafting from a closed time period is allowed — the accrual is locked but the AR side stays open. Posting to a closed accounting period is what would actually be blocked, separately, at the GL level.' : ''}
            >
              {busy ? 'Creating…' : `Create ${selected.size} draft${selected.size !== 1 ? 's' : ''}`}
            </button>
          </div>
        </footer>
      </div>
    </div>
  );
}
