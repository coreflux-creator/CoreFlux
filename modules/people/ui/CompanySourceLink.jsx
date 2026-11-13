import React, { useState } from 'react';
import { api } from '../../../dashboard/src/lib/api';

const endpoint = '/api/admin/integrations/jobdiva_party_review.php';
export default function CompanySourceLink({ id, recordType = 'companies', onLinked }) {
  const [open, setOpen] = useState(false);
  const [sourceId, setSourceId] = useState('');
  const [review, setReview] = useState(null);
  const [confirmed, setConfirmed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [result, setResult] = useState(null);
  const preview = async () => {
    setBusy(true); setError(''); setReview(null); setConfirmed(false); setResult(null);
    try { setReview(await api.post(`${endpoint}?action=preview`, { record_type: recordType, record_id: Number(id), source_id: sourceId.trim() })); }
    catch (e) { setError(e.message); }
    finally { setBusy(false); }
  };
  const link = async () => {
    if (!review || !confirmed) return;
    setBusy(true); setError('');
    try {
      const applied = await api.post(`${endpoint}?action=apply`, { review_token: review.review_token, confirm_review: true });
      setResult(applied); setReview(null); setConfirmed(false); onLinked?.();
    } catch (e) { setError(e.message); }
    finally { setBusy(false); }
  };
  const status = async () => {
    if (!review) return;
    setBusy(true); setError('');
    try {
      const saved = await api.post(`${endpoint}?action=status`, { review_token: review.review_token });
      if (saved.state === 'applied') { setResult(saved.result); setReview(null); setConfirmed(false); onLinked?.(); }
      else setError('This review has not been applied. You can confirm the link or preview again.');
    } catch (e) { setError(e.message); }
    finally { setBusy(false); }
  };
  return <div data-testid={`company-source-link-${recordType}-${id}`} style={{ marginTop: 8 }}>
    <button type="button" className="btn btn--ghost" aria-expanded={open} disabled={busy} onClick={() => setOpen(!open)}>
      {open ? 'Close identity link' : 'Link JobDiva record'}
    </button>
    {open && <section aria-label="Link JobDiva record" style={{ padding: 16, border: '1px solid var(--cf-border, #ddd)', marginTop: 8 }}>
      <p>Review the exact JobDiva {recordType === 'companies' ? 'company' : 'contact'} before linking it to this Core record. Existing Core values stay protected; use Review JobDiva values afterward to choose any source updates.</p>
      <label>JobDiva {recordType === 'companies' ? 'company' : 'contact'} ID
        <input className="input" inputMode="numeric" value={sourceId} disabled={busy} onChange={e => { setSourceId(e.target.value); setReview(null); setConfirmed(false); setResult(null); setError(''); }} />
      </label>
      <button type="button" className="btn btn--ghost" disabled={busy || !/^[1-9][0-9]{0,17}$/.test(sourceId.trim())} onClick={preview}>Preview identity link</button>
      {busy && <p role="status">Checking…</p>}
      {error && <p role="alert" className="error">{error}</p>}
      {result && <p role="status">JobDiva {result.source_id} is linked. Core values and existing provider IDs were preserved.</p>}
      {review && <>
        <div style={{ overflowX: 'auto' }}><table className="data-table"><thead><tr><th>Identity</th><th>Current Core record</th><th>JobDiva record</th></tr></thead>
          <tbody>
            <tr><th>Reference</th><td>{review.target.company_number || `Company #${review.target.company_id}`}{recordType === 'company_contacts' ? ` · Contact #${id}` : ''}</td><td>{review.source.id}</td></tr>
            <tr><th>Name</th><td>{review.target.name}</td><td>{review.source.name}</td></tr>
            <tr><th>Phone</th><td>{review.target.phone || 'Blank'}</td><td>{review.source.phone || 'Blank'}</td></tr>
            {recordType === 'company_contacts' && <><tr><th>Email</th><td>{review.target.email || 'Blank'}</td><td>{review.source.email || 'Blank'}</td></tr><tr><th>Company</th><td>{review.target.company_name}</td><td>{review.source.company_id} · already linked to this Core company</td></tr></>}
          </tbody></table></div>
        {review.target.already_linked && <p>These exact identities are already linked. Confirming again preserves the existing link and field ownership.</p>}
        <label style={{ display: 'block', marginTop: 12 }}><input type="checkbox" checked={confirmed} disabled={busy} onChange={e => setConfirmed(e.target.checked)} /> I reviewed both records and confirm they represent the same {recordType === 'companies' ? 'company' : 'contact'}.</label>
        <button type="button" className="btn btn--primary" disabled={busy || !confirmed} onClick={link}>Confirm identity link</button>
        <button type="button" className="btn btn--ghost" disabled={busy} onClick={status}>Check saved result</button>
      </>}
    </section>}
  </div>;
}
