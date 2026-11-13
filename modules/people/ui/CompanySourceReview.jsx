import React, { useState } from 'react';
import { api } from '../../../dashboard/src/lib/api';

const labelFor = field => ({ roles: 'Client / vendor roles', ein_last4: 'Tax ID last four', msa_signed_at: 'MSA signed',
  is_primary: 'Primary contact', is_active: 'Active', account_manager_user_id: 'Account manager ID' }[field]
  || field.replaceAll('_', ' ').replace(/^./, c => c.toUpperCase()));
const displayValue = value => value === null || value === '' ? 'Blank' : Array.isArray(value) ? value.join(', ') || 'None' : String(value);

export default function CompanySourceReview({ id, recordType = 'companies', onAccepted }) {
  const [open, setOpen] = useState(false);
  const [review, setReview] = useState(null);
  const [selected, setSelected] = useState([]);
  const [confirmed, setConfirmed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');
  const endpoint = `/modules/people/api/companies.php?id=${encodeURIComponent(id)}&record_type=${recordType}`;
  const refresh = async () => {
    setBusy(true); setError(''); setMessage(''); setReview(null); setSelected([]); setConfirmed(false);
    try { const result = await api.get(`${endpoint}&action=source_review`); setReview(result.review); }
    catch (e) { setError(e.message); }
    finally { setBusy(false); }
  };
  const accept = async () => {
    if (!confirmed || !selected.length || !review) return;
    setBusy(true); setError('');
    try {
      const result = await api.post(`${endpoint}&action=source_accept`, {
        fields: selected, review_token: review.review_token, confirm_source: true,
      });
      setReview(null); setSelected([]); setConfirmed(false);
      setMessage(result.replayed ? 'This selection was already applied. Later Core edits were preserved.' : 'Selected fields now use JobDiva.');
      onAccepted?.();
    } catch (e) { setError(e.message); }
    finally { setBusy(false); }
  };
  return <div data-testid={`company-source-review-${recordType}-${id}`} style={{ marginTop: 8 }}>
    <button type="button" className="btn btn--ghost" aria-expanded={open} disabled={busy}
      onClick={() => { setOpen(!open); if (!open) refresh(); }}>
      {open ? 'Close JobDiva review' : 'Review JobDiva values'}
    </button>
    {open && <section aria-label="Review JobDiva values" style={{ padding: 16, border: '1px solid var(--cf-border, #ddd)', marginTop: 8 }}>
      <p>Core edits protect those fields from source updates. Choose which values to use from JobDiva.</p>
      <button type="button" className="btn btn--ghost" disabled={busy} onClick={refresh}>Refresh review</button>
      {busy && <p role="status">Working…</p>}
      {error && <p className="error" role="alert">{error}</p>}
      {message && <p role="status">{message}</p>}
      {review && !review.source && <p>No JobDiva values have been observed for this record.</p>}
      {review?.source && <>
        <p>JobDiva record {review.source.external_id}{review.observed_at ? ` · Last observed ${new Date(review.observed_at).toLocaleString()}` : ''}</p>
        {review.legacy_unreviewed && <p>Existing Core values are protected until reviewed.</p>}
        <div style={{ overflowX: 'auto' }}><table className="data-table">
          <thead><tr><th>Use JobDiva</th><th>Field</th><th>Current Core value</th><th>Latest JobDiva value</th><th>Updated by</th></tr></thead>
          <tbody>{Object.entries(review.proposed).map(([field, value]) => <tr key={field}>
            <td><input type="checkbox" aria-label={`Use JobDiva for ${labelFor(field)}`} checked={selected.includes(field)} disabled={busy}
              onChange={e => { setSelected(e.target.checked ? [...selected, field] : selected.filter(f => f !== field)); setConfirmed(false); }} /></td>
            <td>{labelFor(field)}</td><td style={{ whiteSpace: 'pre-wrap' }}>{displayValue(review.current[field])}</td>
            <td style={{ whiteSpace: 'pre-wrap' }}>{displayValue(value)}</td><td>{review.owned_fields.includes(field) ? 'Core — protected' : 'JobDiva'}</td>
          </tr>)}</tbody>
        </table></div>
        {!Object.keys(review.proposed).length && <p>No source fields are available for review.</p>}
        <label style={{ display: 'block', marginTop: 12 }}><input type="checkbox" checked={confirmed} disabled={busy || !selected.length}
          onChange={e => setConfirmed(e.target.checked)} /> Use the selected JobDiva values now and keep these fields updated from JobDiva. A later Core edit will protect the field again.</label>
        <button type="button" className="btn btn--primary" disabled={busy || !confirmed || !selected.length} onClick={accept} style={{ marginTop: 12 }}>
          Use selected JobDiva values
        </button>
      </>}
    </section>}
  </div>;
}
