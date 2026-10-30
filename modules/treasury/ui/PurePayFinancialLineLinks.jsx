import React, { useEffect, useRef, useState } from 'react';
import { api, getPinnedTenantId } from '../../../dashboard/src/lib/api';

function Receipt({ link }) {
  const saved = link.saved;
  return <p>Association #{link.id}: {saved.target.placement.number} · {saved.target.person.number} · {saved.target.person_name}.
    {' '}Reviewed {link.reviewed_at} by Core user #{link.reviewed_by}, using capture #{saved.capture_id} and source line {saved.source.line_id}.
    {' '}This is historical review evidence.</p>;
}

export default function PurePayFinancialLineLinks({ tenantId, captureId, lineId }) {
  const [number, setNumber] = useState('');
  const [review, setReview] = useState(null);
  const [history, setHistory] = useState(null);
  const [receipt, setReceipt] = useState(null);
  const [confirmed, setConfirmed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const generation = useRef(0), active = useRef(null), pending = useRef(false);
  useEffect(() => {
    generation.current++; active.current?.abort(); pending.current = false;
    setNumber(''); setReview(null); setHistory(null); setReceipt(null); setConfirmed(false); setBusy(false); setError('');
    return () => { generation.current++; active.current?.abort(); pending.current = false; };
  }, [tenantId, captureId, lineId]);
  const current = () => !!tenantId && (!getPinnedTenantId() || String(getPinnedTenantId()) === String(tenantId));
  const clear = () => { setReview(null); setHistory(null); setReceipt(null); setConfirmed(false); };
  const run = async (action, before = null) => {
    if (pending.current) return;
    if (!current()) { clear(); setError('Workspace changed. Refresh the company connection.'); return; }
    const sequence = ++generation.current, controller = new AbortController(); active.current?.abort(); active.current = controller;
    const body = { capture_id: captureId, line_id: lineId };
    if (action === 'history') body.before = before;
    else body.placement_number = number.trim().toUpperCase();
    if (action === 'save') { body.confirmed = confirmed; body.review_hash = review?.review_hash; }
    pending.current = true; setBusy(true); setError(''); setConfirmed(false); setReceipt(null); setHistory(null);
    if (action !== 'save') setReview(null);
    try {
      const result = await api.post(`/api/purepay_financial_line_links.php?action=${action}`, body,
        { timeoutMs: 60000, headers: { 'X-CoreFlux-Tenant-Id': String(tenantId) }, fetch: { signal: controller.signal } });
      if (sequence !== generation.current) return;
      if (!current() || String(result.tenant_id) !== String(tenantId)) throw new Error('Workspace changed. Refresh the company connection.');
      if (action === 'preview') setReview(result);
      else if (action === 'history') setHistory(result);
      else { setReview(null); setReceipt(result); }
    } catch (e) { if (sequence === generation.current) { clear(); setError(e.message || 'The source-line association could not be reviewed.'); } }
    finally { if (sequence === generation.current) { pending.current = false; setBusy(false); } }
  };
  return <section aria-label="Source line placement association" style={{ borderTop: '1px solid var(--cf-border)', paddingTop: 12, marginTop: 12 }}>
    <h5>Associate source line {lineId} with a Core placement</h5>
    <p>This applies to this historical document line only. It does not match time entries, verify currency, post accounting or create a rule for future invoices.</p>
    <label>Core placement number<input className="input" value={number} disabled={busy} placeholder="PL-…" onChange={e => { clear(); setNumber(e.target.value); }} /></label>
    <p><button className="btn" disabled={busy || !/^PL-[1-9][0-9]*$/.test(number.trim().toUpperCase())} onClick={() => run('preview')}>Review placement association</button>{' '}
      <button className="btn" disabled={busy} onClick={() => run('history')}>Association history</button></p>
    {error && <p role="alert" className="error">{error}</p>}
    {current() && review && <><p><strong>{review.target.placement.number}</strong> · {review.target.person.number} · {review.target.person_name} · {review.target.title}</p>
      <p>Placement dates: {review.target.start_date || 'Unknown'} → {review.target.end_date || 'Open-ended'}. Source service dates: {review.source.line.service_start || 'Unknown'} → {review.source.line.service_end || 'Unknown'}.</p>
      <p>Source item: {review.source.line.item_name || review.source.line.item_id || 'Unknown'}. Source currency: {review.source.document_currency || 'Unknown'}.</p>
      {review.existing_link && <Receipt link={review.existing_link} />}
      <label><input type="checkbox" checked={confirmed} disabled={busy} onChange={e => setConfirmed(e.target.checked)} /> I confirm that this source line belongs to {review.target.placement.number}.</label>
      <p><button className="btn btn--primary" disabled={busy || !confirmed} onClick={() => run('save')}>Save placement association</button></p></>}
    {current() && receipt && <div role="status"><p>{receipt.replayed ? 'The reviewed association already exists. No duplicate created.' : 'Placement association saved.'}</p><Receipt link={receipt.link} /></div>}
    {current() && history && <>{history.items.length === 0 ? <p>No reviewed association for this source line.</p> : history.items.map(link => <Receipt key={link.id} link={link} />)}
      {history.next_before && <button className="btn" disabled={busy} onClick={() => run('history', history.next_before)}>Older associations</button>}</>}
  </section>;
}
