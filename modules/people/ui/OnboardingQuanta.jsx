import React, { useCallback, useEffect, useRef, useState } from 'react';
import { api } from '../../../dashboard/src/lib/api';
const URL = '/modules/people/api/onboarding_quanta.php';

export default function OnboardingQuanta({ value, canManage, disabled, onImported, onBusy }) {
  const [status, setStatus] = useState(null), [preview, setPreview] = useState(null), [error, setError] = useState('');
  const [busy, setBusy] = useState(false), [pending, setPending] = useState(null);
  const [transfers, setTransfers] = useState(null);
  const epoch = useRef(0);
  const invalidate = useCallback(() => { epoch.current++; }, []);
  useEffect(() => { invalidate(); setStatus(null); setTransfers(null); setPreview(null); setPending(null); setError(''); setBusy(false); return invalidate; }, [value.id, value.version, invalidate]);
  async function load() {
    const token = ++epoch.current; setBusy(true); setError('');
    try { const data = await api.get(`${URL}?case_id=${value.id}`); if (token === epoch.current) { setStatus(data); setTransfers(data.document_transfers || null); } }
    catch (e) { if (token === epoch.current) { setStatus(null); setError(e.message); } }
    finally { if (token === epoch.current) setBusy(false); }
  }
  async function loadTransfers() {
    const token = ++epoch.current; setBusy(true); setError('');
    try { const data = await api.get(`${URL}?case_id=${value.id}&view=document_transfers`); if (token === epoch.current) setTransfers(data); }
    catch (e) { if (token === epoch.current) { setTransfers(null); setError(e.message); } }
    finally { if (token === epoch.current) setBusy(false); }
  }
  async function send(body) {
    const token = ++epoch.current; setBusy(true); onBusy?.(true); setError(''); setPending(body);
    try {
      const { result } = await api.post(URL, body);
      if (token !== epoch.current) return;
      setPending(null);
      if (body.action === 'preview') setPreview(result);
      else if (['import', 'import_document'].includes(body.action)) { setPreview(null); await onImported(); }
      else { setPreview(null); const data = await api.get(`${URL}?case_id=${value.id}`); if (token === epoch.current) setStatus(data); }
    } catch (e) {
      if (token !== epoch.current) return;
      setError(e.message);
      if ([400, 401, 403, 404, 409, 415, 422].includes(e.status)) { setPending(null); setPreview(null); setStatus(null); }
    } finally { if (token === epoch.current) setBusy(false); onBusy?.(false); }
  }
  const act = (action, fields = {}) => send({ action, case_id: value.id, ...fields });
  const locked = busy || disabled || Boolean(pending);
  return <section aria-label="Quanta onboarding" style={{ border: '1px solid #dce4ed', borderRadius: 8, padding: 16, marginBottom: 16 }}>
    <h3>Worker portal · Quanta</h3>
    <p>Publish the confirmed requirements to the linked worker. Import worker submissions for employer review. Documents are saved in the person’s vault; importing evidence does not approve a requirement.</p>
    {error && <p role="alert">{error}</p>}
    <button disabled={locked} onClick={load}>Check portal and worker submissions</button>
    {' '}<button disabled={locked} onClick={loadTransfers}>Check retained document transfers</button>
    {busy && <p role="status">Checking…</p>}
    {pending && !busy && <p>The last response is unconfirmed. <button onClick={() => send(pending)}>Check the same request</button></p>}
    {transfers && <details open><summary>Document transfer history</summary>
      <p>Recorded transfer state in Core. A retry verifies the original file before importing it. Retained copies stay private and do not complete a requirement.</p>
      {!transfers.items.length && <p>No document transfers recorded for this case.</p>}
      {transfers.items.map(t => <p key={t.import_id}>{value.tasks.find(task => task.id === t.task_id)?.title || 'Earlier requirement'} · {t.updated_at} · {{ reserved: 'Transfer not yet confirmed', stored: 'Copy prepared; import pending', registered: 'Imported for staff review' }[t.state] || 'Needs reconciliation'}</p>)}
      {transfers.has_more && <p>Showing the latest 100 transfers; earlier records remain retained.</p>}
    </details>}
    {status && <>
      <p>Workspace: <strong>{status.workspace.name}</strong>. {status.publication ? `${status.publication.state === 'withdrawn' ? 'Withdrawn' : 'Published'} · Core revision ${status.publication.core_version}` : 'No publication found.'}</p>
      {status.publication && (!status.publication.assignment_current || !status.publication.source_current || status.publication.core_version !== value.version) && <p>The portal’s copy differs from the current case. Review and publish the latest requirements.</p>}
      {!status.delivery_enabled && <p>Publication is disabled in this environment. You can prepare a review and check existing receipts.</p>}
      {canManage && <p><button disabled={locked} onClick={() => act('preview', { state: 'published' })}>Review publication</button>{' '}
        {status.publication && <button disabled={locked} onClick={() => act('preview', { state: 'withdrawn' })}>Review withdrawal</button>}</p>}
      {preview && <div style={{ background: '#f4f7fb', padding: 12 }}>
        <p><strong>{preview.proposed.state === 'withdrawn' ? 'Withdraw requirements from' : 'Publish requirements to'} {preview.workspace.name}</strong> · {value.person_number} · {value.placement_number}</p>
        <ul>{preview.proposed.tasks.map(t => <li key={t.id}><strong>{t.title}</strong>: {t.instructions} · {t.assignee} · {t.evidence_kind} · {t.required ? 'Required' : 'Optional'}</li>)}</ul>
        {preview.status === 'unchanged' ? <p>This publication already agrees with Core.</p> : <button disabled={locked || !preview.delivery_enabled} onClick={() => act('send', { delivery_id: Number(preview.delivery.id), review_hash: preview.delivery.review_hash })}>Confirm {preview.proposed.state === 'withdrawn' ? 'withdrawal' : 'publication'}</button>}
      </div>}
      <h4>Worker submissions</h4>
      {!status.receipts.length && <p>No worker submissions found.</p>}
      {status.receipts.map(r => <p key={r.id}>{value.tasks.find(t => t.id === r.task_id)?.title || 'Earlier requirement'} · {r.submitted_at} · {r.imported ? 'Imported for staff review' : 'Not imported'}{' '}
        {r.document && <span>{r.document.filename} · {Math.ceil(r.document.size_bytes / 1024)} KB. </span>}
        {!r.imported && canManage && (r.delivery_id ? (r.kind === 'document' && !status.document_transfer ? <span>Document import needs private storage, workspace setup and document-management access.</span> :
          <button disabled={locked} onClick={() => act(r.kind === 'document' ? 'import_document' : 'import', { receipt_id: r.id, delivery_id: Number(r.delivery_id), expected_version: value.version })}>{r.kind === 'document' ? 'Import document for review' : 'Import for review'}</button>) : <span>Confirm the original publication receipt before importing.</span>)}
      </p>)}
      <details><summary>Publication history</summary>{status.history_has_more && <p>Showing recent attempts; earlier records remain retained.</p>}
        {status.deliveries.map(d => <p key={d.id}>Publication #{d.id} · {d.state}{' '}{canManage && ['sending', 'uncertain'].includes(d.state) && <button disabled={locked} onClick={() => act('check_receipt', { delivery_id: Number(d.id), review_hash: d.review_hash })}>Check provider receipt</button>}</p>)}
      </details>
    </>}
  </section>;
}
