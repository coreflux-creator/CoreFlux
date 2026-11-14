import React, { useState } from 'react';
import { api } from '../../../dashboard/src/lib/api';

const LABELS = { client_id: 'Client', company_id: 'Company', title: 'Title', status: 'Status', description: 'Description', department: 'Department', location_city: 'City', location_state: 'State', location_country: 'Country', remote_policy: 'Remote policy', opened_at: 'Opened', closed_at: 'Closed' };
export default function JobSourceReview({ review, disabled = false, onSaved }) {
  const [fields, setFields] = useState([]), [mode, setMode] = useState('use_once'), [confirmed, setConfirmed] = useState(false);
  const [busy, setBusy] = useState(false), [error, setError] = useState('');
  if (!review?.source) return null;
  const display = (field, value) => value == null || value === '' ? 'Empty' : review.references?.[field]?.[value] ? `${review.references[field][value]} (${value})` : String(value);
  async function decide() {
    setBusy(true); setError('');
    try {
      await api.post('/modules/staffing/api/jobs.php?action=source_decide', { id: review.job_id, fields, mode, review_token: review.review_token, confirm_source: true });
      await onSaved();
    } catch (e) { setError(e.message || 'Could not save this source decision.'); }
    finally { setBusy(false); }
  }
  return <details style={{ marginTop: 16, borderTop: '1px solid var(--cf-border)', paddingTop: 12 }} data-testid="job-source-review">
    <summary>Review JobDiva fields</summary>
    <p>JobDiva · {review.source.external_id}. Core edits remain protected until you change their ownership here.</p>
    {disabled && <p>Save or discard your job edits before reviewing incoming values.</p>}
    <fieldset disabled={disabled || busy} style={{ border: 0, padding: 0, minWidth: 0 }}>
      {Object.entries(review.proposed).map(([field, incoming]) => <div key={field} style={{ borderBottom: '1px solid var(--cf-border)', padding: '10px 0', overflowWrap: 'anywhere' }}>
        <label><input type="checkbox" aria-label={`Select ${LABELS[field]}`} checked={fields.includes(field)} onChange={e => { setFields(v => e.target.checked ? [...v, field] : v.filter(f => f !== field)); setConfirmed(false); }} /> {LABELS[field]}</label>
        <div style={{ fontSize: '.85em', color: 'var(--cf-text-secondary)' }}>{review.owned_fields.includes(field) ? 'Core controls this field' : 'Follows JobDiva'}</div>
        <div>Core: {display(field, review.current[field])}</div>
        <div>JobDiva: {display(field, incoming)}</div>
      </div>)}
      <label style={{ display: 'block', margin: '12px 0' }}>Selected fields
        <select aria-label="Job source decision" value={mode} onChange={e => { setMode(e.target.value); setConfirmed(false); }} style={{ display: 'block', maxWidth: '100%' }}>
          <option value="use_once">Use JobDiva values once; Core keeps control</option>
          <option value="follow_source">Use JobDiva values and follow future updates</option>
          <option value="keep_core">Keep Core values; Core controls these fields</option>
        </select>
      </label>
      <p>{mode === 'follow_source' ? 'Future JobDiva refreshes can update these fields. Editing a field in Core takes control again.' : mode === 'use_once' ? 'The selected values are applied once. Future JobDiva changes remain available for review.' : 'The current Core values are retained, including empty values.'}</p>
      <label><input type="checkbox" aria-label="Confirm job source decision" checked={confirmed} onChange={e => setConfirmed(e.target.checked)} /> I reviewed the selected fields and their ownership.</label>
      <div style={{ marginTop: 10 }}><button type="button" className="btn" disabled={disabled || busy || !confirmed || !fields.length} onClick={decide}>{busy ? 'Saving decision…' : 'Save source decision'}</button></div>
    </fieldset>
    {error && <p role="alert">{error}</p>}
  </details>;
}
