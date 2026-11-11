import React, { useRef, useState } from 'react';
import { api } from '../lib/api';
import { useWorkspaceRequest } from './QuantaMetadataPanel';

const endpoint = '/api/quanta.php?action=legacy_time_';
const pending = state => ['sending', 'uncertain'].includes(state);

export default function QuantaLegacyTime({ tenantId, connectionRevision, personId, worksiteId, disabled = false }) {
  const [inventory, setInventory] = useState(null);
  const [review, setReview] = useState(null);
  const [confirmed, setConfirmed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const busyRef = useRef(false);
  const clear = () => { setInventory(null); setReview(null); setConfirmed(false); setError(''); };
  const { currentTenant, begin } = useWorkspaceRequest(tenantId, `${connectionRevision}/${personId}/${worksiteId}`, () => {
    clear(); setBusy(false); busyRef.current = false;
  });
  const run = async (action, row = null) => {
    if (busyRef.current || disabled) return;
    if (!currentTenant()) { clear(); setError('Workspace changed. Refresh before continuing.'); return; }
    const envelope = review?.delivery;
    if (action === 'send' && (!confirmed || !review?.delivery_enabled || !['preview', 'approved'].includes(envelope?.state))) return;
    if (pending(envelope?.state) && action !== 'receipt') return;
    const request = begin();
    const body = action === 'inventory' ? { person_id: Number(personId), worksite_id: worksiteId }
      : action === 'preview' ? { source_entry_id: row.source_entry_id }
        : { delivery_id: Number(envelope.id), review_hash: envelope.review_hash, ...(action === 'send' ? { confirm_existing_time: true } : {}) };
    busyRef.current = true; setBusy(true); setError(''); setConfirmed(false);
    if (action === 'inventory' || action === 'preview') setReview(null);
    try {
      const result = await api.post(endpoint + action, body, request.options);
      if (!request.current()) return;
      if (!currentTenant()) { clear(); setError('Workspace changed. Refresh before continuing.'); return; }
      if (action === 'inventory') setInventory(result);
      else if (action === 'preview') setReview(result);
      else setReview(prior => ({ ...prior, delivery: action === 'send' ? result : result.delivery,
        receiptState: action === 'receipt' ? result.receipt_state : null }));
    } catch (e) {
      if (!request.current()) return;
      if (!currentTenant()) { clear(); setError('Workspace changed. Refresh before continuing.'); return; }
      if (action === 'send') setReview(prior => ({ ...prior, delivery: { ...envelope, state: 'uncertain' } }));
      setError(e.message || 'Could not review the existing time link');
    } finally { if (request.current()) { busyRef.current = false; setBusy(false); } }
  };
  const state = review?.delivery?.state;
  const locked = disabled || busy || pending(state);
  return <section style={{ marginTop: 18 }}>
    <h3>Review existing time</h3>
    <p>Link previously imported, approved Quanta time to its reviewed Core placement. Original hours and approvals stay recorded.</p>
    <button className="btn btn-secondary" disabled={locked || !tenantId || !personId || !worksiteId} onClick={() => run('inventory')}>Find existing time to link</button>
    {error && <p role="alert">{error}</p>}
    {inventory && <>
      <p>{inventory.remaining} entries still need review. Showing {inventory.rows.length}{!inventory.complete ? '; the source inventory is incomplete' : ''}.</p>
      {inventory.rows.map((r, i) => <div key={r.source_entry_id}>
        <span>{r.work_date || 'Date unavailable'} · {r.imported ? `${r.hours} imported hours` : 'Import and reconcile this time in Core first'}</span>{' '}
        <button className="btn btn-secondary" disabled={locked || !r.imported} onClick={() => run('preview', r)}>Review existing entry {i + 1}</button>
      </div>)}
    </>}
    {review && <div>
      {!review.receipt_only && <>
        <p><strong>{review.person_number} · {review.placement_number}</strong> · {review.work_date} · {review.hours} hours · {review.workspace.name}</p>
        <p>Quanta approved: {review.source_approved_at}. Core review: {review.components.map(c => `${c.component}: ${c.status}`).join(', ')}.</p>
      </>}
      {review.status === 'unchanged' && <p role="status">This original time is already linked to the reviewed placement.</p>}
      {state && <p role="status">Historical link: {state === 'delivered' ? 'Confirmed by Quanta' : state}.</p>}
      {pending(state) && <><p>The original result is unconfirmed. Check its receipt before making another attempt.</p>
        <button className="btn btn-secondary" disabled={busy || disabled} onClick={() => run('receipt')}>Check original historical link receipt</button></>}
      {['not_found', 'unconfirmed'].includes(review.receiptState) && <p>This attempt remains unconfirmed.</p>}
      {state === 'failed' && <p>The link was rejected. Find existing time again to review the latest evidence.</p>}
      {(state === 'delivered' || review.status === 'unchanged') && <p>Refresh existing time to review the next entries, then refresh assignment time setup.</p>}
      {!review.receipt_only && !review.delivery_enabled && review.status !== 'unchanged' && <p>Historical time linking is not enabled for this connection.</p>}
      {review.delivery_enabled && ['preview', 'approved'].includes(state) && <>
        <label><input type="checkbox" aria-label="Confirm historical time link" checked={confirmed} disabled={busy || disabled} onChange={e => setConfirmed(e.target.checked)} /> Link this original time to the displayed placement using its existing approval and Core review.</label>{' '}
        <button className="btn btn-primary" disabled={busy || disabled || !confirmed} onClick={() => run('send')}>Link reviewed historical time</button>
      </>}
    </div>}
  </section>;
}

export function QuantaLegacyTimeRecovery({ tenantId, connectionRevision }) {
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
      setError(e.message || 'Could not check historical time receipts');
    } finally { if (request.current()) { busyRef.current = false; setBusy(false); } }
  };
  return <section style={{ marginTop: 24 }}>
    <h3>Historical time link recovery</h3>
    <p>Find an interrupted attempt even when its original time or placement is unavailable.</p>
    <button className="btn btn-secondary" disabled={busy || !tenantId} onClick={() => run()}>Find unconfirmed historical links</button>
    {error && <p role="alert">{error}</p>}
    {page?.rows.length === 0 && <p role="status">No unconfirmed historical links.</p>}
    {page?.rows.map(r => <div key={r.delivery.id}>
      <p>Delivery #{r.delivery.id} · {r.delivery.attempted_at || r.delivery.created_at} · {r.delivery.state}</p>
      {pending(r.delivery.state) && <button className="btn btn-secondary" disabled={busy} onClick={() => run(r.delivery)}>Check historical link receipt #{r.delivery.id}</button>}
      {r.delivery.state === 'delivered' && <p role="status">Original link confirmed. Refresh assignment time setup to see what remains.</p>}
      {r.delivery.state === 'failed' && <p>Original link rejected. Review the current time again.</p>}
      {['not_found', 'unconfirmed'].includes(r.receiptState) && <p>This attempt remains unconfirmed.</p>}
    </div>)}
    {page?.next_before && <button className="btn btn-secondary" disabled={busy} onClick={() => run(null, page.next_before)}>Earlier historical link attempts</button>}
  </section>;
}
