import React, { useEffect, useRef, useState } from 'react';
import { api, getPinnedTenantId } from '../lib/api';

export default function JobDivaPersonReview({ workspaceId }) {
  const [candidate, setCandidate] = useState('');
  const [number, setNumber] = useState('');
  const [review, setReview] = useState(null);
  const [confirmed, setConfirmed] = useState(false);
  const [result, setResult] = useState(null);
  const [needsCheck, setNeedsCheck] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const alive = useRef(false), inFlight = useRef(false), scope = useRef(workspaceId);
  scope.current = workspaceId;
  useEffect(() => {
    alive.current = true;
    setReview(null); setConfirmed(false); setResult(null); setNeedsCheck(false); setError('');
    return () => { alive.current = false; };
  }, [workspaceId]);
  const same = () => alive.current && scope.current === workspaceId && /^[1-9][0-9]*$/.test(String(workspaceId || ''))
    && (!getPinnedTenantId() || String(getPinnedTenantId()) === String(workspaceId));
  const perform = async action => {
    if (inFlight.current || (action === 'apply' && (!review || !confirmed || needsCheck))) return;
    if (!same()) { setReview(null); setError('The workspace changed. Refresh JobDiva settings before reviewing.'); return; }
    inFlight.current = true; setBusy(true); setError(''); setConfirmed(false);
    if (action === 'preview') { setReview(null); setResult(null); setNeedsCheck(false); }
    try {
      const body = action === 'preview' ? { candidate_id: candidate.trim(), person_number: number.trim().toUpperCase() }
        : { review_token: review.review_token, ...(action === 'apply' && { confirm_review: true }) };
      const data = await api.post(`/api/admin/integrations/jobdiva_person_review.php?action=${action}`, body,
        { timeoutMs: 120000, headers: { 'X-CoreFlux-Tenant-Id': String(workspaceId) } });
      if (!same()) return;
      if (action === 'preview') setReview(data);
      else if (action === 'apply' || data.state === 'applied') { setResult(action === 'apply' ? data : data.result); setNeedsCheck(false); }
      else { setNeedsCheck(false); setError('No completed link is recorded. Confirm the review again before applying.'); }
    } catch (e) {
      if (same()) { setError(e.message || 'Could not complete the review.'); if (action !== 'preview') setNeedsCheck(true); }
    } finally {
      inFlight.current = false;
      if (alive.current) { setBusy(false); if (!same()) { setReview(null); setResult(null); setError('The workspace changed. Refresh JobDiva settings before reviewing.'); } }
    }
  };
  return <section style={{ padding: 16, border: '1px solid var(--cf-border, #e2e8f0)', borderRadius: 10 }}>
    <h2>Link a JobDiva candidate</h2>
    <p>Use this when an imported candidate already exists in Core. Compare the current JobDiva candidate with the Core person before linking. Names, contact details and provider IDs are preserved.</p>
    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 12 }}>
      <label>JobDiva candidate ID<input aria-label="JobDiva candidate ID" value={candidate} disabled={busy || !!review} onChange={e => setCandidate(e.target.value)} /></label>
      <label>Core person number<input aria-label="Core person number" placeholder="P-…" value={number} disabled={busy || !!review} onChange={e => setNumber(e.target.value)} /></label>
      {!review && <button className="btn btn--primary" disabled={busy || !candidate.trim() || !number.trim() || !workspaceId} onClick={() => perform('preview')}>Review candidate link</button>}
    </div>
    {busy && <p role="status">Checking identities…</p>}
    {error && <p role="alert">{error}</p>}
    {review && <>
      <table style={{ width: '100%', textAlign: 'left', marginTop: 12 }}><thead><tr><th>Field</th><th>JobDiva candidate</th><th>Core person</th></tr></thead>
        <tbody>{[
          ['Name', review.source.name, `${review.target.first_name} ${review.target.last_name}`],
          ['Email', review.source.email, review.target.email_primary], ['Phone', review.source.phone, review.target.phone_primary],
          ['Record ID', review.source.candidate_id, review.target.person_number], ['Existing external ID', 'JobDiva candidate ID retained', review.target.external_id],
        ].map(([label, source, target]) => <tr key={label}><th>{label}</th><td>{source || '—'}</td><td>{target || '—'}</td></tr>)}</tbody>
      </table>
      {!result && !needsCheck && <label style={{ display: 'block', marginTop: 12 }}><input type="checkbox" checked={confirmed} disabled={busy} onChange={e => setConfirmed(e.target.checked)} /> I reviewed these records and confirm they are the same person.</label>}
      {!result && !needsCheck && <button className="btn btn--primary" disabled={busy || !confirmed} onClick={() => perform('apply')}>Apply reviewed link</button>}
      {!result && <button className="btn btn--ghost" disabled={busy} onClick={() => perform('status')}>Check recorded result</button>}
      {needsCheck && <p>Check the recorded result before another attempt.</p>}
      {!needsCheck && <button className="btn btn--ghost" disabled={busy} onClick={() => { setReview(null); setConfirmed(false); setResult(null); setError(''); }}>Review another link</button>}
    </>}
    {result && <p role="status">Recorded: JobDiva candidate {result.candidate_id} → {result.person_number}. Sync the assignment again to continue importing it.</p>}
  </section>;
}
