import React, { useMemo, useState } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';
import { useBulkSelection } from '../../../dashboard/src/lib/useBulkSelection';

const TARGETS = [
  { id: 'billing', label: 'Billing → AR Invoice', refLabel: 'AR Invoice ID' },
  { id: 'ap',      label: 'AP → Vendor Bill',     refLabel: 'AP Bill ID' },
  { id: 'payroll', label: 'Payroll → Run Line',   refLabel: 'Payroll Line ID' },
];

/**
 * Time Settlement — per-day extract engine for Billing / AP / Payroll.
 *
 * Each row in the table is ONE extractable day-block (placement × work_date)
 * containing all approved time entries for that day. Once extracted, the
 * day disappears from this list — its entries are stamped with the
 * target-specific ref and are never extracted again unless un-extracted.
 *
 * No period close required. Cycle defaults from the placement are shown
 * as advisory hints; user can extract any subset of approved days.
 */
export default function TimeSettlement() {
  const [target, setTarget] = useState('billing');
  return (
    <section data-testid="time-settlement">
      <header style={{ display: 'flex', alignItems: 'baseline', gap: 24, marginBottom: 16 }}>
        <h2 style={{ margin: 0, fontSize: 20 }}>Time Settlement</h2>
        <p style={{ margin: 0, color: 'var(--cf-text-secondary)', fontSize: 12 }}>
          Approved days, ready to extract. Each day is one block — no period close required.
        </p>
      </header>
      <nav style={{ display: 'flex', gap: 6, marginBottom: 16 }}>
        {TARGETS.map(t => (
          <button
            key={t.id}
            data-testid={`time-settlement-tab-${t.id}`}
            onClick={() => setTarget(t.id)}
            style={{
              padding: '6px 14px', borderRadius: 6, border: '1px solid var(--cf-border, #e5e7eb)',
              background: target === t.id ? 'var(--cf-text, #111827)' : 'transparent',
              color: target === t.id ? '#fff' : 'var(--cf-text-secondary)',
              fontSize: 13, cursor: 'pointer',
            }}
          >{t.label}</button>
        ))}
      </nav>
      <SettlementBoard key={target} target={target} />
    </section>
  );
}

function SettlementBoard({ target }) {
  const [from, setFrom] = useState('');
  const [to, setTo]     = useState('');
  const [placementId, setPlacementId] = useState('');
  const [targetRef, setTargetRef]     = useState('');
  const [busy, setBusy]               = useState(false);
  const [err, setErr]                 = useState(null);
  const [success, setSuccess]         = useState(null);

  const qs = useMemo(() => {
    const p = new URLSearchParams({ target });
    if (from) p.set('from', from);
    if (to)   p.set('to',   to);
    if (placementId) p.set('placement_id', placementId);
    return p.toString();
  }, [target, from, to, placementId]);

  const { data, loading, error, reload } = useApi(`/modules/time/api/settlement.php?${qs}`);
  const blocks = data?.blocks ?? [];

  // Each "id" here is the day-block's flat list of entry ids — collected as
  // a single selection unit when the user ticks the day.
  const dayBlockIds = useMemo(() => blocks.map(b => `${b.placement_id}:${b.work_date}`), [blocks]);
  const sel = useBulkSelection(dayBlockIds);
  const selectedEntryIds = useMemo(() => {
    const ids = [];
    blocks.forEach(b => {
      if (sel.has(`${b.placement_id}:${b.work_date}`)) {
        b.entries.forEach(e => ids.push(e.id));
      }
    });
    return ids;
  }, [blocks, sel]);

  const totalSelectedHours = useMemo(() =>
    blocks
      .filter(b => sel.has(`${b.placement_id}:${b.work_date}`))
      .reduce((sum, b) => sum + (Number(b.total_hours) || 0), 0),
    [blocks, sel]
  );

  const targetMeta = TARGETS.find(t => t.id === target);

  const extract = async () => {
    if (!selectedEntryIds.length) return;
    if (!targetRef || Number(targetRef) <= 0) {
      setErr(new Error(`${targetMeta.refLabel} required (positive integer)`));
      return;
    }
    setBusy(true); setErr(null); setSuccess(null);
    try {
      const res = await api.post(
        '/modules/time/api/settlement.php?action=extract',
        { entry_ids: selectedEntryIds, target, target_ref: Number(targetRef) }
      );
      setSuccess(`✓ Extracted ${res.extracted_count} entries (${selectedEntryIds.length} days) → ${targetMeta.refLabel} #${targetRef}`);
      sel.clear();
      setTargetRef('');
      reload();
    } catch (e) { setErr(e); }
    finally { setBusy(false); }
  };

  return (
    <>
      <div style={{ display: 'flex', gap: 12, alignItems: 'flex-end', marginBottom: 12, flexWrap: 'wrap' }}>
        <label style={{ fontSize: 12 }}>
          From <input type="date" value={from} onChange={e => setFrom(e.target.value)} data-testid="time-settlement-from" style={inp} />
        </label>
        <label style={{ fontSize: 12 }}>
          To <input type="date" value={to} onChange={e => setTo(e.target.value)} data-testid="time-settlement-to" style={inp} />
        </label>
        <label style={{ fontSize: 12 }}>
          Placement <input type="number" min="0" value={placementId} onChange={e => setPlacementId(e.target.value)}
                           placeholder="optional" data-testid="time-settlement-placement-filter"
                           style={{ ...inp, width: 110 }} />
        </label>
        <button className="btn btn--ghost" onClick={() => reload()} data-testid="time-settlement-reload">Refresh</button>
      </div>

      {sel.size > 0 && (
        <div data-testid="time-settlement-bulk-bar" style={bulkBar}>
          <span><strong>{sel.size}</strong> {sel.size === 1 ? 'day' : 'days'} selected</span>
          <span style={{ color: 'var(--cf-text-secondary)', fontSize: 12 }}>
            {selectedEntryIds.length} entries · {totalSelectedHours.toFixed(2)}h total
          </span>
          <input type="number" min="1" value={targetRef} onChange={e => setTargetRef(e.target.value)}
                 placeholder={targetMeta.refLabel} data-testid="time-settlement-target-ref"
                 style={{ ...inp, width: 160 }} />
          <button className="btn btn--primary" onClick={extract} disabled={busy || !targetRef}
                  data-testid="time-settlement-extract">
            {busy ? 'Extracting…' : `Extract → ${targetMeta.label.split(' → ')[1]}`}
          </button>
          <button className="btn btn--ghost" onClick={sel.clear} data-testid="time-settlement-clear">Clear</button>
        </div>
      )}
      {err && <p className="error" data-testid="time-settlement-error">Error: {err.message}</p>}
      {success && <p data-testid="time-settlement-success" style={successStyle}>{success}</p>}

      {loading && <p>Loading…</p>}
      {error && <p className="error">Load error: {error.message}</p>}

      <table className="data-table" data-testid="time-settlement-table">
        <thead>
          <tr>
            <th style={{ width: 32 }}>
              <input
                type="checkbox"
                checked={sel.allSelected}
                ref={el => { if (el) el.indeterminate = sel.someSelected; }}
                onChange={sel.toggleAll}
                disabled={!blocks.length}
                data-testid="time-settlement-select-all"
              />
            </th>
            <th>Day</th>
            <th>Placement</th>
            <th>Cycle (default)</th>
            <th>Cycle window</th>
            <th>Entries</th>
            <th style={{ textAlign: 'right' }}>Hours</th>
          </tr>
        </thead>
        <tbody>
          {!loading && blocks.length === 0 && (
            <tr><td colSpan={7} className="empty" data-testid="time-settlement-empty">
              No approved days awaiting extract for {target}.
            </td></tr>
          )}
          {blocks.map(b => {
            const id = `${b.placement_id}:${b.work_date}`;
            return (
              <tr key={id} data-testid={`time-settlement-day-${b.placement_id}-${b.work_date}`}
                  style={sel.has(id) ? { background: 'var(--cf-surface-alt, #f9fafb)' } : null}>
                <td>
                  <input type="checkbox" checked={sel.has(id)} onChange={() => sel.toggle(id)}
                         data-testid={`time-settlement-select-${b.placement_id}-${b.work_date}`} />
                </td>
                <td>{b.work_date}</td>
                <td>#{b.placement_id}</td>
                <td><span className="badge">{b.cycle_default}</span></td>
                <td style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>
                  {b.cycle_window?.label}
                  <br/>
                  <span style={{ fontSize: 11 }}>{b.cycle_window?.from} – {b.cycle_window?.to}</span>
                </td>
                <td>
                  {b.entries.map(e => (
                    <span key={e.id} className="badge" style={{ marginRight: 4 }}>
                      {e.category} · {e.hours}h
                    </span>
                  ))}
                </td>
                <td style={{ textAlign: 'right', fontWeight: 600 }}>{Number(b.total_hours).toFixed(2)}</td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </>
  );
}

const inp = { padding: '4px 8px', borderRadius: 6, border: '1px solid var(--cf-border, #e5e7eb)', fontSize: 13 };
const bulkBar = {
  display: 'flex', alignItems: 'center', gap: 12, padding: '10px 14px',
  background: 'var(--cf-surface-alt, #f3f4f6)', borderRadius: 8,
  marginBottom: 12, flexWrap: 'wrap',
};
const successStyle = {
  background: '#ecfdf5', color: '#065f46', padding: 8, borderRadius: 6, fontSize: 13,
};
