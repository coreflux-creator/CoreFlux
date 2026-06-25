import React, { useState, useEffect } from 'react';
import { api } from '../../../dashboard/src/lib/api';

/**
 * Batch 4+ (2026-02) — AI-assisted "Suggest invoice" modal.
 *
 * Mounted from PlacementTimesheetsTab. One-click invoice draft per
 * placement: backend gathers every approved billable entry since the
 * last invoice for this placement, picks an aggregation strategy
 * (rule-based), and asks the AI to draft a memo. Operator reviews
 * inside this modal, optionally overrides aggregation, then confirms
 * to fire the existing from-time-entries POST.
 */
export default function SuggestInvoiceModal({ placementId, placementTitle, onClose, onCreated }) {
  const [loading, setLoading]         = useState(true);
  const [busy, setBusy]               = useState(false);
  const [error, setError]             = useState(null);
  const [suggestion, setSuggestion]   = useState(null);
  const [aggregation, setAggregation] = useState('per_placement');
  const [memo, setMemo]               = useState('');
  const [selectedIds, setSelectedIds] = useState(new Set());

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const res = await api.post('/api/v1/billing/invoices?action=suggest-from-placement', {
          placement_id: placementId,
        });
        if (cancelled) return;
        setSuggestion(res);
        setAggregation(res.suggested_aggregation || 'per_placement');
        setMemo(res.suggested_memo || '');
        setSelectedIds(new Set((res.candidate_entry_ids || []).map(Number)));
      } catch (e) { if (!cancelled) setError(e); }
      finally { if (!cancelled) setLoading(false); }
    })();
    return () => { cancelled = true; };
  }, [placementId]);

  const toggle = (id) => {
    setSelectedIds(prev => {
      const next = new Set(prev);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });
  };

  const submit = async () => {
    if (selectedIds.size === 0) return;
    setBusy(true); setError(null);
    try {
      const res = await api.post('/api/v1/billing/invoices?action=from-time-entries', {
        time_entry_ids: Array.from(selectedIds),
        aggregation,
        memo,
      });
      onCreated?.(res);
    } catch (e) { setError(e); }
    finally { setBusy(false); }
  };

  return (
    <div data-testid="suggest-invoice-modal"
         style={{ position: 'fixed', inset: 0, background: 'rgba(15,18,28,0.5)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }}
         onClick={(e) => e.target === e.currentTarget && !busy && onClose?.()}>
      <div style={{ background: '#fff', borderRadius: 12, width: 'min(860px, 100%)', maxHeight: '92vh', display: 'flex', flexDirection: 'column' }}>
        <header style={{ padding: 20, borderBottom: '1px solid #e5e7eb' }}>
          <h3 style={{ margin: 0 }}>
            <span style={{ background: 'linear-gradient(135deg, #2563eb, #7c3aed)', WebkitBackgroundClip: 'text', WebkitTextFillColor: 'transparent', fontWeight: 700 }}>
              Suggest invoice
            </span>
          </h3>
          <p style={{ margin: '4px 0 0', fontSize: 13, color: '#666' }}>
            Auto-picked every approved billable entry for <strong>{placementTitle || `placement #${placementId}`}</strong> since the last invoice. Review, tweak, confirm.
          </p>
        </header>

        <div style={{ overflow: 'auto', padding: 20, flex: 1 }}>
          {loading && <p data-testid="suggest-invoice-loading">Asking the AI for a draft…</p>}
          {error && <p className="error" data-testid="suggest-invoice-error">{error.message}</p>}
          {!loading && suggestion && (
            <>
              {/* Summary card */}
              <div style={{
                background: '#f1f5f9', borderRadius: 8, padding: 14, marginBottom: 14,
                display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 10,
              }} data-testid="suggest-invoice-summary">
                <Stat label="Client" value={suggestion.placement?.client_name || '—'} />
                <Stat label="Working days" value={suggestion.period?.distinct_days ?? '—'} />
                <Stat label="Hours" value={Number(suggestion.total_hours).toFixed(2)} />
                <Stat label="Est. subtotal" value={`$${Number(suggestion.estimated_subtotal).toFixed(2)}`} />
              </div>

              {/* Reasoning + AI badge */}
              <div style={{
                background: '#eff6ff', borderLeft: '3px solid #2563eb',
                padding: '10px 12px', borderRadius: 4, fontSize: 13, marginBottom: 14,
              }} data-testid="suggest-invoice-reasoning">
                <div style={{ fontWeight: 600, marginBottom: 4 }}>
                  Recommended aggregation: <code>{suggestion.suggested_aggregation}</code>
                  {suggestion.ai_used && (
                    <span data-testid="suggest-invoice-ai-badge"
                          style={{ marginLeft: 8, padding: '2px 6px', borderRadius: 999, background: '#7c3aed', color: '#fff', fontSize: 10 }}>
                      AI memo
                    </span>
                  )}
                </div>
                <div style={{ color: '#475569' }}>{suggestion.suggested_reasoning}</div>
                {suggestion.last_invoice_date && (
                  <div style={{ fontSize: 12, color: '#64748b', marginTop: 6 }}>
                    Cutoff: entries since <strong>{suggestion.last_invoice_date}</strong> (last invoice for this placement).
                  </div>
                )}
              </div>

              {/* Aggregation override */}
              <fieldset style={{ border: '1px solid #e5e7eb', borderRadius: 8, padding: 10, marginBottom: 14 }}>
                <legend style={{ fontSize: 12, color: '#666', padding: '0 6px' }}>Aggregation override</legend>
                {['per_day', 'per_placement', 'per_client'].map(opt => (
                  <label key={opt} style={{ marginRight: 16, fontSize: 13 }}>
                    <input type="radio" name="suggest-agg" value={opt}
                          checked={aggregation === opt}
                          onChange={() => setAggregation(opt)}
                          data-testid={`suggest-invoice-agg-${opt}`} /> {opt.replace('_', ' ')}
                  </label>
                ))}
              </fieldset>

              {/* Memo edit */}
              <label style={{ display: 'block', fontSize: 12, marginBottom: 14 }}>
                Memo
                <textarea className="input" rows={2} value={memo}
                          onChange={e => setMemo(e.target.value)}
                          style={{ width: '100%', marginTop: 4, fontSize: 13 }}
                          data-testid="suggest-invoice-memo" />
              </label>

              {/* Entries picker */}
              {suggestion.candidate_entries.length === 0 ? (
                <p style={{ color: '#999' }} data-testid="suggest-invoice-no-entries">
                  No approved billable entries to invoice — either nothing approved since the last invoice, or the placement has no recent activity.
                </p>
              ) : (
                <table className="data-table" data-testid="suggest-invoice-entries">
                  <thead>
                    <tr><th></th><th>Date</th><th>Hour type</th><th>Worker</th><th style={{ textAlign: 'right' }}>Hours</th></tr>
                  </thead>
                  <tbody>
                    {suggestion.candidate_entries.map(e => (
                      <tr key={e.id} data-testid={`suggest-invoice-entry-${e.id}`}>
                        <td><input type="checkbox" checked={selectedIds.has(e.id)} onChange={() => toggle(e.id)}
                                  data-testid={`suggest-invoice-entry-check-${e.id}`} /></td>
                        <td style={{ fontSize: 12 }}>{e.work_date}</td>
                        <td style={{ fontSize: 12 }}>{e.hour_type}</td>
                        <td style={{ fontSize: 12 }}>{`${e.first_name || ''} ${e.last_name || ''}`.trim()}</td>
                        <td style={{ textAlign: 'right', fontWeight: 600 }}>{Number(e.hours).toFixed(2)}</td>
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
            <strong data-testid="suggest-invoice-selected-count">{selectedIds.size}</strong> entries selected
          </span>
          <div style={{ display: 'flex', gap: 8 }}>
            <button className="btn btn--ghost" onClick={onClose} disabled={busy}
                    data-testid="suggest-invoice-cancel">Cancel</button>
            <button className="btn btn--primary" onClick={submit}
                    disabled={busy || loading || selectedIds.size === 0}
                    data-testid="suggest-invoice-confirm">
              {busy ? 'Creating…' : 'Create draft invoice'}
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
