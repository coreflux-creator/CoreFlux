import React, { useEffect, useRef, useState } from 'react';
import { api, getPinnedTenantId } from '../lib/api';

const outcomeText = row => row.state !== 'delivered'
  ? ({ pending: 'Awaiting return to Quanta', sending: 'Return needs receipt inspection', uncertain: 'Return needs receipt inspection', failed: 'Quanta did not accept the attempt' })[row.state] || 'Inspect this return'
  : row.outcome?.contact_state === 'conflict' ? 'Quanta kept a newer contact edit. Reconcile the listed fields before sharing a new snapshot.'
  : row.outcome?.contact_state === 'rejected' ? 'Quanta received the rejection and review note.'
  : row.outcome?.contact_state === 'applied' ? 'Quanta confirmed the contact update.'
  : 'A verified contact outcome is unavailable.';
const nativeLabel = { full_name: 'Name', phone: 'Phone', contact_email: 'Contact email' };

export default function QuantaContactReturns({ workspaceId }) {
  const [page, setPage] = useState(null), [busy, setBusy] = useState(false), [error, setError] = useState('');
  const [confirmed, setConfirmed] = useState(null);
  const alive = useRef(false), inFlight = useRef(false), sequence = useRef(0);
  useEffect(() => { alive.current = true; sequence.current++; return () => { alive.current = false; }; }, []);
  const sameWorkspace = () => /^[1-9][0-9]*$/.test(String(workspaceId || ''))
    && (!getPinnedTenantId() || String(getPinnedTenantId()) === String(workspaceId));
  const update = data => setPage(old => ({ ...old, rows: old.rows.map(row => row.change_id === data.change_id ? { ...row, ...data } : row) }));
  const run = async (action, body, accept, failed) => {
    if (inFlight.current) return;
    if (!sameWorkspace()) { setError('The workspace changed. Refresh before continuing.'); return; }
    inFlight.current = true; const request = ++sequence.current; setBusy(true); setError(''); setConfirmed(null);
    try {
      const data = await api.post('/api/quanta.php?action=' + action, body, { timeoutMs: 120000, headers: { 'X-CoreFlux-Tenant-Id': String(workspaceId) } });
      if (alive.current && request === sequence.current && sameWorkspace()) accept(data);
    } catch (e) {
      if (alive.current && request === sequence.current && sameWorkspace()) { failed?.(); setError(e.message || 'The return could not be confirmed.'); }
    } finally {
      if (alive.current && request === sequence.current) { inFlight.current = false; setBusy(false); }
    }
  };
  const load = cursor => run('profile_return_list', { cursor: cursor || null }, data => setPage(old => ({ ...data,
    rows: cursor && old ? [...old.rows, ...data.rows.filter(row => !old.rows.some(previous => previous.change_id === row.change_id))] : data.rows })));
  const send = (row, resume) => run(resume ? 'profile_return_resume' : 'profile_return_send',
    { change_id: row.change_id, confirm_return: true }, data => update({ ...data, resume_allowed: false }),
    () => update({ change_id: row.change_id, state: 'uncertain', resume_allowed: false }));
  return <section aria-label="Return contact decisions" style={{ marginTop: 24 }}>
    <h3>Return saved decisions to Quanta</h3>
    <p>Return the accepted contact values or rejection note. Each return uses the original saved decision; sign-in details and provider IDs stay unchanged.</p>
    <button type="button" className="btn btn-secondary" disabled={busy} onClick={() => load()}>Load decision returns</button>
    {error && <p role="alert">{error}</p>}
    {page && !page.rows.length && <p>No saved decisions on this page.</p>}
    {!!page?.unavailable_count && <p>{page.unavailable_count} saved decision(s) need connection or identity reconciliation.</p>}
    {page && !page.delivery_enabled && <p>Contact return is not enabled for this pilot. Saved receipts remain available.</p>}
    {page?.rows.map(row => <article key={row.change_id} style={{ padding: '12px 0', borderBottom: '1px solid var(--cf-border)' }}>
      <strong>{row.person_number} · {row.person_name}</strong>
      <p>{row.decision === 'accepted' ? 'Accepted in Core' : 'Rejected in Core'} · {outcomeText(row)}</p>
      {!!row.outcome?.conflict_fields?.length && <p>Fields to reconcile: {row.outcome.conflict_fields.map(field => nativeLabel[field] || field).join(', ')}</p>}
      {row.state !== 'delivered' && <>
        <button type="button" className="btn btn-secondary" disabled={busy} onClick={() => run('profile_return_status', { change_id: row.change_id }, update)}>Inspect return receipt</button>{' '}
        {page.delivery_enabled && (row.state === 'pending' || row.resume_allowed) && <>
          <label><input type="checkbox" disabled={busy} checked={confirmed === row.change_id} onChange={e => setConfirmed(e.target.checked ? row.change_id : null)} /> Return this saved decision to Quanta.</label>{' '}
          <button type="button" className="btn btn-primary" disabled={busy || confirmed !== row.change_id}
            onClick={() => send(row, row.state !== 'pending')}>{row.state === 'pending' ? 'Return saved decision' : 'Resume original return'}</button>
        </>}
      </>}
    </article>)}
    {page?.next_cursor && <button type="button" className="btn btn-secondary" disabled={busy} onClick={() => load(page.next_cursor)}>More decision returns</button>}
  </section>;
}
