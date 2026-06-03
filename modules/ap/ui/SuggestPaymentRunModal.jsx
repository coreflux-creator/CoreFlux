import React, { useState, useEffect, useMemo } from 'react';
import { api } from '../../../dashboard/src/lib/api';

/**
 * AP "Suggest payment run" modal (2026-02).
 *
 * Mirror of the AR-side SuggestInvoiceModal: ask the backend for a
 * preview of every approved bill due within N days, grouped by
 * vendor, and the AI commentary. Operator picks horizon + rail,
 * deselects any vendor groups they want to skip, and confirms to
 * execute the run. Execution creates one ap_payments row per vendor
 * group (draft + auto-allocated). Operator still has to release each
 * payment via the existing ap.payment.send flow — SoD is preserved.
 */

const RAIL_OPTIONS = [
  { id: 'mercury',        label: 'Mercury'        },
  { id: 'plaid_transfer', label: 'Plaid Transfer' },
  { id: 'nacha',          label: 'NACHA file'     },
];

export default function SuggestPaymentRunModal({ onClose, onCreated }) {
  const [horizon, setHorizon]     = useState(7);
  const [rail, setRail]           = useState('mercury');
  const [loading, setLoading]     = useState(true);
  const [busy, setBusy]           = useState(false);
  const [error, setError]         = useState(null);
  const [suggestion, setSuggestion] = useState(null);
  const [selectedVendors, setSelectedVendors] = useState(new Set());

  const fetchSuggestion = async () => {
    setLoading(true); setError(null);
    try {
      const res = await api.post('/modules/ap/api/bills.php?action=suggest-payment-run', {
        days_ahead: Number(horizon),
        rail,
      });
      setSuggestion(res);
      // Default-select every rail-eligible vendor group.
      setSelectedVendors(new Set(
        (res.vendor_groups || [])
          .filter(g => g.rail_eligible)
          .map(g => g.vendor_name)
      ));
    } catch (e) { setError(e); }
    finally { setLoading(false); }
  };

  useEffect(() => {
    fetchSuggestion();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [horizon, rail]);

  const toggle = (vname) => {
    setSelectedVendors(prev => {
      const next = new Set(prev);
      next.has(vname) ? next.delete(vname) : next.add(vname);
      return next;
    });
  };

  const groups = suggestion?.vendor_groups || [];
  const selectedTotal = useMemo(() => groups
    .filter(g => selectedVendors.has(g.vendor_name))
    .reduce((s, g) => s + Number(g.total_due || 0), 0), [groups, selectedVendors]);
  const selectedBillCount = useMemo(() => groups
    .filter(g => selectedVendors.has(g.vendor_name))
    .reduce((s, g) => s + Number(g.bill_count || 0), 0), [groups, selectedVendors]);

  const execute = async () => {
    if (selectedVendors.size === 0) return;
    setBusy(true); setError(null);
    try {
      const payloadGroups = groups
        .filter(g => selectedVendors.has(g.vendor_name))
        .map(g => ({ vendor_name: g.vendor_name, bill_ids: g.bill_ids, method: rail }));
      const res = await api.post('/modules/ap/api/bills.php?action=execute-payment-run', {
        rail, vendor_groups: payloadGroups,
      });
      onCreated?.(res);
    } catch (e) { setError(e); }
    finally { setBusy(false); }
  };

  return (
    <div data-testid="suggest-payment-run-modal"
         style={{ position: 'fixed', inset: 0, background: 'rgba(15,18,28,0.5)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }}
         onClick={(e) => e.target === e.currentTarget && !busy && onClose?.()}>
      <div style={{ background: '#fff', borderRadius: 12, width: 'min(900px, 100%)', maxHeight: '92vh', display: 'flex', flexDirection: 'column' }}>
        <header style={{ padding: 20, borderBottom: '1px solid #e5e7eb' }}>
          <h3 style={{ margin: 0 }}>
            <span style={{ background: 'linear-gradient(135deg, #059669, #2563eb)', WebkitBackgroundClip: 'text', WebkitTextFillColor: 'transparent', fontWeight: 700 }}>
              Suggest payment run
            </span>
          </h3>
          <p style={{ margin: '4px 0 0', fontSize: 13, color: '#666' }}>
            AI-grouped approved bills due in the next {horizon} days. Drafts get
            created on confirm — a second approver still has to release each one
            (SoD enforced).
          </p>
        </header>

        <div style={{ overflow: 'auto', padding: 20, flex: 1 }}>
          {/* Controls */}
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 12, marginBottom: 14 }}>
            <label style={{ fontSize: 12 }}>Horizon (days)
              <input className="input" type="number" min="1" max="60" value={horizon}
                     onChange={e => setHorizon(e.target.value)}
                     data-testid="suggest-payment-run-horizon" />
            </label>
            <label style={{ fontSize: 12 }}>Rail
              <select className="input" value={rail} onChange={e => setRail(e.target.value)}
                      data-testid="suggest-payment-run-rail">
                {RAIL_OPTIONS.map(r => <option key={r.id} value={r.id}>{r.label}</option>)}
              </select>
            </label>
            <div style={{ alignSelf: 'end' }}>
              <button className="btn btn--ghost" onClick={fetchSuggestion} disabled={loading || busy}
                      data-testid="suggest-payment-run-refresh">Refresh</button>
            </div>
          </div>

          {loading && <p data-testid="suggest-payment-run-loading">Asking the AI for a payment run…</p>}
          {error && <p className="error" data-testid="suggest-payment-run-error">{error.message}</p>}
          {!loading && suggestion && (
            <>
              {/* Summary card */}
              <div style={{
                background: '#f1f5f9', borderRadius: 8, padding: 14, marginBottom: 14,
                display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 10,
              }} data-testid="suggest-payment-run-summary">
                <Stat label="Vendors"     value={suggestion.totals?.vendor_count ?? 0} />
                <Stat label="Bills"       value={suggestion.totals?.bill_count ?? 0} />
                <Stat label="Total due"   value={`$${Number(suggestion.totals?.total_due || 0).toFixed(2)}`} />
                <Stat label="Eligible"    value={`$${Number(suggestion.totals?.rail_eligible_total || 0).toFixed(2)}`} />
              </div>

              {/* AI summary banner */}
              <div style={{
                background: '#ecfdf5', borderLeft: '3px solid #059669',
                padding: '10px 12px', borderRadius: 4, fontSize: 13, marginBottom: 14,
              }} data-testid="suggest-payment-run-summary-banner">
                <div style={{ fontWeight: 600, marginBottom: 4 }}>
                  Cutoff: <code>{suggestion.cutoff_date}</code> · Rail:
                  <code style={{ marginLeft: 4 }}>{suggestion.rail}</code>
                  {suggestion.ai_used && (
                    <span data-testid="suggest-payment-run-ai-badge"
                          style={{ marginLeft: 8, padding: '2px 6px', borderRadius: 999, background: '#059669', color: '#fff', fontSize: 10 }}>
                      AI summary
                    </span>
                  )}
                  {!suggestion.rail_configured && (
                    <span data-testid="suggest-payment-run-rail-warning"
                          style={{ marginLeft: 8, padding: '2px 6px', borderRadius: 999, background: '#fef3c7', color: '#92400e', fontSize: 10 }}>
                      Rail not configured for this tenant
                    </span>
                  )}
                </div>
                <div style={{ color: '#065f46' }}>{suggestion.ai_summary}</div>
              </div>

              {/* PWP-blocked notice */}
              {suggestion.totals?.pwp_blocked_count > 0 && (
                <div style={{
                  background: '#fef3c7', borderLeft: '3px solid #d97706',
                  padding: '10px 12px', borderRadius: 4, fontSize: 13, marginBottom: 14,
                }} data-testid="suggest-payment-run-pwp-notice">
                  <strong>{suggestion.totals.pwp_blocked_count}</strong> bill(s) totaling{' '}
                  <strong>${Number(suggestion.totals.pwp_blocked_amount || 0).toFixed(2)}</strong>{' '}
                  are blocked by the Pay-When-Paid gate and excluded from this run.
                </div>
              )}

              {/* Vendor groups */}
              {groups.length === 0 ? (
                <p style={{ color: '#999' }} data-testid="suggest-payment-run-empty">
                  No approved bills are due within the next {horizon} days.
                </p>
              ) : (
                <table className="data-table" data-testid="suggest-payment-run-groups">
                  <thead>
                    <tr>
                      <th></th>
                      <th>Vendor</th>
                      <th>Type</th>
                      <th>Bills</th>
                      <th>Earliest due</th>
                      <th style={{ textAlign: 'right' }}>Total due</th>
                      <th>Eligibility</th>
                    </tr>
                  </thead>
                  <tbody>
                    {groups.map(g => (
                      <tr key={g.vendor_name}
                          data-testid={`suggest-payment-run-row-${g.vendor_name.replace(/[^a-z0-9]+/gi, '-').toLowerCase()}`}
                          style={!g.rail_eligible ? { background: '#fef3c7' } : null}>
                        <td>
                          <input type="checkbox"
                                checked={selectedVendors.has(g.vendor_name)}
                                disabled={!g.rail_eligible}
                                onChange={() => toggle(g.vendor_name)}
                                data-testid={`suggest-payment-run-check-${g.vendor_name.replace(/[^a-z0-9]+/gi, '-').toLowerCase()}`} />
                        </td>
                        <td><strong>{g.vendor_name}</strong></td>
                        <td><span className="badge">{g.vendor_type || '—'}</span></td>
                        <td>
                          {g.bill_count}{' '}
                          <span style={{ fontSize: 11, color: '#666' }}>({g.bill_refs.slice(0, 3).join(', ')}{g.bill_refs.length > 3 ? '…' : ''})</span>
                        </td>
                        <td style={{ fontSize: 12, color: '#666' }}>{g.earliest_due_date}</td>
                        <td style={{ textAlign: 'right', fontWeight: 600 }}>${Number(g.total_due).toFixed(2)}</td>
                        <td style={{ fontSize: 11 }}>
                          {g.rail_eligible
                            ? <span style={{ color: '#059669' }}>✓ ready</span>
                            : <span style={{ color: '#b45309' }}>⚠ {g.eligibility_note}</span>}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </>
          )}
        </div>

        <footer style={{ padding: 16, borderTop: '1px solid #e5e7eb', display: 'flex', justifyContent: 'space-between', alignItems: 'center', background: '#f9fafb' }}>
          <span style={{ fontSize: 13, color: '#666' }}>
            <strong data-testid="suggest-payment-run-selected-count">{selectedVendors.size}</strong> vendors · {selectedBillCount} bills · ${selectedTotal.toFixed(2)} selected
          </span>
          <div style={{ display: 'flex', gap: 8 }}>
            <button className="btn btn--ghost" onClick={onClose} disabled={busy}
                    data-testid="suggest-payment-run-cancel">Cancel</button>
            <button className="btn btn--primary" onClick={execute}
                    disabled={busy || loading || selectedVendors.size === 0}
                    style={{ background: 'linear-gradient(135deg, #059669, #2563eb)', border: 0 }}
                    data-testid="suggest-payment-run-confirm">
              {busy ? 'Creating drafts…' : `Create ${selectedVendors.size} draft payment${selectedVendors.size === 1 ? '' : 's'}`}
            </button>
          </div>
        </footer>
      </div>
    </div>
  );
}

function Stat({ label, value }) {
  return (
    <div>
      <div style={{ fontSize: 11, color: '#64748b', textTransform: 'uppercase', letterSpacing: '0.05em' }}>{label}</div>
      <div style={{ fontSize: 18, fontWeight: 700, color: '#0f172a', marginTop: 2 }}>{value}</div>
    </div>
  );
}
