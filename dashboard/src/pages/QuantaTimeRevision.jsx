import React, { useEffect, useRef, useState } from 'react';
import { api, getPinnedTenantId } from '../lib/api';

/** Keep the original review and reason after response loss so retry returns its receipt. */
export default function QuantaTimeRevision({ entryId, tenantId, connectionRevision, onApplied }) {
  const [review, setReview] = useState(null);
  const [reason, setReason] = useState('');
  const [confirmed, setConfirmed] = useState(false);
  const [history, setHistory] = useState(null);
  const [receipt, setReceipt] = useState(null);
  const [attempt, setAttempt] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const generation = useRef(0);
  const request = useRef(null);
  useEffect(() => {
    const lifecycle = generation;
    const activeRequest = request;
    generation.current++; setReview(null); setReason(''); setConfirmed(false); setHistory(null);
    setReceipt(null); setAttempt(null); setBusy(false); setError('');
    return () => { lifecycle.current++; activeRequest.current?.abort(); };
  }, [entryId, tenantId, connectionRevision]);
  const currentTenant = () => !!tenantId && (!getPinnedTenantId() || String(getPinnedTenantId()) === String(tenantId));
  const run = async (action, before = null) => {
    if (!currentTenant()) { setReview(null); setError('Company changed. Refresh before continuing.'); return; }
    const sequence = ++generation.current;
    request.current?.abort(); const controller = new AbortController(); request.current = controller;
    const body = action === 'apply' ? attempt || { source_entry_id: entryId, review_hash: review.review_hash,
      reason: reason.trim(), confirm_revision: true } : { source_entry_id: entryId, ...(before ? { before } : {}) };
    if (action === 'apply') setAttempt(body);
    setBusy(true); setError('');
    try {
      const result = await api.post('/api/quanta.php?action=time_revision_' + action, body, { timeoutMs: 60000,
        headers: { 'X-CoreFlux-Tenant-Id': String(tenantId) }, fetch: { signal: controller.signal } });
      if (sequence !== generation.current) return;
      if (!currentTenant()) { setReview(null); setError('Company changed. Refresh before continuing.'); return; }
      if (action === 'preview') { setReview(result); setReceipt(null); setConfirmed(false); }
      else if (action === 'history') setHistory(prior => ({ ...result, items: before ? [...(prior?.items || []), ...result.items] : result.items }));
      else { setReceipt(result); setAttempt(null); setReview(null); setConfirmed(false); onApplied?.(result); }
    } catch (err) {
      if (sequence !== generation.current) return;
      setError(err.message || 'Could not review the time correction');
      if (action === 'preview') setReview(null);
      // Server rejections are atomic. Transport failure retains the exact submitted request.
      if (action === 'apply' && [400, 401, 403, 409, 422].includes(err.status)) { setAttempt(null); setReview(null); setConfirmed(false); }
    } finally { if (sequence === generation.current) setBusy(false); }
  };
  return <details style={{ marginTop: 8, fontSize: 12 }}>
    <summary>Correct source time and view history</summary>
    <p>Correct and approve the source in Quanta first. Core keeps the original entries and approvals, and requires independent approval of replacement hours.</p>
    <button className="btn btn-secondary btn-sm" disabled={busy || !!attempt} onClick={() => run('preview')}>Review source correction</button>{' '}
    <button className="btn btn-secondary btn-sm" disabled={busy} onClick={() => run('history')}>Correction history</button>
    {error && <p role="alert">{error}</p>}
    {review && <div>
      <p><strong>{review.workspace?.name}</strong> · Quanta entry {review.source_entry_id}</p>
      <p>Current Core entries:</p>
      <ul>{review.before.map(row => <li key={row.id}>#{row.id} · {row.work_date} · {row.category}: {row.hours} hours · {row.status}
        {row.approved_at && ` · Approved ${row.approved_at}`}</li>)}</ul>
      <p>Replacement: {review.after.work_date} · Placement #{review.after.placement_id}</p>
      <ul>{Object.entries(review.after.components).map(([component, hours]) => <li key={component}>{component}: {hours} hours</li>)}</ul>
      <p>{review.after.description}</p>
      <p>Quanta approval: {review.after.source_approval?.approved_at} · {review.after.source_approval?.approved_by_email || 'Approver not supplied'}</p>
      <label>Correction reason <textarea value={reason} maxLength={500} disabled={busy || !!attempt} onChange={e => setReason(e.target.value)} /></label>
      <label style={{ display: 'block' }}><input type="checkbox" checked={confirmed} disabled={busy || !!attempt} onChange={e => setConfirmed(e.target.checked)} /> I reviewed the original and replacement time.</label>
      {!attempt && <button className="btn btn-primary btn-sm" disabled={busy || !confirmed || !reason.trim()} onClick={() => run('apply')}>Apply correction for Core review</button>}
    </div>}
    {attempt && <p>The response is unconfirmed. Retry the same request to recover its receipt. <button className="btn btn-secondary btn-sm" disabled={busy} onClick={() => run('apply')}>Check original correction</button></p>}
    {receipt && <p role="status">Correction #{receipt.id} saved. Replacement hours await independent Core approval.</p>}
    {history && <div>{history.items.length === 0 && <p>No recorded source corrections.</p>}
      {history.items.map(item => <div key={item.id}><strong>Correction #{item.id}</strong> · {item.created_at} · Core user #{item.actor_user_id}
        <p>{item.reason}</p><p>Original entries: {Object.values(item.result.superseded_entry_ids).join(', ')}. Replacement entries: {Object.values(item.result.new_entry_ids).join(', ')}.</p>
        <p>{item.review.before.map(row => `${row.work_date}: ${row.hours} hours (${row.category})`).join('; ')} → {item.review.after.work_date}: {Object.entries(item.review.after.components).map(([key, hours]) => `${hours} ${key}`).join(', ')}</p>
      </div>)}
      {history.next_before && <button disabled={busy} onClick={() => run('history', history.next_before)}>Earlier corrections</button>}
    </div>}
  </details>;
}

export function QuantaTimeRevisionHistory({ tenantId, connectionRevision }) {
  const [page, setPage] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const generation = useRef(0);
  useEffect(() => {
    const lifecycle = generation; generation.current++; setPage(null); setBusy(false); setError('');
    return () => { lifecycle.current++; };
  }, [tenantId, connectionRevision]);
  const run = async (before = null) => {
    const current = () => !!tenantId && (!getPinnedTenantId() || String(getPinnedTenantId()) === String(tenantId));
    if (!current()) { setPage(null); setError('Company changed. Refresh before continuing.'); return; }
    const sequence = ++generation.current; setBusy(true); setError('');
    try {
      const result = await api.post('/api/quanta.php?action=time_revision_recent', { before }, { headers: { 'X-CoreFlux-Tenant-Id': String(tenantId) } });
      if (sequence !== generation.current) return;
      if (!current()) { setPage(null); setError('Company changed. Refresh before continuing.'); return; }
      setPage(prior => ({ ...result, items: before ? [...(prior?.items || []), ...result.items] : result.items }));
    } catch (err) { if (sequence === generation.current) setError(err.message || 'Could not retrieve correction history'); }
    finally { if (sequence === generation.current) setBusy(false); }
  };
  return <section style={{ marginTop: 28, paddingTop: 22, borderTop: '1px solid var(--cf-border)' }}>
    <h2 style={{ fontSize: 18 }}>Source correction history</h2>
    <p>Recover saved correction receipts even when the Quanta source has changed or is no longer available.</p>
    <button className="btn btn-secondary" disabled={busy || !tenantId} onClick={() => run()}>Find saved corrections</button>
    {error && <p role="alert">{error}</p>}
    {page?.items.length === 0 && <p>No saved corrections.</p>}
    {page?.items.map(item => <div key={item.id} style={{ marginTop: 12 }}>
      <strong>Correction #{item.id} · {item.review.person_number} · {item.review.placement_number}</strong>
      <p>{item.created_at} · {item.review.workspace?.name} · Source {item.review.source_entry_id} · Core user #{item.actor_user_id}</p>
      <p>{item.reason}</p>
      <p>Original entries: {Object.values(item.result.superseded_entry_ids).join(', ')}. Replacement entries: {Object.values(item.result.new_entry_ids).join(', ')}.</p>
      <p>Replacement hours required independent Core approval. Check the timesheet for its current status.</p>
    </div>)}
    {page?.next_before && <button className="btn btn-secondary" disabled={busy} onClick={() => run(page.next_before)}>Earlier saved corrections</button>}
  </section>;
}
