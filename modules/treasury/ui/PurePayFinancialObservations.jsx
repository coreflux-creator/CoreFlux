import React, { useEffect, useRef, useState } from 'react';
import { api, getPinnedTenantId } from '../../../dashboard/src/lib/api';
import PurePayFinancialLineLinks from './PurePayFinancialLineLinks';

const endpoint = '/api/purepay_financial_observations.php?action=';
const title = value => value.replaceAll('_', ' ').replace(/\bqbo\b/g, 'QuickBooks').replace(/\bid\b/g, 'ID');
const display = value => value === null ? 'Unknown' : typeof value === 'boolean' ? (value ? 'Yes' : 'No') : String(value);
const reviewLabels = { no_core_record: 'Choose a Core record to compare', currency_missing: 'Currency missing', currency_differs: 'Different currencies', amount_missing: 'Amount missing', agrees: 'Amounts agree', differs: 'Amount differs' };

function Facts({ value }) {
  return <dl>{Object.entries(value).map(([field, item]) => <React.Fragment key={field}><dt>{title(field)}</dt><dd style={{ overflowWrap: 'anywhere' }}>{Array.isArray(item)
    ? item.length ? item.map((v, i) => typeof v === 'object' && v !== null ? <Facts key={i} value={v} /> : <div key={i}>{display(v)}</div>) : 'None recorded'
    : display(item)}</dd></React.Fragment>)}</dl>;
}

function Rows({ rows, label }) {
  const [page, setPage] = useState(0);
  const start = page * 25;
  return <section><h5>{label} ({rows.length})</h5>{rows.length === 0 && <p>None recorded in these source records.</p>}
    {rows.slice(start, start + 25).map((row, i) => <details key={start + i}><summary>{label} {start + i + 1} · {row.id || row.payment_id || row.line_id || 'No source ID'} · {row.status || row.pay_status || ''}</summary><Facts value={row} /></details>)}
    {rows.length > 25 && <p>Showing {start + 1}–{Math.min(start + 25, rows.length)} of {rows.length}. <button className="btn" disabled={page === 0} onClick={() => setPage(p => p - 1)}>Previous {label.toLowerCase()}</button>{' '}<button className="btn" disabled={start + 25 >= rows.length} onClick={() => setPage(p => p + 1)}>Next {label.toLowerCase()}</button></p>}
  </section>;
}

function FinancialLines({ rows, onChooseLine }) {
  const [page, setPage] = useState(0);
  const [search, setSearch] = useState('');
  const selected = rows.filter(row => [row.line_id, row.item_id, row.item_name, row.description, row.assignment_id]
    .some(value => typeof value === 'string' && value.toLowerCase().includes(search.trim().toLowerCase())) || !search.trim());
  const start = page * 25;
  return <section aria-label="Source document lines"><h5>Lines ({rows.length})</h5>
    <p>Item names and descriptions are source labels. They do not establish a Core person, placement or service date. Missing dates remain unknown.</p>
    <label>Find a source line<input className="input" value={search} onChange={e => { setSearch(e.target.value); setPage(0); }} placeholder="Item, description, assignment or line ID" /></label>
    <p>{selected.length} of {rows.length} source lines match.</p>
    {selected.slice(start, start + 25).map((row, index) => <details key={start + index}>
      <summary>{row.item_name || row.item_id || 'Unlabelled item'} · Line {row.line_id || 'ID unknown'} · Amount {display(row.amount)}</summary>
      {row.description !== undefined && <p style={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' }}>Source description: {display(row.description)}</p>}
      <p>Service dates: {display(row.service_start)} → {display(row.service_end)}. Hours: {display(row.hours)}. Bill rate: {display(row.bill_rate)}. Pay rate: {display(row.pay_rate)}.</p>
      <Facts value={row} />
      {onChooseLine && row.line_id && <button className="btn" onClick={() => onChooseLine(row.line_id)}>Associate this line with a placement</button>}
    </details>)}
    {selected.length > 25 && <p>Showing {start + 1}–{Math.min(start + 25, selected.length)} of {selected.length}. <button className="btn" disabled={page === 0} onClick={() => setPage(p => p - 1)}>Previous lines</button>{' '}<button className="btn" disabled={start + 25 >= selected.length} onClick={() => setPage(p => p + 1)}>Next lines</button></p>}
  </section>;
}

export function PurePayFinancialObservationView({ capture, savedAt, captureId }) {
  const [selectedLine, setSelectedLine] = useState(null);
  const source = capture.observation;
  const { line_items: lines, invoice_line_splits: splits, recorded_receipts: receipts, quickbooks_receipts: qboReceipts, ...document } = source.document;
  return <section aria-label="Financial source evidence" key={source.observation_hash}>
    {savedAt && <p role="status">Capture saved {savedAt}. This shows the source and Core figures reviewed then.</p>}
    <p><strong>{source.kind === 'bill' ? 'Supplier bill' : 'Customer invoice'} {source.document_id}</strong> · {document.currency || 'Currency unknown'} · Recorded status: {document.status || 'Unknown'}</p>
    <p>Stored payment labels and balances do not establish bank delivery. These records cover {source.enumerated_sources.join(' and ')} and embedded receipts; external payment sources have not been refreshed.</p>
    <h5>Comparison with Core</h5>
    <p>{capture.comparison.core_record ? `Selected Core record ${capture.comparison.core_record.number} (#${capture.comparison.core_record.id}), ${capture.comparison.core_record.currency}.` : 'No Core record selected.'} Selecting a record or finding equal amounts does not establish that the records represent the same obligation.</p>
    <div style={{ overflowX: 'auto' }}><table className="data-table"><thead><tr><th>Provider figure</th><th>Stored value</th><th>Core figure</th><th>Core value</th><th>Provider − Core</th><th>Review</th></tr></thead><tbody>{capture.comparison.amounts.map(row => <tr key={row.provider_field}><td>{title(row.provider_field)}</td><td>{display(row.provider_value)}</td><td>{title(row.core_field)}</td><td>{display(row.core_value)}</td><td>{display(row.difference)}</td><td>{reviewLabels[row.status]}</td></tr>)}</tbody></table></div>
    <details><summary>Document amounts and source references</summary><Facts value={document} /></details>
    <FinancialLines key={`lines-${source.observation_hash}`} rows={lines} onChooseLine={captureId ? setSelectedLine : null} />
    {captureId && selectedLine && <PurePayFinancialLineLinks tenantId={capture.tenant_id} captureId={captureId} lineId={selectedLine} />}
    <Rows key={`split-${source.observation_hash}`} rows={splits} label="Invoice allocations" />
    <p>Payment amounts are in the recorded currency where available. Fields labelled cents retain that unit; gross, net and fees are shown separately.</p>
    <Rows key={`payments-${source.observation_hash}`} rows={source.outgoing_payments} label="Outgoing payments" />
    <Rows key={`collections-${source.observation_hash}`} rows={source.collections} label="Collections" />
    <Rows key={`receipts-${source.observation_hash}`} rows={receipts} label="Recorded receipts" />
    <Rows key={`qbo-${source.observation_hash}`} rows={qboReceipts} label="QuickBooks receipts" />
    <p>Observed {source.observed_at}. Missing values remain unknown. No Core accounting entry is posted by this review.</p>
  </section>;
}

export default function PurePayFinancialObservations({ tenantId, connectionVersion }) {
  const [kind, setKind] = useState('bill');
  const [documentId, setDocumentId] = useState('');
  const [coreId, setCoreId] = useState('');
  const [review, setReview] = useState(null);
  const [history, setHistory] = useState(null);
  const [confirmed, setConfirmed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');
  const generation = useRef(0);
  const active = useRef(null);
  const pending = useRef(false);
  useEffect(() => {
    generation.current++; active.current?.abort(); pending.current = false;
    setReview(null); setHistory(null); setConfirmed(false); setBusy(false); setError(''); setMessage(''); setDocumentId(''); setCoreId('');
    const lifecycle = generation; const request = active; const working = pending;
    return () => { lifecycle.current++; request.current?.abort(); working.current = false; };
  }, [tenantId, connectionVersion]);
  const current = () => !!tenantId && (!getPinnedTenantId() || String(getPinnedTenantId()) === String(tenantId));
  const clear = () => { setReview(null); setHistory(null); setConfirmed(false); setMessage(''); };
  const run = async (action, before = null) => {
    if (pending.current) return;
    if (!current()) { clear(); setError('Workspace changed. Refresh the company connection.'); return; }
    const sequence = ++generation.current;
    active.current?.abort(); const controller = new AbortController(); active.current = controller;
    const body = { kind, document_id: documentId.trim() };
    if (action !== 'history') body.core_id = coreId ? Number(coreId) : null;
    if (action === 'save') { body.confirmed = confirmed; body.review_hash = review?.review_hash; }
    if (action === 'history') body.before = before;
    pending.current = true; setBusy(true); setError(''); setMessage(''); setConfirmed(false);
    if (action !== 'save') setReview(null);
    setHistory(null);
    try {
      const result = await api.post(endpoint + action, body, { timeoutMs: 60000, headers: { 'X-CoreFlux-Tenant-Id': String(tenantId) }, fetch: { signal: controller.signal } });
      if (sequence !== generation.current) return;
      const scoped = action === 'save' ? result.saved : result;
      if (!current() || String(scoped.tenant_id) !== String(tenantId)) throw new Error('Workspace changed. Refresh the company connection.');
      if (action === 'history') setHistory(result);
      else if (action === 'save') { setReview(null); setMessage(result.replayed ? 'This capture is already saved. No duplicate created.' : 'Evidence saved in Core. No accounting entry was posted.'); }
      else setReview(result);
    } catch (e) { if (sequence === generation.current) { clear(); setError(e.message || 'Could not review financial evidence.'); } }
    finally { if (sequence === generation.current) { pending.current = false; setBusy(false); } }
  };
  const valid = /^[A-Za-z0-9_-]{1,255}$/.test(documentId.trim()) && (!coreId || /^[1-9][0-9]*$/.test(coreId) && Number.isSafeInteger(Number(coreId)));
  return <section aria-label="PurePay financial evidence" style={{ padding: 16, border: '1px solid var(--cf-border)', borderRadius: 8, marginBottom: 16 }}>
    <h3>Review bills, invoices and payments</h3><p>Read an existing PurePay record, compare optional Core figures, and save a dated evidence capture.</p>
    <label>Source record type<select className="input" value={kind} disabled={busy} onChange={e => { clear(); setKind(e.target.value); setDocumentId(''); setCoreId(''); }}><option value="bill">Supplier bill</option><option value="invoice">Customer invoice</option></select></label>
    <label>PurePay record ID<input className="input" value={documentId} disabled={busy} onChange={e => { clear(); setDocumentId(e.target.value); }} /></label>
    <label>Core record ID to compare (optional)<input className="input" inputMode="numeric" value={coreId} disabled={busy} onChange={e => { clear(); setCoreId(e.target.value); }} /></label>
    <p><button className="btn" disabled={busy || !valid} onClick={() => run('read')}>Review source</button>{' '}<button className="btn" disabled={busy || !documentId.trim()} onClick={() => run('history')}>Saved captures</button></p>
    {error && <p role="alert" className="error">{error}</p>}{message && <p role="status">{message}</p>}
    {current() && review && <><PurePayFinancialObservationView capture={review} /><p><label><input type="checkbox" checked={confirmed} disabled={busy} onChange={e => setConfirmed(e.target.checked)} /> I reviewed these source records and any comparison gaps.</label></p><button className="btn btn--primary" disabled={busy || !confirmed} onClick={() => run('save')}>Save evidence capture</button></>}
    {current() && history && <>{history.items.length === 0 ? <p>No captures saved for this record.</p> : history.items.map(item => <PurePayFinancialObservationView key={`${tenantId}:${connectionVersion}:${item.id}`} capture={item.saved} savedAt={item.saved_at} captureId={item.id} />)}{history.next_before && <button className="btn" disabled={busy} onClick={() => run('history', history.next_before)}>Older capture</button>}</>}
  </section>;
}
