import React, { useRef, useState } from 'react';
import { api } from '../lib/api';
import { useWorkspaceRequest } from './QuantaMetadataPanel';

const endpoint = '/api/quanta.php?action=time_control_';
const pending = state => ['sending', 'uncertain'].includes(state);
const allowed = choice => choice.placement_id && ['draft', 'active', 'ended'].includes(choice.status);
const section = { marginTop: 28, paddingTop: 22, borderTop: '1px solid var(--cf-border)' };

export default function QuantaTimeControl({ people, worksites, tenantId, connectionRevision }) {
  const [person, setPerson] = useState('');
  const [site, setSite] = useState('');
  const [context, setContext] = useState(null);
  const [selected, setSelected] = useState([]);
  const [review, setReview] = useState(null);
  const [confirmed, setConfirmed] = useState(false);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const busyRef = useRef(false);
  const clear = () => { setContext(null); setSelected([]); setReview(null); setConfirmed(false); setError(''); };
  const { currentTenant, begin } = useWorkspaceRequest(tenantId, connectionRevision, () => {
    clear(); setPerson(''); setSite(''); setBusy(false); busyRef.current = false;
  });
  const run = async action => {
    if (busyRef.current) return;
    if (!currentTenant()) { clear(); setError('Workspace changed. Refresh before continuing.'); return; }
    const envelope = review?.delivery;
    if (action === 'send' && (!confirmed || !review?.delivery_enabled || !['preview', 'approved'].includes(envelope?.state))) return;
    const request = begin();
    const body = ['context', 'preview'].includes(action)
      ? { person_id: Number(person), worksite_id: site, ...(action === 'preview' ? { placement_ids: selected, context_hash: context.context_hash } : {}) }
      : { delivery_id: Number(envelope.id), review_hash: envelope.review_hash, ...(action === 'send' ? { confirm_time_control: true } : {}) };
    busyRef.current = true; setBusy(true); setError(''); setConfirmed(false);
    if (action === 'context') clear();
    if (action === 'preview') setReview(null);
    try {
      const result = await api.post(endpoint + action, body, request.options);
      if (!request.current()) return;
      if (!currentTenant()) { clear(); setError('Workspace changed. Refresh before continuing.'); return; }
      if (action === 'context') {
        setContext(result); setSelected(result.choices.filter(c => c.selected && allowed(c)).map(c => c.placement_id));
      } else if (action === 'preview') setReview(result);
      else setReview(prior => ({ ...prior, delivery: action === 'send' ? result : result.delivery,
        receiptState: action === 'receipt' ? result.receipt_state : null }));
    } catch (e) {
      if (!request.current()) return;
      if (!currentTenant()) { clear(); setError('Workspace changed. Refresh before continuing.'); return; }
      if (action === 'send') setReview(prior => ({ ...prior, delivery: { ...envelope, state: 'uncertain' } }));
      setError(e.message || 'Could not review assignment time setup');
    } finally { if (request.current()) { busyRef.current = false; setBusy(false); } }
  };
  const state = review?.delivery?.state;
  const locked = busy || pending(state);
  const legacyBlocked = context && (!context.current_version || selected.length > 0)
    && (context.unbound_time.count > 0 || !context.unbound_time.complete);
  return <section style={section}>
    <h2 style={{ fontSize: 18 }}>Assignment time setup</h2>
    <p>Choose which shared Core placements can receive time at a Quanta worksite. Quanta still checks the worker’s access, placement status and dates.</p>
    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 12 }}>
      <label>Core person<br /><select aria-label="Time setup person" value={person} disabled={locked} onChange={e => { if (locked) return; setPerson(e.target.value); clear(); }}>
        <option value="">Choose a person</option>{people.map(p => <option key={p.id} value={p.id}>{p.person_number || 'Number unavailable'} · {p.name}</option>)}
      </select></label>
      <label>Quanta worksite<br /><select aria-label="Time setup worksite" value={site} disabled={locked} onChange={e => { if (locked) return; setSite(e.target.value); clear(); }}>
        <option value="">Choose a worksite</option>{worksites.filter(s => s.active).map(s => <option key={s.id} value={s.id}>{s.name}</option>)}
      </select></label>
      <button className="btn btn-secondary" disabled={locked || !person || !site || !tenantId} onClick={() => run('context')}>Review assignment time setup</button>
    </div>
    {error && <p role="alert">{error}</p>}
    {context && <div>
      <p><strong>{context.person_number}</strong> · {context.worker_name} · {context.worksite_name} · {context.workspace.name}</p>
      {context.choices.map(c => <label key={c.assignment_id} style={{ display: 'block', margin: '8px 0' }}>
        <input type="checkbox" aria-label={`Select ${c.placement_number}`} checked={selected.includes(c.placement_id)} disabled={locked || !allowed(c)}
          onChange={e => { if (locked) return; setSelected(prior => e.target.checked ? [...prior, c.placement_id] : prior.filter(id => id !== c.placement_id)); setReview(null); setConfirmed(false); }} />
        {' '}{c.placement_number} · {c.title} · {c.status} · {c.start_date} to {c.end_date || 'open-ended'}
        {!c.placement_id && ' · Core placement unavailable; reconcile its identity'}
      </label>)}
      {!context.choices.length && <p>No shared assignments at this worksite. Publish assignment details first, or review a hold on new time.</p>}
      {!selected.length && <p>This selection holds new time at this worksite. Existing hours and approvals stay recorded.</p>}
      {(context.unbound_time.count > 0 || !context.unbound_time.complete) && <p role="status">{context.unbound_time.count} existing time entries need assignment review{!context.unbound_time.complete ? '; the inventory is incomplete' : ''}. Enabling assignments is held. An existing setup can still hold new time.</p>}
      {!context.delivery_enabled && <p>Assignment time setup is not enabled for this connection.</p>}
      {!review && <button className="btn btn-secondary" disabled={locked || legacyBlocked} onClick={() => run('preview')}>Preview time setup change</button>}
    </div>}
    {review && <div>
      {!review.receipt_only && <p>Reviewed selection: {review.selected_placements.length ? review.selected_placements.join(', ') : 'Hold new time at this worksite'}.</p>}
      <p role="status">Delivery: {state === 'delivered' ? 'Confirmed by Quanta' : state}.</p>
      {pending(state) && <><p>The original result is unconfirmed. Checking its receipt can recover a committed change without resending it.</p>
        <button className="btn btn-secondary" disabled={busy} onClick={() => run('receipt')}>Check original time setup receipt</button></>}
      {['not_found', 'unconfirmed'].includes(review.receiptState) && <p>This attempt remains unconfirmed.</p>}
      {state === 'failed' && <p>The original change was rejected. Refresh assignment time setup before reviewing another change.</p>}
      {state === 'delivered' && <p>Refresh assignment time setup to check the current selection and any later changes.</p>}
      {review.delivery_enabled && ['preview', 'approved'].includes(state) && <>
        <label style={{ display: 'block', margin: '12px 0' }}><input type="checkbox" aria-label="Confirm time setup" checked={confirmed} disabled={busy} onChange={e => setConfirmed(e.target.checked)} /> Core will limit time at this worksite to the reviewed placements; an empty selection holds new time.</label>
        <button className="btn btn-primary" disabled={busy || !confirmed} onClick={() => run('send')}>Apply reviewed time setup</button>
      </>}
    </div>}
  </section>;
}

export function QuantaTimeControlRecovery({ tenantId, connectionRevision }) {
  const [page, setPage] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const busyRef = useRef(false);
  const { currentTenant, begin } = useWorkspaceRequest(tenantId, connectionRevision, () => {
    setPage(null); setBusy(false); setError(''); busyRef.current = false;
  });
  const run = async (delivery = null, before = null) => {
    if (busyRef.current) return;
    if (!currentTenant()) { setPage(null); setError('Workspace changed. Refresh before continuing.'); return; }
    const request = begin(); busyRef.current = true; setBusy(true); setError('');
    try {
      const result = await api.post(endpoint + (delivery ? 'receipt' : 'pending'), delivery
        ? { delivery_id: Number(delivery.id), review_hash: delivery.review_hash } : { before }, request.options);
      if (!request.current()) return;
      if (!currentTenant()) { setPage(null); setError('Workspace changed. Refresh before continuing.'); return; }
      if (delivery) setPage(prior => ({ ...prior, rows: prior.rows.map(r => String(r.delivery.id) === String(delivery.id)
        ? { delivery: result.delivery, receiptState: result.receipt_state } : r) }));
      else setPage(prior => ({ ...result, rows: before ? [...(prior?.rows || []), ...result.rows.filter(r => !prior?.rows.some(old => String(old.delivery.id) === String(r.delivery.id)))] : result.rows }));
    } catch (e) {
      if (!request.current()) return;
      if (!currentTenant()) { setPage(null); setError('Workspace changed. Refresh before continuing.'); return; }
      setError(e.message || 'Could not check time setup receipts');
    } finally { if (request.current()) { busyRef.current = false; setBusy(false); } }
  };
  return <section style={section}>
    <h2 style={{ fontSize: 18 }}>Time setup recovery</h2>
    <p>Check unconfirmed changes even if the person or placement is no longer available.</p>
    <button className="btn btn-secondary" disabled={busy || !tenantId} onClick={() => run()}>Find unconfirmed time setup</button>
    {error && <p role="alert">{error}</p>}
    {page?.rows.length === 0 && <p role="status">No unconfirmed time setup changes.</p>}
    {page?.rows.map(r => <div key={r.delivery.id}>
      <p>Delivery #{r.delivery.id} · {r.delivery.attempted_at || r.delivery.created_at} · {r.delivery.state}</p>
      {pending(r.delivery.state) && <button className="btn btn-secondary" disabled={busy} onClick={() => run(r.delivery)}>Check time setup receipt #{r.delivery.id}</button>}
      {r.delivery.state === 'delivered' && <p role="status">Original change confirmed. Review the current setup separately for later changes.</p>}
      {r.delivery.state === 'failed' && <p>Original change rejected. Review the current setup again.</p>}
      {['not_found', 'unconfirmed'].includes(r.receiptState) && <p>This attempt remains unconfirmed.</p>}
    </div>)}
    {page?.next_before && <button className="btn btn-secondary" disabled={busy} onClick={() => run(null, page.next_before)}>Earlier time setup changes</button>}
  </section>;
}
