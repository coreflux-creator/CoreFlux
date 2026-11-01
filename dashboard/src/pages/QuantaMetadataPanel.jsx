import React, { useEffect, useRef, useState } from 'react';
import { api, getPinnedTenantId } from '../lib/api';

const endpoint = '/api/quanta.php?action=';
const style = { padding: '8px 10px', border: '1px solid var(--cf-border)', borderRadius: 5, background: 'var(--cf-surface)', color: 'var(--cf-text-primary)', maxWidth: '100%' };
const labels = { person: 'Person number', placement: 'Placement number', worker_id: 'Quanta worker', client: 'Client number', vendor: 'Vendor number', end_client: 'Company number', worksite_id: 'Quanta worksite', work_location: 'Work location', title: 'Title', engagement_type: 'Engagement type', start_date: 'Start date', end_date: 'End date', status: 'Status' };
function valueLabel(value, field) {
  if (field === 'work_location' && value) return ['city', 'state', 'country'].map(key => value[key] || '—').join(' · ');
  return value && typeof value === 'object' ? value.number : (value ?? '—');
}

function useWorkspaceRequest(tenantId, connectionRevision, reset) {
  const generation = useRef(0);
  const request = useRef(null);
  const resetRef = useRef(reset);
  resetRef.current = reset;
  useEffect(() => {
    const lifecycle = generation;
    const activeRequest = request;
    generation.current++; resetRef.current();
    return () => { lifecycle.current++; activeRequest.current?.abort(); };
  }, [tenantId, connectionRevision]);
  const currentTenant = () => !!tenantId && (!getPinnedTenantId() || String(getPinnedTenantId()) === String(tenantId));
  const begin = () => {
    const sequence = ++generation.current;
    request.current?.abort(); const controller = new AbortController(); request.current = controller;
    return { current: () => sequence === generation.current, options: { timeoutMs: 120000,
      headers: { 'X-CoreFlux-Tenant-Id': String(tenantId) }, fetch: { signal: controller.signal } } };
  };
  return { currentTenant, begin };
}

export default function QuantaMetadataPanel({ people, placements, worksites, tenantId, connectionRevision }) {
  const [kind, setKind] = useState('person');
  const [recordId, setRecordId] = useState('');
  const [siteId, setSiteId] = useState('');
  const [review, setReview] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [confirmed, setConfirmed] = useState(false);
  const resetReview = () => { setReview(null); setConfirmed(false); setError(''); };
  const { currentTenant, begin } = useWorkspaceRequest(tenantId, connectionRevision, () => {
    setReview(null); setConfirmed(false); setError(''); setBusy(false); setRecordId(''); setSiteId('');
  });
  const run = async action => {
    if (!currentTenant()) { resetReview(); setError('Workspace changed. Refresh before continuing.'); return; }
    const request = begin(); const envelope = review?.delivery;
    const body = action === 'preview'
      ? { entity_type: kind, entity_id: Number(recordId), worksite_id: kind === 'placement' && siteId ? siteId : null }
      : { delivery_id: Number(envelope.id), review_hash: envelope.review_hash,
        ...(action === 'send' ? { confirm_metadata: confirmed } : {}) };
    if (action === 'preview') setReview(null);
    setBusy(true); setError(''); setConfirmed(false);
    try {
      const result = await api.post(`${endpoint}metadata_${action}`, body, request.options);
      if (!request.current()) return;
      if (!currentTenant()) { resetReview(); setError('Workspace changed. Refresh before continuing.'); return; }
      if (action === 'preview') setReview(result);
      else setReview(prior => ({ ...prior, delivery: action === 'receipt' ? result.delivery : result,
        receipt_only: action === 'receipt' || prior.receipt_only, receiptState: action === 'receipt' ? result.receipt_state : null }));
    } catch (e) {
      if (!request.current()) return;
      if (!currentTenant()) { resetReview(); setError('Workspace changed. Refresh before continuing.'); return; }
      // A lost acknowledgement must retain the original delivery, without another provider write.
      if (action === 'send') setReview(prior => ({ ...prior, delivery: { ...envelope, state: 'uncertain' } }));
      setError(e.message || 'Could not check the shared record');
    } finally { if (request.current()) setBusy(false); }
  };
  const options = kind === 'person' ? people : placements;
  const state = review?.delivery?.state;
  const uncertain = ['sending', 'uncertain'].includes(state);
  const canSend = review?.delivery_enabled && ['preview', 'approved'].includes(state);
  return <section style={{ marginTop: 28, paddingTop: 22, borderTop: '1px solid var(--cf-border)' }}>
    <h2 style={{ fontSize: 18, margin: '0 0 8px' }}>Share Core identities and assignments</h2>
    <p style={{ fontSize: 13 }}>Publish a linked person’s Core number, then their placement details. Worker access, onboarding, rates and time routing are managed separately.</p>
    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 12, alignItems: 'end' }}>
      <label>Record type<br /><select style={style} value={kind} disabled={busy} onChange={e => { setKind(e.target.value); setRecordId(''); setSiteId(''); resetReview(); }}><option value="person">Person identity</option><option value="placement">Assignment details</option></select></label>
      <label>Core record<br /><select style={style} value={recordId} disabled={busy} onChange={e => { setRecordId(e.target.value); resetReview(); }}>
        <option value="">Choose a record</option>
        {options.map(row => <option key={row.id} value={row.id}>{kind === 'person' ? `${row.person_number || 'Number unavailable'} · ${row.name || ''}` : `${row.placement_number || 'Number unavailable'} · ${row.person_name || ''} · ${row.title || ''}`}</option>)}
      </select></label>
      {kind === 'placement' && <label>Quanta worksite<br /><select style={style} value={siteId} disabled={busy} onChange={e => { setSiteId(e.target.value); resetReview(); }}>
        <option value="">No worksite reference</option>{worksites.filter(site => site.active).map(site => <option key={site.id} value={site.id}>{site.name}</option>)}
      </select></label>}
      <button className="btn btn-secondary btn-sm" disabled={busy || !recordId || !tenantId} onClick={() => run('preview')}>{busy ? 'Working…' : 'Preview shared record'}</button>
    </div>
    {error && <p role="alert" style={{ color: '#b91c1c' }}>{error}</p>}
    {review && <div style={{ marginTop: 16 }}>
      {!review.receipt_only && <><p><strong>{review.reference.number}</strong> → Quanta <strong>{review.workspace.name}</strong></p>
      <table style={{ width: '100%', maxWidth: 820, borderCollapse: 'collapse', fontSize: 13 }}>
        <thead><tr><th style={{ textAlign: 'left' }}>Field</th><th style={{ textAlign: 'left' }}>Currently in Quanta</th><th style={{ textAlign: 'left' }}>Proposed</th></tr></thead>
        <tbody>{Object.entries(review.proposed).filter(([key]) => labels[key]).map(([key, value]) => <tr key={key}>
          <th style={{ textAlign: 'left', padding: '6px 0' }}>{labels[key]}</th><td>{valueLabel(review.current?.[key], key)}</td><td>{valueLabel(value, key)}</td>
        </tr>)}</tbody>
      </table></>}
      {review.status === 'unchanged' && <p role="status">The shared record already agrees.</p>}
      {state && <p role="status">Delivery: {state === 'delivered' ? 'Confirmed by Quanta' : state}</p>}
      {review.delivery_enabled === false && <p>Metadata delivery has not been enabled for this environment.</p>}
      {review.receipt_only && state === 'delivered' && <p role="status">Original delivery confirmed. Preview the current record again to check for later changes.</p>}
      {uncertain && <><p>The result is unconfirmed. Check the original receipt before another change to this record.</p>
        <button className="btn btn-secondary btn-sm" disabled={busy} onClick={() => run('receipt')}>Check original receipt</button></>}
      {review.receiptState === 'not_found' && <p>No receipt was found. This attempt remains unconfirmed.</p>}
      {review.receiptState === 'unconfirmed' && <p>Quanta has not completed this attempt. Check its receipt again later.</p>}
      {state === 'failed' && <p>Quanta rejected the original delivery. Preview the current record before making another change.</p>}
      {canSend && <>
        <label style={{ display: 'block', margin: '12px 0' }}><input type="checkbox" checked={confirmed} disabled={busy} onChange={e => setConfirmed(e.target.checked)} /> I reviewed these identities and assignment details.</label>
        <button className="btn btn-primary btn-sm" disabled={busy || !confirmed} onClick={() => run('send')}>Send reviewed metadata</button>
      </>}
    </div>}
  </section>;
}

/** Independent of provider catalogs and source records that may have since been removed. */
export function QuantaMetadataRecovery({ tenantId, connectionRevision }) {
  const [page, setPage] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const { currentTenant, begin } = useWorkspaceRequest(tenantId, connectionRevision, () => {
    setPage(null); setBusy(false); setError('');
  });
  const run = async (delivery = null, before = null) => {
    if (!currentTenant()) { setPage(null); setError('Workspace changed. Refresh before continuing.'); return; }
    const request = begin(); setBusy(true); setError('');
    try {
      const action = delivery ? 'receipt' : 'pending';
      const body = delivery ? { delivery_id: Number(delivery.id), review_hash: delivery.review_hash } : { before };
      const result = await api.post(`${endpoint}metadata_${action}`, body, request.options);
      if (!request.current()) return;
      if (!currentTenant()) { setPage(null); setError('Workspace changed. Refresh before continuing.'); return; }
      if (delivery) setPage(prior => ({ ...prior, rows: prior.rows.map(row => String(row.delivery.id) === String(delivery.id)
        ? { ...row, delivery: result.delivery, receiptState: result.receipt_state } : row) }));
      else setPage(prior => ({ ...result, rows: before
        ? [...(prior?.rows || []), ...result.rows.filter(row => !prior?.rows.some(old => String(old.delivery.id) === String(row.delivery.id)))]
        : result.rows }));
    } catch (err) {
      if (!request.current()) return;
      if (!currentTenant()) { setPage(null); setError('Workspace changed. Refresh before continuing.'); return; }
      setError(err.message || 'Could not check identity and assignment deliveries');
    } finally { if (request.current()) setBusy(false); }
  };
  return <section style={{ marginTop: 28, paddingTop: 22, borderTop: '1px solid var(--cf-border)' }}>
    <h2 style={{ fontSize: 18 }}>Identity and assignment delivery recovery</h2>
    <p>Find unconfirmed deliveries, including records that have since changed or been removed.</p>
    <button className="btn btn-secondary" disabled={busy || !tenantId} onClick={() => run()}>Find unconfirmed identity and assignment deliveries</button>
    {error && <p role="alert">{error}</p>}
    {page?.rows.length === 0 && <p role="status">No unconfirmed identity or assignment deliveries.</p>}
    {page?.rows.map(row => <div key={row.delivery.id} style={{ marginTop: 12 }}>
      <strong>{row.delivery.entity_type === 'person' ? 'Person identity' : 'Assignment'}</strong> · Delivery #{row.delivery.id} · {row.delivery.attempted_at || row.delivery.created_at}
      {['sending', 'uncertain'].includes(row.delivery.state) && <p><button className="btn btn-secondary btn-sm" disabled={busy}
        onClick={() => run(row.delivery)}>Check original receipt #{row.delivery.id}</button></p>}
      {row.delivery.state === 'delivered' && <p role="status">Original delivery confirmed. Preview the current record separately for later changes.</p>}
      {row.delivery.state === 'failed' && <p role="status">Quanta rejected the original delivery. Preview the current record before making another change.</p>}
      {row.receiptState === 'not_found' && <p>No receipt was found. This attempt remains unconfirmed.</p>}
      {row.receiptState === 'unconfirmed' && <p>Quanta has not completed this attempt. Check its receipt again later.</p>}
    </div>)}
    {page?.next_before && <button className="btn btn-secondary" disabled={busy} onClick={() => run(null, page.next_before)}>Earlier unconfirmed identity and assignment deliveries</button>}
  </section>;
}
