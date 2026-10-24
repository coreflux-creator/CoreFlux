import React, { useEffect, useRef, useState } from 'react';
import { api, getPinnedTenantId } from '../lib/api';

const endpoint = '/api/quanta.php?action=';

export default function QuantaIdentityReview({ request, workspaceId, connectionRevision, onClose, onComplete }) {
  const [review, setReview] = useState(null);
  const [confirmed, setConfirmed] = useState(false);
  const [result, setResult] = useState(null);
  const [needsCheck, setNeedsCheck] = useState(false);
  const [busy, setBusy] = useState(true);
  const [error, setError] = useState('');
  const alive = useRef(false);
  const scope = `${workspaceId}:${connectionRevision}`;
  const currentScope = useRef(scope); currentScope.current = scope;
  const inFlight = useRef(false);

  useEffect(() => {
    let active = true; alive.current = true;
    const controller = new AbortController();
    const same = () => active && (!getPinnedTenantId() || String(getPinnedTenantId()) === String(workspaceId));
    setReview(null); setConfirmed(false); setResult(null); setNeedsCheck(false); setBusy(true); setError('');
    if (!/^[1-9][0-9]*$/.test(String(workspaceId || '')) || !same()) {
      setError('The workspace changed. Refresh the workers before reviewing.'); setBusy(false);
    } else {
      api.post(`${endpoint}identity_preview`, request, { timeoutMs: 120000, headers: { 'X-CoreFlux-Tenant-Id': String(workspaceId) }, fetch: { signal: controller.signal } })
        .then(data => { if (same()) setReview(data); })
        .catch(e => { if (same()) setError(e.message || 'Could not review these identities.'); })
        .finally(() => { if (active) { setBusy(false); if (!same()) setError('The workspace changed. Refresh the workers before reviewing.'); } });
    }
    return () => { active = false; alive.current = false; controller.abort(); };
  }, [request, workspaceId, connectionRevision]);

  const perform = async check => {
    if (!review || inFlight.current || (!check && (!confirmed || needsCheck))) return;
    const same = () => alive.current && currentScope.current === scope && (!getPinnedTenantId() || String(getPinnedTenantId()) === String(workspaceId));
    if (!same()) { setReview(null); setError('The workspace changed. Refresh the workers before reviewing.'); return; }
    inFlight.current = true; setBusy(true); setError(''); setConfirmed(false);
    try {
      const action = check ? 'identity_status' : review.mode === 'create' ? 'create_worker_person' : 'save_worker_links';
      const data = await api.post(`${endpoint}${action}`, { review_token: review.review_token, ...(!check && { confirm_review: true }) },
        { timeoutMs: 120000, headers: { 'X-CoreFlux-Tenant-Id': String(workspaceId) } });
      if (!same()) return;
      if (!check || data.state === 'applied') { setResult(check ? data.result : data); setNeedsCheck(false); }
      else { setNeedsCheck(false); setError('No completed intake is recorded. Review the fields and confirm again before applying.'); }
    } catch (e) {
      if (same()) { setNeedsCheck(true); setError(e.message || 'The response was interrupted. Check the result before applying again.'); }
    } finally {
      if (alive.current && currentScope.current === scope) {
        inFlight.current = false; setBusy(false);
        if (!same()) { setReview(null); setError('The workspace changed. Refresh the workers before reviewing.'); }
      }
    }
  };

  return <div role="dialog" aria-modal="true" aria-label="Review Quanta identities" style={{ position: 'fixed', inset: 0, zIndex: 1000, background: '#0b223a88', display: 'grid', placeItems: 'center', padding: 20 }}>
    <section style={{ background: 'var(--cf-surface, white)', color: 'var(--cf-text-primary)', padding: 24, borderRadius: 8, maxWidth: 1000, width: '100%', maxHeight: '85vh', overflow: 'auto' }}>
      <h2>Review Quanta identities</h2>
      <p>{request.mode === 'create' ? 'Create a provisional Core person and link this worker. Employment classification and placement details remain for review.' : 'Link the chosen records. Names, contact fields and provider IDs will be preserved.'} No Quanta access or invitation is created.</p>
      {busy && <p role="status">Checking…</p>}
      {error && <p role="alert">{error}</p>}
      {review && <><p>Quanta workspace: <strong>{review.workspace.name}</strong> · Core people directory: <strong>{review.people_directory_name}</strong></p>
        {review.rows.map(row => <div key={row.worker.id} style={{ marginTop: 16, paddingTop: 12, borderTop: '1px solid var(--cf-border)' }}>
          <h3>{row.worker.full_name}</h3>
          <table style={{ width: '100%', textAlign: 'left', borderCollapse: 'collapse' }}><thead><tr><th>Field</th><th>Quanta</th><th>{review.mode === 'create' ? 'Proposed Core person' : 'Chosen Core person'}</th></tr></thead>
            <tbody>{[
              ['Name', row.worker.full_name, `${row.target.first_name} ${row.target.last_name}`],
              ['Email', row.worker.email, row.target.email_primary], ['Phone', row.worker.phone, row.target.phone_primary],
              ['Status', row.worker.status, row.target.status], ['Person number', 'Separate Core identity', row.target.person_number || 'Allocated on creation'],
            ].map(([label, source, target]) => <tr key={label}><th style={{ padding: '7px 0' }}>{label}</th><td>{source || '—'}</td><td>{target || '—'}</td></tr>)}</tbody></table>
          <p>Quanta worker: {row.worker.id}{row.worker.employee_id && ` · Provider employee ID: ${row.worker.employee_id}`}{row.worker.external_id && ` · Provider external ID: ${row.worker.external_id}`}</p>
        </div>)}
      </>}
      {result && <p role="status">Recorded in Core: {result.people.map(person => person.person_number).join(', ')}. Refresh the directory to see current links.</p>}
      {review && !result && !needsCheck && <label style={{ display: 'block', marginTop: 16 }}><input type="checkbox" checked={confirmed} disabled={busy} onChange={e => setConfirmed(e.target.checked)} /> I reviewed these identities and contact fields.</label>}
      {needsCheck && <p>Check the recorded result before another attempt.</p>}
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 10, marginTop: 18 }}>
        {review && !result && !needsCheck && <button type="button" className="btn btn-primary" disabled={busy || !confirmed} onClick={() => perform(false)}>{review.mode === 'create' ? 'Create and link' : 'Apply reviewed links'}</button>}
        {review && !result && <button type="button" className="btn btn-secondary" disabled={busy} onClick={() => perform(true)}>Check recorded result</button>}
        {result && <button type="button" className="btn btn-primary" disabled={busy} onClick={() => { if (alive.current && currentScope.current === scope && (!getPinnedTenantId() || String(getPinnedTenantId()) === String(workspaceId))) onComplete(result); }}>Refresh workers</button>}
        <button type="button" className="btn btn-secondary" disabled={busy} onClick={onClose}>Close review</button>
      </div>
    </section>
  </div>;
}
