import React, { useEffect, useRef, useState } from 'react';
import { ExternalLink, Upload } from 'lucide-react';
import { api, getPinnedTenantId } from '../../../dashboard/src/lib/api';

const endpoint = '/modules/placements/api/documents.php';
const types = ['msa', 'sow', 'work_order', 'rate_sheet', 'timesheet_template', 'poc', 'noc', 'other'];
const label = value => value.replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase());
const identity = context => ['tenant_id', 'placements_tenant_id', 'actor_user_id', 'placement_id'].map(key => Number(context?.[key])).join(':');
const pendingKey = context => `coreflux:pending-placement-document:${identity(context)}`;
const options = context => ({ headers: { 'X-CoreFlux-Tenant-Id': String(context.tenant_id) } });
const digest = async file => Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', await file.arrayBuffer())), byte => byte.toString(16).padStart(2, '0')).join('');

// A separate instance prevents an old placement request from updating a newly selected placement.
export default function PlacementDocumentsTab({ pid, readOnly = false }) {
  const pin = getPinnedTenantId();
  return <DocumentWorkspace key={`${pid}:${pin || 'session'}:${readOnly}`} pid={Number(pid)} pin={pin} readOnly={readOnly} />;
}

function DocumentWorkspace({ pid, pin, readOnly }) {
  const [context, setContext] = useState(null);
  const [rows, setRows] = useState([]);
  const [pending, setPending] = useState(null);
  const [form, setForm] = useState({ doc_type: 'other', effective_from: '', effective_to: '' });
  const [file, setFile] = useState(null);
  const [needsFile, setNeedsFile] = useState(false);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState(null);
  const [loading, setLoading] = useState(true);
  const mounted = useRef(true);
  const busyRef = useRef(false);
  const pendingRef = useRef(null);
  const contextRef = useRef(null);
  const sequence = useRef(0);
  const fileInput = useRef(null);
  const here = () => mounted.current && getPinnedTenantId() === pin;
  const assertHere = () => { if (!here()) throw new Error('The selected workspace changed. Return to the original placement to resume this upload.'); };
  const accept = (result, expected = null) => {
    assertHere();
    const next = result?.context;
    if (!next || ['tenant_id', 'placements_tenant_id', 'actor_user_id', 'placement_id'].some(key => !Number.isSafeInteger(Number(next[key])) || Number(next[key]) <= 0)
      || Number(next.placement_id) !== pid || (pin && String(next.tenant_id) !== pin)
      || typeof next.permissions?.manage !== 'boolean' || (expected && identity(next) !== identity(expected))) {
      contextRef.current = null; setContext(null); setRows([]);
      throw new Error('The document workspace changed or could not be verified. Reload this placement.');
    }
    contextRef.current = next; setContext(next);
    return next;
  };
  const load = async expected => {
    const request = ++sequence.current;
    try {
      const result = await api.get(`${endpoint}?placement_id=${pid}`, expected ? options(expected) : undefined);
      assertHere();
      if (request !== sequence.current) throw new Error('A newer document review is in progress.');
      const next = accept(result, expected);
      if (!Array.isArray(result.documents)) throw new Error('Document list could not be verified.');
      setRows(result.documents);
      return next;
    } catch (error) {
      if (here() && request === sequence.current) { contextRef.current = null; setContext(null); setRows([]); }
      throw error;
    }
  };
  const requireManage = async expected => {
    const next = await load(expected);
    if (readOnly || !next.permissions.manage) throw new Error('You have read-only access to these documents.');
    return next;
  };
  const remember = receipt => {
    // Preserve only the exact metadata and file fingerprint, never signed credentials or file bytes.
    window.sessionStorage.setItem(pendingKey(receipt.context), JSON.stringify(receipt));
    pendingRef.current = receipt;
    if (here()) setPending(receipt);
  };
  useEffect(() => {
    mounted.current = true;
    load().then(next => {
      if (readOnly || !next.permissions.manage) return;
      const raw = window.sessionStorage.getItem(pendingKey(next));
      if (!raw) return;
      const saved = JSON.parse(raw);
      if (identity(saved.context) !== identity(next) || !/^[a-f0-9]{64}$/.test(saved.sha256 || '')
        || !saved.body?.storage_key?.startsWith(`placements/${next.placements_tenant_id}/document/${pid}/`)
        || !types.includes(saved.body.doc_type) || typeof saved.body.filename !== 'string') throw new Error('The pending upload needs review before another file can be added.');
      pendingRef.current = saved; setPending(saved);
      setMessage({ ok: false, text: 'An earlier upload needs confirmation. Check the original file before uploading another.' });
    }).catch(error => {
      if (here()) { contextRef.current = null; setContext(null); setMessage({ ok: false, text: error.message }); }
    }).finally(() => { if (here()) setLoading(false); });
    return () => { mounted.current = false; };
    // The keyed workspace owns this one-time initial load and its pending receipt.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pid, pin, readOnly]);

  const upload = async event => {
    event.preventDefault();
    if (readOnly || busyRef.current || !contextRef.current?.permissions.manage || !here()) return;
    busyRef.current = true; setBusy(true); setMessage(null);
    try {
      let receipt = pendingRef.current;
      const owner = await requireManage(receipt?.context || contextRef.current);
      if (!receipt) {
        if (!file || file.size <= 0 || file.size > 26214400) throw new Error('Choose a file between 1 byte and 25 MB.');
        if (form.effective_from && form.effective_to && form.effective_to < form.effective_from) throw new Error('The effective end cannot precede the start.');
        const sha256 = await digest(file); assertHere();
        const signed = await api.get(`${endpoint}?action=upload_url&placement_id=${pid}&file_name=${encodeURIComponent(file.name)}`, options(owner));
        accept(signed, owner);
        if (!signed.storage_key || !signed.upload?.url) throw new Error('The upload could not be prepared.');
        receipt = { context: owner, sha256, body: { storage_key: signed.storage_key, filename: file.name, mime: file.type || null, size_bytes: file.size, doc_type: form.doc_type, effective_from: form.effective_from || null, effective_to: form.effective_to || null } };
        remember(receipt);
        await sendFile(signed, file);
      } else {
        const status = await api.get(`${endpoint}?action=upload_status&placement_id=${pid}&storage_key=${encodeURIComponent(receipt.body.storage_key)}`, options(owner));
        accept(status, owner);
        if (typeof status.present !== 'boolean') throw new Error('The original upload could not be checked.');
        if (!status.present) {
          setNeedsFile(true);
          if (!file) throw new Error('The original file has not arrived. Select that same file to resume.');
          if (file.name !== receipt.body.filename || file.size !== receipt.body.size_bytes || await digest(file) !== receipt.sha256) throw new Error('Choose the same original file; this selection does not match the pending upload.');
          assertHere();
          const signed = await api.get(`${endpoint}?action=upload_url&placement_id=${pid}&file_name=${encodeURIComponent(receipt.body.filename)}&storage_key=${encodeURIComponent(receipt.body.storage_key)}`, options(owner));
          accept(signed, owner);
          if (signed.storage_key !== receipt.body.storage_key || !signed.upload?.url) throw new Error('The original upload reference could not be confirmed.');
          await sendFile(signed, file);
        }
      }
      // Recheck current permission and directory after the external upload finishes.
      await requireManage(owner); assertHere();
      const result = await api.post(`${endpoint}?placement_id=${pid}`, receipt.body, options(owner));
      accept(result, owner);
      const document = result.document;
      if (!Number.isSafeInteger(Number(result.id)) || Number(result.id) <= 0 || Number(document?.id) !== Number(result.id)
        || Number(document.placement_id) !== pid || Number(document.storage_object_id) !== Number(result.storage_object_id)
        || document.file_name !== receipt.body.filename || document.doc_type !== receipt.body.doc_type
        || document.effective_from !== receipt.body.effective_from || document.effective_to !== receipt.body.effective_to) throw new Error('The save result could not be confirmed. Check this same upload again.');
      setRows(current => [...current.filter(row => Number(row.id) !== Number(document.id)), document]);
      // A receipt confirms the save even when browser cleanup or a later list refresh fails.
      let cleaned = true;
      try { window.sessionStorage.removeItem(pendingKey(owner)); } catch { cleaned = false; }
      if (cleaned) {
        pendingRef.current = null; setPending(null); setFile(null); setNeedsFile(false);
        setForm({ doc_type: 'other', effective_from: '', effective_to: '' });
        if (fileInput.current) fileInput.current.value = '';
      }
      setMessage({ ok: true, text: `${receipt.body.filename} saved.${result.replayed ? ' The original save is confirmed; no duplicate was added.' : ''}${cleaned ? '' : ' Your browser could not clear the pending receipt. Check this same upload again before starting another.'}` });
    } catch (error) {
      if (here()) setMessage({ ok: false, text: `${error.message}${pendingRef.current ? ' Your original upload reference has been retained.' : ''}` });
    } finally {
      busyRef.current = false;
      if (here()) setBusy(false);
    }
  };
  const sendFile = async (signed, original) => {
    assertHere();
    const body = new FormData();
    Object.entries(signed.upload.fields || {}).forEach(([key, value]) => body.append(key, value));
    body.append('file', original);
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 30000);
    let response;
    try { response = await fetch(signed.upload.url, { method: 'POST', body, signal: controller.signal }); }
    finally { clearTimeout(timer); }
    assertHere();
    if (!response.ok) throw new Error(`File transfer was not confirmed (${response.status}). Check the original upload before retrying.`);
    setNeedsFile(false);
  };
  const openDocument = async id => {
    if (!contextRef.current || !here()) return;
    setMessage(null);
    try {
      const owner = await load(contextRef.current);
      const result = await api.get(`${endpoint}?placement_id=${pid}&id=${id}`, options(owner));
      assertHere();
      if (!result.signed_url || Number(result.document?.placement_id) !== pid || Number(result.document?.id) !== Number(id)) throw new Error('A download link could not be verified.');
      window.open(result.signed_url, '_blank', 'noopener,noreferrer');
    } catch (error) { if (here()) setMessage({ ok: false, text: error.message }); }
  };
  const shown = pending?.body || form;
  return <div data-testid="tab-documents">
    <h3>Documents</h3>
    {loading && <p>Checking document access…</p>}
    {context && (readOnly || !context.permissions.manage) && <p>Read-only document access.</p>}
    {!readOnly && context?.permissions.manage && <form onSubmit={upload} data-testid="placement-document-upload">
      <fieldset disabled={busy || !!pending} className="filter-bar" style={{ border: 0, padding: 0, alignItems: 'end' }}>
        <label>Type<select className="input" aria-label="Document type" value={shown.doc_type} onChange={e => setForm(current => ({ ...current, doc_type: e.target.value }))}>{types.map(type => <option key={type} value={type}>{label(type)}</option>)}</select></label>
        <label>Effective from<input className="input" aria-label="Document effective from" type="date" value={shown.effective_from || ''} onChange={e => setForm(current => ({ ...current, effective_from: e.target.value }))} /></label>
        <label>Effective to<input className="input" aria-label="Document effective to" type="date" value={shown.effective_to || ''} onChange={e => setForm(current => ({ ...current, effective_to: e.target.value }))} /></label>
        <label>File<input className="input" type="file" ref={fileInput} onChange={e => setFile(e.target.files?.[0] || null)} data-testid="placement-document-file" /></label>
      </fieldset>
      {pending && <p>Pending file: {pending.body.filename}</p>}
      {pending && needsFile && <label>Select the original file<input type="file" disabled={busy} data-testid="placement-document-resume-file" onChange={e => setFile(e.target.files?.[0] || null)} /></label>}
      <button className="btn btn--primary" type="submit" disabled={busy || (!pending && !file)} data-testid="placement-document-submit"><Upload size={16} aria-hidden="true" /> {busy ? 'Saving…' : pending ? 'Check original upload' : 'Upload'}</button>
    </form>}
    {message && <p role="status" className={message.ok ? 'success' : 'error'} data-testid="placement-document-message">{message.text}</p>}
    {context && <table className="data-table" data-testid="documents-table">
      <thead><tr><th>Type</th><th>File</th><th>Effective</th><th>Uploaded</th><th aria-label="Actions" /></tr></thead>
      <tbody>{rows.length === 0 && <tr><td colSpan={5} className="empty" data-testid="documents-empty">No documents yet.</td></tr>}
        {rows.map(d => <tr key={d.id} data-testid={`document-row-${d.id}`}><td>{label(d.doc_type)}</td><td>{d.file_name || `#${d.storage_object_id}`}</td><td>{d.effective_from || '—'}{d.effective_to ? ` → ${d.effective_to}` : ''}</td><td>{(d.created_at || '').slice(0, 10)}</td><td><button className="btn btn--ghost btn--icon" type="button" onClick={() => openDocument(d.id)} aria-label={`Open ${d.file_name || 'document'}`} title="Open document"><ExternalLink size={16} aria-hidden="true" /></button></td></tr>)}
      </tbody>
    </table>}
  </div>;
}
