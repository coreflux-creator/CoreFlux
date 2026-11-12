import React, { useEffect, useRef, useState } from 'react';
import { api, getPinnedTenantId } from '../../../dashboard/src/lib/api';

const empty = { sheet: '', month_cell: '', identity_cell: '', day_header_range: '', hours_range: '', hours_total_cell: '', bill_rate_cell: '', bill_total_cell: '', currency_cell: '' };
const fields = { sheet: 'Worksheet name', month_cell: 'Month date cell', identity_cell: 'Person name cell', day_header_range: 'Day header range', hours_range: 'Daily hours range', hours_total_cell: 'Total hours cell', bill_rate_cell: 'Bill rate cell', bill_total_cell: 'Bill amount cell', currency_cell: 'Currency code cell (optional)' };
const show = v => v == null ? 'Unknown' : String(v);

export function WorkbookEvidenceDetails({ workbook }) {
  const w = workbook;
  return <section aria-label="Workbook source detail">
    <p><strong>{w.file_name}</strong> · {w.selection.sheet} · row identity {w.row_identity}</p>
    <p>Worksheet period: {w.period.start} to {w.period.end}. These dates come from the workbook, not the provider.</p>
    <p>Saved total hours: {w.stated_hours}. Sum of numeric daily inputs: {w.numeric_hours_sum}. Difference: {w.hours_difference}.</p>
    <p>Saved bill amount: {w.stated_bill_amount}. Hours × bill rate: {w.calculated_bill_amount}. Difference: {w.bill_amount_difference}. Currency: {show(w.currency)}.</p>
    <p>{w.blank_days} blank days remain unknown. Formulas use their saved results; this review does not recalculate Excel, prove client approval, match Core time entries or allocate payments.</p>
    <details><summary>Source cells and original formulas</summary>
      <p>File fingerprint: <code>{w.file_sha256}</code></p>
      <div style={{ overflowX: 'auto' }}><table className="data-table"><thead><tr><th>Cell</th><th>Stored value</th><th>Formula</th></tr></thead>
        <tbody>{Object.values(w.cells).map(c => <tr key={c.cell}><td>{c.cell}</td><td>{show(c.value)}</td><td>{c.formula || '—'}</td></tr>)}</tbody></table></div>
    </details>
  </section>;
}

function Receipt({ item }) {
  return <div><p>Review #{item.id} · {item.saved.action === 'withdraw' ? 'Withdrawn' : 'Workbook recorded'} · {item.reviewed_at} · Core user #{item.saved.reviewed_by}.</p>
    {item.saved.reason && <p>{item.saved.reason}</p>}{item.saved.workbook && <WorkbookEvidenceDetails workbook={item.saved.workbook} />}</div>;
}

async function encodeWorkbook(file) {
  if (!file || file.size > 2097152 || !/\.xlsx$/i.test(file.name)) throw new Error('Choose an .xlsx workbook no larger than 2 MB.');
  const bytes = new Uint8Array(await file.arrayBuffer());
  if (bytes.length !== file.size || bytes.length > 2097152) throw new Error('The workbook file changed. Select it again.');
  let binary = ''; for (let i = 0; i < bytes.length; i += 32768) binary += String.fromCharCode(...bytes.subarray(i, i + 32768));
  return btoa(binary);
}

export default function StaffingFinancialEvidence({ tenantId, associationId, canManage = false, onSaved }) {
  const [file, setFile] = useState(null), [selection, setSelection] = useState(empty), [decision, setDecision] = useState('record'), [reason, setReason] = useState('');
  const [review, setReview] = useState(null), [confirmed, setConfirmed] = useState(false), [receipt, setReceipt] = useState(null), [history, setHistory] = useState(null);
  const [busy, setBusy] = useState(false), [error, setError] = useState(''), [uncertain, setUncertain] = useState(false);
  const generation = useRef(0), active = useRef(null), pending = useRef(false), frozen = useRef(null), requestId = useRef(null), fileInput = useRef(null);
  useEffect(() => {
    generation.current++; active.current?.abort(); pending.current = false; frozen.current = null; requestId.current = null;
    setFile(null); setSelection(empty); setDecision('record'); setReason(''); setReview(null); setConfirmed(false); setReceipt(null); setHistory(null); setBusy(false); setError(''); setUncertain(false);
    if (fileInput.current) fileInput.current.value = '';
    return () => { generation.current++; active.current?.abort(); pending.current = false; };
  }, [tenantId, associationId, canManage]);
  const current = () => !!tenantId && (!getPinnedTenantId() || String(getPinnedTenantId()) === String(tenantId));
  const clear = () => { setReview(null); setConfirmed(false); setReceipt(null); setHistory(null); setError(''); requestId.current = null; frozen.current = null; };
  const update = fn => e => { clear(); fn(e.target.value); };
  const run = async (action, before = null) => {
    if (pending.current) return;
    if (action !== 'history' && !canManage) return;
    if (!current()) { clear(); setError('Workspace changed. Refresh the company connection.'); return; }
    const sequence = ++generation.current, controller = new AbortController(); active.current?.abort(); active.current = controller;
    pending.current = true; setBusy(true); setError('');
    let body;
    try {
      if (action === 'history') body = { association_id: associationId, before };
      else if (action === 'save' && uncertain) body = frozen.current;
      else {
        const fileBase64 = decision === 'record' ? await encodeWorkbook(file) : null;
        if (sequence !== generation.current) return;
        body = { association_id: associationId, decision, reason, file_name: decision === 'record' ? file.name : null, file_base64: fileBase64,
          selection: decision === 'record' ? { ...selection, currency_cell: selection.currency_cell || null } : null };
        if (action === 'save') { body = { ...body, confirmed, review_hash: review?.review_hash, request_id: requestId.current }; frozen.current = body; }
      }
      if (!body || !current()) throw new Error('Workspace changed. Refresh the company connection.');
      if (action === 'preview') { setReview(null); setReceipt(null); setConfirmed(false); }
      const result = await api.post(`/api/staffing_financial_evidence.php?action=${action}`, body,
        { timeoutMs: 60000, headers: { 'X-CoreFlux-Tenant-Id': String(tenantId) }, fetch: { signal: controller.signal } });
      if (sequence !== generation.current) return;
      if (!current() || String(result.tenant_id) !== String(tenantId)) throw new Error('Workspace changed. Refresh the company connection.');
      if (action === 'preview') { setReview(result); requestId.current = crypto.randomUUID(); }
      else if (action === 'history') setHistory(result);
      else { setReceipt(result); setReview(null); setConfirmed(false); setUncertain(false); frozen.current = null; requestId.current = null; onSaved?.(); }
    } catch (e) {
      if (sequence === generation.current) {
        setError(e.message || 'Workbook review failed.');
        if (action === 'save' && frozen.current && (!e.status || e.status === 408 || e.status >= 500)) setUncertain(true);
        else if (action !== 'history') { setReview(null); setConfirmed(false); setUncertain(false); frozen.current = null; }
      }
    } finally { if (sequence === generation.current) { pending.current = false; setBusy(false); } }
  };
  const download = async item => {
    if (pending.current || !current()) return;
    const sequence = ++generation.current, controller = new AbortController(); active.current?.abort(); active.current = controller;
    pending.current = true; setBusy(true); setError('');
    const timer = setTimeout(() => controller.abort(), 60000);
    try {
      const response = await fetch('/api/staffing_financial_evidence.php?action=download', { method: 'POST', credentials: 'include', redirect: 'error', signal: controller.signal,
        headers: { 'Content-Type': 'application/json', 'X-CoreFlux-Tenant-Id': String(tenantId) }, body: JSON.stringify({ association_id: associationId, evidence_id: item.id }) });
      if (!response.ok || response.headers.get('X-CoreFlux-Tenant-Id') !== String(tenantId) || response.headers.get('Content-Type') !== 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') throw new Error('The retained workbook could not be downloaded. Refresh the company session and history.');
      const blob = await response.blob();
      if (blob.size !== item.saved.workbook.file_bytes || blob.size > 2097152) throw new Error('The downloaded workbook size differs from the saved source.');
      const hash = Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', await blob.arrayBuffer())), b => b.toString(16).padStart(2, '0')).join('');
      if (hash !== item.saved.workbook.file_sha256) throw new Error('The downloaded workbook differs from the saved source.');
      if (sequence !== generation.current || !current()) return;
      const url = URL.createObjectURL(blob), a = document.createElement('a'); a.href = url; a.download = item.saved.workbook.file_name; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (e) { if (sequence === generation.current) setError(e.message || 'The workbook download failed.'); }
    finally { clearTimeout(timer); if (sequence === generation.current) { pending.current = false; setBusy(false); } }
  };
  const locked = busy || uncertain;
  const ready = decision === 'withdraw' ? !!reason.trim() : file && Object.entries(selection).every(([key, value]) => key === 'currency_cell' || value.trim());
  return <details><summary>{canManage ? 'Review supporting workbook' : 'Supporting workbook'}</summary>
    <p>{canManage ? 'Retain the original spreadsheet and the selected row as supplemental evidence for this invoice line. Saving this review does not change provider dates, approve time or post accounting.' : 'Read the saved source reviews and download their retained original workbook. Source evidence does not establish client approval or payment allocation.'}</p>
    {canManage && <>
    <label>Review action<select className="input" value={decision} disabled={locked} onChange={update(setDecision)}><option value="record">Record workbook evidence</option><option value="withdraw">Withdraw current workbook evidence</option></select></label>
    {decision === 'record' && <><label>Original workbook<input ref={fileInput} type="file" accept=".xlsx" disabled={locked} onChange={e => { clear(); setFile(e.target.files?.[0] || null); }} /></label>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(220px,1fr))', gap: 12 }}>{Object.entries(fields).map(([key, title]) => <label key={key}>{title}<input className="input" value={selection[key]} disabled={locked} onChange={e => { clear(); setSelection(old => ({ ...old, [key]: e.target.value })); }} /></label>)}</div></>}
    <label>Explanation<textarea className="input" value={reason} disabled={locked} maxLength={2000} onChange={update(setReason)} /></label>
    <p><button className="btn" disabled={locked || !ready} onClick={() => run('preview')}>Review workbook evidence</button></p>
    </>}
    <p><button className="btn" disabled={busy} onClick={() => run('history')}>Workbook review history</button></p>
    {error && <p className="error" role="alert">{error}</p>}
    {canManage && uncertain && <p role="status">The save result is unconfirmed. Retrying sends the same request and will return its original receipt if it already succeeded. <button className="btn" disabled={busy} onClick={() => run('save')}>Retry this saved review</button></p>}
    {canManage && current() && review && <><p>{review.association.target.placement.number} · {review.association.target.person.number} · {review.association.target.person_name}</p>
      <p>Core placement dates: {show(review.association.target.start_date)} to {review.association.target.end_date || 'Open-ended'}.</p>
      {review.workbook ? <WorkbookEvidenceDetails workbook={review.workbook} /> : <p>The current workbook support will be withdrawn. Its original file and review history will remain.</p>}
      <label><input type="checkbox" checked={confirmed} disabled={locked} onChange={e => setConfirmed(e.target.checked)} /> I reviewed the source row and its relationship to this invoice line.</label>
      <p><button className="btn btn--primary" disabled={locked || !confirmed} onClick={() => run('save')}>Save workbook review</button></p></>}
    {current() && receipt && <div role="status"><p>{receipt.replayed ? 'The original receipt was returned; no duplicate review was created.' : 'Workbook review saved.'} {!receipt.is_current && 'A later review is current; this receipt does not reactivate the earlier evidence.'}</p><Receipt item={receipt.receipt} /></div>}
    {current() && history && <>{!history.items.length && <p>No workbook reviews have been saved for this association.</p>}{history.items.map(item => <details key={item.id}><summary>Review #{item.id} · {item.saved.action} · {item.reviewed_at}{item.id === history.current_id ? ' · Current' : ''}</summary><Receipt item={item} />
      {item.saved.workbook && <button className="btn" disabled={busy} onClick={() => download(item)}>Download original workbook</button>}</details>)}
      {history.next_before && <button className="btn" disabled={busy} onClick={() => run('history', history.next_before)}>Older workbook reviews</button>}</>}
  </details>;
}
