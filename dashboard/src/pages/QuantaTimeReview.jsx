import React, { useEffect, useRef, useState } from 'react';
import { api, getPinnedTenantId } from '../lib/api';

const endpoint = '/api/quanta.php?action=time_review_';
const labels = { pending_review: 'Awaiting Core review', accepted: 'Accepted in Core', needs_correction: 'Needs correction in Core' };

export default function QuantaTimeReview({ entryId, tenantId, connectionRevision }) {
  const [review, setReview] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [confirmed, setConfirmed] = useState(false);
  const generation = useRef(0);
  const request = useRef(null);
  useEffect(() => {
    const lifecycle = generation;
    const activeRequest = request;
    generation.current++; setReview(null); setError(''); setBusy(false); setConfirmed(false);
    return () => { lifecycle.current++; activeRequest.current?.abort(); };
  }, [entryId, tenantId, connectionRevision]);
  const currentTenant = () => !!tenantId && (!getPinnedTenantId() || String(getPinnedTenantId()) === String(tenantId));
  const run = async action => {
    if (!currentTenant()) { setReview(null); setError('Workspace changed. Refresh before continuing.'); return; }
    const sequence = ++generation.current;
    request.current?.abort();
    const controller = new AbortController(); request.current = controller;
    setBusy(true); setError(''); setConfirmed(false);
    const envelope = review?.delivery;
    const body = action === 'preview' ? { source_entry_id: entryId } : {
      delivery_id: Number(envelope.id), review_hash: envelope.review_hash, confirm_status: confirmed,
    };
    try {
      const result = await api.post(endpoint + action, body, { timeoutMs: 60000, headers: { 'X-CoreFlux-Tenant-Id': String(tenantId) }, fetch: { signal: controller.signal } });
      if (sequence !== generation.current) return;
      if (!currentTenant()) { setReview(null); setError('Workspace changed. Refresh before continuing.'); return; }
      if (action === 'preview') setReview(result);
      else setReview(prior => ({ ...prior, delivery: action === 'receipt' ? result.delivery : result,
        receiptState: action === 'receipt' ? result.receipt_state : null }));
    } catch (err) {
      if (sequence !== generation.current) return;
      if (!currentTenant()) { setReview(null); setError('Workspace changed. Refresh before continuing.'); return; }
      // Preserve the original delivery for receipt recovery after a lost response.
      if (action === 'send') setReview(prior => ({ ...prior, delivery: { ...envelope, state: 'uncertain' } }));
      if (action === 'preview') setReview(null);
      setError(err.message || 'Could not check Core review status');
    } finally { if (sequence === generation.current) setBusy(false); }
  };
  const state = review?.delivery?.state;
  const uncertain = ['sending', 'uncertain'].includes(state);
  const canSend = review?.delivery_enabled && ['preview', 'approved'].includes(state);
  return <details style={{ fontSize: 12, marginTop: 8 }}>
    <summary>Share Core review status</summary>
    <p>Show this time’s Core review status in the Quanta worker portal.</p>
    {!uncertain && <button className="btn btn-secondary btn-sm" disabled={busy} onClick={() => run('preview')}>Review current status</button>}
    {error && <p role="alert" style={{ color: '#b91c1c' }}>{error}</p>}
    {review && <div>
      {!review.receipt_only && <><p><strong>{labels[review.state]}</strong> · {review.placement_number} · {review.work_date}</p>
        <p>Quanta workspace: {review.workspace?.name}</p></>}
      {review.receipt_only && state === 'delivered' && <p role="status">Original delivery confirmed. Review current status again to check for later changes.</p>}
      {!review.receipt_only && (review.status === 'unchanged' || state === 'delivered') && <p role="status">Status confirmed in Quanta.</p>}
      {!review.delivery_enabled && <p>Time status sharing is not enabled in this environment.</p>}
      {canSend && <><label style={{ display: 'block', margin: '8px 0' }}><input type="checkbox" checked={confirmed} disabled={busy} onChange={e => setConfirmed(e.target.checked)} /> I reviewed the status and destination.</label>
        <button className="btn btn-primary btn-sm" disabled={busy || !confirmed} onClick={() => run('send')}>Share reviewed status</button></>}
      {uncertain && <><p>The result is unconfirmed. Check the original receipt before another change.</p>
        <button className="btn btn-secondary btn-sm" disabled={busy} onClick={() => run('receipt')}>Check original receipt</button></>}
      {review.receiptState === 'not_found' && <p>No receipt was found. Keep this attempt for reconciliation.</p>}
      {['failed', 'conflict', 'cancelled'].includes(state) && <p>The status was not confirmed. Review the current source before proceeding.</p>}
    </div>}
  </details>;
}

/** Does not depend on the provider feed, its date window, or the current connection being available. */
export function QuantaTimeReviewRecovery({ tenantId, connectionRevision }) {
  const [page, setPage] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const generation = useRef(0);
  const request = useRef(null);
  useEffect(() => {
    const lifecycle = generation;
    const activeRequest = request;
    generation.current++; setPage(null); setBusy(false); setError('');
    return () => { lifecycle.current++; activeRequest.current?.abort(); };
  }, [tenantId, connectionRevision]);
  const currentTenant = () => !!tenantId && (!getPinnedTenantId() || String(getPinnedTenantId()) === String(tenantId));
  const run = async (delivery = null, before = null) => {
    if (!currentTenant()) { setPage(null); setError('Workspace changed. Refresh before continuing.'); return; }
    const sequence = ++generation.current;
    request.current?.abort(); const controller = new AbortController(); request.current = controller;
    setBusy(true); setError('');
    try {
      const action = delivery ? 'receipt' : 'pending';
      const body = delivery ? { delivery_id: Number(delivery.id), review_hash: delivery.review_hash } : { before };
      const result = await api.post(endpoint + action, body, { timeoutMs: 60000,
        headers: { 'X-CoreFlux-Tenant-Id': String(tenantId) }, fetch: { signal: controller.signal } });
      if (sequence !== generation.current) return;
      if (!currentTenant()) { setPage(null); setError('Workspace changed. Refresh before continuing.'); return; }
      if (delivery) setPage(prior => ({ ...prior, rows: prior.rows.map(row => String(row.delivery.id) === String(delivery.id)
        ? { ...row, delivery: result.delivery, receiptState: result.receipt_state } : row) }));
      else setPage(prior => ({ ...result, rows: before
        ? [...(prior?.rows || []), ...result.rows.filter(row => !prior?.rows.some(old => String(old.delivery.id) === String(row.delivery.id)))]
        : result.rows }));
    } catch (err) {
      if (sequence !== generation.current) return;
      if (!currentTenant()) { setPage(null); setError('Workspace changed. Refresh before continuing.'); return; }
      setError(err.message || 'Could not check time status deliveries');
    } finally { if (sequence === generation.current) setBusy(false); }
  };
  return <section style={{ marginTop: 28, paddingTop: 22, borderTop: '1px solid var(--cf-border)' }}>
    <h2 style={{ fontSize: 18 }}>Time status delivery recovery</h2>
    <p>Find unconfirmed status deliveries, including time that has since changed or disappeared from Quanta.</p>
    <button className="btn btn-secondary" disabled={busy || !tenantId} onClick={() => run()}>Find unconfirmed deliveries</button>
    {error && <p role="alert">{error}</p>}
    {page?.rows.length === 0 && <p role="status">No unconfirmed time status deliveries.</p>}
    {page?.rows.map(row => <div key={row.delivery.id} style={{ marginTop: 12 }}>
      <strong>{row.placement_number || 'Placement number unavailable'}</strong> · Delivery #{row.delivery.id} · {row.delivery.attempted_at || row.delivery.created_at}
      {['sending', 'uncertain'].includes(row.delivery.state) && <p><button className="btn btn-secondary btn-sm" disabled={busy}
        onClick={() => run(row.delivery)}>Check original receipt #{row.delivery.id}</button></p>}
      {row.delivery.state === 'delivered' && <p role="status">Original delivery confirmed. Review current time separately for later changes.</p>}
      {row.delivery.state === 'failed' && <p role="status">Quanta rejected the original delivery. Review current time before sharing another status.</p>}
      {row.receiptState === 'not_found' && <p>No receipt was found. This attempt remains unconfirmed.</p>}
      {row.receiptState === 'unconfirmed' && <p>Quanta has not completed this attempt. Check its receipt again later.</p>}
    </div>)}
    {page?.next_before && <button className="btn btn-secondary" disabled={busy} onClick={() => run(null, page.next_before)}>Earlier unconfirmed deliveries</button>}
  </section>;
}
