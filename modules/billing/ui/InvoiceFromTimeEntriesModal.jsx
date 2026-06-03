import React, { useState, useEffect, useMemo } from 'react';
import { api } from '../../../dashboard/src/lib/api';

/**
 * Batch 4 (2026-02) — Day-level invoice picker.
 *
 * Operator picks a placement + date range, sees the matching approved
 * billable time entries, checks the ones to invoice, picks aggregation,
 * confirms. Bypasses the period/bundle abstraction so you can bill last
 * Friday's 8 hours without waiting for period-close.
 *
 * Mounted from InvoicesList alongside the existing bundle-driven modal.
 */
export default function InvoiceFromTimeEntriesModal({ onClose, onCreated, defaultPlacementId = '' }) {
  const [placementId, setPlacementId] = useState(defaultPlacementId);
  const [dateFrom, setDateFrom]       = useState(defaultDateFrom());
  const [dateTo, setDateTo]           = useState(today());
  const [aggregation, setAggregation] = useState('per_day');
  const [entries, setEntries]         = useState([]);
  const [selected, setSelected]       = useState(new Set());
  const [loading, setLoading]         = useState(false);
  const [busy, setBusy]               = useState(false);
  const [error, setError]             = useState(null);

  const search = async () => {
    setLoading(true); setError(null);
    try {
      const params = new URLSearchParams({ action: 'approved_entries', purpose: 'billable' });
      if (placementId) params.append('placement_id', placementId);
      if (dateFrom)    params.append('date_from', dateFrom);
      if (dateTo)      params.append('date_to', dateTo);
      const res = await api.get(`/modules/staffing/api/timesheets.php?${params.toString()}`);
      setEntries(res.rows || []);
      setSelected(new Set((res.rows || []).map(r => r.id)));   // default-all
    } catch (e) { setError(e); }
    finally { setLoading(false); }
  };

  // Auto-search on mount + when filters change (debounced).
  useEffect(() => {
    const t = setTimeout(search, 250);
    return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [placementId, dateFrom, dateTo]);

  const toggle = (id) => {
    setSelected(prev => {
      const next = new Set(prev);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });
  };
  const toggleAll = () => {
    if (selected.size === entries.length) setSelected(new Set());
    else setSelected(new Set(entries.map(r => r.id)));
  };

  const selectedHours = useMemo(
    () => entries.filter(e => selected.has(e.id)).reduce((s, e) => s + Number(e.hours || 0), 0),
    [entries, selected]
  );

  const submit = async () => {
    if (selected.size === 0) return;
    setBusy(true); setError(null);
    try {
      const res = await api.post('/modules/billing/api/invoices.php?action=from-time-entries', {
        time_entry_ids: Array.from(selected),
        aggregation,
      });
      onCreated?.(res);
    } catch (e) { setError(e); }
    finally { setBusy(false); }
  };

  return (
    <div data-testid="billing-from-entries-modal"
         style={{ position: 'fixed', inset: 0, background: 'rgba(15,18,28,0.5)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }}
         onClick={(e) => e.target === e.currentTarget && !busy && onClose?.()}>
      <div style={{ background: '#fff', borderRadius: 12, width: 'min(860px, 100%)', maxHeight: '92vh', display: 'flex', flexDirection: 'column' }}>
        <header style={{ padding: 20, borderBottom: '1px solid #e5e7eb' }}>
          <h3 style={{ margin: 0 }}>Create invoices from approved hours (day-level)</h3>
          <p style={{ margin: '4px 0 0', fontSize: 13, color: '#666' }}>
            Pick a placement + date range, then hand-pick which approved entries to bill.
            No period close required — works against any approved entry.
          </p>
        </header>

        <div style={{ overflow: 'auto', padding: 20, flex: 1 }}>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 12, marginBottom: 12 }}>
            <label style={{ fontSize: 12 }}>Placement ID (optional)
              <input className="input" type="number" min="0" value={placementId}
                     onChange={e => setPlacementId(e.target.value)}
                     placeholder="leave blank for all"
                     data-testid="billing-from-entries-placement" />
            </label>
            <label style={{ fontSize: 12 }}>Date from
              <input className="input" type="date" value={dateFrom}
                     onChange={e => setDateFrom(e.target.value)}
                     data-testid="billing-from-entries-date-from" />
            </label>
            <label style={{ fontSize: 12 }}>Date to
              <input className="input" type="date" value={dateTo}
                     onChange={e => setDateTo(e.target.value)}
                     data-testid="billing-from-entries-date-to" />
            </label>
          </div>

          <fieldset style={{ border: '1px solid #e5e7eb', borderRadius: 8, padding: 10, marginBottom: 12 }}>
            <legend style={{ fontSize: 12, color: '#666', padding: '0 6px' }}>Aggregation</legend>
            <label style={{ marginRight: 16, fontSize: 13 }}>
              <input type="radio" name="agg" value="per_day" checked={aggregation === 'per_day'}
                     onChange={() => setAggregation('per_day')}
                     data-testid="billing-from-entries-agg-day" /> One invoice per day
            </label>
            <label style={{ marginRight: 16, fontSize: 13 }}>
              <input type="radio" name="agg" value="per_placement" checked={aggregation === 'per_placement'}
                     onChange={() => setAggregation('per_placement')}
                     data-testid="billing-from-entries-agg-placement" /> One per placement
            </label>
            <label style={{ fontSize: 13 }}>
              <input type="radio" name="agg" value="per_client" checked={aggregation === 'per_client'}
                     onChange={() => setAggregation('per_client')}
                     data-testid="billing-from-entries-agg-client" /> One per client
            </label>
          </fieldset>

          {loading && <p data-testid="billing-from-entries-loading">Searching…</p>}
          {error && <p className="error" data-testid="billing-from-entries-error">{error.message}</p>}
          {!loading && entries.length === 0 && (
            <p style={{ color: '#999' }} data-testid="billing-from-entries-empty">
              No approved billable entries match the current filters.
            </p>
          )}
          {entries.length > 0 && (
            <table className="data-table" data-testid="billing-from-entries-table">
              <thead>
                <tr>
                  <th><input type="checkbox" checked={selected.size === entries.length}
                            onChange={toggleAll}
                            data-testid="billing-from-entries-select-all" /></th>
                  <th>Date</th>
                  <th>Placement</th>
                  <th>Client</th>
                  <th>Worker</th>
                  <th>Hour type</th>
                  <th style={{ textAlign: 'right' }}>Hours</th>
                </tr>
              </thead>
              <tbody>
                {entries.map(e => (
                  <tr key={e.id} data-testid={`billing-from-entries-row-${e.id}`}>
                    <td><input type="checkbox" checked={selected.has(e.id)} onChange={() => toggle(e.id)}
                              data-testid={`billing-from-entries-check-${e.id}`} /></td>
                    <td style={{ fontSize: 12 }}>{e.work_date}</td>
                    <td style={{ fontSize: 12 }}>{e.placement_title || `#${e.placement_id}`}</td>
                    <td style={{ fontSize: 12 }}>{e.client_name}</td>
                    <td style={{ fontSize: 12 }}>{`${e.first_name || ''} ${e.last_name || ''}`.trim()}</td>
                    <td style={{ fontSize: 12 }}>{e.hour_type}</td>
                    <td style={{ textAlign: 'right', fontWeight: 600 }}>{Number(e.hours).toFixed(2)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>

        <footer style={{ padding: 16, borderTop: '1px solid #e5e7eb', display: 'flex', justifyContent: 'space-between', alignItems: 'center', background: '#f9fafb' }}>
          <span style={{ fontSize: 13, color: '#666' }}>
            <strong data-testid="billing-from-entries-selected-count">{selected.size}</strong> of {entries.length} entries · {selectedHours.toFixed(2)}h selected
          </span>
          <div style={{ display: 'flex', gap: 8 }}>
            <button className="btn btn--ghost" onClick={onClose} disabled={busy}
                    data-testid="billing-from-entries-cancel">Cancel</button>
            <button className="btn btn--primary" onClick={submit}
                    disabled={busy || selected.size === 0}
                    data-testid="billing-from-entries-confirm">
              {busy ? 'Creating…' : `Create ${selected.size > 0 ? 'draft' + (aggregation === 'per_day' ? 's' : '') : 'drafts'}`}
            </button>
          </div>
        </footer>
      </div>
    </div>
  );
}

function today() {
  return new Date().toISOString().slice(0, 10);
}
function defaultDateFrom() {
  const d = new Date();
  d.setDate(d.getDate() - 14);  // last two weeks by default
  return d.toISOString().slice(0, 10);
}
