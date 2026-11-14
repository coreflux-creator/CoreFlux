import React, { useEffect, useRef, useState } from 'react';
import { api, bustApiCachePrefix, getPinnedTenantId } from '../../../dashboard/src/lib/api';

const selectionKey = ids => JSON.stringify([...new Set(ids.map(Number))].sort((a, b) => a - b));
const sameWorkspace = id => !getPinnedTenantId() || String(getPinnedTenantId()) === String(id);

export default function TimeDecisionReview({ kind, ids, decision, reason: initialReason = '', onClose, onDecided }) {
  const [review, setReview] = useState(null);
  const [reason, setReason] = useState(initialReason);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [reload, setReload] = useState(0);
  const pending = useRef(false);
  const dialog = useRef(null);
  const key = `${kind}:${decision}:${selectionKey(ids)}`;
  const endpoint = kind === 'weeks' ? '/modules/staffing/api/timesheets.php' : '/modules/time/api/entries.php';

  useEffect(() => {
    let active = true;
    setReview(null); setError(''); setLoading(true);
    const selectedIds = JSON.parse(key.slice(key.lastIndexOf(':') + 1));
    api.post(`${endpoint}?action=decision_review`, { ids: selectedIds, decision }).then(data => {
      if (!active) return;
      if (!sameWorkspace(data.workspace_id) || data.kind !== kind || data.decision !== decision
        || selectionKey(data.ids || []) !== selectionKey(selectedIds) || !/^[a-f0-9]{64}$/.test(data.revision || '')
        || !data.entries?.length || !data.entry_count) throw new Error('The review does not match this selection and workspace. Reload it.');
      setReview({ ...data, requestKey: key });
    }).catch(e => { if (active) setError(e.message); }).finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [key, endpoint, decision, kind, reload]);

  useEffect(() => {
    if (typeof document === 'undefined') return undefined;
    const previous = document.activeElement;
    dialog.current?.focus();
    return () => previous?.focus?.();
  }, []);

  const ready = review?.requestKey === key && sameWorkspace(review.workspace_id);
  const save = async event => {
    event.preventDefault();
    if (!ready || !sameWorkspace(review.workspace_id) || busy || pending.current || (decision === 'reject' && !reason.trim())) return;
    pending.current = true; setBusy(true); setError('');
    try {
      const result = await api.post(`${endpoint}?action=decision_commit`, {
        ids: review.ids, decision, review_revision: review.revision,
        ...(decision === 'reject' ? { reason: reason.trim() } : {}),
      });
      if (!sameWorkspace(review.workspace_id) || String(result.workspace_id) !== String(review.workspace_id)
        || result.kind !== kind || result.decision !== decision || selectionKey(result.ids || []) !== selectionKey(review.ids)) {
        throw new Error('The workspace or response changed. Check time history before trying again.');
      }
      bustApiCachePrefix('/modules/time/api/entries.php');
      bustApiCachePrefix('/api/v1/time/entries');
      bustApiCachePrefix('/modules/staffing/api/timesheets.php');
      bustApiCachePrefix('timesheets-list:');
      onDecided(result);
    } catch (e) { setReview(null); setError(e.message); }
    finally { pending.current = false; setBusy(false); }
  };
  const keyboard = event => {
    if (event.key === 'Escape' && !pending.current) { event.preventDefault(); onClose(); }
    if (event.key !== 'Tab' || !dialog.current) return;
    const controls = [...dialog.current.querySelectorAll('button:not(:disabled), textarea:not(:disabled), [href]')];
    const first = controls[0], last = controls[controls.length - 1];
    if (event.shiftKey && (document.activeElement === first || document.activeElement === dialog.current)) { event.preventDefault(); last?.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
  };
  return <div style={{ position: 'fixed', inset: 0, zIndex: 1500, background: '#0008', padding: 24, display: 'grid', placeItems: 'center' }}>
    <section ref={dialog} tabIndex={-1} onKeyDown={keyboard} role="dialog" aria-modal="true" aria-labelledby="time-decision-title"
      data-testid="time-decision-review" style={{ width: 'min(1100px, 100%)', maxHeight: '90vh', overflow: 'auto', background: 'var(--cf-surface, white)', borderRadius: 12, padding: 24 }}>
      <h2 id="time-decision-title">{decision === 'approve' ? 'Review time for approval' : 'Review time for correction'}</h2>
      {loading && <p>Loading current time…</p>}
      {error && <p role="alert" data-testid="time-decision-error">{error} <button className="btn" disabled={busy || loading} onClick={() => setReload(value => value + 1)}>Reload review</button></p>}
      {!loading && !error && !ready && <p role="alert">Reload this selection in its original workspace.</p>}
      {ready && <form onSubmit={save} data-testid="time-decision-form">
        <p>{review.entry_count} submitted entries · {Number(review.total_hours).toFixed(2)} hours. Confirm the details below. Already-approved entries retain their earlier decision.</p>
        <div style={{ overflowX: 'auto' }}><table className="data-table" style={{ width: '100%' }}>
          <thead><tr><th>Worker</th><th>Assignment</th><th>Date</th><th>Hours</th><th>Type</th><th>Source</th><th>Decision</th></tr></thead>
          <tbody>{review.entries.map(row => <tr key={row.id}>
            <td>{row.person_number} · {row.worker_name}</td><td>{row.placement_number} · {row.placement_title}</td>
            <td>{row.work_date}</td><td>{Number(row.hours).toFixed(2)}</td><td>{String(row.hour_type || row.category).replaceAll('_', ' ')}{row.description && <p>{row.description}</p>}</td>
            <td>{String(row.source || 'manual_entry').replaceAll('_', ' ')}</td><td>{row.will_change ? (decision === 'approve' ? 'Approve' : 'Return for correction') : 'Retain earlier approval'}</td>
          </tr>)}</tbody>
        </table></div>
        {decision === 'reject' && <label style={{ display: 'block', margin: '16px 0' }}>Reason for correction
          <textarea className="input" required maxLength={500} value={reason} disabled={busy} onChange={event => setReason(event.target.value)} data-testid="time-decision-reason" />
        </label>}
        <button className="btn btn--primary" type="submit" disabled={busy || (decision === 'reject' && !reason.trim())} data-testid="time-decision-confirm">
          {busy ? 'Saving decision…' : decision === 'approve' ? 'Confirm approval' : 'Confirm rejection'}
        </button>{' '}
      </form>}
      <button className="btn" disabled={busy} onClick={onClose} data-testid="time-decision-cancel">Cancel</button>
    </section>
  </div>;
}
