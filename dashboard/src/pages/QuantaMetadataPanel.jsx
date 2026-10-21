import React, { useState } from 'react';
import { api } from '../lib/api';

const endpoint = '/api/quanta.php?action=';
const style = { padding: '8px 10px', border: '1px solid var(--cf-border)', borderRadius: 5, background: 'var(--cf-surface)', color: 'var(--cf-text-primary)', maxWidth: '100%' };
const labels = { person: 'Person number', placement: 'Placement number', worker_id: 'Quanta worker', client: 'Client number', vendor: 'Vendor number', end_client: 'Company number', worksite_id: 'Quanta worksite', title: 'Title', engagement_type: 'Engagement type', start_date: 'Start date', end_date: 'End date', status: 'Status' };
function valueLabel(value) { return value && typeof value === 'object' ? value.number : (value ?? '—'); }

export default function QuantaMetadataPanel({ people, placements, worksites }) {
  const [kind, setKind] = useState('person');
  const [recordId, setRecordId] = useState('');
  const [siteId, setSiteId] = useState('');
  const [review, setReview] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [confirmed, setConfirmed] = useState(false);
  const resetReview = () => { setReview(null); setConfirmed(false); setError(''); };
  const preview = async () => {
    resetReview(); setBusy(true);
    try { setReview(await api.post(`${endpoint}metadata_preview`, { entity_type: kind, entity_id: Number(recordId), worksite_id: kind === 'placement' && siteId ? siteId : null }, { timeoutMs: 120000 })); }
    catch (e) { setError(e.message || 'Could not prepare the shared record'); }
    finally { setBusy(false); }
  };
  const send = async () => {
    setBusy(true); setError('');
    try {
      const delivery = await api.post(`${endpoint}metadata_send`, { delivery_id: Number(review.delivery.id), review_hash: review.delivery.review_hash, confirm_metadata: confirmed }, { timeoutMs: 120000 });
      setReview(current => ({ ...current, delivery })); setConfirmed(false);
    } catch (e) {
      // A failed browser request may follow a successful provider write. Require a fresh server review.
      setReview(null); setConfirmed(false); setError(e.message || 'Delivery needs review before another attempt');
    } finally { setBusy(false); }
  };
  const options = kind === 'person' ? people : placements;
  const state = review?.delivery?.state;
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
      <button className="btn btn-secondary btn-sm" disabled={busy || !recordId} onClick={preview}>{busy ? 'Working…' : 'Preview shared record'}</button>
    </div>
    {error && <p role="alert" style={{ color: '#b91c1c' }}>{error}</p>}
    {review && <div style={{ marginTop: 16 }}>
      <p><strong>{review.reference.number}</strong> → Quanta <strong>{review.workspace.name}</strong></p>
      <table style={{ width: '100%', maxWidth: 820, borderCollapse: 'collapse', fontSize: 13 }}>
        <thead><tr><th style={{ textAlign: 'left' }}>Field</th><th style={{ textAlign: 'left' }}>Currently in Quanta</th><th style={{ textAlign: 'left' }}>Proposed</th></tr></thead>
        <tbody>{Object.entries(review.proposed).filter(([key]) => labels[key]).map(([key, value]) => <tr key={key}>
          <th style={{ textAlign: 'left', padding: '6px 0' }}>{labels[key]}</th><td>{valueLabel(review.current?.[key])}</td><td>{valueLabel(value)}</td>
        </tr>)}</tbody>
      </table>
      {review.status === 'unchanged' && <p role="status">The shared record already agrees.</p>}
      {state && <p role="status">Delivery: {state === 'delivered' ? 'Confirmed by Quanta' : state}</p>}
      {!review.delivery_enabled && <p>Metadata delivery has not been enabled for this environment.</p>}
      {canSend && <>
        <label style={{ display: 'block', margin: '12px 0' }}><input type="checkbox" checked={confirmed} disabled={busy} onChange={e => setConfirmed(e.target.checked)} /> I reviewed these identities and assignment details.</label>
        <button className="btn btn-primary btn-sm" disabled={busy || !confirmed} onClick={send}>Send reviewed metadata</button>
      </>}
    </div>}
  </section>;
}
